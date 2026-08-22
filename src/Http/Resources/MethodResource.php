<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Http\Resources;

use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorMethod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The only shape a method is ever exposed in.
 *
 * An explicit allow-list rather than `$model->toArray()` minus hidden fields:
 * a column added later must not become a response field by default.
 *
 * @mixin TwoFactorMethod
 */
class MethodResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $credential = is_array($this->credential) ? $this->credential : [];

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'label' => $this->type->label(),
            'icon' => $this->type->icon(),
            'name' => $this->name,
            'is_default' => (bool) $this->is_default,
            'phishing_resistant' => $this->type->isPhishingResistant(),
            'destination_hint' => $this->destination_hint,
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),

            // A synced passkey exists on the user's other devices; a
            // device-bound one does not. That is a real distinction users can
            // act on, so it is surfaced rather than hidden.
            'synced' => $this->type->isPhishingResistant()
                ? (bool) ($credential['backupEligible'] ?? false)
                : null,
        ];
    }
}
