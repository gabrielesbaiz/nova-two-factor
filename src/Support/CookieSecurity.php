<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

/**
 * Whether this package's cookies carry `Secure`.
 *
 * Asking `$request->isSecure()` alone is how a cookie ships naked on a site
 * that is plainly HTTPS. Behind a TLS-terminating proxy — nginx, an ALB,
 * Cloudflare, an ingress — PHP is spoken to over plain HTTP, and Laravel only
 * believes `X-Forwarded-Proto` when `TrustProxies` has been configured. Miss
 * that, and `isSecure()` says false while the user's address bar says
 * otherwise: the cookie then travels on any `http://` request to the host, and
 * encryption does not save it, because possession is the whole credential.
 *
 * So the application's own answer comes first. A host that set
 * `SESSION_SECURE_COOKIE` has already decided this for a cookie strictly more
 * sensitive than any of ours, and ours must never be laxer than the session
 * they protect. The request is the last resort, not the first.
 */
class CookieSecurity
{
    public static function secure(Request $request): bool
    {
        // An explicit package setting wins: someone serving the panel over TLS
        // and something else over plain HTTP needs a way to say so.
        $configured = Config::get('nova-two-factor.cookies.secure');

        if ($configured !== null) {
            return (bool) $configured;
        }

        $session = Config::get('session.secure');

        if ($session !== null) {
            return (bool) $session;
        }

        return $request->isSecure();
    }
}
