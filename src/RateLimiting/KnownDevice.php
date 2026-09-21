<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\RateLimiting;

use Gabrielesbaiz\NovaTwoFactor\Support\CookieSecurity;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/**
 * A name for the browser this challenge is coming from, used only to decide
 * which rate-limit bucket it spends.
 *
 * The problem it solves: the challenge limiter is keyed on the account, which
 * is right against brute force and wrong against sabotage. Someone holding a
 * leaked password cannot pass the challenge, but they can spend that account's
 * budget over and over, and under `required` enforcement a locked account is a
 * person who cannot work. One credential dump then stops a whole panel.
 *
 * So: a browser that has cleared a challenge for this user before gets a bucket
 * of its own, and everything else shares the account's bucket as it does today.
 * The attacker arrives on a browser that has never cleared anything, exhausts
 * the shared bucket, and the victim's own laptop still has its full budget.
 *
 * What stops the attacker simply minting an identifier: the cookie carries an
 * HMAC over the id, the account and `APP_KEY`, so a value that was not issued
 * here does not verify and falls back to the shared bucket. Rotating a forged
 * id buys nothing — every forgery lands in the same place.
 *
 * This is **not** an authentication token and grants nothing: the worst a
 * stolen one can do is give its thief a private allowance of wrong guesses,
 * still bounded by the same per-device budget. Skipping the challenge is what
 * {@see \Gabrielesbaiz\NovaTwoFactor\TrustedDevices\TrustedDeviceManager} does,
 * and that is deliberately a different cookie with a database row behind it.
 */
class KnownDevice
{
    public function enabled(): bool
    {
        return (bool) Config::get('nova-two-factor.rate_limits.per_device', true);
    }

    public function cookieName(): string
    {
        return (string) Config::get('nova-two-factor.rate_limits.device_cookie', 'nova_two_factor_known_device');
    }

    /**
     * Mark this browser as one that has cleared a challenge for this account.
     *
     * Issued on every success rather than offered as a choice: the user is not
     * being asked to trade security for convenience, and a prompt for something
     * that only affects a rate-limit bucket would be noise.
     */
    public function issue(Authenticatable $user, Request $request): ?SymfonyCookie
    {
        if (! $this->enabled()) {
            return null;
        }

        $id = $this->idFor($request, $user) ?? Str::random(32);

        return Cookie::make(
            name: $this->cookieName(),
            value: $id.'|'.$this->sign($id, $user),
            // A year: a browser that stops arriving simply stops mattering, and
            // a short life would put people back in the shared bucket exactly
            // when an attack made that painful.
            minutes: 525_600,
            secure: CookieSecurity::secure($request),
            httpOnly: true,
            sameSite: 'lax',
        );
    }

    /**
     * The id this browser may spend against, or null when it has none we issued.
     */
    public function idFor(Request $request, Authenticatable $user): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        $cookie = $request->cookie($this->cookieName());

        if (! is_string($cookie) || ! str_contains($cookie, '|')) {
            return null;
        }

        [$id, $signature] = explode('|', $cookie, 2);

        if ($id === '' || ! hash_equals($this->sign($id, $user), $signature)) {
            return null;
        }

        return $id;
    }

    /**
     * Bound to the account as well as the id: a cookie earned on one account is
     * not a private bucket on another, which matters on a shared machine.
     */
    private function sign(string $id, Authenticatable $user): string
    {
        return hash_hmac('sha256', implode('|', [
            $id,
            $user->getMorphClass(),
            (string) $user->getAuthIdentifier(),
        ]), (string) Config::get('app.key'));
    }
}
