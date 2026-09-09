<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $class_id
 * @property int|null $class_group_id
 * @property int $day_of_week
 * @property string $starts_at
 * @property string $ends_at
 * @property Carbon|null $starts_on
 * @property Carbon|null $ends_on
 */
#[Fillable(['class_id', 'class_group_id', 'day_of_week', 'starts_at', 'ends_at', 'starts_on', 'ends_on'])]
class RecurringLessonSlot extends Model
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
            'day_of_week' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
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
     * Quem participa neste tempo — NULL é a turma inteira.
     *
     * @return BelongsTo<ClassGroup, $this>
     */
    public function classGroup(): BelongsTo
    {
        return $this->belongsTo(ClassGroup::class);
    }

    /**
     * @return HasMany<Lesson, $this>
     */
    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }

    /**
     * Has this slot's schedule already taken effect, as of "today" in the
     * given timezone? A null `starts_on` means "in effect since the
     * beginning" and counts as already in vigor — the same reading
     * MaterializeLessonsForRange already gives a null `starts_on` when it
     * treats it as an unbounded lower edge.
     *
     * The timezone is a PARAMETER, not read from the container in here: a
     * model has no business resolving CurrentOrganization itself (it would
     * be untestable outside a tenant and impossible to reuse for a
     * different organization's "today" in the same request). Callers pass
     * `app(CurrentOrganization::class)->get()->timezone`.
     *
     * Compared as plain `Y-m-d` strings — the same idiom
     * MaterializeLessonsForRange::isNonTeachingDay() already uses for
     * `starts_on`/`ends_on`, which are DATE columns and never carry a time
     * of their own to compare instants with.
     */
    public function isAlreadyInVigor(string $timezone): bool
    {
        if ($this->starts_on === null) {
            return true;
        }

        return $this->starts_on->toDateString() <= CarbonImmutable::now($timezone)->toDateString();
    }

    /**
     * Did this slot's own `starts_on` land on exactly today, in the given
     * timezone? The one date for which `isAlreadyInVigor()` is true but the
     * slot may still have produced nothing yet — every other in-vigor
     * `starts_on` is strictly in the past, so whatever it was going to
     * produce already exists or never will.
     *
     * Same PARAMETER discipline as `isAlreadyInVigor()` above, and the same
     * plain `Y-m-d` string comparison — `starts_on` is a DATE column with no
     * time of its own to compare instants with.
     */
    public function startedExactlyToday(string $timezone): bool
    {
        return $this->starts_on !== null
            && $this->starts_on->toDateString() === CarbonImmutable::now($timezone)->toDateString();
    }

    /**
     * Whether any linked Lesson contains pedagogical history that must remain
     * attached to this schedule version.
     */
    public function hasRelevantPedagogicalHistory(): bool
    {
        return $this->lessons()
            ->where(function (Builder $query): void {
                $query
                    ->where('status', LessonStatus::Taught)
                    ->orWhereHas('summary')
                    ->orWhereHas('plan');
            })
            ->exists();
    }

    /**
     * The real branch condition for LessonScheduleController::update()/
     * destroy(): does changing this slot destructively (in-place edit,
     * hard delete) risk rewriting history, or not?
     *
     * `isAlreadyInVigor()` alone draws that line at "today or earlier", but
     * a slot whose `starts_on` is exactly today and that has not yet
     * produced a single Lesson has no history to protect — it behaves
     * exactly like a future slot until the first Lesson exists under it.
     * That is the one carve-out on top of `isAlreadyInVigor()`:
     *
     *   requiresVersioning = isAlreadyInVigor
     *       AND NOT (startedExactlyToday AND no Lessons yet)
     *
     * Only relevant pedagogical history protects a schedule version. A
     * materialized preparation Lesson without a summary or plan is empty
     * history and may be updated in place.
     */
    public function requiresVersioning(string $timezone): bool
    {
        if (! $this->isAlreadyInVigor($timezone)) {
            return false;
        }

        return ! ($this->startedExactlyToday($timezone) && ! $this->hasRelevantPedagogicalHistory());
    }
}
