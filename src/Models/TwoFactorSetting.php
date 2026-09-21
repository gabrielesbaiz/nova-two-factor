<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Models;

use Gabrielesbaiz\NovaTwoFactor\Models\Concerns\BelongsToTwoFactorTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One setting an administrator changed from the panel.
 *
 * @property string $key
 * @property mixed $value
 */
class TwoFactorSetting extends Model
{
    use BelongsToTwoFactorTable;

    protected static string $tableConfigKey = 'settings';

    protected $guarded = ['id'];

    /**
     * Who changed it. Kept on the row as well as in the audit log: the audit
     * log is prunable, and "who set this" should outlive the pruning.
     */
    public function updatedBy(): MorphTo
    {
        return $this->morphTo('updated_by');
    }

    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }
}
