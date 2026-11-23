<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O comentário privado do professor sobre um aluno num elemento de avaliação.
 * Texto sobre uma criança: nunca entra em cálculo, nunca viaja na importação de
 * uma grelha para outra turma e só sai no PDF se o professor o escolher.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $instrument_id
 * @property int $enrollment_id
 * @property string $body
 * @property int $lock_version
 * @property int|null $created_by
 * @property int|null $updated_by
 */
#[Fillable(['instrument_id', 'enrollment_id', 'body', 'lock_version', 'created_by', 'updated_by'])]
class InstrumentStudentComment extends Model
{
    use BelongsToOrganization, HasUlids;

    /** O que o professor vê (caracteres) e o que a coluna TEXT guarda (bytes). */
    public const int BODY_MAX_LENGTH = 2000;

    public const int BODY_MAX_BYTES = 65535;

    /**
     * Que limite um corpo ultrapassa, se algum: `'characters'`, `'bytes'` ou null.
     * Um só sítio para o formulário e para o importador de cópias de segurança.
     */
    public static function bodyLimitViolation(string $body): ?string
    {
        if (mb_strlen($body, 'UTF-8') > self::BODY_MAX_LENGTH) {
            return 'characters';
        }

        if (strlen($body) > self::BODY_MAX_BYTES) {
            return 'bytes';
        }

        return null;
    }

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
            'lock_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Instrument, $this>
     */
    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class);
    }

    /**
     * @return BelongsTo<Enrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }
}
