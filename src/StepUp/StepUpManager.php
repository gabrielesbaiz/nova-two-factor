<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\StepUp;

use Gabrielesbaiz\NovaTwoFactor\Events\StepUpDenied;
use Gabrielesbaiz\NovaTwoFactor\Events\StepUpGranted;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use RuntimeException;

/**
 * Issues, stores and checks step-up grants.
 *
 * Grants are keyed by scope, each with its own expiry, so proving yourself for
 * one sensitive route never quietly unlocks another.
 */
class StepUpManager
{
    private const SESSION_KEY = 'nova_two_factor.step_up';

    public function grant(Request $request, Authenticatable $user, string $scope, string $factor): StepUpGrant
    {
        // A grant has nowhere to live without a session, and silently returning
        // one that is never stored would read as success while leaving the route
        // unguarded on the next request.
        if (! $request->hasSession()) {
            throw new RuntimeException('A step-up grant requires a session.');
        }

        $grant = StepUpGrant::issue($request, $user, $scope, $factor);

        $grants = $this->stored($request);
        $grants[$scope] = $grant->toArray();

        $request->session()->put(self::SESSION_KEY, $grants);

        event(new StepUpGranted($user, null, ['scope' => $scope, 'factor' => $factor]));

        return $grant;
    }

    public function has(Request $request, Authenticatable $user, string $scope): bool
    {
        $grant = $this->find($request, $user, $scope);

        return $grant instanceof StepUpGrant;
    }

    public function find(Request $request, Authenticatable $user, string $scope): ?StepUpGrant
    {
        $stored = $this->stored($request)[$scope] ?? null;

        if (! is_array($stored)) {
            return null;
        }

        $grant = StepUpGrant::fromArray($stored);

        if (! $grant instanceof StepUpGrant || ! $grant->isValidFor($request, $user, $scope)) {
            // Drop anything invalid or stale rather than leaving it to be
            // re-evaluated on every subsequent request.
            $this->forget($request, $scope);

            return null;
        }

        return $grant;
    }

    public function deny(Authenticatable $user, string $scope): void
    {
        event(new StepUpDenied($user, null, ['scope' => $scope]));
    }

    public function forget(Request $request, string $scope): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $grants = $this->stored($request);
        unset($grants[$scope]);

        $request->session()->put(self::SESSION_KEY, $grants);
    }

    public function flush(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->forget(self::SESSION_KEY);
        }
    }

    /**
     * Which scope, if any, guards this request.
     *
     * Patterns are matched with wildcards and are method-aware, in the form
     * `"DELETE nova-api/users/*"`. 1.x compared `$request->path()` against a
     * flat array with `in_array`, so no pattern ever matched anything.
     */
    public function scopeFor(Request $request): ?string
    {
        /** @var array<string, mixed> $protected */
        $protected = Config::get('nova-two-factor.step_up.protect', []);

        foreach ($protected as $scope => $patterns) {
            foreach ((array) $patterns as $pattern) {
                if ($this->matches($request, (string) $pattern)) {
                    return (string) $scope;
                }
            }
        }

        return null;
    }

    protected function matches(Request $request, string $pattern): bool
    {
        $pattern = trim($pattern);

        if ($pattern === '') {
            return false;
        }

        $parts = preg_split('/\s+/', $pattern, 2);

        if ($parts === false) {
            return false;
        }

        // A bare pattern with no verb applies to every method.
        if (count($parts) === 1) {
            return $request->is(ltrim($parts[0], '/'));
        }

        [$method, $path] = $parts;

        $methodMatches = $method === '*'
            || strtoupper($method) === strtoupper($request->method());

        return $methodMatches && $request->is(ltrim($path, '/'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function stored(Request $request): array
    {
        if (! $request->hasSession()) {
            return [];
        }

        $stored = $request->session()->get(self::SESSION_KEY, []);

        return is_array($stored) ? $stored : [];
    }
}
