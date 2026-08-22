<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Exceptions;

/**
 * Thrown when removing a method would leave an account that policy requires to
 * hold one with no second factor at all.
 */
class LastFactorException extends TwoFactorException {}
