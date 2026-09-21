<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Http\Middleware;

use Closure;
use Gabrielesbaiz\NovaTwoFactor\Settings\Pause;
use Gabrielesbaiz\NovaTwoFactor\Support\Enforcement;
use Gabrielesbaiz\NovaTwoFactor\Support\Routing;
use Gabrielesbaiz\NovaTwoFactor\Support\SessionInvalidation;
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
        private readonly SessionInvalidation $sessions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! Config::get('nova-two-factor.enabled', true)) {
            return $next($request);
        }

        // Paused: nobody is challenged. Checked before the user is resolved,
        // because the point of a pause is that it applies to everybody at once
        // — including the administrator who paused it, who is usually the one
        // locked out by whatever they are about to fix.
        if (app(Pause::class)->active()) {
            return $next($request);
        }

        $user = $request->user(Config::get('nova.guard') ?: null);

        // A guest has nothing to verify; authentication middleware is what
        // handles them. 1.x used this as a bypass instead.
        if ($user === null) {
            return $next($request);
        }

        // Stamped before it is judged: an unstamped session is one whose first
        // request this is, which is a session that began *after* any marker —
        // exactly the freshly signed-in user the reset is meant to let back in.
        //
        // Checked before the exception list, because a revoked session must not
        // keep reaching even the screens a non-compliant user is allowed.
        if ($request->hasSession()) {
            $fresh = $this->sessions->stamp($request->session());

            if (! $fresh && $this->sessions->isStale($user, $request->session())) {
                return $this->rejectStaleSession($request);
            }
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

        if ($session->hasPassed($user)) {
            return $next($request);
        }

        if ($this->trustedDevices->isTrusted($user, $request)) {
            $session->markPassed(user: $user);

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

    /**
     * End a session that predates a reset.
     *
     * Logged out rather than merely challenged: the factor that verified this
     * session has been deleted, so there is nothing left for it to be verified
     * by, and leaving it authenticated is the hole the reset was closing.
     */
    protected function rejectStaleSession(Request $request): Response
    {
        $guard = Config::get('nova.guard') ?: null;

        auth()->guard($guard)->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $message = __('Your two-factor settings were reset. Sign in again.');

        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'two_factor_reset' => true], 401);
        }

        return redirect()->to($this->novaLoginPath())->with('nova-two-factor.notice', $message);
    }

    protected function novaLoginPath(): string
    {
        $prefix = trim((string) Config::get('nova.path', '/nova'), '/');

        return '/'.trim($prefix.'/login', '/');
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
