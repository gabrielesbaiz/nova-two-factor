<?php

namespace Gabrielesbaiz\NovaTwoFactor\Http\Middleware;

use Closure;
use Gabrielesbaiz\NovaTwoFactor\Helpers\NovaUser;

class TwoFaMandatory
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
            'nova-vendor/nova-two-factor/confirm',
            'nova-vendor/nova-two-factor/recover',
            'nova-vendor/nova-two-factor/register',
            'nova-vendor/nova-two-factor/status',
            'nova-vendor/nova-two-factor/toggle',
            'nova-vendor/nova-notifications',
            'nova/logout',
            'admin/login',
            'admin/logout',
            'admin/nova-two-factor',
        ];

        $except = array_merge($except, config('nova-two-factor.except_routes'));

        if (
            config('nova-two-factor.enabled') &&
            config('nova-two-factor.mandatory')
        ) {
            if ($this->novaUser()?->hasTwoFactorAuthentication() && $this->novaUser()?->hasTwoFactorAuthenticationEnable()) {
                return $next($request);
            }

            if (in_array($request->path(), $except)) {
                return $next($request);
            }

            return redirect('admin/nova-two-factor');
        }

        return $next($request);
    }
}
