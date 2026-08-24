<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reusable sequence of lesson content, owned by one teacher (Fatia 4).
 *
 * A TEMPLATE, NOT A LIVE LINK — the same discipline as `ReportTemplate`
 * (see its docblock). ApplyLessonSequence reads an item once and writes an
 * independent `LessonSummary`; editing this sequence afterwards never
 * reaches back into a lesson it was already applied to.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $user_id
 * @property int $subject_id
 * @property int $academic_year_id
 * @property string|null $grade_level
 * @property string $name
 */
#[Fillable(['user_id', 'subject_id', 'academic_year_id', 'grade_level', 'name'])]
class LessonSequence extends Model
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
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Subject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * @return BelongsTo<AcademicYear, $this>
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * @return HasMany<LessonSequenceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(LessonSequenceItem::class)->orderBy('position');
    }
}
