<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Http\Controllers;

use Gabrielesbaiz\NovaTwoFactor\Enums\ChallengePurpose;
use Gabrielesbaiz\NovaTwoFactor\Events\LockedOut;
use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorMethod;
use Gabrielesbaiz\NovaTwoFactor\RateLimiting\KnownDevice;
use Gabrielesbaiz\NovaTwoFactor\Support\TwoFactorSession;
use Gabrielesbaiz\NovaTwoFactor\TrustedDevices\TrustedDeviceManager;
use Gabrielesbaiz\NovaTwoFactor\TwoFactorManager;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
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
    public function prepare(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate(['method_id' => ['required', 'integer']]);

        $user = $this->novaUserOrFail();
        $method = $this->ownedMethod($user, (int) $validated['method_id']);

        $payload = $this->twoFactor
            ->driver($method->type)
            ->beginChallenge($method, $this->twoFactor->context($user, ChallengePurpose::Login));

        // A plain form post is how this screen works with JavaScript off, and
        // an emailed code cannot be sent from the GET that renders the page:
        // a link prefetch would spend the user's code before they read it.
        if (! $request->expectsJson()) {
            return back()->with('nova-two-factor.status', $this->sendStatus($payload ?? []));
        }

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

        if (! $request->filled('credential')) {
            $this->assertNotRateLimited($request, $user);
        }

        $context = $this->twoFactor->context($user, ChallengePurpose::Login);

        $usingRecoveryCode = ! empty($validated['recovery_code']);

        if ($usingRecoveryCode) {
            $this->assertRecoveryNotRateLimited($request, $user, (string) $validated['recovery_code']);
        }

        $result = $usingRecoveryCode
            ? $this->twoFactor->consumeRecoveryCode($user, (string) $validated['recovery_code'], $context)
            : $this->twoFactor->verify(
                $this->ownedMethod($user, (int) ($validated['method_id'] ?? 0)),
                $validated,
                $context,
            );

        if (! $result->passed) {
            // A refused signature is not a guess against a budget: counting it
            // spends the code-attempt allowance a user may still need, and
            // locks the strongest factor they own behind a wait that protects
            // nothing.
            if ($usingRecoveryCode) {
                $this->recordRecoveryFailure($request, $user, (string) $validated['recovery_code']);
            } elseif (! $request->filled('credential')) {
                $this->recordFailure($request, $user);
            }

            throw ValidationException::withMessages([
                $usingRecoveryCode ? 'recovery_code' : 'code' => [$this->messageFor($result->failure)],
            ])->status(422);
        }

        $this->clearRateLimit($request, $user);
        $this->clearRecoveryRateLimit($request, $user);

        // Regenerates the session id, which is the point at which fixation
        // across the 2FA boundary has to be broken.
        (new TwoFactorSession($request->session()))->markPassed($result->method, $user);

        $response = response()->json([
            'redirect' => $request->session()->pull('url.intended', $this->novaPath()),
            'recovery_codes_remaining' => $this->twoFactor->recoveryCodes()->unusedCount($user),
            'used_recovery_code' => $usingRecoveryCode,
        ]);

        // This browser has now cleared a challenge for this account, so the next
        // one it makes spends its own rate-limit budget rather than the
        // account's shared one. Issued on every success, including a recovery
        // code: the point is to know the browser, not to trust it.
        $knownDevice = app(KnownDevice::class)->issue($user, $request);

        if ($knownDevice !== null) {
            $response->withCookie($knownDevice);
        }

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

    /**
     * What to tell someone who cannot see the JavaScript status line.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function sendStatus(array $payload): string
    {
        $destination = $payload['destination_hint'] ?? null;

        if (! is_string($destination) || $destination === '') {
            return __('Check your inbox for the code.');
        }

        return ($payload['sent'] ?? true) === false
            ? __('A code was already sent to :destination.', ['destination' => $destination])
            : __('We sent a code to :destination.', ['destination' => $destination]);
    }

    protected function ownedMethod(mixed $user, int $methodId): TwoFactorMethod
    {
        /** @var TwoFactorMethod|null $method */
        $method = $user->twoFactorMethods()->confirmed()->whereKey($methodId)->first();

        abort_if($method === null, 404);

        return $method;
    }

    /**
     * Whose budget this attempt spends.
     *
     * Keyed on the account, and then on the browser: one that has cleared a
     * challenge for this account before has a bucket of its own, everything
     * else shares `unknown`. Without the split, anybody holding a leaked
     * password can lock the owner out of their own machine by failing the
     * challenge for them — the limiter working exactly as designed, aimed.
     */
    protected function limiterKey(Request $request, mixed $user): string
    {
        $key = 'nova-two-factor:challenge|'.$user->getMorphClass().'|'.$user->getAuthIdentifier();

        $device = app(KnownDevice::class)->idFor($request, $user);

        return $key.'|'.($device === null ? 'unknown' : 'device:'.sha1($device));
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

    /**
     * The recovery path gets its own budget, on purpose.
     *
     * It is reached by someone who has already lost their usual factor, and
     * sharing the code budget means the attempt that matters most is the one
     * most likely to be already spent. A recovery code carries ~119 bits, so
     * the limit here is not what stops it being guessed — the entropy is. What
     * the limit stops is volume, and it must not stop a person reading twenty
     * characters off a card.
     *
     * Hence two budgets: a generous one for anything shaped like a code, and a
     * tight one for input that is not. A script pushing arbitrary bytes runs
     * into the second almost immediately; a human fat-fingering a transcription
     * has room to try again.
     *
     * @return array{0: string, 1: string}
     */
    protected function recoveryKeys(Request $request, mixed $user): array
    {
        $base = 'nova-two-factor:recovery|'.$user->getMorphClass().'|'.$user->getAuthIdentifier();

        $device = app(KnownDevice::class)->idFor($request, $user);
        $base .= '|'.($device === null ? 'unknown' : 'device:'.sha1($device));

        return [$base, $base.'|malformed'];
    }

    /**
     * @return array{well_formed: int, malformed: int, lockout: int}
     */
    protected function recoveryLimits(): array
    {
        $limits = Config::get('nova-two-factor.rate_limits.recovery', []);

        return [
            'well_formed' => max(1, (int) ($limits['per_user'] ?? 10)),
            'malformed' => max(1, (int) ($limits['malformed_per_user'] ?? 3)),
            // Flat, and deliberately not escalating like the code path. The
            // person most likely to mistype a recovery code is the one who just
            // lost their phone, and doubling their wait each time turns the
            // break-glass path into a locked door — which ends in an
            // administrator resetting the account over a phone call, a weaker
            // check than the code would have been.
            'lockout' => max(60, (int) ($limits['lockout'] ?? 3600)),
        ];
    }

    protected function assertRecoveryNotRateLimited(Request $request, mixed $user, string $submitted): void
    {
        [$key, $malformedKey] = $this->recoveryKeys($request, $user);
        $limits = $this->recoveryLimits();

        $tooMany = RateLimiter::tooManyAttempts($key, $limits['well_formed'])
            || RateLimiter::tooManyAttempts($malformedKey, $limits['malformed']);

        if (! $tooMany) {
            return;
        }

        $seconds = max(
            RateLimiter::availableIn($key),
            RateLimiter::availableIn($malformedKey),
        );

        event(new Lockout($request));
        event(new LockedOut($user, null, ['retry_after' => $seconds, 'path' => 'recovery']));

        throw ValidationException::withMessages([
            'recovery_code' => [__('Too many attempts. Try again in :seconds seconds.', ['seconds' => $seconds])],
        ])->status(429);
    }

    protected function recordRecoveryFailure(Request $request, mixed $user, string $submitted): void
    {
        [$key, $malformedKey] = $this->recoveryKeys($request, $user);
        $limits = $this->recoveryLimits();

        RateLimiter::hit($key, $limits['lockout']);

        if (! $this->twoFactor->recoveryCodes()->looksWellFormed($submitted)) {
            RateLimiter::hit($malformedKey, $limits['lockout']);
        }
    }

    protected function clearRecoveryRateLimit(Request $request, mixed $user): void
    {
        foreach ($this->recoveryKeys($request, $user) as $key) {
            RateLimiter::clear($key);
        }
    }

    protected function messageFor(?string $failure): string
    {
        return match ($failure) {
            'replayed' => __('That code has already been used. Wait for a new one.'),
            'already_used' => __('That recovery code has already been used.'),
            'superseded' => __('That code is no longer valid. Ask for a new one.'),
            'expired' => __('That code has expired. Request a new one.'),
            'attempts_exhausted' => __('Too many incorrect attempts. Request a new code.'),
            'ceremony_expired' => __('That took too long. Try again.'),
            'user_verification_required' => __('Your device needs to verify you — use your fingerprint, face, or PIN.'),
            'counter_regression' => __('This security key reported an unexpected state and was refused. Contact your administrator.'),
            default => __('That code is not correct.'),
        };
    }
}
