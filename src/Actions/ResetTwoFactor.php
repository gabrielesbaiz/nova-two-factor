<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Actions;

use Gabrielesbaiz\NovaTwoFactor\Events\TwoFactorReset;
use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorAudit;
use Gabrielesbaiz\NovaTwoFactor\RateLimiting\RegistersRateLimiters;
use Gabrielesbaiz\NovaTwoFactor\Recovery\RecoveryCodeManager;
use Gabrielesbaiz\NovaTwoFactor\Support\Enforcement;
use Gabrielesbaiz\NovaTwoFactor\Support\SessionInvalidation;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Wipes every factor, recovery code and trusted device for a user.
 *
 * The break-glass path for an administrator, and for the CLI. Always attributed
 * and always audited: an unexplained reset of somebody else's second factor is
 * indistinguishable from an attack.
 */
class ResetTwoFactor
{
    use RegistersRateLimiters;

    public function __construct(
        private readonly RecoveryCodeManager $recoveryCodes,
        private readonly SessionInvalidation $sessions,
        private readonly Enforcement $enforcement,
    ) {}

    /**
     * @param  array<int, string>  $extraAddresses  Buckets to clear beyond the ones the audit log knows about.
     */
    public function __invoke(
        Authenticatable $user,
        string $reason,
        ?Authenticatable $performedBy = null,
        array $extraAddresses = [],
    ): void {
        DB::transaction(function () use ($user): void {
            // Methods first: challenges cascade from them.
            $user->twoFactorMethods()->delete();
            $user->twoFactorTrustedDevices()->delete();
            $this->recoveryCodes->clear($user);
        });

        // A reset that leaves the user locked out has not reset anything they
        // can feel: the administrator is told it worked, and the user still
        // meets "try again in 4:39" at the screen they were sent to. The
        // buckets are part of the state being cleared.
        $this->clearRateLimits($user, $extraAddresses);

        // The sessions this user already holds were verified by a factor that
        // no longer exists. Leaving them open is the hole the reset closes.
        $this->sessions->invalidateExisting($user);

        // And start prompting again: a reset that leaves last week's "don't
        // remind me" in place hands back an account with nothing enrolled and
        // nothing asking.
        $this->enforcement->clearSnooze($user);

        event(new TwoFactorReset($user, null, array_filter([
            'reason' => $reason,
            'performed_by' => $performedBy?->getAuthIdentifier(),
            'performed_by_type' => $performedBy?->getMorphClass(),
        ], static fn (mixed $value): bool => $value !== null)));
    }

    /**
     * Forget every throttle bucket keyed to this user.
     *
     * Two shapes, because two things count attempts: Laravel's `throttle`
     * middleware, which hashes `md5($limiterName.$limit->key)`, and this
     * package's own per-user counter at the challenge, which keys itself.
     */
    /**
     * @param  array<int, string>  $extraAddresses
     */
    protected function clearRateLimits(Authenticatable $user, array $extraAddresses = []): void
    {
        $subject = $user->getAuthIdentifier().'@'.$user->getMorphClass();
        $addresses = array_unique([...$this->recentAddresses($user), ...array_filter($extraAddresses)]);

        foreach (self::limiterConfigKeys() as $limiter => $configKey) {
            RateLimiter::clear(md5($limiter.$configKey.'|subject|'.$subject));
            RateLimiter::clear($limiter.'|'.$user->getMorphClass().'|'.$user->getAuthIdentifier());

            // Every limiter is keyed on the IP as well, and that half is what
            // survived an otherwise complete reset: a user who spent the
            // afternoon locked out of their own office still met the wall from
            // the same address. The audit trail is the only record of which
            // addresses this user actually used, so it is what gets cleared —
            // not every bucket in the cache.
            foreach ($addresses as $ip) {
                RateLimiter::clear(md5($limiter.$configKey.'|ip|'.$ip));
            }
        }
    }

    /**
     * Addresses this user has been seen at recently.
     *
     * Bounded deliberately: clearing an IP bucket lifts the limit for everyone
     * behind that address, so it is done only for the handful this user has
     * actually just been failing from, not for their whole history.
     *
     * @return array<int, string>
     */
    protected function recentAddresses(Authenticatable $user): array
    {
        return TwoFactorAudit::query()
            ->where('authenticatable_type', $user->getMorphClass())
            ->where('authenticatable_id', $user->getAuthIdentifier())
            ->whereNotNull('ip')
            ->where('created_at', '>=', now()->subDay())
            ->orderByDesc('id')
            ->limit(200)
            ->pluck('ip')
            ->unique()
            ->take(10)
            ->values()
            ->all();
    }
}
