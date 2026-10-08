<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step of a `LessonSequence` — a reusable template of Sumario text.
 *
 * WHAT THIS MAY NEVER BECOME: a live reference a lesson keeps reading. It is
 * read only when the teacher applies the sequence, by `ApplyLessonSequence`,
 * which copies its fields into an independent `LessonSummary`. Editing this
 * item afterwards changes nothing that was already applied — the same
 * guarantee `ReportTemplate` gives its reports. The summary does remember which
 * item it came from (`lesson_summaries.lesson_sequence_item_id`), but only so
 * that an explicit re-application can recognise its own placements.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $lesson_sequence_id
 * @property int $position
 * @property string $summary
 * @property string|null $private_notes
 * @property string|null $resources
 * @property string|null $homework
 */
#[Fillable(['lesson_sequence_id', 'position', 'summary', 'private_notes', 'resources', 'homework'])]
class LessonSequenceItem extends Model
{
    use BelongsToOrganization, HasUlids;

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

    /**
     * @return BelongsTo<LessonSequence, $this>
     */
    public function sequence(): BelongsTo
    {
        return $this->belongsTo(LessonSequence::class, 'lesson_sequence_id');
    }
}
