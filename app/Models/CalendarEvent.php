<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * Um «acontecimento» do Calendário do Ano Letivo (Fase 5.3) — uma reunião, uma
 * atividade, uma visita de estudo, ou outra coisa datada.
 *
 * `CalendarEvent` is the TECHNICAL name and stays inside the code. Every string
 * the teacher reads says «acontecimento» — «Novo acontecimento», «Adicionar ao
 * calendário» — and never «evento de calendário», which is the name of a table
 * and not of anything a professor has ever had to schedule.
 *
 * PERSONAL, NOT SHARED, exactly as `LessonSequence` is: `organization_id` is
 * the tenant boundary and `user_id` is the owner, and CalendarEventPolicy is
 * the only place that decides who may read or change one. There is no
 * institutional calendar in this version — a colleague's acontecimentos are
 * invisible, not merely read-only.
 *
 * IT OWNS NOTHING ELSE. Attaching turmas writes to one pivot table and nowhere
 * near `Instrument`, `AcademicPeriod`, `Lesson` or `RecurringLessonSlot`;
 * deleting one takes its pivot rows and nothing besides.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $user_id
 * @property CalendarEventType $type
 * @property string $title
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property string|null $starts_at
 * @property string|null $ends_at
 * @property string|null $description
 */
#[Fillable([
    'user_id', 'type', 'title', 'starts_on', 'ends_on', 'starts_at', 'ends_at', 'description',
])]
class CalendarEvent extends Model
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
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'type' => CalendarEventType::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            // starts_at/ends_at are deliberately NOT cast. They are `time`
            // columns, so Eloquent hands back «HH:MM:SS» and the reader trims
            // to «HH:MM» — the same idiom TeacherTimetableController already
            // applies to RecurringLessonSlot. Casting them to a datetime would
            // invent a date for a time of day that has none of its own.
        ];
    }

    /**
     * The owning teacher. An acontecimento never changes hands: SaveCalendarEvent
     * stamps this on create and never on update.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The turmas this acontecimento is about — zero, one, or many, through a
     * real pivot table and never a list of ids in a column.
     *
     * @return BelongsToMany<SchoolClass, $this>
     */
    public function schoolClasses(): BelongsToMany
    {
        return $this->belongsToMany(
            SchoolClass::class,
            'calendar_event_school_class',
            'calendar_event_id',
            'school_class_id',
        )->orderBy('label');
    }
}
