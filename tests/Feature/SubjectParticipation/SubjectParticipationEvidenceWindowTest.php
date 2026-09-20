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
use App\Services\Assessment\BuildClassStatistics;
use App\Services\Assessment\ClassResultsCalculator;
use PHPUnit\Framework\Attributes\Test;

/**
 * DECISÃO DO PRODUCT OWNER (regra das duas perguntas): uma janela de
 * não-frequência NUNCA apaga retroativamente evidência produzida enquanto o
 * aluno ainda frequentava. Este ficheiro exercita exatamente essa regra sobre
 * `BuildClassElements` e `ClassResultsCalculator` — os dois sítios que antes
 * excluíam a matrícula em bloco (`ClassCohort::for()->attending()`) e que
 * agora recebem todas as matrículas (`->all()`) e decidem a elegibilidade
 * ELEMENTO A ELEMENTO, pela data do próprio elemento
 * (`ClassCohort::wasAttendingOn()`).
 *
 * NUNCA PRO-RATA, NUNCA DENOMINADOR FRACIONÁRIO: cada afirmação abaixo conta
 * elementos e alunos, sempre em inteiros.
 */
class SubjectParticipationEvidenceWindowTest extends SubjectParticipationTestCase
{
    /**
     * @return array{period1: AcademicPeriod, period2: AcademicPeriod}
     */
    protected function twoPeriodsFor($class): array
    {
        return $this->inTenant($this->organization, function () use ($class): array {
            $period1 = AcademicPeriod::factory()->recycle($this->organization)->create([
                'academic_year_id' => $class->academic_year_id,
                'sequence' => 1,
                'starts_on' => '2026-09-01',
                'ends_on' => '2026-10-01',
            ]);
            $period2 = AcademicPeriod::factory()->recycle($this->organization)->create([
                'academic_year_id' => $class->academic_year_id,
                'sequence' => 2,
                'starts_on' => '2026-10-02',
                'ends_on' => '2027-06-30',
            ]);

            return ['period1' => $period1, 'period2' => $period2];
        });
    }

    /**
     * Um perfil de avaliação com um só domínio a 100% — o mínimo que o motor
     * precisa para calcular, sem nada que a regra sob teste não exija.
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

    #[Test]
    public function evidence_before_the_suspension_still_counts_in_build_class_elements(): void
    {
        $class = $this->schoolClassFor();
        $periods = $this->twoPeriodsFor($class);
        $this->profileVersionFor($class);

        $attending = $this->enrollStudent($class);
        $target = $this->enrollStudent($class);

        // Instrumento de outubro, ANTES da janela de não-frequência.
        $october = $this->instrumentAppliedOn($class, $periods['period1'], '2026-09-20');
        $this->score($october, $attending, 10);
        $this->score($october, $target, 10);

        // Instrumento posterior, DEPOIS da janela abrir.
        $later = $this->instrumentAppliedOn($class, $periods['period2'], '2026-10-10');
        $this->score($later, $attending, 5);
        $this->score($later, $target, 5);

        $this->inTenant($this->organization, function () use ($target): void {
            app(MarkNotAttendingSubject::class)->execute(
                $target,
                SubjectParticipationReason::AlternativeSubject,
                '2026-10-01',
                reasonDetail: 'PLNM',
            );
        });

        $this->inTenant($this->organization, function () use ($class, $periods, $attending, $target): void {
            $result = app(BuildClassElements::class)->for($class, collect([$periods['period1'], $periods['period2']]));

            // NUNCA EXCLUÍDO EM BLOCO: o aluno em janela continua na lista.
            $this->assertArrayHasKey($target->getKey(), $result['by_student']);
            $this->assertArrayHasKey($attending->getKey(), $result['by_student']);

            $targetRows = collect($result['by_student'][$target->getKey()])->keyBy('instrument_id');
            $attendingRows = collect($result['by_student'][$attending->getKey()])->keyBy('instrument_id');

            $octoberId = (int) $result['elements'][0]['instrument_id'];
            $laterId = (int) $result['elements'][1]['instrument_id'];

            // O CASO NUCLEAR: a evidência de outubro continua dele, apesar da
            // janela ter aberto depois — não é ineligível, e o valor coincide
            // com o do aluno que nunca deixou de frequentar.
            $this->assertTrue($targetRows[$octoberId]['applicable']);
            $this->assertNotNull($targetRows[$octoberId]['normalized_value']);
            $this->assertSame(
                $attendingRows[$octoberId]['normalized_value'],
                $targetRows[$octoberId]['normalized_value'],
            );

            // A evidência posterior à abertura da janela NÃO é dele — e não é
            // um zero: fica marcada `applicable = false`, nunca `0`.
            $this->assertFalse($targetRows[$laterId]['applicable']);
            $this->assertNull($targetRows[$laterId]['normalized_value']);
            // M3: a matrícula continua ativa (nunca saiu) — quem exclui este
            // elemento é a janela de não-frequência, não a matrícula. A
            // frase tem de dizer isso e não «fora do período de matrícula»,
            // que seria falso de um aluno matriculado.
            $this->assertSame(
                'Não aplicável — não frequentava a disciplina nesta data',
                $targetRows[$laterId]['state_label'],
            );

            // O aluno que nunca teve janela continua elegível nos dois.
            $this->assertTrue($attendingRows[$octoberId]['applicable']);
            $this->assertTrue($attendingRows[$laterId]['applicable']);
        });
    }

    #[Test]
    public function accumulated_result_keeps_evidence_from_before_the_suspension_but_not_after(): void
    {
        $class = $this->schoolClassFor();
        $periods = $this->twoPeriodsFor($class);
        $this->profileVersionFor($class);

        $target = $this->enrollStudent($class);

        $october = $this->instrumentAppliedOn($class, $periods['period1'], '2026-09-20');
        $this->score($october, $target, 8);

        $later = $this->instrumentAppliedOn($class, $periods['period2'], '2026-10-10');
        $this->score($later, $target, 2);

        $this->inTenant($this->organization, function () use ($target): void {
            app(MarkNotAttendingSubject::class)->execute(
                $target,
                SubjectParticipationReason::AlternativeSubject,
                '2026-10-01',
                reasonDetail: 'PLNM',
            );
        });

        $this->inTenant($this->organization, function () use ($class, $periods, $target): void {
            $calculator = app(ClassResultsCalculator::class);

            $periodResult = collect($calculator->forPeriod($class, $periods['period1']))
                ->firstWhere('enrollment.id', $target->getKey());
            $accumulatedResult = collect($calculator->forAccumulated($class, $periods['period2']))
                ->firstWhere('enrollment.id', $target->getKey());

            // O aluno continua a aparecer nas duas leituras — nunca excluído
            // em bloco por uma janela que ainda estava por abrir em outubro.
            $this->assertNotNull($periodResult);
            $this->assertNotNull($accumulatedResult);

            // O resultado do período 1 (só o instrumento de outubro, ainda
            // dentro da janela de frequência) tem de existir e não ser nulo.
            $this->assertNotNull($periodResult['outcome']->normalizedValue);

            // O acumulado ao período 2 é IGUAL ao do período 1: o único
            // elemento que entra é o de outubro — o instrumento posterior à
            // suspensão não é somado, mas também não arrasta o de outubro
            // com ele.
            $this->assertSame(
                $periodResult['outcome']->normalizedValue,
                $accumulatedResult['outcome']->normalizedValue,
            );
        });
    }

    #[Test]
    public function domain_analysis_of_the_earlier_period_still_shows_the_student(): void
    {
        $class = $this->schoolClassFor();
        $periods = $this->twoPeriodsFor($class);
        $version = $this->profileVersionFor($class);
        $domainId = (int) $version->domains()->value('domain_id');

        $target = $this->enrollStudent($class);

        $october = $this->instrumentAppliedOn($class, $periods['period1'], '2026-09-20');
        $this->score($october, $target, 9);

        $this->inTenant($this->organization, function () use ($target): void {
            app(MarkNotAttendingSubject::class)->execute(
                $target,
                SubjectParticipationReason::AlternativeSubject,
                '2026-10-01',
                reasonDetail: 'PLNM',
            );
        });

        $this->inTenant($this->organization, function () use ($class, $periods, $target, $domainId): void {
            $result = collect(app(ClassResultsCalculator::class)->forPeriod($class, $periods['period1']))
                ->firstWhere('enrollment.id', $target->getKey());

            $this->assertNotNull($result);

            $domainOutcome = collect($result['outcome']->domains)
                ->first(fn ($domain) => (int) $domain->domainId === $domainId);

            // O domínio da evidência anterior continua a aparecer para ele —
            // não é apagado pela janela que só abriu depois.
            $this->assertNotNull($domainOutcome);
            $this->assertNotNull($domainOutcome->normalizedValue);
        });
    }

    /**
     * M5: A VERSÃO ORIGINAL DESTE TESTE ERA VAZIA — `assertIsInt(count(...))`
     * é sempre verdade e `assertIsBool()` também, e os dois passariam com a
     * funcionalidade inteira removida. Substituído para provar de facto os
     * casos 8/9 do product owner: um aluno com frequência PARCIAL de um
     * período tem de poder estar DENTRO da análise por domínio (pergunta A,
     * decidida pela data da evidência) e FORA do balanço final do período
     * (pergunta B, decidida pelo cohort à data de referência) — SIMULTANEAMENTE
     * — e todos os denominadores envolvidos, sob os dois universos, são
     * inteiros exatos, nunca uma fração de aluno.
     */
    #[Test]
    public function partial_attendance_stays_in_the_domain_analysis_and_out_of_the_final_balance_as_whole_students(): void
    {
        $class = $this->schoolClassFor();
        $periods = $this->twoPeriodsFor($class);
        $this->profileVersionFor($class);

        $attendingFull = $this->enrollStudent($class);
        $partial = $this->enrollStudent($class);

        // Evidência DENTRO da janela em que `$partial` ainda frequentava —
        // tem de continuar válida na análise por domínio (regra A).
        $october = $this->instrumentAppliedOn($class, $periods['period1'], '2026-09-10');
        $this->score($october, $attendingFull, 8);
        $this->score($october, $partial, 6);

        // A janela abre A MEIO do período 1 (2026-09-01 a 2026-10-01) — a
        // frequência de `$partial` neste período é genuinamente parcial.
        $this->inTenant($this->organization, function () use ($partial): void {
            app(MarkNotAttendingSubject::class)->execute(
                $partial,
                SubjectParticipationReason::AlternativeSubject,
                '2026-09-20',
                reasonDetail: 'PLNM',
            );
        });

        $this->inTenant($this->organization, function () use ($class, $periods): void {
            foreach ([CohortUniverse::AttendingOnly, CohortUniverse::AllClassStudents] as $universe) {
                $statistics = app(BuildClassStatistics::class)->for(
                    $class,
                    $periods['period1'],
                    universe: $universe,
                );

                // REGRA B (balanço final): à data de referência (o fim do
                // período), `$partial` não frequenta e não tem resultado
                // externo nenhum — fica fora do denominador do balanço, sob
                // os DOIS universos (sem resultado externo, `AllClassStudents`
                // também não o inclui — H1).
                $this->assertIsInt($statistics['summary']['students_total']);
                $this->assertSame(1, $statistics['summary']['students_total']);

                // REGRA A (análise por domínio): `$partial` TEM dados de
                // domínio (a evidência de 10/09, dentro da janela) — por
                // isso entra no denominador da análise por domínio, ao lado
                // de `$attendingFull`. NUNCA o cohort de referência a decidir
                // aqui.
                $this->assertIsInt($statistics['summary']['domain_students_total']);
                $this->assertSame(2, $statistics['summary']['domain_students_total']);

                $this->assertCount(1, $statistics['domain_statistics']);
                $this->assertIsInt($statistics['domain_statistics'][0]['students_with_result']);
                $this->assertSame(2, $statistics['domain_statistics'][0]['students_with_result']);

                // As duas notas nunca se contradizem: `$partial` TEM dados de
                // domínio, por isso não é «excluído por falta de dados» — e
                // TEM frequência parcial genuína, por isso a nota neutra do
                // §6 aparece.
                $this->assertNull($statistics['notes']['domain_exclusion']);
                $this->assertNotNull($statistics['notes']['partial_period_attendance']);

                // Nenhum denominador de sucesso/distribuição é uma fração.
                $this->assertIsInt($statistics['summary']['success']['placed']);
                foreach ($statistics['distribution'] as $band) {
                    $this->assertIsInt($band['count']);
                }
            }
        });
    }

    /**
     * M6: A MAIOR REGRESSÃO POSSÍVEL DESTA FEATURE NÃO TINHA GUARDA NENHUMA —
     * nenhum teste garantia que uma turma SEM nenhuma linha de
     * `subject_participations` continuava a comportar-se exatamente como
     * antes desta funcionalidade existir.
     */
    #[Test]
    public function a_class_with_no_participation_rows_is_completely_unaffected(): void
    {
        $class = $this->schoolClassFor();
        $periods = $this->twoPeriodsFor($class);
        $this->profileVersionFor($class);

        $first = $this->enrollStudent($class);
        $second = $this->enrollStudent($class);

        $october = $this->instrumentAppliedOn($class, $periods['period1'], '2026-09-10');
        $this->score($october, $first, 10);
        $this->score($october, $second, 5);

        $this->inTenant($this->organization, function () use ($class, $periods, $first, $second): void {
            $elements = app(BuildClassElements::class)->for($class, collect([$periods['period1']]));

            // Todos os alunos presentes, todos os elementos aplicáveis.
            foreach ([$first, $second] as $enrollment) {
                foreach ($elements['by_student'][$enrollment->getKey()] as $row) {
                    $this->assertTrue($row['applicable']);
                }
            }

            $statistics = app(BuildClassStatistics::class)->for($class, $periods['period1']);

            $this->assertSame(2, $statistics['summary']['students_total']);
            $this->assertSame(2, $statistics['summary']['domain_students_total']);
            $this->assertSame(0, $statistics['cohort']['not_attending_count']);
            $this->assertSame([], $statistics['cohort']['not_attending_origins']);
            $this->assertSame(0, $statistics['cohort']['external_included_count']);
            $this->assertSame(0, $statistics['cohort']['not_attending_without_result']);

            $this->assertNull($statistics['notes']['domain_exclusion']);
            $this->assertNull($statistics['notes']['external_inclusion']);
            $this->assertNull($statistics['notes']['partial_period_attendance']);
        });
    }

    /**
     * A RE-ENTRADA A MEIO DO PERÍODO TAMBÉM É FREQUÊNCIA PARCIAL.
     *
     * O aluno deixa de frequentar a 5 de setembro e VOLTA a 11 — dentro do
     * mesmo período. À data de referência do balanço ele já frequenta outra
     * vez, portanto `period.participation` é null e o cohort da pergunta (B)
     * não o distingue de ninguém. A frequência dele neste período foi, ainda
     * assim, parcial, e a nota do §6 tem de o dizer.
     *
     * ESTE CASO ERA INALCANÇÁVEL. Enquanto os candidatos à nota eram semeados
     * a partir de `participation !== null`, uma janela FECHADA nunca era
     * sequer consultada: só se olhava para quem não frequentava HOJE. Sem
     * este teste, a correção que passou a semear a partir de todas as
     * matrículas podia ser revertida sem nada ficar vermelho.
     */
    #[Test]
    public function a_window_that_opens_and_closes_inside_the_period_is_partial_attendance_too(): void
    {
        $class = $this->schoolClassFor();
        $periods = $this->twoPeriodsFor($class);
        $this->profileVersionFor($class);

        $attendingFull = $this->enrollStudent($class);
        $returned = $this->enrollStudent($class);

        // Evidência de 2 de setembro: ambos frequentavam nesse dia.
        $instrument = $this->instrumentAppliedOn($class, $periods['period1'], '2026-09-02');
        $this->score($instrument, $attendingFull, 8);
        $this->score($instrument, $returned, 7);

        $this->inTenant($this->organization, function () use ($returned): void {
            app(MarkNotAttendingSubject::class)->execute(
                $returned,
                SubjectParticipationReason::AlternativeSubject,
                '2026-09-05',
                reasonDetail: 'PLNM',
            );

            // Volta a 11 — a janela fecha a 10 (inclusivo).
            app(ReactivateSubjectParticipation::class)->execute($returned, '2026-09-11');
        });

        $this->inTenant($this->organization, function () use ($class, $periods): void {
            $statistics = app(BuildClassStatistics::class)->for($class, $periods['period1']);

            // Pergunta (B): à data de referência ele FREQUENTA — voltou.
            // Não é um aluno «que não frequenta», e nenhum contador o trata
            // como tal.
            $this->assertSame(0, $statistics['cohort']['not_attending_count']);
            $this->assertSame(0, $statistics['cohort']['not_attending_without_result']);
            $this->assertNull($statistics['notes']['domain_exclusion']);

            // Pergunta (A): a evidência de 2 de setembro é dele e conta.
            $this->assertSame(2, $statistics['summary']['students_total']);
            $this->assertSame(2, $statistics['summary']['domain_students_total']);

            // §6: e, mesmo assim, a frequência foi parcial — e é dito.
            $this->assertNotNull($statistics['notes']['partial_period_attendance']);
            $this->assertStringContainsString(
                'apenas durante parte do período',
                $statistics['notes']['partial_period_attendance'],
            );
            // A regra transversal das exportações, também aqui.
            $this->assertStringNotContainsStringIgnoringCase(
                'lapispro',
                $statistics['notes']['partial_period_attendance'],
            );
        });
    }
}
