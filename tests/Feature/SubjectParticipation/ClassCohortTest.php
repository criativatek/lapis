<?php

namespace Tests\Feature\SubjectParticipation;

use App\Actions\SubjectParticipation\MarkNotAttendingSubject;
use App\Actions\SubjectParticipation\ReactivateSubjectParticipation;
use App\Models\AcademicPeriod;
use App\Models\AssessmentProfile;
use App\Models\AssessmentProfileVersion;
use App\Models\CohortUniverse;
use App\Models\Domain;
use App\Models\Instrument;
use App\Models\InstrumentItem;
use App\Models\ProfileVersionStatus;
use App\Models\ResultState;
use App\Models\Scale;
use App\Models\StudentItemScore;
use App\Models\SubjectParticipationReason;
use App\Services\Assessment\BuildClassElements;
use App\Services\Assessment\BuildResultsProgression;
use App\Services\Assessment\ClassCohort;
use App\Services\Assessment\ClassResultsCalculator;
use App\Support\Assessment\AssessmentCutoff;
use PHPUnit\Framework\Attributes\Test;

/**
 * O resolver único da coorte de uma disciplina (§ver docblock de ClassCohort):
 * quem entra na análise, à mesma matrícula (aluno, turma, disciplina) e à
 * mesma data que as ações de SubjectParticipationTest já exercitam.
 */
class ClassCohortTest extends SubjectParticipationTestCase
{
    /**
     * O mínimo que o motor precisa para calcular: um perfil ativo com um só
     * domínio a 100%.
     */
    protected function profileVersionFor($class): AssessmentProfileVersion
    {
        return $this->inTenant($this->organization, function () use ($class) {
            $profile = AssessmentProfile::factory()->recycle($this->organization)->create([
                'academic_year_id' => $class->academic_year_id,
                'subject_id' => $class->subject_id,
            ]);

            $version = AssessmentProfileVersion::factory()->recycle($this->organization)->create([
                'assessment_profile_id' => $profile->id,
                'scale_id' => Scale::query()->firstOrFail()->id,
                'status' => ProfileVersionStatus::Active,
                'version_number' => 1,
            ]);

            $domain = Domain::factory()->recycle($this->organization)->create(['subject_id' => $class->subject_id]);
            $version->domains()->create(['domain_id' => $domain->id, 'weight_percent' => 100, 'sequence' => 1]);

            $class->forceFill(['assessment_profile_version_id' => $version->id])->save();

            return $version->refresh();
        });
    }

    /**
     * @return array{instrument: Instrument, item: InstrumentItem}
     */
    protected function instrumentAppliedOn($class, AcademicPeriod $period, string $appliedOn): array
    {
        return $this->inTenant($this->organization, function () use ($class, $period, $appliedOn): array {
            $instrument = Instrument::factory()->recycle($this->organization)->create([
                'class_id' => $class->id,
                'academic_period_id' => $period->id,
                'applied_on' => $appliedOn,
            ]);
            $item = InstrumentItem::factory()->recycle($this->organization)->create([
                'instrument_id' => $instrument->id,
                'points_possible' => 10,
            ]);

            $domainId = $class->fresh()->profileVersion->domains()->value('domain_id');
            $item->domainAllocations()->create([
                'domain_id' => $domainId,
                'allocation_percent' => 100,
            ]);

            return ['instrument' => $instrument->refresh(), 'item' => $item->fresh()];
        });
    }

    protected function score($instrumentItem, $enrollment, float $pointsEarned): void
    {
        $this->inTenant($this->organization, function () use ($instrumentItem, $enrollment, $pointsEarned): void {
            StudentItemScore::create([
                'instrument_id' => $instrumentItem['instrument']->id,
                'instrument_item_id' => $instrumentItem['item']->id,
                'enrollment_id' => $enrollment->id,
                'result_state' => ResultState::Assessed,
                'points_earned' => $pointsEarned,
            ]);
        });
    }

    /**
     * M4: A PREMISSA ORIGINAL DESTE TESTE JÁ NÃO É VERDADE. Antes da decisão
     * do product owner (regra das duas perguntas), `BuildClassElements`
     * excluía a matrícula não-frequentante EM BLOCO, e este teste só chamava
     * `for()` com uma coleção de períodos VAZIA — nunca exercitava
     * `by_student` de facto, e o seu comentário afirmava uma exclusão em
     * bloco que o próprio ficheiro já não faz (ver o docblock de
     * `BuildClassElements`/`ClassCohort`). Substituído, em vez de corrigido no
     * lugar: a regra atual é por ELEMENTO, decidida pela data de cada um, e é
     * isso que este teste tem de provar.
     */
    #[Test]
    public function build_class_elements_decides_eligibility_per_element_by_its_own_date(): void
    {
        $class = $this->schoolClassFor();
        $this->profileVersionFor($class);
        $notAttending = $this->enrollStudent($class);

        $period = $this->inTenant($this->organization, fn () => AcademicPeriod::factory()->recycle($this->organization)->create([
            'academic_year_id' => $class->academic_year_id,
            'sequence' => 1,
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-12-15',
        ]));

        // Instrumento ANTES da janela abrir — a evidência é dele.
        $inWindow = $this->instrumentAppliedOn($class, $period, '2026-09-20');
        $this->score($inWindow, $notAttending, 10);

        $this->inTenant($this->organization, function () use ($notAttending): void {
            app(MarkNotAttendingSubject::class)->execute(
                $notAttending,
                SubjectParticipationReason::AlternativeSubject,
                '2026-10-01',
                reasonDetail: 'PLNM',
            );
        });

        // Instrumento DEPOIS da janela abrir — não é dele.
        $outOfWindow = $this->instrumentAppliedOn($class, $period, '2026-10-10');
        $this->score($outOfWindow, $notAttending, 5);

        $this->inTenant($this->organization, function () use ($class, $period, $notAttending): void {
            $result = app(BuildClassElements::class)->for($class, collect([$period]));

            $rows = collect($result['by_student'][$notAttending->getKey()])->keyBy('instrument_id');
            $inWindowId = (int) $result['elements'][0]['instrument_id'];
            $outOfWindowId = (int) $result['elements'][1]['instrument_id'];

            // A evidência de antes da janela continua dele, e não é um zero.
            $this->assertTrue($rows[$inWindowId]['applicable']);
            $this->assertNotNull($rows[$inWindowId]['normalized_value']);

            // A evidência de depois da janela não é dele — marcada
            // `applicable = false`, nunca `0` e nunca omitida.
            $this->assertFalse($rows[$outOfWindowId]['applicable']);
            $this->assertNull($rows[$outOfWindowId]['normalized_value']);
        });
    }

    /**
     * M4: A PREMISSA ORIGINAL TAMBÉM JÁ NÃO É VERDADE. O teste original nunca
     * invocava `ClassResultsCalculator` — só voltava a ler `ClassCohort` e
     * afirmava contagens de `StudentItemScore`/`Instrument` de fixtures que
     * ele próprio nunca criava, o que era sempre verdade e nada provava.
     * Substituído por um exercício real do calculador: a evidência dentro da
     * janela entra no resultado do período, a de fora não — e não como zero.
     */
    #[Test]
    public function class_results_calculator_includes_in_window_evidence_and_excludes_out_of_window_evidence(): void
    {
        $class = $this->schoolClassFor();
        $this->profileVersionFor($class);
        $notAttending = $this->enrollStudent($class);

        $period = $this->inTenant($this->organization, fn () => AcademicPeriod::factory()->recycle($this->organization)->create([
            'academic_year_id' => $class->academic_year_id,
            'sequence' => 1,
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-12-15',
        ]));

        $inWindow = $this->instrumentAppliedOn($class, $period, '2026-09-20');
        $this->score($inWindow, $notAttending, 10);

        $this->inTenant($this->organization, function () use ($notAttending): void {
            app(MarkNotAttendingSubject::class)->execute(
                $notAttending,
                SubjectParticipationReason::AlternativeSubject,
                '2026-10-01',
                reasonDetail: 'PLNM',
            );
        });

        $this->inTenant($this->organization, function () use ($class, $period, $notAttending): void {
            $result = app(ClassResultsCalculator::class)->forPeriod($class, $period, AssessmentCutoff::none());

            $row = collect($result)->first(
                fn (array $entry): bool => $entry['enrollment']->getKey() === $notAttending->getKey(),
            );

            $this->assertNotNull($row);
            // A única evidência que o motor via era a de dentro da janela —
            // por isso o resultado do período existe e não é um zero.
            $this->assertNotNull($row['outcome']->normalizedValue);
        });
    }

    #[Test]
    public function the_same_student_is_present_in_build_results_progression_labelled_not_absent(): void
    {
        $class = $this->schoolClassFor();
        $enrollment = $this->enrollStudent($class);

        $this->inTenant($this->organization, function () use ($class): void {
            AcademicPeriod::factory()->recycle($this->organization)->create([
                'academic_year_id' => $class->academic_year_id,
                'sequence' => 1,
                'starts_on' => '2026-09-01',
                'ends_on' => '2026-12-15',
            ]);
        });

        $this->inTenant($this->organization, function () use ($enrollment): void {
            app(MarkNotAttendingSubject::class)->execute(
                $enrollment,
                SubjectParticipationReason::AlternativeSubject,
                // ANTES de «hoje» (frozen em 2026-10-15) — a janela já abriu,
                // por isso continua a valer mesmo com o período ainda em
                // curso lido à data de hoje (M4/ClassCohort::asOfPeriod()). Uma
                // data futura, como '2026-11-15' era antes deste teste, ainda
                // não teria aberto e não pode marcar o aluno hoje — essa é
                // exatamente a distorção que M4 corrige.
                '2026-10-01',
                reasonDetail: 'PLNM',
            );
        });

        $this->inTenant($this->organization, function () use ($class, $enrollment): void {
            $progression = app(BuildResultsProgression::class)->for($class);

            $rows = collect($progression['students'])->firstWhere('enrollment_id', $enrollment->getKey());

            $this->assertNotNull($rows, 'o aluno tem de continuar na lista de Resultados, nunca omitido');
            $this->assertSame($enrollment->getKey(), $rows['enrollment_id']);

            $periodRow = $rows['periods'][0] ?? null;

            $this->assertNotNull($periodRow);
            $this->assertNotNull(
                $periodRow['participation'],
                'o período que cobre a janela de não-frequência tem de a rotular, nunca omitir o aluno',
            );
            $this->assertSame('not_attending', $periodRow['participation']['state']);
            $this->assertSame('PLNM', $periodRow['participation']['reason_detail']);
            $this->assertSame('2026-10-01', $periodRow['participation']['since']);

            // NUNCA representado como falta, nem como «não participou»: os
            // campos de resultado do período continuam a existir, e nenhum
            // deles é sobreposto pela participação.
            $this->assertArrayHasKey('weighted_average', $periodRow);
        });
    }

    #[Test]
    public function other_subject_of_the_same_class_label_keeps_its_student_in_the_cohort(): void
    {
        $portuguese = $this->schoolClassFor(label: '8.º F');
        $plnm = $this->sameLabelDifferentSubject($portuguese);

        $portugueseEnrollment = $this->enrollStudent($portuguese);
        $plnmEnrollment = $this->sameStudentEnrolledIn($plnm, $portugueseEnrollment);

        $this->inTenant($this->organization, function () use ($portugueseEnrollment): void {
            app(MarkNotAttendingSubject::class)->execute(
                $portugueseEnrollment,
                SubjectParticipationReason::AlternativeSubject,
                '2026-11-15',
                reasonDetail: 'PLNM',
            );
        });

        $this->inTenant($this->organization, function () use ($plnm, $plnmEnrollment): void {
            $ids = ClassCohort::for($plnm)->attending()->pluck('id')->all();

            $this->assertContains($plnmEnrollment->getKey(), $ids);
        });
    }

    #[Test]
    public function the_answer_is_as_of_a_date_never_rewriting_the_past(): void
    {
        $class = $this->schoolClassFor();
        $enrollment = $this->enrollStudent($class);

        $this->inTenant($this->organization, function () use ($enrollment): void {
            app(MarkNotAttendingSubject::class)->execute(
                $enrollment,
                SubjectParticipationReason::AlternativeSubject,
                '2027-01-15',
                reasonDetail: 'PLNM',
            );
        });

        $this->inTenant($this->organization, function () use ($class, $enrollment): void {
            $before = ClassCohort::for($class, '2026-11-20');
            $after = ClassCohort::for($class, '2027-03-10');

            $this->assertContains($enrollment->getKey(), $before->attending()->pluck('id')->all());
            $this->assertNotContains($enrollment->getKey(), $after->attending()->pluck('id')->all());

            $this->assertContains($enrollment->getKey(), $after->notAttending()->pluck('id')->all());
            $this->assertNotContains($enrollment->getKey(), $before->notAttending()->pluck('id')->all());
        });
    }

    #[Test]
    public function reactivation_returns_the_student_to_attending_from_the_reactivation_date(): void
    {
        $class = $this->schoolClassFor();
        $enrollment = $this->enrollStudent($class);

        $this->inTenant($this->organization, function () use ($enrollment): void {
            app(MarkNotAttendingSubject::class)->execute(
                $enrollment,
                SubjectParticipationReason::AlternativeSubject,
                '2026-11-15',
                reasonDetail: 'PLNM',
            );

            app(ReactivateSubjectParticipation::class)->execute($enrollment, '2027-01-02');
        });

        $this->inTenant($this->organization, function () use ($class, $enrollment): void {
            $stillNotAttending = ClassCohort::for($class, '2027-01-01');
            $attendingAgain = ClassCohort::for($class, '2027-01-02');

            $this->assertContains($enrollment->getKey(), $stillNotAttending->notAttending()->pluck('id')->all());
            $this->assertContains($enrollment->getKey(), $attendingAgain->attending()->pluck('id')->all());
        });
    }

    #[Test]
    public function not_attending_origins_lists_the_distinct_reason_details(): void
    {
        $class = $this->schoolClassFor();
        $first = $this->enrollStudent($class);
        $second = $this->enrollStudent($class);

        $this->inTenant($this->organization, function () use ($first, $second): void {
            app(MarkNotAttendingSubject::class)->execute(
                $first,
                SubjectParticipationReason::AlternativeSubject,
                '2026-11-15',
                reasonDetail: 'PLNM',
            );
            app(MarkNotAttendingSubject::class)->execute(
                $second,
                SubjectParticipationReason::AlternativeSubject,
                '2026-11-15',
                reasonDetail: 'PLNM',
            );
        });

        $this->inTenant($this->organization, function () use ($class): void {
            $this->assertSame(['PLNM'], ClassCohort::for($class, '2026-12-01')->notAttendingOrigins());
        });
    }

    #[Test]
    public function ordering_by_class_number_is_preserved(): void
    {
        $class = $this->schoolClassFor();

        $third = $this->enrollStudent($class);
        $third->forceFill(['class_number' => 3])->save();
        $first = $this->enrollStudent($class);
        $first->forceFill(['class_number' => 1])->save();
        $second = $this->enrollStudent($class);
        $second->forceFill(['class_number' => 2])->save();

        $this->inTenant($this->organization, function () use ($class, $first, $second, $third): void {
            $ordered = ClassCohort::for($class)->all()->pluck('id')->all();

            $this->assertSame(
                [$first->getKey(), $second->getKey(), $third->getKey()],
                $ordered,
            );
        });
    }

    #[Test]
    public function for_universe_all_class_students_includes_the_non_attending_student(): void
    {
        $class = $this->schoolClassFor();
        $enrollment = $this->enrollStudent($class);

        $this->inTenant($this->organization, function () use ($enrollment): void {
            app(MarkNotAttendingSubject::class)->execute(
                $enrollment,
                SubjectParticipationReason::AlternativeSubject,
                '2026-11-15',
                reasonDetail: 'PLNM',
            );
        });

        $this->inTenant($this->organization, function () use ($class, $enrollment): void {
            $cohort = ClassCohort::for($class, '2026-12-01');

            $this->assertContains($enrollment->getKey(), $cohort->forUniverse(CohortUniverse::AllClassStudents)->pluck('id')->all());
            $this->assertNotContains($enrollment->getKey(), $cohort->forUniverse(CohortUniverse::AttendingOnly)->pluck('id')->all());
            $this->assertFalse($cohort->isAttending($enrollment));
            $this->assertNotNull($cohort->participationOf($enrollment));
        });
    }
}
