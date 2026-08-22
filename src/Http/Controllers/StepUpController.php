<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Http\Controllers;

use Gabrielesbaiz\NovaTwoFactor\Enums\ChallengePurpose;
use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorMethod;
use Gabrielesbaiz\NovaTwoFactor\StepUp\StepUpManager;
use Gabrielesbaiz\NovaTwoFactor\TwoFactorManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class StepUpController extends Controller
{
    public function __construct(
        private readonly TwoFactorManager $twoFactor,
        private readonly StepUpManager $stepUp,
    ) {}

    public function show(Request $request): View
    {
        $user = $this->novaUserOrFail();

        $validated = $request->validate([
            'scope' => ['required', 'string', 'max:120'],
            'intended' => ['nullable', 'string', 'max:2048'],
        ]);

        return view('nova-two-factor::step-up', [
            'scope' => $validated['scope'],
            'intended' => $this->safeIntended($validated['intended'] ?? null),
            'methods' => $user->confirmedTwoFactorMethods(),
            'default' => $user->defaultTwoFactorMethod(),
        ]);
    }

    public function prepare(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'method_id' => ['required', 'integer'],
        ]);

        $user = $this->novaUserOrFail();
        $method = $this->ownedMethod($user, (int) $validated['method_id']);

        // Purpose is StepUp, which is what forces user verification on a
        // passkey regardless of the configured preference.
        $payload = $this->twoFactor
            ->driver($method->type)
            ->beginChallenge($method, $this->twoFactor->context($user, ChallengePurpose::StepUp));

        return response()->json($payload ?? [])
            ->header('Cache-Control', 'no-store, max-age=0');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'scope' => ['required', 'string', 'max:120'],
            'method_id' => ['required', 'integer'],
            'code' => ['nullable', 'string', 'max:64'],
            'credential' => ['nullable'],
            'intended' => ['nullable', 'string', 'max:2048'],
        ]);

        $user = $this->novaUserOrFail();
        $scope = (string) $validated['scope'];

        $this->assertNotRateLimited($user);

        $method = $this->ownedMethod($user, (int) $validated['method_id']);

        $result = $this->twoFactor->verify(
            $method,
            $validated,
            $this->twoFactor->context($user, ChallengePurpose::StepUp, $scope),
        );

        if (! $result->passed) {
            RateLimiter::hit($this->limiterKey($user), (int) Config::get('nova-two-factor.rate_limits.step_up.lockout', 60));

            throw ValidationException::withMessages([
                'code' => [__('That code is not correct.')],
            ])->status(422);
        }

        RateLimiter::clear($this->limiterKey($user));

        $grant = $this->stepUp->grant($request, $user, $scope, $method->type->value);

        return response()->json([
            'redirect' => $this->safeIntended($validated['intended'] ?? null),
            'expires_in' => $grant->secondsRemaining(),
            'scope' => $scope,
        ]);
    }

    protected function ownedMethod(mixed $user, int $methodId): TwoFactorMethod
    {
        /** @var TwoFactorMethod|null $method */
        $method = $user->twoFactorMethods()->confirmed()->whereKey($methodId)->first();

        abort_if($method === null, 404);

        return $method;
    }

    /**
     * Only ever redirect back inside this application.
     *
     * `intended` arrives from a query string, so echoing it into a redirect
     * unchecked is an open-redirect straight out of the admin panel.
     */
    protected function safeIntended(?string $intended): string
    {
        $fallback = $this->novaPath();

        if ($intended === null || $intended === '') {
            return $fallback;
        }

        $host = parse_url($intended, PHP_URL_HOST);

        // A relative path is fine; a protocol-relative one is not, since
        // "//evil.test" is an absolute URL that parse_url reads as a host.
        if ($host === null || $host === false) {
            return str_starts_with($intended, '/') && ! str_starts_with($intended, '//')
                ? $intended
                : $fallback;
        }

        $appHost = parse_url((string) Config::get('app.url'), PHP_URL_HOST);

        return is_string($appHost) && hash_equals($appHost, $host) ? $intended : $fallback;
    }

    protected function limiterKey(mixed $user): string
    {
        return 'nova-two-factor:step-up|'.$user->getMorphClass().'|'.$user->getAuthIdentifier();
    }

    protected function assertNotRateLimited(mixed $user): void
    {
        $max = (int) Config::get('nova-two-factor.rate_limits.step_up.per_user', 5);
        $key = $this->limiterKey($user);

        if (! RateLimiter::tooManyAttempts($key, $max)) {
            return;
        }

        throw ValidationException::withMessages([
            'code' => [__('Too many attempts. Try again in :seconds seconds.', [
                'seconds' => RateLimiter::availableIn($key),
            ])],
        ])->status(429);
    }
}
