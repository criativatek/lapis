<?php

namespace Tests\Feature\Lessons;

use App\Actions\Classes\ArchiveSchoolClass;
use App\Actions\Lessons\MaterializeLessonsForWeek;
use App\Models\AcademicYear;
use App\Models\AttendanceStatus;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonAttendance;
use App\Models\LessonOrigin;
use App\Models\LessonOutcome;
use App\Models\LessonPlan;
use App\Models\LessonStatus;
use App\Models\LessonSummary;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\RecurringLessonSlot;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Lessons\WeeklyLessonsQuery;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ARQUIVAR UMA TURMA É UM FACTO TEMPORAL, E OS TRÊS SÍTIOS QUE O LEEM TÊM DE
 * O LER DA MESMA MANEIRA (JANELA AB).
 *
 * O caso: uma turma arquivada a meio de setembro mantinha um tempo do horário
 * aberto e continuava a fazer nascer aulas — uma delas criada onze dias DEPOIS
 * do arquivamento — e a mostrá-las como «Por preparar» na semana. O Horário do
 * Professor já não mostrava a turma; a materialização e a vista semanal não
 * sabiam da regra, porque a condição estava escrita só no Horário.
 *
 * Os dados aqui são inteiramente fictícios. O que se reproduz é a GEOMETRIA do
 * caso — arquivamento a meio do ano letivo, tempo do horário ainda aberto,
 * ocorrências nas semanas seguintes —, e nunca uma cópia de linhas reais.
 */
class ArchivedClassLessonConsistencyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * O CASO, na sua forma mínima: arquivada a 13/09, com o tempo de
     * sexta-feira ainda aberto, abrir a semana de 21/09 não pode fazer nascer
     * a aula de 25/09. Antes da correção nascia.
     */
    #[Test]
    public function an_archived_class_does_not_materialise_lessons_after_the_archival_day(): void
    {
        [$teacher, $organization, $year, $class] = $this->context();
        $this->tenant($organization, fn () => RecurringLessonSlot::create($this->slot($class, 5)));
        $this->archiveOn($organization, $class, '2026-09-13 10:00:00');

        $this->materialiseWeek($organization, $teacher, $year, '2026-09-21');

        $this->assertDatabaseMissing('lessons', ['class_id' => $class->id]);
    }

    /**
     * O PASSADO DA TURMA CONTINUA A MATERIALIZAR-SE. Abrir uma semana anterior
     * ao arquivamento cria as aulas dessa semana: naquela semana a turma estava
     * viva, e o arquivamento de hoje não reescreve o que ela foi.
     */
    #[Test]
    public function an_archived_class_still_materialises_the_weeks_it_was_alive(): void
    {
        [$teacher, $organization, $year, $class] = $this->context();
        $this->tenant($organization, fn () => RecurringLessonSlot::create($this->slot($class, 5)));
        $this->archiveOn($organization, $class, '2026-09-13 10:00:00');

        $this->materialiseWeek($organization, $teacher, $year, '2026-09-07');

        $this->assertDatabaseHas('lessons', [
            'class_id' => $class->id,
            'starts_at' => '2026-09-11 09:00:00',
        ]);
    }

    /**
     * A FRONTEIRA É O PRÓPRIO DIA DO ARQUIVAMENTO, e é inclusiva contra a
     * turma: arquivar grava um instante daquele dia, e a ocorrência desse mesmo
     * dia já não nasce. A véspera nasce.
     */
    #[Test]
    public function the_archival_day_itself_is_already_outside_the_schedule(): void
    {
        [$teacher, $organization, $year, $class] = $this->context();
        // Quinta-feira 10/09 e sexta-feira 11/09, na mesma semana.
        $this->tenant($organization, function () use ($class): void {
            RecurringLessonSlot::create($this->slot($class, 4));
            RecurringLessonSlot::create($this->slot($class, 5, '11:00', '11:50'));
        });
        $this->archiveOn($organization, $class, '2026-09-11 08:00:00');

        $this->materialiseWeek($organization, $teacher, $year, '2026-09-07');

        $this->assertDatabaseHas('lessons', ['class_id' => $class->id, 'starts_at' => '2026-09-10 09:00:00']);
        $this->assertDatabaseMissing('lessons', ['class_id' => $class->id, 'starts_at' => '2026-09-11 11:00:00']);
    }

    /**
     * UMA TURMA ACTIVA NÃO MUDA NADA. O controlo de que todos os outros
     * dependem: sem arquivamento, a semana materializa-se e mostra-se como
     * sempre.
     */
    #[Test]
    public function an_active_class_keeps_materialising_and_showing_its_week(): void
    {
        [$teacher, $organization, $year, $class] = $this->context();
        $this->tenant($organization, fn () => RecurringLessonSlot::create($this->slot($class, 5)));

        $this->materialiseWeek($organization, $teacher, $year, '2026-09-21');

        $this->assertDatabaseHas('lessons', ['class_id' => $class->id, 'starts_at' => '2026-09-25 09:00:00']);
        $this->assertSame(['2026-09-25'], $this->weekDays($organization, $teacher, $year, '2026-09-21'));
    }

    /**
     * AS AULAS QUE O DEFEITO JÁ DEIXOU PARA TRÁS. Materializadas antes da
     * correção, vazias e nascidas do horário, deixam de aparecer na semana —
     * sem que uma única linha seja apagada.
     */
    #[Test]
    public function empty_scheduled_lessons_after_archival_leave_the_weekly_view_without_being_deleted(): void
    {
        [$teacher, $organization, $year, $class] = $this->context();
        $this->tenant($organization, function () use ($class, $teacher): void {
            $this->lesson($class, $teacher, '2026-09-25 09:00:00');
            $this->lesson($class, $teacher, '2026-10-02 09:00:00');
            $this->lesson($class, $teacher, '2026-10-09 09:00:00');
        });
        $this->archiveOn($organization, $class, '2026-09-13 10:00:00');

        $this->assertSame([], $this->weekDays($organization, $teacher, $year, '2026-09-21'));
        $this->assertSame([], $this->weekDays($organization, $teacher, $year, '2026-09-28'));
        $this->assertSame([], $this->weekDays($organization, $teacher, $year, '2026-10-05'));

        // ESCONDIDAS, NUNCA APAGADAS.
        $this->assertDatabaseHas('lessons', ['class_id' => $class->id, 'starts_at' => '2026-09-25 09:00:00']);
        $this->assertDatabaseHas('lessons', ['class_id' => $class->id, 'starts_at' => '2026-10-02 09:00:00']);
        $this->assertDatabaseHas('lessons', ['class_id' => $class->id, 'starts_at' => '2026-10-09 09:00:00']);
    }

    /**
     * AS AULAS ANTERIORES AO ARQUIVAMENTO CONTINUAM NA SEMANA DELAS. O filtro é
     * temporal, e não «a turma está arquivada».
     */
    #[Test]
    public function lessons_before_the_archival_stay_in_their_week(): void
    {
        [$teacher, $organization, $year, $class] = $this->context();
        $this->tenant($organization, fn () => $this->lesson($class, $teacher, '2026-09-11 09:00:00'));
        $this->archiveOn($organization, $class, '2026-09-13 10:00:00');

        $this->assertSame(['2026-09-11'], $this->weekDays($organization, $teacher, $year, '2026-09-07'));
    }

    /**
     * LECIONADA, COM SUMÁRIO: fica. Uma aula posterior ao arquivamento que
     * mesmo assim aconteceu é história, e história não se esconde.
     */
    #[Test]
    public function a_taught_lesson_with_a_summary_survives_the_archival(): void
    {
        [$teacher, $organization, $year, $class] = $this->context();
        $this->tenant($organization, function () use ($class, $teacher): void {
            $lesson = $this->lesson($class, $teacher, '2026-09-25 09:00:00');
            $lesson->update(['status' => LessonStatus::Taught, 'outcome' => LessonOutcome::Taught]);
            LessonSummary::create(['lesson_id' => $lesson->id, 'content' => 'O que se fez na aula.']);
        });
        $this->archiveOn($organization, $class, '2026-09-13 10:00:00');

        $rows = $this->week($organization, $teacher, $year, '2026-09-21');

        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['has_summary']);
    }

    /**
     * PLANO E RASCUNHO DE FALTAS: ficam. «Por preparar» NÃO quer dizer vazia —
     * uma aula por preparar pode já ter um plano escrito ou linhas de
     * assiduidade ainda em rascunho, e é por isso que o estado sozinho nunca
     * chega para esconder seja o que for.
     */
    #[Test]
    public function a_prepared_lesson_with_a_plan_or_a_draft_of_absences_survives_the_archival(): void
    {
        [$teacher, $organization, $year, $class] = $this->context();
        $this->tenant($organization, function () use ($class, $teacher): void {
            $planned = $this->lesson($class, $teacher, '2026-09-25 09:00:00');
            LessonPlan::create([
                'lesson_id' => $planned->id,
                'planned_summary' => 'Revisões.',
                'created_by' => $teacher->id,
            ]);

            $drafted = $this->lesson($class, $teacher, '2026-09-25 11:00:00');
            $enrollment = $this->enrollment($class);
            LessonAttendance::create([
                'lesson_id' => $drafted->id,
                'enrollment_id' => $enrollment->id,
                'student_id' => $enrollment->student_id,
                'status' => AttendanceStatus::Absent,
                'updated_by' => $teacher->id,
            ]);
        });
        $this->archiveOn($organization, $class, '2026-09-13 10:00:00');

        $this->assertSame(
            ['2026-09-25', '2026-09-25'],
            $this->weekDays($organization, $teacher, $year, '2026-09-21'),
        );
    }

    /**
     * A AULA INTRODUZIDA À MÃO FICA. Não nasceu do horário, e por isso o
     * arquivamento do horário não tem nada a dizer sobre ela.
     */
    #[Test]
    public function a_manual_lesson_survives_the_archival(): void
    {
        [$teacher, $organization, $year, $class] = $this->context();
        $this->tenant($organization, fn () => $this->lesson($class, $teacher, '2026-09-25 09:00:00')
            ->update(['origin' => LessonOrigin::Manual]));
        $this->archiveOn($organization, $class, '2026-09-13 10:00:00');

        $this->assertSame(['2026-09-25'], $this->weekDays($organization, $teacher, $year, '2026-09-21'));
    }

    /**
     * DESARQUIVAR DEVOLVE TUDO. `archived_at` volta a `null` e com ele volta o
     * horário: a semana materializa-se outra vez, e as ocorrências que estavam
     * escondidas reaparecem — porque nunca tinham sido apagadas.
     */
    #[Test]
    public function unarchiving_restores_both_the_materialisation_and_the_weekly_view(): void
    {
        [$teacher, $organization, $year, $class] = $this->context();
        $this->tenant($organization, fn () => RecurringLessonSlot::create($this->slot($class, 5)));
        $this->archiveOn($organization, $class, '2026-09-13 10:00:00');

        $this->materialiseWeek($organization, $teacher, $year, '2026-09-21');
        $this->materialiseWeek($organization, $teacher, $year, '2026-09-28');

        $this->assertSame([], $this->weekDays($organization, $teacher, $year, '2026-09-21'));
        $this->assertSame([], $this->weekDays($organization, $teacher, $year, '2026-09-28'));

        $this->tenant($organization, fn () => app(ArchiveSchoolClass::class)->restore($class->fresh()));

        $this->materialiseWeek($organization, $teacher, $year, '2026-09-21');
        $this->materialiseWeek($organization, $teacher, $year, '2026-09-28');

        $this->assertSame(['2026-09-25'], $this->weekDays($organization, $teacher, $year, '2026-09-21'));
        $this->assertSame(['2026-10-02'], $this->weekDays($organization, $teacher, $year, '2026-09-28'));
    }

    /**
     * A TURMA DO LADO NÃO É AFECTADA. Uma aula à mesma hora, noutra turma
     * activa, continua exactamente onde estava.
     */
    #[Test]
    public function a_simultaneous_lesson_of_another_class_is_untouched(): void
    {
        [$teacher, $organization, $year, $class] = $this->context();
        $other = $this->schoolClass($organization, $year, $teacher, '7.º B');
        $this->tenant($organization, function () use ($class, $other, $teacher): void {
            $this->lesson($class, $teacher, '2026-09-25 09:00:00');
            $this->lesson($other, $teacher, '2026-09-25 09:00:00');
        });
        $this->archiveOn($organization, $class, '2026-09-13 10:00:00');

        $rows = $this->week($organization, $teacher, $year, '2026-09-21');

        $this->assertCount(1, $rows);
        $this->assertSame($other->ulid, $rows[0]['school_class']['ulid']);
    }

    // --- andaimes -------------------------------------------------------

    /** @return array{0: User, 1: Organization, 2: AcademicYear, 3: SchoolClass} */
    private function context(): array
    {
        $teacher = User::factory()->create();
        $organization = $teacher->personalOrganization();
        $this->subscribeToPro($organization);
        $year = $this->tenant($organization, fn () => AcademicYear::factory()->recycle($organization)->active()->create([
            'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30',
        ]));

        return [$teacher, $organization, $year, $this->schoolClass($organization, $year, $teacher, '7.º A')];
    }

    private function schoolClass(Organization $organization, AcademicYear $year, User $teacher, string $label): SchoolClass
    {
        return $this->tenant($organization, function () use ($organization, $year, $teacher, $label): SchoolClass {
            $class = SchoolClass::factory()->recycle($organization)->create([
                'academic_year_id' => $year->id, 'label' => $label,
            ]);
            $class->teachers()->attach($teacher, ['role' => 'owner']);

            return $class;
        });
    }

    /**
     * Arquivar pela PRÓPRIA ação, e só depois recuar o instante para a data
     * fictícia: é `ArchiveSchoolClass` que decide o que arquivar significa, e um
     * `update` directo do teste deixaria de ver uma mudança nessa decisão.
     */
    private function archiveOn(Organization $organization, SchoolClass $class, string $archivedAt): void
    {
        $this->tenant($organization, function () use ($class, $archivedAt): void {
            app(ArchiveSchoolClass::class)->execute($class);
            SchoolClass::query()->whereKey($class->getKey())->update(['archived_at' => $archivedAt]);
        });
    }

    private function materialiseWeek(Organization $organization, User $teacher, AcademicYear $year, string $weekStart): void
    {
        $from = CarbonImmutable::parse($weekStart, 'Europe/Lisbon')->startOfWeek();
        $this->tenant($organization, fn () => app(MaterializeLessonsForWeek::class)->execute(
            $teacher, $year, $from, $from->endOfWeek(),
        ));
    }

    /** @return list<array<string, mixed>> */
    private function week(Organization $organization, User $teacher, AcademicYear $year, string $weekStart): array
    {
        return $this->tenant($organization, fn (): array => app(WeeklyLessonsQuery::class)->for(
            $teacher, $year, CarbonImmutable::parse($weekStart),
        ));
    }

    /** @return list<string> */
    private function weekDays(Organization $organization, User $teacher, AcademicYear $year, string $weekStart): array
    {
        return array_map(
            fn (array $row): string => substr((string) $row['starts_at'], 0, 10),
            $this->week($organization, $teacher, $year, $weekStart),
        );
    }

    private function lesson(SchoolClass $class, User $teacher, string $startsAt): Lesson
    {
        return Lesson::create([
            'class_id' => $class->id,
            'starts_at' => $startsAt,
            'ends_at' => CarbonImmutable::parse($startsAt)->addMinutes(50),
            'origin' => LessonOrigin::Schedule,
            'status' => LessonStatus::Preparation,
            'created_by' => $teacher->id,
        ]);
    }

    private function enrollment(SchoolClass $class): Enrollment
    {
        return Enrollment::factory()->create([
            'class_id' => $class->id,
            'student_id' => Student::factory()->create()->id,
            'enrolled_on' => '2026-09-01',
        ]);
    }

    /** @return array<string, mixed> */
    private function slot(SchoolClass $class, int $day, string $startsAt = '09:00', string $endsAt = '09:50'): array
    {
        return [
            'class_id' => $class->id,
            'day_of_week' => $day,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-06-30',
        ];
    }

    private function subscribeToPro(Organization $organization): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')->where('organization_id', $organization->id)->delete();
        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->id,
            'plan_id' => Plan::where('key', 'pro')->firstOrFail()->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::now()->subDay(),
        ]);
        app(Entitlements::class)->flush();
    }

    private function tenant(Organization $organization, callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($organization, $callback);
    }
}
