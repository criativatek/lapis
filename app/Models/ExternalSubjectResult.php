<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\ExternalSubjectResultFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Uma classificação obtida fora deste sistema — ver o docblock da migração
 * para o que esta tabela deliberadamente NÃO é (não entra em
 * `student_overall_results`, `student_domain_results` nem
 * `calculation_snapshots`; não tem critérios, instrumentos, domínios nem
 * evidência).
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $enrollment_id
 * @property int|null $period_id
 * @property string $origin
 * @property int|null $scale_level_id
 * @property string|null $level_code
 * @property string|null $numeric_value
 * @property Carbon $recorded_on
 * @property string|null $note
 */
#[Fillable(['enrollment_id', 'period_id', 'origin', 'scale_level_id', 'level_code', 'numeric_value', 'recorded_on', 'note'])]
class ExternalSubjectResult extends Model
{
    /** @use HasFactory<ExternalSubjectResultFactory> */
    use BelongsToOrganization, HasFactory, HasUlids;

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    protected function casts(): array
    {
        return [
            'numeric_value' => 'decimal:3',
            'recorded_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Enrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /**
     * @return BelongsTo<ProfileVersionPeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(ProfileVersionPeriod::class);
    }

    /**
     * @return BelongsTo<ScaleLevel, $this>
     */
    public function scaleLevel(): BelongsTo
    {
        return $this->belongsTo(ScaleLevel::class);
    }
}
