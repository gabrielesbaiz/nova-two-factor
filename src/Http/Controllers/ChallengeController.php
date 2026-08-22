<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Http\Controllers;

use Gabrielesbaiz\NovaTwoFactor\Enums\ChallengePurpose;
use Gabrielesbaiz\NovaTwoFactor\Events\LockedOut;
use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorMethod;
use Gabrielesbaiz\NovaTwoFactor\Support\TwoFactorSession;
use Gabrielesbaiz\NovaTwoFactor\TrustedDevices\TrustedDeviceManager;
use Gabrielesbaiz\NovaTwoFactor\TwoFactorManager;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ChallengeController extends Controller
{
    public function __construct(
        private readonly TwoFactorManager $twoFactor,
        private readonly TrustedDeviceManager $trustedDevices,
    ) {}

    /**
     * The challenge page.
     *
     * Server-rendered Blade, not an Inertia page. Nova resolves the initial
     * Inertia component *before* it executes any tool script, so a page
     * registered by a tool renders a spinner forever on a cold load — and a
     * challenge is always a cold load. This also means the screen works with
     * JavaScript disabled.
     */
    public function show(Request $request): View
    {
        $user = $this->novaUserOrFail();
        $methods = $user->confirmedTwoFactorMethods();

        return view('nova-two-factor::challenge', [
            'methods' => $methods,
            'default' => $user->defaultTwoFactorMethod(),
            'recoveryCodesRemaining' => $this->twoFactor->recoveryCodes()->unusedCount($user),
            'trustedDevicesEnabled' => $this->trustedDevices->enabled(),
            'trustedDeviceDays' => (int) Config::get('nova-two-factor.trusted_devices.days', 30),
            'intended' => $request->session()->get('url.intended', $this->novaPath()),
        ]);
    }

    /**
     * Prepare a factor — send a code, or build ceremony options.
     */
    public function prepare(Request $request): JsonResponse
    {
        $validated = $request->validate(['method_id' => ['required', 'integer']]);

        $user = $this->novaUserOrFail();
        $method = $this->ownedMethod($user, (int) $validated['method_id']);

        $payload = $this->twoFactor
            ->driver($method->type)
            ->beginChallenge($method, $this->twoFactor->context($user, ChallengePurpose::Login));

        return response()->json($payload ?? [])
            ->header('Cache-Control', 'no-store, max-age=0');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'method_id' => ['nullable', 'integer'],
            'code' => ['nullable', 'string', 'max:64'],
            'recovery_code' => ['nullable', 'string', 'max:64'],
            'credential' => ['nullable'],
            'trust_device' => ['nullable', 'boolean'],
        ]);

        $user = $this->novaUserOrFail();

        $this->assertNotRateLimited($request, $user);

        $context = $this->twoFactor->context($user, ChallengePurpose::Login);

        $usingRecoveryCode = ! empty($validated['recovery_code']);

        $result = $usingRecoveryCode
            ? $this->twoFactor->consumeRecoveryCode($user, (string) $validated['recovery_code'], $context)
            : $this->twoFactor->verify(
                $this->ownedMethod($user, (int) ($validated['method_id'] ?? 0)),
                $validated,
                $context,
            );

        if (! $result->passed) {
            $this->recordFailure($request, $user);

            throw ValidationException::withMessages([
                $usingRecoveryCode ? 'recovery_code' : 'code' => [$this->messageFor($result->failure)],
            ])->status(422);
        }

        $this->clearRateLimit($request, $user);

        // Regenerates the session id, which is the point at which fixation
        // across the 2FA boundary has to be broken.
        (new TwoFactorSession($request->session()))->markPassed($result->method);

        $response = response()->json([
            'redirect' => $request->session()->pull('url.intended', $this->novaPath()),
            'recovery_codes_remaining' => $this->twoFactor->recoveryCodes()->unusedCount($user),
            'used_recovery_code' => $usingRecoveryCode,
        ]);

        // Never offered when a recovery code was used: that path already means
        // the user has lost their normal factor.
        if (! $usingRecoveryCode && ($validated['trust_device'] ?? false)) {
            $cookie = $this->trustedDevices->trust($user, $request);

            if ($cookie !== null) {
                $response->withCookie($cookie);
            }
        }

        return $response;
    }

    protected function ownedMethod(mixed $user, int $methodId): TwoFactorMethod
    {
        /** @var TwoFactorMethod|null $method */
        $method = $user->twoFactorMethods()->confirmed()->whereKey($methodId)->first();

        abort_if($method === null, 404);

        return $method;
    }

    protected function limiterKey(Request $request, mixed $user): string
    {
        return 'nova-two-factor:challenge|'.$user->getMorphClass().'|'.$user->getAuthIdentifier();
    }

    /**
     * Refuse further attempts once the budget is spent.
     *
     * A `Retry-After` header is sent deliberately: the client's countdown reads
     * it rather than guessing, so the UI and the server never disagree about
     * when the lock lifts.
     */
    protected function assertNotRateLimited(Request $request, mixed $user): void
    {
        $limits = Config::get('nova-two-factor.rate_limits.challenge', []);
        $max = (int) ($limits['per_user'] ?? 5);
        $key = $this->limiterKey($request, $user);

        if (! RateLimiter::tooManyAttempts($key, $max)) {
            return;
        }

        $seconds = RateLimiter::availableIn($key);

        event(new Lockout($request));
        event(new LockedOut($user, null, ['retry_after' => $seconds]));

        throw ValidationException::withMessages([
            'code' => [__('Too many attempts. Try again in :seconds seconds.', ['seconds' => $seconds])],
        ])->status(429);
    }

    /**
     * Count a failure with exponential backoff.
     *
     * The decay grows only once the ordinary budget is spent, so a user
     * fighting a skewed phone clock is not punished like a brute-forcer.
     */
    protected function recordFailure(Request $request, mixed $user): void
    {
        $limits = Config::get('nova-two-factor.rate_limits.challenge', []);
        $max = (int) ($limits['per_user'] ?? 5);
        $base = (int) ($limits['lockout'] ?? 60);
        $ceiling = (int) ($limits['lockout_ceiling'] ?? 900);

        $key = $this->limiterKey($request, $user);
        $attempts = RateLimiter::attempts($key);

        $decay = $attempts < $max
            ? $base
            : min($ceiling, $base * (2 ** ($attempts - $max + 1)));

        RateLimiter::hit($key, $decay);
    }

    protected function clearRateLimit(Request $request, mixed $user): void
    {
        RateLimiter::clear($this->limiterKey($request, $user));
    }

    protected function messageFor(?string $failure): string
    {
        return match ($failure) {
            'replayed' => __('That code has already been used. Wait for a new one.'),
            'already_used' => __('That recovery code has already been used.'),
            'expired' => __('That code has expired. Request a new one.'),
            'attempts_exhausted' => __('Too many incorrect attempts. Request a new code.'),
            'ceremony_expired' => __('That took too long. Try again.'),
            'user_verification_required' => __('Your device needs to verify you — use your fingerprint, face, or PIN.'),
            'counter_regression' => __('This security key reported an unexpected state and was refused. Contact your administrator.'),
            default => __('That code is not correct.'),
        };
    }
}
