<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Models;

use Gabrielesbaiz\NovaTwoFactor\Enums\AuditEvent;
use Gabrielesbaiz\NovaTwoFactor\Models\Concerns\BelongsToTwoFactorTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * @property int $id
 * @property AuditEvent $event
 * @property string|null $method_type
 * @property int|null $method_id
 * @property string|null $ip
 * @property string|null $user_agent
 * @property array<string, mixed>|null $context
 */
class TwoFactorAudit extends Model
{
    use BelongsToTwoFactorTable;

    public const UPDATED_AT = null;

    /**
     * Context keys that must never be written. A secret in an audit row is a
     * secret in every log aggregator, backup and support export downstream, so
     * this fails loudly outside production rather than being filtered quietly.
     */
    public const FORBIDDEN_CONTEXT_PATTERN = '/secret|password|credential|code|token|otp/i';

    protected static string $tableConfigKey = 'audits';

    protected $guarded = ['id'];

    public function authenticatable(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeSuspicious(Builder $query): Builder
    {
        $events = array_map(
            static fn (AuditEvent $event): string => $event->value,
            array_filter(AuditEvent::cases(), static fn (AuditEvent $event): bool => $event->isSuspicious()),
        );

        return $query->whereIn('event', $events);
    }

    protected function casts(): array
    {
        return [
            'event' => AuditEvent::class,
            'context' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $audit): void {
            $audit->assertContextCarriesNoSecrets();
        });
    }

    /**
     * `recovery_code.consumed` is a legitimate event name that trips the
     * pattern, so only the context payload is inspected — never the event.
     */
    private function assertContextCarriesNoSecrets(): void
    {
        $context = $this->context;

        if (! is_array($context) || $context === []) {
            return;
        }

        $offending = array_filter(
            array_keys($context),
            static fn (int|string $key): bool => is_string($key)
                && preg_match(self::FORBIDDEN_CONTEXT_PATTERN, $key) === 1,
        );

        if ($offending === []) {
            return;
        }

        if (app()->environment('production')) {
            $this->context = array_diff_key($context, array_flip($offending));

            return;
        }

        throw new LogicException(sprintf(
            'Refusing to write a two-factor audit row: context key(s) [%s] look like they carry a secret.',
            implode(', ', $offending),
        ));
    }
}
