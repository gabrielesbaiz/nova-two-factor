<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Http\Middleware;

use Closure;
use Gabrielesbaiz\NovaTwoFactor\Events\EnforcementBlocked;
use Gabrielesbaiz\NovaTwoFactor\Settings\Pause;
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
        if (app(Pause::class)->active()) {
            return $next($request);
        }

        $user = $request->user(Config::get('nova.guard') ?: null);

        if ($user === null || $this->isExcepted($request)) {
            return $next($request);
        }

        // Not blocked yet — either `encouraged`, or `required` with time left.
        // Both put the page in front of a user who has nothing enrolled: once,
        // on a page they are actually looking at, and only until they wave it
        // away. The second case is the one that used to be missing entirely:
        // under `required` with a grace window, nothing was shown until the day
        // the wall appeared, so the first a user heard of the policy was being
        // locked out by it.
        if (! $this->enforcement->blocks($user)) {
            if ($this->shouldInterrupt($request, $user)) {
                return redirect()->to($this->enrollmentUrl());
            }

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

    /**
     * Whether this particular request is the right moment to remind someone.
     *
     * A redirect is only honest on a page the user asked for: firing it at an
     * XHR would answer a data call with HTML, and firing it at a POST would
     * lose whatever they were submitting.
     */
    protected function shouldInterrupt(Request $request, mixed $user): bool
    {
        if ($request->hasSession() && $this->enforcement->isDismissedForSession($request->session())) {
            return false;
        }

        if (! $request->isMethod('GET') || $request->expectsJson() || $request->ajax()) {
            return false;
        }

        // A countdown under `required` is shown every session until it is acted
        // on — unlike the `encouraged` reminder, which can be put away for
        // days. The deadline is real, and a warning that can be silenced past
        // it is not a warning.
        return $this->enforcement->shouldRemind($user) || $this->enforcement->shouldWarn($user);
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
