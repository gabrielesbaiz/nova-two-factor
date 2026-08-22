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

    public function blocks(): bool
    {
        return $this === self::Required;
    }

    public function prompts(): bool
    {
        return $this !== self::Optional;
    }
}
