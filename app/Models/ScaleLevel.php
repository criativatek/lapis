<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A level within a scale. Where numeric→qualitative conversion lives.
 *
 * normalized_value and band columns are nullable: §10.4 forbids inventing a
 * numeric value or a level threshold for a qualitative descriptor. Without them,
 * a result at this level cannot enter arithmetic (question Q1).
 *
 * @property int $id
 * @property int $scale_id
 * @property string $code
 * @property string $label
 * @property string|null $inovar_code
 * @property int $sequence
 * @property string|null $numeric_value
 * @property string|null $normalized_value
 * @property string|null $band_min_normalized
 * @property string|null $band_max_normalized
 * @property bool $is_negative
 */
#[Fillable(['code', 'inovar_code', 'label', 'sequence', 'numeric_value', 'normalized_value', 'band_min_normalized', 'band_max_normalized', 'is_negative'])]
class ScaleLevel extends Model
{
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'numeric_value' => 'decimal:3',
            'normalized_value' => 'decimal:6',
            'band_min_normalized' => 'decimal:6',
            'band_max_normalized' => 'decimal:6',
            'is_negative' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Scale, $this>
     */
    public function scale(): BelongsTo
    {
        return $this->belongsTo(Scale::class);
    }
}
