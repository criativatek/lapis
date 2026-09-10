<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property SupportMeasureLevel $support_measure_level
 * @property SupportMeasureCode $support_measure_code
 * @property LegalMappingSource|null $legal_mapping_source
 */
#[Fillable(['intervention_id', 'support_measure_level', 'support_measure_code', 'legal_mapping_source'])]
class InterventionSupportMeasure extends Model
{
    use HasUlids;

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    protected function casts(): array
    {
        return [
            'support_measure_level' => SupportMeasureLevel::class,
            'support_measure_code' => SupportMeasureCode::class,
            'legal_mapping_source' => LegalMappingSource::class,
        ];
    }

    /** @return BelongsTo<Intervention, $this> */
    public function intervention(): BelongsTo
    {
        return $this->belongsTo(Intervention::class);
    }
}
