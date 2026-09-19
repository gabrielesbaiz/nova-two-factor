<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Auth;

use Laravel\Nova\Auth\Actions\RedirectIfTwoFactorAuthenticatable;

/**
 * Keeps Fortify's own two-factor challenge out of Nova's login pipeline.
 *
 * Nova binds its `RedirectIfTwoFactorAuthenticatable` into the login pipeline
 * whenever the `twoFactorAuthentication` feature is on — and that feature has
 * to stay on, because Nova's user-security card is rendered behind it. The
 * action diverts anyone holding a `users.two_factor_secret` to Fortify's
 * challenge *before* authentication completes, which is before any middleware
 * in this package has run.
 *
 * That matters for two kinds of host application:
 *
 *  - one upgrading from Fortify's or Nova's two-factor, whose rows still carry
 *    a secret this package knows nothing about
 *  - one that runs a *separate* Fortify two-factor implementation on another
 *    guard — a customer-facing front end, say — sharing the same user table
 *
 * In both cases the columns are either stale or load-bearing somewhere else,
 * and neither is a statement about the Nova session. The README's other remedy
 * — clearing `two_factor_secret` — is destructive in the second case, since
 * those rows are the other guard's live enrollments.
 *
 * Superseding the divert is not a weakening: this package challenges *after*
 * authentication, through `RequireTwoFactor` on both Nova middleware groups, so
 * an unverified session still cannot reach a single Nova page or API endpoint.
 * The pre-authentication credential check is left exactly as the parent runs
 * it, so failed logins still fire `Failed`, still count against the login rate
 * limiter, and still throw the same validation exception.
 */
class SupersedeFortifyTwoFactorChallenge extends RedirectIfTwoFactorAuthenticatable
{
    /**
     * {@inheritDoc}
     */
    public function handle($request, $next)
    {
        // Deliberately not `parent::handle()`: that is the method whose only
        // other behaviour is the divert we are superseding.
        $this->validateCredentials($request);

        return $next($request);
    }
}
