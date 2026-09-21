<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Enums;

enum EnforcementMode: string
{
    /** Nothing is required and nothing is shown. */
    case Optional = 'optional';

    /** A dismissible prompt. Never blocks a request. */
    case Encouraged = 'encouraged';

    /** Nova is unreachable until enrolled, once the grace window has passed. */
    case Required = 'required';

    /**
     * How the mode reads to an administrator looking at the compliance page.
     *
     * Phrased as what it does, not what it is called: "overdue" under
     * `encouraged` means a deadline has passed with no consequence, and an
     * admin reading a red number needs to know which of those they are seeing.
     */
    public function label(): string
    {
        // Namespaced, not a plain key: an application's own catalogue wins over
        // a package's for plain keys, and a host that translates "Required" for
        // its own form fields would quietly rename the enforcement mode.
        return __('nova-two-factor::enforcement.modes.'.$this->value);
    }

    public function summary(): string
    {
        return __('nova-two-factor::enforcement.summaries.'.$this->value);
    }

    public function blocks(): bool
    {
        return $this === self::Required;
    }

    public function prompts(): bool
    {
        return $this !== self::Optional;
    }
}
