<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

/**
 * Cuts off sessions that predate an administrative reset.
 *
 * Wiping somebody's second factor while their browser keeps a verified session
 * is half a revocation: the account the reset was meant to protect stays open
 * in whatever tab is already logged in, still counted as having cleared 2FA by
 * a factor that no longer exists.
 *
 * A cache marker rather than a column, because the alternative is a migration
 * on the host's users table — and rather than deleting session rows, because
 * that only works for applications on the `database` session driver.
 */
class SessionInvalidation
{
    private const STAMP = 'nova_two_factor.session_started_at';

    /**
     * Every session for this user that began before now is no longer valid.
     */
    public function invalidateExisting(Authenticatable $user): void
    {
        // Microsecond precision: a login and a reset landing in the same
        // second is not rare on a fast machine, and whole seconds make that a
        // coin toss between killing a fresh session and sparing a stale one.
        Cache::put($this->key($user), microtime(true), $this->ttl());
    }

    /**
     * Whether this session began before the last reset.
     *
     * A session with no stamp is one that started before this feature existed,
     * or before the marker was written; either way it predates the reset and
     * has to go.
     */
    public function isStale(Authenticatable $user, Session $session): bool
    {
        $marker = Cache::get($this->key($user));

        if (! is_numeric($marker)) {
            return false;
        }

        $startedAt = $session->get(self::STAMP);

        return ! is_numeric($startedAt) || (float) $startedAt <= (float) $marker;
    }

    /**
     * Stamp a session the first time it is seen, so a later reset can tell
     * which sessions came before it.
     *
     * @return bool Whether this was the session's first request.
     */
    public function stamp(Session $session): bool
    {
        if ($session->has(self::STAMP)) {
            return false;
        }

        $session->put(self::STAMP, microtime(true));

        return true;
    }

    protected function key(Authenticatable $user): string
    {
        return 'nova-two-factor:sessions-invalid-before|'
            .$user->getMorphClass().'|'.$user->getAuthIdentifier();
    }

    /**
     * Outlives the application's own session lifetime, or the marker expires
     * while the sessions it is meant to kill are still alive.
     */
    protected function ttl(): int
    {
        return max(86400, ((int) Config::get('session.lifetime', 120)) * 60 * 2);
    }
}
