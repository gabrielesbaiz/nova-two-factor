<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Http\Middleware;

use Closure;
use Gabrielesbaiz\NovaTwoFactor\Events\EnforcementBlocked;
use Gabrielesbaiz\NovaTwoFactor\Support\Enforcement;
use Gabrielesbaiz\NovaTwoFactor\Support\Routing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces enrollment once a user's grace window has closed.
 *
 * Registered on both `nova.middleware` and `nova.api_middleware`, which is what
 * makes it unavoidable. 1.x only guarded page routes, so the whole policy could
 * be sidestepped by talking to `nova-api/*` directly — and its redirect target
 * was the hardcoded string `admin/nova-two-factor`, which produced an infinite
 * loop on any app whose Nova path was not `admin`.
 */
class RequireTwoFactorEnrollment
{
    public function __construct(private readonly Enforcement $enforcement) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user(Config::get('nova.guard') ?: null);

        if ($user === null || $this->isExcepted($request)) {
            return $next($request);
        }

        if (! $this->enforcement->blocks($user)) {
            return $next($request);
        }

        event(new EnforcementBlocked($user, null, [
            'path' => $request->path(),
            'method' => $request->method(),
        ]));

        $target = $this->enrollmentUrl();

        if ($request->expectsJson()) {
            return response()->json([
                'message' => __('Your organization requires two-factor authentication.'),
                'two_factor_enrollment_required' => true,
                'redirect' => $target,
            ], 403);
        }

        return redirect()->to($target);
    }

    protected function isExcepted(Request $request): bool
    {
        return $request->is(...$this->enforcement->exceptPatterns());
    }

    protected function enrollmentUrl(): string
    {
        return Routing::path('required');
    }
}
