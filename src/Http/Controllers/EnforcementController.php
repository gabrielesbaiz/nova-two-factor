<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Http\Controllers;

use Gabrielesbaiz\NovaTwoFactor\Support\Enforcement;
use Gabrielesbaiz\NovaTwoFactor\TwoFactorManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            'canDefer' => $withinGrace,
        ]);
    }
}
