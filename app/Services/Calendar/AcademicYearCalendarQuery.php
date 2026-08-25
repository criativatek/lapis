<?php

namespace App\Services\Calendar;

use App\Models\AcademicCalendarException;
use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\CalendarEvent;
use App\Models\Instrument;
use App\Models\SchoolClass;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * «Calendário do Ano Letivo» — what is relevant in the teacher's year, over an
 * arbitrary range of dates.
 *
 * A PURE READ MODEL. Reading the calendar cannot create, alter or delete
 * anything, and nothing below writes. Two of the four things it reads own no
 * table of the calendar's at all — the year's structure comes from
 * AcademicPeriod and the avaliações from Instrument.applied_on, both exactly
 * where they already live. The third, `calendar_events` (Fase 5.3), IS the
 * calendar's own, but it is written only by SaveCalendarEvent, from an explicit
 * action of the teacher's, and never from here.
 *
 * O QUARTO — `academic_calendar_exceptions` (Fase 5.4) — é lido aqui e escrito
 * em «Estrutura do Ano Letivo», ao lado dos períodos, porque é o que ele é: a
 * outra metade da forma do ano. Um feriado e uma interrupção letiva NÃO são
 * acontecimentos: um acontecimento é pessoal e não impede aula nenhuma, uma
 * exceção é da organização inteira e é precisamente a coisa que diz que naquele
 * dia não há aula. São por isso duas leituras separadas, e nunca uma só lista
 * com espécies misturadas lá dentro.
 *
 * AULAS ARE DELIBERATELY ABSENT, and neither Lesson nor RecurringLessonSlot is
 * reachable from here. «Que aulas tenho, quando e onde» is «Horário do
 * Professor»'s question and it already answers it; this calendar answers a
 * different one — «o que é relevante no meu ano» — and repeating the horário
 * inside it would make the two the same page. This is a product decision, not
 * an omission, which is why WeeklyLessonsQuery is not reused, adapted or
 * imported: it reads Lesson rows, and no Lesson row belongs on this page.
 *
 * The range is a plain [from, to] pair rather than an ISO week, because the two
 * views need very different spans of it: a month grid (which reaches into the
 * neighbouring months to fill its first and last row) and the whole year at
 * once. Both call this one method; neither has a query of its own.
 */
final class AcademicYearCalendarQuery
{
    private const TIMEZONE = 'Europe/Lisbon';

    /**
     * @param  string|null  $returnMonth  The «Y-m» the caller is showing, when it
     *                                    has one, so that an avaliação's href can
     *                                    carry the way back to the month it was
     *                                    opened from. The Ano view passes nothing,
     *                                    because it renders no assessment link at
     *                                    all, and its hrefs stay exactly as they were.
     * @return array{
     *     periods: list<array{ulid: string, label: string, kind: string, kind_label: string, sequence: int, starts_on: string, ends_on: string}>,
     *     assessments: list<array{ulid: string, title: string, applied_on: string, class_ulid: string, class_label: string, subject: string, type: string, status: string, status_label: string, href: string}>,
     *     events: list<array{ulid: string, type: string, type_label: string, type_short_label: string, title: string, starts_on: string, ends_on: string, starts_at: string|null, ends_at: string|null, description: string|null, school_classes: list<array{ulid: string, label: string}>}>,
     *     exceptions: list<array{ulid: string, type: string, type_label: string, type_short_label: string, title: string, starts_on: string, ends_on: string, note: string|null}>
     * }
     */
    public function for(User $teacher, AcademicYear $academicYear, CarbonImmutable $from, CarbonImmutable $to, ?string $returnMonth = null): array
    {
        $fromDate = $from->setTimezone(self::TIMEZONE)->toDateString();
        $toDate = $to->setTimezone(self::TIMEZONE)->toDateString();

        return [
            'periods' => $this->periods($academicYear, $fromDate, $toDate),
            'assessments' => $this->assessments($teacher, $academicYear, $fromDate, $toDate, $returnMonth),
            'events' => $this->events($teacher, $fromDate, $toDate),
            'exceptions' => $this->exceptions($academicYear, $fromDate, $toDate),
        ];
    }

    /**
     * The year's own períodos that OVERLAP the range — not only those wholly
     * inside it. A semester running from September to January is the structure
     * of every month it crosses, so October must be told about it.
     *
     * A year is not required to be covered end to end: nothing forbids a gap
     * between two períodos, and a date in one is honestly left without any
     * period rather than attached to the nearest.
     *
     * @return list<array{ulid: string, label: string, kind: string, kind_label: string, sequence: int, starts_on: string, ends_on: string}>
     */
    private function periods(AcademicYear $academicYear, string $from, string $to): array
    {
        // whereDate on both bounds, and not a plain where: these are date
        // columns, but Eloquent stores them as «Y-m-d 00:00:00», so comparing
        // the raw column against a «Y-m-d» string is a string comparison in
        // which «2026-10-31 00:00:00» is NOT <= «2026-10-31» — a período
        // beginning on the last visible day would silently vanish. The same
        // trap EnrollmentController already documents for enrolled_on.
        return array_values($academicYear->periods()
            ->whereDate('starts_on', '<=', $to)
            ->whereDate('ends_on', '>=', $from)
            ->get()
            ->map(fn (AcademicPeriod $period): array => [
                'ulid' => $period->ulid,
                'label' => $period->label,
                'kind' => $period->kind->value,
                'kind_label' => $period->kind->label(),
                'sequence' => $period->sequence,
                'starts_on' => $period->starts_on->toDateString(),
                'ends_on' => $period->ends_on->toDateString(),
            ])
            ->all());
    }

    /**
     * As exceções letivas DESTE ano que cruzam o intervalo (Fase 5.4) — os
     * feriados, as interrupções letivas e os dias não letivos.
     *
     * DA ORGANIZAÇÃO, E NÃO DE UM PROFESSOR. Ao contrário dos acontecimentos
     * aqui em baixo, não há aqui `user_id` nenhum para filtrar, e a ausência é
     * o ponto: um feriado não é de ninguém em particular, é do ano letivo — a
     * mesma natureza dos períodos aqui em cima, e por isso a mesma pergunta,
     * feita da mesma maneira, ao próprio ano (`$academicYear->exceptions()`) e
     * nunca a uma tabela solta filtrada por datas.
     *
     * SOBREPOSIÇÃO, E NÃO CONTENÇÃO, pela mesma razão que os períodos: uma
     * interrupção de 21 a 31 de dezembro é o que está a acontecer em cada um
     * desses onze dias, e o dia visível a meio dela tem de a mostrar — uma que
     * comece em dezembro e acabe em janeiro é estrutura DOS DOIS meses.
     *
     * Com `whereDate` nos dois lados, e nunca um `where` simples: são colunas
     * `date` que o Eloquent guarda como «Y-m-d 00:00:00», e comparadas como
     * texto contra um limite «Y-m-d», «2026-10-31 00:00:00» NÃO é <=
     * «2026-10-31» — a exceção do último dia visível desaparecia sem erro
     * nenhum. É a armadilha que já mordeu os períodos, as avaliações e os
     * acontecimentos desta mesma classe.
     *
     * @return list<array{ulid: string, type: string, type_label: string, type_short_label: string, title: string, starts_on: string, ends_on: string, note: string|null}>
     */
    private function exceptions(AcademicYear $academicYear, string $from, string $to): array
    {
        return array_values($academicYear->exceptions()
            ->whereDate('starts_on', '<=', $to)
            ->whereDate('ends_on', '>=', $from)
            ->get()
            ->map(fn (AcademicCalendarException $exception): array => [
                'ulid' => $exception->ulid,
                'type' => $exception->type->value,
                'type_label' => $exception->type->label(),
                // A PALAVRA CURTA VAI JUNTO, e não é derivada na página: é o
                // que — com o ícone próprio — mantém uma exceção distinguível
                // de um período e de um acontecimento num ecrã monocromático.
                'type_short_label' => $exception->type->shortLabel(),
                'title' => $exception->title,
                'starts_on' => $exception->starts_on->toDateString(),
                'ends_on' => $exception->ends_on->toDateString(),
                'note' => $exception->note,
            ])
            ->all());
    }

    /**
     * Every avaliação applied in the range, of the turmas this teacher teaches
     * IN THIS YEAR, and of no others.
     *
     * «Whose turma» is SchoolClass::scopeTaughtBy — the same source of truth
     * ClassController::teacherClasses() and «Horário do Professor» already use,
     * called here rather than reproduced — and the narrowing is the whereIn over
     * the keys it returns, the idiom TeacherTimetableController established for
     * exactly this problem. That scoping is AUTHORIZATION and stays explicit;
     * the organization boundary is not, because each model's own global scope
     * already draws it and a second, redundant filter would only invite the two
     * to disagree.
     *
     * @return list<array{ulid: string, title: string, applied_on: string, class_ulid: string, class_label: string, subject: string, type: string, status: string, status_label: string, href: string}>
     */
    private function assessments(User $teacher, AcademicYear $academicYear, string $from, string $to, ?string $returnMonth = null): array
    {
        $classIds = SchoolClass::query()
            ->taughtBy($teacher)
            ->where('academic_year_id', $academicYear->getKey())
            ->pluck('id')
            ->all();

        if ($classIds === []) {
            return [];
        }

        return array_values(Instrument::query()
            ->whereIn('class_id', $classIds)
            // Inclusive on both ends, and whereDate for the same reason the
            // períodos above use it: applied_on is a date column stored as
            // «Y-m-d 00:00:00», so a plain whereBetween against «Y-m-d» bounds
            // drops precisely the avaliações on the LAST visible day.
            ->whereDate('applied_on', '>=', $from)
            ->whereDate('applied_on', '<=', $to)
            ->with(['schoolClass.subject', 'type'])
            ->orderBy('applied_on')
            ->orderBy('title')
            ->get()
            ->map(fn (Instrument $instrument): array => [
                'ulid' => $instrument->ulid,
                'title' => $instrument->title,
                'applied_on' => $instrument->applied_on->toDateString(),
                'class_ulid' => $instrument->schoolClass->ulid,
                'class_label' => $instrument->schoolClass->label,
                'subject' => $instrument->schoolClass->subject->name,
                'type' => $instrument->type->name,
                'status' => $instrument->status->value,
                'status_label' => $instrument->status->label(),
                // The real page of the real element, by its real route: an
                // entry in the calendar is a way in, never a dead end.
                //
                // E, QUANDO SE SABE DE QUE MÊS SE PARTIU, o caminho de volta vai
                // escrito no próprio endereço — a mesma mecânica de query string
                // que «Avaliações» já usa (?from=assessments), e não um sistema
                // de «returnTo» vindo do servidor. O «month» é sempre o «Y-m» que
                // o próprio servidor já resolveu, nunca texto do cliente, e o
                // destino que a página constrói dele é sempre /calendar.
                'href' => $returnMonth === null
                    ? route('instruments.show', $instrument, false)
                    : route('instruments.show', ['instrument' => $instrument, 'from' => 'calendar', 'month' => $returnMonth], false),
            ])
            ->all());
    }

    /**
     * Os acontecimentos DESTE professor que cruzam o intervalo (Fase 5.3) —
     * uma reunião, uma atividade, uma visita de estudo, ou outra coisa datada.
     *
     * PESSOAIS, E NÃO DA ESCOLA. `user_id` is the whole of «whose», because
     * that is what a CalendarEvent is in this version: um acontecimento de um
     * colega não aparece aqui, e não é apenas não-editável — é invisível, tal
     * como CalendarEventPolicy diz. A fronteira da organização não é repetida
     * aqui pela mesma razão que não é repetida nas avaliações acima: o global
     * scope do próprio modelo já a desenha, e um segundo filtro redundante só
     * convidaria os dois a discordarem.
     *
     * OVERLAP, E NÃO CONTENÇÃO — exatamente a mesma forma que os períodos acima
     * usam, e pela mesma razão: uma visita de estudo de segunda a quinta é o
     * que está a acontecer em cada um desses quatro dias, e o dia visível a
     * meio dela tem de a mostrar. Com `whereDate` nos dois lados, e nunca um
     * `where` simples, porque estas são colunas `date` que o Eloquent guarda
     * como «Y-m-d 00:00:00»: comparadas como texto contra um limite «Y-m-d»,
     * «2026-10-31 00:00:00» NÃO é <= «2026-10-31», e o acontecimento do último
     * dia visível desaparecia sem erro nenhum — a armadilha que já mordeu os
     * períodos e as avaliações desta mesma classe.
     *
     * `ends_on` nunca é nulo (ver SaveCalendarEvent e a migração), e é isso que
     * permite que esta condição seja esta e não uma com um COALESCE por dentro.
     *
     * @return list<array{ulid: string, type: string, type_label: string, type_short_label: string, title: string, starts_on: string, ends_on: string, starts_at: string|null, ends_at: string|null, description: string|null, school_classes: list<array{ulid: string, label: string}>}>
     */
    private function events(User $teacher, string $from, string $to): array
    {
        return array_values(CalendarEvent::query()
            ->where('user_id', $teacher->getKey())
            ->whereDate('starts_on', '<=', $to)
            ->whereDate('ends_on', '>=', $from)
            // Eager-loaded, so a month with forty acontecimentos is still two
            // queries and not forty-one.
            ->with('schoolClasses')
            ->orderBy('starts_on')
            ->orderBy('starts_at')
            ->orderBy('title')
            ->get()
            ->map(fn (CalendarEvent $event): array => [
                'ulid' => $event->ulid,
                'type' => $event->type->value,
                'type_label' => $event->type->label(),
                'type_short_label' => $event->type->shortLabel(),
                'title' => $event->title,
                'starts_on' => $event->starts_on->toDateString(),
                'ends_on' => $event->ends_on->toDateString(),
                // `time` columns come back as «HH:MM:SS»; trimmed to «HH:MM»
                // exactly as «Horário do Professor» already trims its own.
                'starts_at' => $event->starts_at === null ? null : substr($event->starts_at, 0, 5),
                'ends_at' => $event->ends_at === null ? null : substr($event->ends_at, 0, 5),
                'description' => $event->description,
                'school_classes' => array_values($event->schoolClasses
                    ->map(fn (SchoolClass $schoolClass): array => [
                        'ulid' => $schoolClass->ulid,
                        'label' => $schoolClass->label,
                    ])
                    ->all()),
            ])
            ->all());
    }
}
