<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Http\Middleware;

use Closure;
use Gabrielesbaiz\NovaTwoFactor\StepUp\StepUpManager;
use Gabrielesbaiz\NovaTwoFactor\TwoFactorManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;

/**
 * Demands a fresh second factor in front of a sensitive route.
 *
 * Usable two ways, and both are wired up so neither can be forgotten:
 * declaratively as `nova.2fa.step-up:users.destroy` on a route, and globally
 * from the `step_up.protect` patterns via the Nova middleware groups.
 */
class RequireFreshTwoFactor
{
    public function __construct(
        private readonly StepUpManager $stepUp,
        private readonly TwoFactorManager $twoFactor,
    ) {}

    public function handle(Request $request, Closure $next, ?string $scope = null): Response
    {
        if (! Config::get('nova-two-factor.enabled', true)) {
            return $next($request);
        }

        $scope ??= $this->stepUp->scopeFor($request);

        if ($scope === null) {
            return $next($request);
        }

        $user = $request->user(Config::get('nova.guard') ?: null);

        if ($user === null) {
            return $next($request);
        }

        if ($this->stepUp->has($request, $user, $scope)) {
            return $next($request);
        }

        $this->stepUp->deny($user, $scope);

        return $this->requireStepUp($request, $user, $scope);
    }

    /**
     * Answer with 423, mirroring Laravel's own `RequirePassword`.
     *
     * Matching that shape means one axios interceptor on the client can handle
     * both password confirmation and step-up, instead of every call site having
     * to know which it might get.
     */
    protected function requireStepUp(Request $request, mixed $user, string $scope): Response
    {
        $factors = $this->twoFactor->hasConfirmedMethods($user)
            ? $user->confirmedTwoFactorMethods()
                ->map(static fn ($method): string => $method->type->value)
                ->unique()
                ->values()
                ->all()
            : ['password'];

        $target = $this->stepUpUrl($scope, $request->fullUrl());

        if ($request->expectsJson()) {
            return response()->json([
                'message' => __('Please confirm your identity to continue.'),
                'step_up_required' => true,
                'scope' => $scope,
                'factors' => $factors,
                'redirect' => $target,
            ], 423);
        }

        return redirect()->to($target);
    }

    protected function stepUpUrl(string $scope, string $intended): string
    {
        $prefix = trim((string) Config::get('nova.path', '/nova'), '/');

        return '/'.trim($prefix.'/two-factor/step-up', '/')
            .'?'.http_build_query(['scope' => $scope, 'intended' => $intended]);
    }
}
