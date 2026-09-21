<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Http\Controllers;

use Gabrielesbaiz\NovaTwoFactor\Enums\EnforcementMode;
use Gabrielesbaiz\NovaTwoFactor\Events\ReminderSnoozed;
use Gabrielesbaiz\NovaTwoFactor\Support\Enforcement;
use Gabrielesbaiz\NovaTwoFactor\TwoFactorManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\View\View;

class EnforcementController extends Controller
{
    public function __construct(
        private readonly TwoFactorManager $twoFactor,
        private readonly Enforcement $enforcement,
    ) {}

    public function show(Request $request): View|RedirectResponse
    {
        $user = $this->novaUserOrFail();

        // Nothing to enforce: never strand a compliant user on this page.
        if ($user->hasTwoFactorEnabled() || ! $this->enforcement->appliesTo($user)) {
            return redirect()->to($this->novaPath('user-security'));
        }

        // `encouraged` reaches this page as a reminder rather than a wall.
        $reminder = ! $this->enforcement->mode()->blocks();

        $graceEndsAt = $this->enforcement->graceEndsAt($user);
        $withinGrace = $graceEndsAt !== null && $graceEndsAt->isFuture();

        return view('nova-two-factor::enforcement', [
            // Ranked strongest first, each with an honest one-line tradeoff, so
            // the choice is informed rather than alphabetical.
            'drivers' => $this->twoFactor->availableDrivers(),
            'graceEndsAt' => $graceEndsAt,
            'withinGrace' => $withinGrace,
            // The "later" escape hatch disappears when grace expires — but the
            // page always keeps a way to log out and an administrator to
            // contact. A dead end with no explanation is what makes enforcement
            // screens hated.
            'canDefer' => $withinGrace || $reminder,
            'reminder' => $reminder,
            'remindDays' => $this->enforcement->remindEveryDays(),
            // Whole days, for the sentence above the buttons. The countdown
            // beside it is to the minute; this is the number someone repeats
            // to a colleague.
            'daysLeft' => $this->enforcement->graceDaysLeft($user),
            'novaPath' => $this->novaPath(),
        ]);
    }

    /**
     * "Not now" — for the configured number of days.
     */
    public function remindLater(Request $request): RedirectResponse
    {
        $user = $this->novaUserOrFail();

        $encouraged = $this->enforcement->mode() === EnforcementMode::Encouraged;

        // Also reachable under `required` while the user still has time —
        // otherwise "set up later" is a link back to a page that immediately
        // redirects here again, which is the loop this endpoint exists to
        // prevent. What it buys differs: see below.
        abort_unless($encouraged || $this->enforcement->shouldWarn($user), 403);

        // Ticked buys days; unticked buys this session. Recording nothing at
        // all — which is what "keep asking" used to mean — sent the user back
        // to the page they had just dismissed, on the very next request.
        //
        // Under `required` the days are never on offer: the deadline is real,
        // and a countdown that can be silenced past it is not a countdown.
        if ($encouraged && $request->boolean('snooze')) {
            $this->enforcement->snooze($user);

            // The snooze itself is a cache entry; this row is what makes it
            // countable on the admin page without walking every user.
            Event::dispatch(new ReminderSnoozed($user, context: [
                'days' => $this->enforcement->remindEveryDays(),
            ]));
        } elseif ($request->hasSession()) {
            $this->enforcement->dismissForSession($request->session());
        }

        return redirect()->to($this->novaPath());
    }
}
