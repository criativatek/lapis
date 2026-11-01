<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Uma correção do resultado de uma aula: quem, quando, o que havia, o que passou
 * a haver e porquê. Só se acrescenta; nunca se edita nem se apaga.
 *
 * `previous` e `current` guardam os seis campos do resultado (outcome, reason,
 * note, cause, situation, cause_note) com os valores gravados — podem trazer
 * dado reservado ao titular, por isso a leitura passa por LessonOutcomeDetails.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $lesson_id
 * @property int|null $rectified_by
 * @property Carbon $rectified_at
 * @property string $kind `outcome` | `details` | `administrative`
 * @property array<string, string|null> $previous
 * @property array<string, string|null> $current
 * @property string $motive
 */
#[Fillable(['lesson_id', 'rectified_by', 'rectified_at', 'kind', 'previous', 'current', 'motive'])]
class LessonOutcomeRectification extends Model
{
    use BelongsToOrganization, HasUlids;

    public const string KIND_OUTCOME = 'outcome';

    public const string KIND_DETAILS = 'details';

    /** Correção da causa feita pela direção, com o titular fora da turma (RectifyLessonOutcome::executeAsAdministrator). */
    public const string KIND_ADMINISTRATIVE = 'administrative';

    /** Os campos do resultado que uma correção pode mudar, pela ordem em que se mostram. */
    public const array FIELDS = ['outcome', 'reason', 'note', 'cause', 'situation', 'cause_note'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'rectified_at' => 'datetime',
            'previous' => 'array',
            'current' => 'array',
        ];
    }

    /** @return BelongsTo<Lesson, $this> */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /** @return BelongsTo<User, $this> */
    public function rectifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rectified_by');
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }
}
