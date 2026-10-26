<?php

namespace Tests\Feature\Dashboard\Concerns;

use App\Models\AcademicPeriod;
use App\Models\AcademicPeriodKind;
use App\Models\AcademicPeriodStatus;
use App\Models\AssessmentProfileVersion;
use App\Models\CalendarEvent;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\ClassificationStatus;
use App\Models\Enrollment;
use App\Models\Instrument;
use App\Models\InstrumentStatus;
use App\Models\Lesson;
use App\Models\LessonSummary;
use App\Models\RecurringLessonSlot;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\TimetableActivity;
use App\Models\User;
use App\Services\Dashboard\DashboardComposer;
use App\Services\Dashboard\DashboardContext;
use App\Services\Dashboard\DashboardContextFactory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Tests\Concerns\SubscribesOrganizations;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;

/**
 * As fixtures do painel: um professor Pro com o ano letivo 2026/27 dividido
 * em três períodos, a turma «7.º A» de BuildsLessonFixtures e o relógio
 * PARADO numa segunda-feira — 26 de outubro de 2026, 16:40 em Lisboa, já em
 * hora de inverno — para nenhum teste depender do dia em que corre.
 */
trait BuildsDashboardFixtures
{
    use BuildsLessonFixtures, SubscribesOrganizations;

    /** 26 de outubro de 2026 é uma segunda-feira; o período 1 vai de 15/09 a 17/12. */
    protected const FROZEN_AT = '2026-10-26 16:40:00';

    protected AcademicPeriod $firstPeriod;

    protected AcademicPeriod $secondPeriod;

    protected AcademicPeriod $thirdPeriod;

    protected function bootDashboardFixtures(string $frozenAt = self::FROZEN_AT): void
    {
        $this->freeze($frozenAt);
        $this->bootLessonFixtures();
        $this->schoolClass->update(['label' => '7.º A']);

        [$this->firstPeriod, $this->secondPeriod, $this->thirdPeriod] = $this->inTenant($this->organization, fn (): array => [
            $this->period(1, '1.º Período', '2026-09-15', '2026-12-17', AcademicPeriodStatus::Open),
            $this->period(2, '2.º Período', '2027-01-04', '2027-03-26', AcademicPeriodStatus::Draft),
            $this->period(3, '3.º Período', '2027-04-12', '2027-06-30', AcademicPeriodStatus::Draft),
        ]);
    }

    protected function freeze(string $at): void
    {
        $this->travelTo(Carbon::parse($at, DashboardContext::TIMEZONE));
    }

    protected function downgradeToBase(): void
    {
        $this->subscribeOrganizationTo($this->organization, 'base');
    }

    protected function period(int $sequence, string $label, string $from, string $to, AcademicPeriodStatus $status): AcademicPeriod
    {
        return AcademicPeriod::create([
            'academic_year_id' => $this->schoolClass->academic_year_id,
            'label' => $label,
            'kind' => AcademicPeriodKind::Term,
            'sequence' => $sequence,
            'starts_on' => $from,
            'ends_on' => $to,
            'status' => $status,
        ]);
    }

    protected function anotherClass(string $label, ?User $teacher = null): SchoolClass
    {
        return $this->inTenant($this->organization, function () use ($label, $teacher): SchoolClass {
            $class = SchoolClass::factory()->recycle($this->organization)->create([
                'academic_year_id' => $this->schoolClass->academic_year_id,
                'subject_id' => Subject::factory()->recycle($this->organization)->create(['name' => 'Português'])->id,
                'label' => $label,
            ]);
            $class->teachers()->attach($teacher ?? $this->teacher, ['role' => 'owner']);

            return $class;
        });
    }

    protected function slotFor(SchoolClass $class, int $dayOfWeek, string $from, string $to): RecurringLessonSlot
    {
        return $this->inTenant($this->organization, fn (): RecurringLessonSlot => RecurringLessonSlot::create([
            'class_id' => $class->id,
            'day_of_week' => $dayOfWeek,
            'starts_at' => $from,
            'ends_at' => $to,
        ]));
    }

    protected function lessonFor(SchoolClass $class, string $startsAt, int $minutes = 50, array $attributes = []): Lesson
    {
        return $this->inTenant($this->organization, fn (): Lesson => Lesson::create(array_merge([
            'class_id' => $class->id,
            'starts_at' => $startsAt,
            'ends_at' => CarbonImmutable::parse($startsAt, DashboardContext::TIMEZONE)->addMinutes($minutes)->toDateTimeString(),
            'status' => 'preparation',
            'created_by' => $this->teacher->id,
        ], $attributes)));
    }

    protected function summarise(Lesson $lesson, string $content = 'Leitura orientada do capítulo 3.', ?string $updatedAt = null): LessonSummary
    {
        return $this->inTenant($this->organization, function () use ($lesson, $content, $updatedAt): LessonSummary {
            $summary = LessonSummary::create(['lesson_id' => $lesson->id, 'content' => $content]);

            if ($updatedAt !== null) {
                LessonSummary::withoutGlobalScopes()->whereKey($summary->id)->update(['updated_at' => $updatedAt]);
            }

            return $summary->refresh();
        });
    }

    protected function activity(string $title, int $dayOfWeek, string $from, string $to, string $kind = 'meeting'): TimetableActivity
    {
        return $this->inTenant($this->organization, fn (): TimetableActivity => TimetableActivity::create([
            'user_id' => $this->teacher->id,
            'kind' => $kind,
            'title' => $title,
            'day_of_week' => $dayOfWeek,
            'starts_at' => $from,
            'ends_at' => $to,
        ]));
    }

    protected function instrumentFor(SchoolClass $class, string $title, string $appliedOn, InstrumentStatus $status, ?AcademicPeriod $period = null): Instrument
    {
        return $this->inTenant($this->organization, fn (): Instrument => Instrument::factory()->recycle($this->organization)->create([
            'class_id' => $class->id,
            'academic_period_id' => ($period ?? $this->firstPeriod)->id,
            'title' => $title,
            'applied_on' => $appliedOn,
            'status' => $status->value,
        ]));
    }

    protected function classificationFor(Enrollment $enrollment, ClassificationStatus $status, array $attributes = []): Classification
    {
        return $this->inTenant($this->organization, function () use ($enrollment, $status, $attributes): Classification {
            // O ano e a disciplina da turma, reciclados: um ano letivo novo
            // saído da factory tornar-se-ia o «ano em curso» do painel.
            $version = AssessmentProfileVersion::query()->first()
                ?? AssessmentProfileVersion::factory()
                    ->recycle([$this->organization, $this->schoolClass->academicYear, $this->schoolClass->subject])
                    ->create();

            // Uma classificação confirmada ou publicada diz o que foi decidido:
            // o CHECK `classifications_decided_has_decision_check` do MySQL
            // exige `final_value` ou `final_scale_level_id` (o SQLite não o
            // impõe, e a CI corre em MySQL).
            $decided = ! in_array($status, [ClassificationStatus::Proposed, ClassificationStatus::Superseded], true);

            return Classification::create(array_merge([
                'enrollment_id' => $enrollment->id,
                'academic_period_id' => $this->firstPeriod->id,
                'scope' => ClassificationScope::Period,
                'assessment_profile_version_id' => $version->id,
                'status' => $status,
                'proposed_value' => '3.000',
                'final_value' => $decided ? '3.000' : null,
            ], $attributes));
        });
    }

    protected function calendarEvent(string $title, string $from, ?string $to = null, string $type = 'meeting', ?string $startsAt = null): CalendarEvent
    {
        return $this->inTenant($this->organization, fn (): CalendarEvent => CalendarEvent::create([
            'user_id' => $this->teacher->id,
            'type' => $type,
            'title' => $title,
            'starts_on' => $from,
            'ends_on' => $to ?? $from,
            'starts_at' => $startsAt,
        ]));
    }

    /** Corre uma leitura dentro do tenant do professor, como um pedido real faria. */
    protected function within(callable $callback): mixed
    {
        return $this->inTenant($this->organization, $callback);
    }

    protected function context(?User $teacher = null): DashboardContext
    {
        return $this->inTenant($this->organization, fn (): DashboardContext => app(DashboardContextFactory::class)->for($teacher ?? $this->teacher));
    }

    /** @return array<string, mixed> */
    protected function compose(?User $teacher = null): array
    {
        return $this->inTenant($this->organization, fn (): array => app(DashboardComposer::class)->compose($teacher ?? $this->teacher));
    }
}
