<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Listeners;

use Gabrielesbaiz\NovaTwoFactor\Alerts\LockoutBurstDetected;
use Gabrielesbaiz\NovaTwoFactor\Events\LockedOut;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;

/**
 * Notices when lockouts stop looking like forgetfulness.
 *
 * One person fumbling their authenticator locks themselves out and unlocks a
 * minute later; that is the control working. Several *different* accounts
 * locking inside a few minutes is somebody working through a credential dump —
 * they cannot get in, but under `required` enforcement they can keep everybody
 * else out, and the lockout that protects an account is the thing doing it.
 *
 * Distinct accounts, not attempts: a script hammering one address should not
 * trip this, because that single account is already the only casualty.
 *
 * The window lives in the cache and expires on its own, so nothing accumulates
 * and a quiet hour resets the count. When the cache is `array` — the default in
 * tests — this degrades to "never fires", which is the right failure: a missed
 * alert, not a false one.
 */
class DetectLockoutBurst
{
    private const KEY = 'nova-two-factor:lockout-burst';

    public function handle(LockedOut $event): void
    {
        $threshold = (int) Config::get('nova-two-factor.alerts.lockout_burst.accounts', 0);

        // Off by default: an alert nobody wired up is a listener firing into
        // the void, and the threshold is the sort of number only the operator
        // of a given panel can pick.
        if ($threshold < 2) {
            return;
        }

        $user = $event->auditUser();

        if ($user === null) {
            return;
        }

        $window = max(1, (int) Config::get('nova-two-factor.alerts.lockout_burst.window_minutes', 15));
        $key = self::KEY.':'.md5((string) Config::get('app.key'));
        $stamp = $user->getMorphClass().'|'.$user->getAuthIdentifier();

        /** @var array<string, int> $seen */
        $seen = (array) Cache::get($key, []);
        $now = now()->getTimestamp();
        $cutoff = $now - $window * 60;

        // Rebuilt on every lockout rather than trimmed on a schedule: the list
        // is at most a handful of entries, and a stale one must never be able
        // to push a later count over the line.
        $seen = array_filter($seen, static fn (int $at): bool => $at >= $cutoff);
        $alreadyCounted = array_key_exists($stamp, $seen);
        $seen[$stamp] = $now;

        Cache::put($key, $seen, now()->addMinutes($window + 1));

        // Only on the lockout that crosses the line, and only the first time
        // that account joins the window — otherwise one account relocking every
        // minute would page somebody every minute.
        if (! $alreadyCounted && count($seen) === $threshold) {
            Event::dispatch(new LockoutBurstDetected(
                accounts: count($seen),
                windowMinutes: $window,
                threshold: $threshold,
            ));
        }
    }
}
