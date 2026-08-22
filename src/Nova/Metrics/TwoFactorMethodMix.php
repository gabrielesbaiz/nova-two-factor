<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Nova\Metrics;

use DateTimeInterface;
use Gabrielesbaiz\NovaTwoFactor\Enums\MethodType;
use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorMethod;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Metrics\Partition;
use Laravel\Nova\Metrics\PartitionResult;

/**
 * Which factor types are actually in use.
 *
 * The number worth watching is passkey share: it is the only phishing-resistant
 * option, so this is the metric that says whether adoption is improving or just
 * accumulating email codes.
 */
class TwoFactorMethodMix extends Partition
{
    public $name;

    public function __construct()
    {
        parent::__construct();

        $this->name = __('Methods in use');
    }

    public function calculate(NovaRequest $request): PartitionResult
    {
        return $this->count($request, TwoFactorMethod::query()->confirmed(), 'type')
            ->label(fn (string $value): string => MethodType::tryFrom($value)?->label() ?? $value)
            ->colors([
                MethodType::WebAuthn->value => '#22c55e',
                MethodType::Totp->value => '#0ea5e9',
                MethodType::Email->value => '#f59e0b',
            ]);
    }

    public function cacheFor(): DateTimeInterface
    {
        return now()->addMinutes(5);
    }

    public function uriKey(): string
    {
        return 'two-factor-method-mix';
    }
}
