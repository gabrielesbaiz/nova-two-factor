<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Http\Middleware;

use Closure;
use Gabrielesbaiz\NovaTwoFactor\Settings\Pause;
use Gabrielesbaiz\NovaTwoFactor\Support\Routing;
use Gabrielesbaiz\NovaTwoFactor\Support\TwoFactorSession;
use Gabrielesbaiz\NovaTwoFactor\TrustedDevices\TrustedDeviceManager;
use Gabrielesbaiz\NovaTwoFactor\TwoFactorManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the routes that *change* a user's second factors.
 *
 * These endpoints live under the two-factor prefix, which
 * {@see \Gabrielesbaiz\NovaTwoFactor\Support\Enforcement::exceptPatterns()}
 * exempts from the challenge — it has to, or the challenge screen could not
 * load its own assets. That exemption made the whole prefix reachable with a
 * password alone, and two routes behind it handed out a second factor:
 *
 *  - enrolling a method and confirming it, which also marked the session
 *    verified, so a stolen password bought a working factor in two requests
 *  - regenerating recovery codes, which returns the plaintext codes, each of
 *    which satisfies a challenge
 *
 * A password confirmation does not close either one: the attacker in this
 * threat model has the password. What is missing is proof of the *second*
 * factor, so that is what is demanded here.
 *
 * The one account that cannot provide it is the one with nothing enrolled yet,
 * which is exactly who the enrollment screens exist for — so an account with no
 * confirmed method passes straight through. That is the bootstrap case, and it
 * is the only case.
 */
class RequireVerifiedSession
{
    public function __construct(
        private readonly TwoFactorManager $twoFactor,
        private readonly TrustedDeviceManager $trustedDevices,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! Config::get('nova-two-factor.enabled', true)) {
            return $next($request);
        }

        // A pause stands enforcement down for everybody; honouring it here
        // changes nothing an attacker could not already do, because the same
        // pause is letting them into Nova without a challenge anyway.
        if (app(Pause::class)->active()) {
            return $next($request);
        }

        $user = $request->user(Config::get('nova.guard') ?: null);

        // Authentication is somebody else's middleware.
        if ($user === null) {
            return $next($request);
        }

        // Nothing enrolled: this is a first factor being set up, and there is
        // no second factor in existence to prove.
        if (! $this->twoFactor->hasConfirmedMethods($user)) {
            return $next($request);
        }

        if (! $request->hasSession()) {
            return $this->challenge($request);
        }

        $session = new TwoFactorSession($request->session());

        if ($session->hasPassed($user)) {
            return $next($request);
        }

        // A trusted device is a challenge already cleared, and these pages are
        // exempt from the middleware that would normally notice — so the same
        // check has to happen here, or "remember this device" would lock people
        // out of their own security settings.
        if ($this->trustedDevices->isTrusted($user, $request)) {
            $session->markPassed(user: $user);

            return $next($request);
        }

        return $this->challenge($request);
    }

    /**
     * Send them to the challenge rather than refusing outright: they hold the
     * password and may well be the account's owner, one code away from being
     * allowed to do this.
     */
    protected function challenge(Request $request): Response
    {
        $target = Routing::path('challenge');

        if ($request->expectsJson()) {
            return response()->json([
                'message' => __('Confirm your second factor before changing it.'),
                'two_factor_required' => true,
                'redirect' => $target,
            ], 423);
        }

        if ($request->hasSession()) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return redirect()->to($target);
    }
}
