<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Uma ocorrência do horário que o professor eliminou de propósito, e que a
 * materialização não pode voltar a criar.
 *
 * SEM ULID, ao contrário de quase tudo o resto: isto nunca aparece num URL nem
 * num ecrã. É um registo interno que só a materialização lê, e dar-lhe uma
 * chave pública sugeriria uma página que não existe.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $class_id
 * @property int|null $class_group_id
 * @property int $recurring_lesson_slot_id
 * @property Carbon $occurs_at
 * @property int $cancelled_by
 */
#[Fillable(['class_id', 'class_group_id', 'recurring_lesson_slot_id', 'occurs_at', 'cancelled_by'])]
class CancelledLessonOccurrence extends Model
{
    use BelongsToOrganization;

    protected function casts(): array
    {
        return ['occurs_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<SchoolClass, $this>
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    /**
     * @return BelongsTo<RecurringLessonSlot, $this>
     */
    public function recurringLessonSlot(): BelongsTo
    {
        return $this->belongsTo(RecurringLessonSlot::class);
    }
}
