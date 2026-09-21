<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Alerts;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Several accounts locked out at once — the shape of an attack, not of a
 * forgetful afternoon.
 *
 * Deliberately outside the `Events` namespace, where every class is an audited
 * event: the individual lockouts are already in the log, and a second row per
 * burst would only make it noisier. This exists
 * to be *listened to* — pager, Slack, an email to the security inbox — because
 * a denial of service that nobody is told about is one that runs until somebody
 * happens to open a dashboard.
 *
 *     Event::listen(LockoutBurstDetected::class, function ($event) {
 *         Notification::route('slack', $url)->notify(new PanelUnderAttack($event));
 *     });
 */
class LockoutBurstDetected
{
    use Dispatchable;

    /**
     * @param  int  $accounts  Distinct accounts locked inside the window.
     * @param  int  $windowMinutes  The window those lockouts fell in.
     * @param  int  $threshold  The count that tripped this.
     */
    public function __construct(
        public readonly int $accounts,
        public readonly int $windowMinutes,
        public readonly int $threshold,
    ) {}
}
