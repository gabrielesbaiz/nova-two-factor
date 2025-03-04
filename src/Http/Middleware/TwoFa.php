<?php

namespace Gabrielesbaiz\NovaTwoFactor\Http\Middleware;

use Closure;
use Gabrielesbaiz\NovaTwoFactor\NovaTwoFactor;
use Gabrielesbaiz\NovaTwoFactor\Helpers\NovaUser;
use Gabrielesbaiz\NovaTwoFactor\TwoFaAuthenticator;

class TwoFa
{
    use NovaUser;

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request                             $request
     * @param  Closure                                              $next
     * @return mixed
     * @throws \PragmaRX\Google2FA\Exceptions\InsecureCallException
     */
    public function handle($request, Closure $next)
    {
        $except = [
            'nova-vendor/nova-two-factor/authenticate',
            'nova-vendor/nova-two-factor/recover',
            'nova-vendor/nova-two-factor/validatePrompt',
        ];

        $except = array_merge($except, config('nova-two-factor.except_routes'));

        if (! config('nova-two-factor.enabled') || in_array($request->path(), $except)) {
            return $next($request);
        }

        $authenticator = app(TwoFaAuthenticator::class)->boot($request);

        if ($this->novaUser()?->hasNotTwoFactorAuthentication()) {
            return $next($request);
        }

        if ($this->novaUser()?->hasTwoFactorAuthentication() && $this->novaUser()?->hasTwoFactorAuthenticationNotEnable()) {
            return $next($request);
        }

        if (NovaTwoFactor::promptEnabled($request)) {
            return NovaTwoFactor::prompt();
        }

        if (auth()->guest() || $authenticator->isAuthenticated()) {
            return $next($request);
        }

        return response(view('nova-two-factor::sign-in'));
    }
}
