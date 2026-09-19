<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Http\Middleware;

use Closure;
use Gabrielesbaiz\NovaTwoFactor\Support\Enforcement;
use Gabrielesbaiz\NovaTwoFactor\Support\Routing;
use Gabrielesbaiz\NovaTwoFactor\Support\TwoFactorSession;
use Gabrielesbaiz\NovaTwoFactor\TrustedDevices\TrustedDeviceManager;
use Gabrielesbaiz\NovaTwoFactor\TwoFactorManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks an authenticated-but-unverified session from reaching Nova.
 *
 * Every one of 1.x's four fail-open paths is closed here:
 *
 *  - it returned `response(view(...))` in place of the requested page, so the
 *    request still ran and any endpoint that did not render HTML stayed usable;
 *    this halts with a redirect instead
 *  - it let `auth()->guest()` straight through
 *  - it ignored whether an enrollment was ever confirmed
 *  - it let anyone whose `google2fa_enable` flag was off skip the check
 */
class RequireTwoFactor
{
    public function __construct(
        private readonly TwoFactorManager $twoFactor,
        private readonly Enforcement $enforcement,
        private readonly TrustedDeviceManager $trustedDevices,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! Config::get('nova-two-factor.enabled', true)) {
            return $next($request);
        }

        $user = $request->user(Config::get('nova.guard') ?: null);

        // A guest has nothing to verify; authentication middleware is what
        // handles them. 1.x used this as a bypass instead.
        if ($user === null) {
            return $next($request);
        }

        if ($this->isExcepted($request)) {
            return $next($request);
        }

        // Someone with no confirmed factor cannot be challenged — enrollment
        // enforcement is a separate concern, handled by its own middleware.
        if (! $this->twoFactor->hasConfirmedMethods($user)) {
            return $next($request);
        }

        $session = new TwoFactorSession($request->session());

        if ($session->hasPassed()) {
            return $next($request);
        }

        if ($this->trustedDevices->isTrusted($user, $request)) {
            $session->markPassed();

            return $next($request);
        }

        return $this->challenge($request);
    }

    /**
     * Halt, rather than substituting a body.
     */
    protected function challenge(Request $request): Response
    {
        $target = $this->route('challenge');

        if ($request->expectsJson()) {
            return response()->json([
                'message' => __('Two-factor authentication is required.'),
                'two_factor_required' => true,
                'redirect' => $target,
            ], 423);
        }

        $request->session()->put('url.intended', $request->fullUrl());

        return redirect()->to($target);
    }

    protected function isExcepted(Request $request): bool
    {
        return $request->is(...$this->enforcement->exceptPatterns());
    }

    protected function route(string $path): string
    {
        return Routing::path($path);
    }
}
