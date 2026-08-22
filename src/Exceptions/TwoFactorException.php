<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Exceptions;

use RuntimeException;

/**
 * @phpstan-consistent-constructor
 */
abstract class TwoFactorException extends RuntimeException
{
    /**
     * A stable machine code. Kept separate from the message so the HTTP layer
     * can translate for the user without parsing English.
     */
    public string $reason = 'unknown';

    public static function because(string $reason): static
    {
        $exception = new static(static::messageFor($reason));
        $exception->reason = $reason;

        return $exception;
    }

    protected static function messageFor(string $reason): string
    {
        return str_replace('_', ' ', ucfirst($reason)).'.';
    }
}
