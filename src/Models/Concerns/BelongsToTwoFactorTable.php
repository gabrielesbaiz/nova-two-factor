<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Models\Concerns;

/**
 * Table and connection names are configurable, so they cannot be static
 * properties resolved at class-load time.
 */
trait BelongsToTwoFactorTable
{
    public function getTable(): string
    {
        $key = static::$tableConfigKey;

        return config("nova-two-factor.database.tables.{$key}") ?? "two_factor_{$key}";
    }

    public function getConnectionName(): ?string
    {
        return config('nova-two-factor.database.connection') ?? parent::getConnectionName();
    }
}
