<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $class_id
 * @property int|null $recurring_lesson_slot_id
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 * @property LessonStatus $status
 * @property int $created_by
 */
#[Fillable(['class_id', 'recurring_lesson_slot_id', 'starts_at', 'ends_at', 'status', 'created_by'])]
class Lesson extends Model
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

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'status' => LessonStatus::class,
        ];
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasOne<LessonSummary, $this>
     */
    public function summary(): HasOne
    {
        return $this->hasOne(LessonSummary::class);
    }
}
