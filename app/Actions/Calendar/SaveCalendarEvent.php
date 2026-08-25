<?php

namespace App\Actions\Calendar;

use App\Models\CalendarEvent;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Audit\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Criar-ou-atualizar um acontecimento e as suas turmas, numa transação.
 *
 * ONE WRITE PATH FOR BOTH, on purpose: the controller's `store` and `update`
 * call this same method, so «criar» and «editar» cannot drift into two
 * behaviours that agree today and disagree in three months — which is exactly
 * how a default like `ends_on` ends up being applied on creation and forgotten
 * on edit.
 *
 * OWNERSHIP IS STAMPED ONCE. `user_id` is written on create and never touched
 * again: editing an acontecimento never hands it to somebody else, and the
 * policy that guards it therefore never has a moving target.
 *
 * NOTHING ELSE IS TOUCHED. The only tables written here are `calendar_events`
 * and its pivot. No Instrument, no AcademicPeriod, no Lesson, no
 * RecurringLessonSlot is read, created or modified.
 */
class SaveCalendarEvent
{
    public function __construct(protected AuditLog $audit) {}

    /**
     * @param  array<string, mixed>  $attributes  type, title, starts_on, ends_on, starts_at, ends_at, description
     * @param  list<string>  $schoolClassUlids  the turmas to attach — an empty list detaches every one
     */
    public function execute(?CalendarEvent $event, array $attributes, array $schoolClassUlids, User $actor): CalendarEvent
    {
        $isNew = $event === null;

        $event = DB::transaction(function () use ($event, $attributes, $schoolClassUlids, $actor): CalendarEvent {
            $event ??= new CalendarEvent(['user_id' => $actor->getKey()]);

            $event->fill($this->normalize($attributes));
            $event->save();

            // sync, and not attach: the submitted list is the WHOLE truth about
            // which turmas this acontecimento is about, so a turma the teacher
            // has taken out of the form is taken out of the pivot too.
            $event->schoolClasses()->sync($this->schoolClassIds($schoolClassUlids));

            return $event;
        });

        $this->audit->record(
            $isNew ? 'calendar_event.created' : 'calendar_event.updated',
            $event,
            $actor,
            $isNew ? 'Acontecimento criado no calendário.' : 'Acontecimento alterado no calendário.',
            ['type' => $event->type->value, 'classes_count' => count($schoolClassUlids)],
        );

        return $event->refresh();
    }

    /**
     * «Data final opcional; conceptualmente igual à inicial se ausente» — and
     * «conceptually equal» is written down HERE, once, rather than left as a
     * null for every read site to remember to fall back from. The column is
     * NOT NULL precisely so that the calendar's overlap query can be the plain
     * `starts_on <= to AND ends_on >= from` and stay true for a one-day
     * acontecimento as much as for one that runs a week.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalize(array $attributes): array
    {
        $endsOn = $attributes['ends_on'] ?? null;

        $attributes['ends_on'] = is_string($endsOn) && $endsOn !== ''
            ? $endsOn
            : $attributes['starts_on'];

        // Um campo de texto deixado em branco é «não escreveu nada», e não uma
        // descrição vazia — a mesma convenção que LessonSummaryRequest já usa.
        foreach (['starts_at', 'ends_at', 'description'] as $optional) {
            $value = $attributes[$optional] ?? null;
            $attributes[$optional] = is_string($value) && trim($value) !== '' ? trim($value) : null;
        }

        return $attributes;
    }

    /**
     * The turmas, by their own keys. Read through SchoolClass's own query — and
     * therefore through its organization scope — so a ulid from another tenant
     * resolves to nothing at all rather than to somebody else's turma. The Form
     * Request has already refused anything the teacher does not teach; this is
     * the second lock on the same door, not a substitute for it.
     *
     * @param  list<string>  $ulids
     * @return list<int>
     */
    private function schoolClassIds(array $ulids): array
    {
        if ($ulids === []) {
            return [];
        }

        return array_values(SchoolClass::query()
            ->whereIn('ulid', $ulids)
            ->pluck('id')
            ->all());
    }
}
