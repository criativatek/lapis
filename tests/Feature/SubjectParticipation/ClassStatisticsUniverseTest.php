<?php

namespace Tests\Feature\SubjectParticipation;

use App\Actions\SubjectParticipation\MarkNotAttendingSubject;
use App\Models\AcademicPeriod;
use App\Models\AssessmentProfile;
use App\Models\AssessmentProfileVersion;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\ClassificationStatus;
use App\Models\CohortUniverse;
use App\Models\Enrollment;
use App\Models\ExternalSubjectResult;
use App\Models\ProfileVersionStatus;
use App\Models\Scale;
use App\Models\SchoolClass;
use App\Models\SubjectParticipationReason;
use App\Services\Assessment\BuildClassStatistics;
use App\Services\Assessment\ConfirmClassification;
use PHPUnit\Framework\Attributes\Test;

/**
 * O universo da análise (req 8/9/10/12) — `AttendingOnly` por omissão,
 * `AllClassStudents` a acrescentar quem não frequenta com um resultado
 * externo compatível.
 *
 * O CENÁRIO É DELIBERADAMENTE SEM EVIDÊNCIA NENHUMA: nenhum instrumento,
 * nenhuma classificação atribuída a ninguém que frequenta. Isto isola o que
 * está a ser testado — o resultado externo de quem não frequenta — de toda a
 * máquina de avaliação normal, que já tem os seus próprios testes em
 * ClassStatisticsTest.
 */
class ClassStatisticsUniverseTest extends SubjectParticipationTestCase
{
    private function activeScale(): Scale
    {
        return Scale::where('name', 'Escala 1 a 5')->with('levels')->firstOrFail();
    }

    /**
     * @return array{class: SchoolClass, period: AcademicPeriod, attending: Enrollment, notAttending: Enrollment}
     */
    private function scenario(): array
    {
        return $this->inTenant($this->organization, function (): array {
            $class = $this->schoolClassFor();

            $period = AcademicPeriod::factory()->recycle($this->organization)->create([
                'academic_year_id' => $class->academic_year_id,
                'sequence' => 1,
                'starts_on' => self::YEAR_STARTS_ON,
                'ends_on' => '2026-12-15',
            ]);

            $scale = $this->activeScale();

            $profile = AssessmentProfile::factory()->recycle($this->organization)->create([
                'academic_year_id' => $class->academic_year_id,
                'subject_id' => $class->subject_id,
            ]);

            $version = AssessmentProfileVersion::factory()->recycle($this->organization)->create([
                'assessment_profile_id' => $profile->id,
                'scale_id' => $scale->id,
                'status' => ProfileVersionStatus::Active,
            ]);

            $class->forceFill(['assessment_profile_version_id' => $version->id])->save();

            $attending = $this->enrollStudent($class);
            $notAttending = $this->enrollStudent($class);

            app(MarkNotAttendingSubject::class)->execute(
                $notAttending,
                SubjectParticipationReason::AlternativeSubject,
                self::YEAR_STARTS_ON,
                reasonDetail: 'PLNM',
            );

            return compact('class', 'period', 'attending', 'notAttending');
        });
    }

    private function externalResultFor(Enrollment $enrollment, string $levelCode): ExternalSubjectResult
    {
        $level = $this->activeScale()->levels->firstWhere('code', $levelCode);

        return $this->inTenant($this->organization, fn (): ExternalSubjectResult => ExternalSubjectResult::factory()
            ->recycle($this->organization)
            ->create([
                'enrollment_id' => $enrollment->id,
                'period_id' => null,
                'origin' => 'PLNM',
                'scale_level_id' => $level->id,
                'level_code' => null,
                'numeric_value' => null,
                'recorded_on' => '2026-09-14',
            ]));
    }

    private function statisticsFor(SchoolClass $class, AcademicPeriod $period, CohortUniverse $universe): array
    {
        return $this->inTenant(
            $this->organization,
            fn (): array => app(BuildClassStatistics::class)->for($class, $period, universe: $universe),
        );
    }

    #[Test]
    public function attending_only_is_the_default_and_excludes_the_non_attending_student(): void
    {
        ['class' => $class, 'period' => $period] = $this->scenario();

        $statistics = $this->statisticsFor($class, $period, CohortUniverse::AttendingOnly);

        $this->assertSame('attending_only', $statistics['universe']['value']);
        $this->assertSame(1, $statistics['summary']['students_total']);
        $this->assertSame(1, $statistics['cohort']['not_attending_count']);
        $this->assertSame(['PLNM'], $statistics['cohort']['not_attending_origins']);
        $this->assertSame(0, $statistics['cohort']['external_included_count']);
        $this->assertNull($statistics['notes']['external_inclusion']);
    }

    #[Test]
    public function all_class_students_shows_a_bigger_list_but_never_a_bigger_denominator_without_a_result(): void
    {
        ['class' => $class, 'period' => $period] = $this->scenario();

        $attendingOnly = $this->statisticsFor($class, $period, CohortUniverse::AttendingOnly);
        $allStudents = $this->statisticsFor($class, $period, CohortUniverse::AllClassStudents);

        $this->assertSame(1, $attendingOnly['summary']['students_total']);
        $this->assertSame('all_class_students', $allStudents['universe']['value']);

        // A LISTA cresce — o aluno que não frequenta continua visível, rotulado.
        $this->assertCount(2, $allStudents['students']);

        // H1: O DENOMINADOR NÃO CRESCE por si só. Sem resultado externo, quem
        // não frequenta fica fora de `students_total` tal como fica fora de
        // toda a outra fração — nunca «sem classificação».
        $this->assertSame(1, $allStudents['summary']['students_total']);
        $this->assertSame(1, $allStudents['cohort']['not_attending_without_result']);
    }

    #[Test]
    public function a_compatible_external_result_counts_toward_success_only_under_all_class_students(): void
    {
        ['class' => $class, 'period' => $period, 'notAttending' => $notAttending] = $this->scenario();

        // «Bom», is_negative = false.
        $this->externalResultFor($notAttending, '4');

        $attendingOnly = $this->statisticsFor($class, $period, CohortUniverse::AttendingOnly);
        $allStudents = $this->statisticsFor($class, $period, CohortUniverse::AllClassStudents);

        // AttendingOnly nunca vê o resultado externo, nem no denominador.
        $this->assertSame(0, $attendingOnly['summary']['success']['placed']);
        $this->assertSame(0, $attendingOnly['cohort']['external_included_count']);

        // AllClassStudents inclui-o, e o nível resolvido é positivo.
        $this->assertSame(1, $allStudents['summary']['success']['placed']);
        $this->assertSame(1, $allStudents['summary']['success']['succeeded']);
        $this->assertSame(0, $allStudents['summary']['success']['failed']);
        $this->assertSame(1, $allStudents['cohort']['external_included_count']);

        $band = collect($allStudents['assigned_distribution']['bands'])->firstWhere('code', '4');
        $this->assertSame(1, $band['count']);

        $this->assertNotNull($allStudents['notes']['external_inclusion']);
        $this->assertStringContainsString('1 aluno avaliado em PLNM', $allStudents['notes']['external_inclusion']);
        $this->assertStringContainsString('com base na classificação final registada', $allStudents['notes']['external_inclusion']);
    }

    #[Test]
    public function a_negative_external_result_counts_as_failure_never_as_a_zero_average(): void
    {
        ['class' => $class, 'period' => $period, 'notAttending' => $notAttending] = $this->scenario();

        // «Insuficiente», is_negative = true.
        $this->externalResultFor($notAttending, '2');

        $allStudents = $this->statisticsFor($class, $period, CohortUniverse::AllClassStudents);

        $this->assertSame(1, $allStudents['summary']['success']['failed']);
        $this->assertSame(0, $allStudents['summary']['success']['succeeded']);

        $student = collect($allStudents['students'])->firstWhere('enrollment_id', $notAttending->id);
        $this->assertNotNull($student);
        $this->assertSame('external', $student['external']['source'] ?? null);
        $this->assertSame('PLNM', $student['external']['origin'] ?? null);
    }

    #[Test]
    public function a_non_attending_student_without_an_external_result_is_never_counted_as_failure_or_zero(): void
    {
        ['class' => $class, 'period' => $period, 'notAttending' => $notAttending] = $this->scenario();

        $allStudents = $this->statisticsFor($class, $period, CohortUniverse::AllClassStudents);

        $this->assertSame(0, $allStudents['summary']['success']['placed']);
        $this->assertSame(0, $allStudents['summary']['success']['failed']);
        $this->assertSame(0, $allStudents['summary']['success']['succeeded']);
        // H1: o não-frequentante sem resultado externo não entra em
        // `without_classification` — o único aluno aí é o que frequenta e
        // ainda não foi classificado (a turma deste cenário não tem
        // instrumentos nenhuns).
        $this->assertSame(1, $allStudents['summary']['success']['without_classification']);
        $this->assertNull($allStudents['summary']['class_average']);
        $this->assertNull($allStudents['summary']['accumulated_average']);
        $this->assertSame(0, $allStudents['cohort']['external_included_count']);

        // H1: FORA DE TODA A FRAÇÃO — nem no numerador nem no denominador de
        // `summary`. `students_total` conta só quem entra na aritmética; a
        // lista visível (abaixo) é outra coisa.
        $this->assertSame(1, $allStudents['summary']['students_total']);
        // O único aluno contado é o que frequenta, e este cenário não lhe dá
        // nenhum instrumento — por isso também ele fica sem resultado. O que
        // importa aqui é que o denominador (`students_total`) não cresceu
        // por causa do não-frequentante sem resultado externo.
        $this->assertSame(1, $allStudents['summary']['students_without_result']);
        $this->assertSame(1, $allStudents['cohort']['not_attending_without_result']);

        // O aluno continua na lista — rotulado, nunca omitido.
        $this->assertCount(2, $allStudents['students']);
        $flagged = collect($allStudents['students'])->firstWhere('enrollment_id', $notAttending->id);
        $this->assertNotNull($flagged);
        $this->assertNull($flagged['classification']);
        $this->assertNull($flagged['external']);
    }

    /**
     * O NOME ANTIGO DESTE TESTE AFIRMAVA A REGRA QUE FOI REVOGADA.
     *
     * Chamava-se «domain_analysis_stays_attending_only_under_both_universes»,
     * que era a regra ANTERIOR à decisão do product owner: a análise por
     * domínio deixou de ser filtrada pelo cohort à data de referência e passa
     * a decidir-se PELOS DADOS que cada aluno tem (pergunta (A)). Um nome
     * assim levaria o próximo leitor a «corrigir» o código de volta.
     *
     * O que este teste verifica — e tudo o que alguma vez verificou — é a nota
     * dinâmica de exclusão, que continua a valer: quem não tem dados neste
     * domínio não entra nos números dele, e o leitor é avisado disso por
     * palavras. A regra nova é garantida por
     * `SubjectParticipationEvidenceWindowTest::partial_attendance_stays_in_the_domain_analysis_and_out_of_the_final_balance_as_whole_students`.
     */
    #[Test]
    public function the_domain_exclusion_note_is_dynamic_under_both_universes(): void
    {
        ['class' => $class, 'period' => $period, 'notAttending' => $notAttending] = $this->scenario();

        $this->externalResultFor($notAttending, '4');

        foreach ([CohortUniverse::AttendingOnly, CohortUniverse::AllClassStudents] as $universe) {
            $statistics = $this->statisticsFor($class, $period, $universe);

            $this->assertNotNull($statistics['notes']['domain_exclusion']);
            $this->assertStringContainsString('1 aluno avaliado em PLNM', $statistics['notes']['domain_exclusion']);
            $this->assertStringContainsString('não existirem dados disponíveis para este domínio', $statistics['notes']['domain_exclusion']);
        }
    }

    #[Test]
    public function the_domain_note_falls_back_to_a_generic_wording_when_no_origin_is_known(): void
    {
        ['class' => $class, 'period' => $period, 'notAttending' => $notAttending] = $this->inTenant(
            $this->organization,
            function (): array {
                $class = $this->schoolClassFor();

                $period = AcademicPeriod::factory()->recycle($this->organization)->create([
                    'academic_year_id' => $class->academic_year_id,
                    'sequence' => 1,
                    'starts_on' => self::YEAR_STARTS_ON,
                    'ends_on' => '2026-12-15',
                ]);

                $attending = $this->enrollStudent($class);
                $notAttending = $this->enrollStudent($class);

                // SubjectParticipationReason::Other, sem `reason_detail` — a
                // única proveniência que este cenário conhece é nenhuma.
                app(MarkNotAttendingSubject::class)->execute(
                    $notAttending,
                    SubjectParticipationReason::Other,
                    self::YEAR_STARTS_ON,
                );

                return compact('class', 'period', 'attending', 'notAttending');
            },
        );

        $statistics = $this->statisticsFor($class, $period, CohortUniverse::AttendingOnly);

        $this->assertSame([], $statistics['cohort']['not_attending_origins']);
        $this->assertStringContainsString(
            '1 aluno que não frequenta esta disciplina',
            $statistics['notes']['domain_exclusion'],
        );
    }

    #[Test]
    public function no_new_note_ever_names_the_product(): void
    {
        ['class' => $class, 'period' => $period, 'notAttending' => $notAttending] = $this->scenario();

        $this->externalResultFor($notAttending, '4');

        $statistics = $this->statisticsFor($class, $period, CohortUniverse::AllClassStudents);

        $this->assertStringNotContainsStringIgnoringCase('lapispro', (string) $statistics['notes']['domain_exclusion']);
        $this->assertStringNotContainsStringIgnoringCase('lapispro', (string) $statistics['notes']['external_inclusion']);
    }

    #[Test]
    public function an_external_result_never_lands_in_the_canonical_result_tables(): void
    {
        ['notAttending' => $notAttending] = $this->scenario();

        $this->externalResultFor($notAttending, '4');

        $this->inTenant($this->organization, function () use ($notAttending): void {
            // `student_overall_results`/`student_domain_results` do docblock da
            // migração são o conceito do motor de cálculo (docs/domain-model.md),
            // nunca materializado em tabela própria neste esquema — o resultado
            // vive em `calculation_snapshots` e nas evidências que o alimentam.
            // Nenhuma delas ganha uma linha por causa de um resultado externo.
            $this->assertDatabaseMissing('calculation_snapshots', ['enrollment_id' => $notAttending->id]);
            $this->assertDatabaseMissing('student_item_scores', ['enrollment_id' => $notAttending->id]);
            $this->assertDatabaseMissing('classifications', ['enrollment_id' => $notAttending->id]);
        });
    }

    #[Test]
    public function singular_wording_is_used_for_exactly_one_student_and_multiple_origins_are_joined_with_e(): void
    {
        ['class' => $class, 'period' => $period, 'notAttending' => $first] = $this->scenario();

        $second = $this->inTenant($this->organization, fn (): Enrollment => $this->enrollStudent($class));

        $this->inTenant($this->organization, function () use ($second): void {
            app(MarkNotAttendingSubject::class)->execute(
                $second,
                SubjectParticipationReason::AlternativeSubject,
                self::YEAR_STARTS_ON,
                reasonDetail: 'Espanhol',
            );
        });

        $this->externalResultFor($first, '4');
        $this->externalResultFor($second, '3');

        $statistics = $this->statisticsFor($class, $period, CohortUniverse::AllClassStudents);

        $this->assertStringContainsString('2 alunos avaliados em PLNM e Espanhol', $statistics['notes']['domain_exclusion']);
        $this->assertStringContainsString('2 alunos avaliados em PLNM e Espanhol', $statistics['notes']['external_inclusion']);
    }

    #[Test]
    public function h3_the_classification_itself_carries_the_external_provenance(): void
    {
        ['class' => $class, 'period' => $period, 'notAttending' => $notAttending] = $this->scenario();

        $this->externalResultFor($notAttending, '4');

        $statistics = $this->statisticsFor($class, $period, CohortUniverse::AllClassStudents);

        $student = collect($statistics['students'])->firstWhere('enrollment_id', $notAttending->id);
        $this->assertNotNull($student);

        // H3: a proveniência TAMBÉM dentro de `classification`, não só no
        // `period.external` irmão — para que nenhum leitor deste array possa
        // apresentar um PLNM 4 como uma nota de Português decidida aqui.
        $this->assertSame('external', $student['classification']['external']['source'] ?? null);
        $this->assertSame('PLNM', $student['classification']['external']['origin'] ?? null);
        $this->assertSame('confirmed', $student['classification']['status'] ?? null);
    }

    #[Test]
    public function h2_a_year_level_external_result_produces_no_transition_and_no_continuous_movement(): void
    {
        $result = $this->inTenant($this->organization, function (): array {
            $class = $this->schoolClassFor();

            $first = AcademicPeriod::factory()->recycle($this->organization)->create([
                'academic_year_id' => $class->academic_year_id,
                'sequence' => 1,
                'starts_on' => self::YEAR_STARTS_ON,
                'ends_on' => '2026-12-15',
            ]);
            $second = AcademicPeriod::factory()->recycle($this->organization)->create([
                'academic_year_id' => $class->academic_year_id,
                'sequence' => 2,
                'starts_on' => '2026-12-16',
                'ends_on' => '2027-03-15',
            ]);

            $scale = $this->activeScale();
            $profile = AssessmentProfile::factory()->recycle($this->organization)->create([
                'academic_year_id' => $class->academic_year_id,
                'subject_id' => $class->subject_id,
            ]);
            $version = AssessmentProfileVersion::factory()->recycle($this->organization)->create([
                'assessment_profile_id' => $profile->id,
                'scale_id' => $scale->id,
                'status' => ProfileVersionStatus::Active,
            ]);
            $class->forceFill(['assessment_profile_version_id' => $version->id])->save();

            $notAttending = $this->enrollStudent($class);
            app(MarkNotAttendingSubject::class)->execute(
                $notAttending,
                SubjectParticipationReason::AlternativeSubject,
                self::YEAR_STARTS_ON,
                reasonDetail: 'PLNM',
            );

            return compact('class', 'first', 'second', 'notAttending');
        });

        ['class' => $class, 'second' => $second, 'notAttending' => $notAttending] = $result;

        // UM RESULTADO DE ANO COMPLETO (`period_id` nulo) — corresponde a
        // QUALQUER período, mas nunca é uma trajetória entre dois.
        $this->externalResultFor($notAttending, '4');

        $statistics = $this->statisticsFor($class, $second, CohortUniverse::AllClassStudents);

        $student = collect($statistics['students'])->firstWhere('enrollment_id', $notAttending->id);
        $this->assertNotNull($student);

        // H2: sem trajetória — nem «manteve-se», nem uma transição de sucesso
        // para sucesso. Comparar um resultado externo contra si mesmo
        // fabricaria um movimento que ninguém observou.
        $this->assertNull($student['continuous_evolution']);
        $this->assertSame('no_assigned_classification', $student['transition']);

        $this->assertSame(0, $statistics['continuous_evolution']['progressed']);
        $this->assertSame(0, $statistics['continuous_evolution']['stable']);
        $this->assertSame(0, $statistics['continuous_evolution']['regressed']);
        $this->assertSame(0, $statistics['evolution']['transitions']['success_to_success']);
    }

    #[Test]
    public function h4_a_bare_numeric_value_never_produces_an_average_and_a_foreign_scale_level_is_refused(): void
    {
        ['class' => $class, 'period' => $period, 'notAttending' => $notAttending] = $this->scenario();

        $this->inTenant($this->organization, function () use ($notAttending): void {
            ExternalSubjectResult::factory()->recycle($this->organization)->create([
                'enrollment_id' => $notAttending->id,
                'period_id' => null,
                'origin' => 'PLNM',
                'scale_level_id' => null,
                'level_code' => null,
                // Um número solto, sem se saber a escala de origem — nunca
                // normalizado contra a escala desta turma (req H4).
                'numeric_value' => '4.00',
                'recorded_on' => '2026-09-14',
            ]);
        });

        $statistics = $this->statisticsFor($class, $period, CohortUniverse::AllClassStudents);

        $student = collect($statistics['students'])->firstWhere('enrollment_id', $notAttending->id);
        $this->assertNotNull($student);
        $this->assertNull($student['classification']['final'] ?? null);
        $this->assertNull($statistics['summary']['accumulated_average']);
        // Sem nível resolvido, nunca sucesso nem insucesso.
        $this->assertSame(0, $statistics['summary']['success']['placed']);
        $this->assertSame(0, $statistics['summary']['success']['succeeded']);
        $this->assertSame(0, $statistics['summary']['success']['failed']);

        // H1: um resultado externo que EXISTE mas não resolve a nível nesta
        // escala (aqui, só `numeric_value`, sem `scale_level_id`) NÃO é «sem
        // classificação» — é «não frequenta, sem resultado aplicável nesta
        // escala», e é aí que tem de contar. O único aluno em
        // `without_classification` neste cenário é o que FREQUENTA e ainda
        // não tem nenhuma classificação (a turma não tem instrumentos) —
        // nunca o não-frequentante com um PLNM que não coube nesta escala.
        $this->assertSame(1, $statistics['summary']['success']['without_classification']);
        $this->assertSame(1, $statistics['summary']['students_without_result']);
        $this->assertSame(0, $statistics['cohort']['external_included_count']);
        // A nota de inclusão nunca pode dizer que se incluiu alguém que na
        // prática não contribuiu com nada para os números.
        $this->assertNull($statistics['notes']['external_inclusion']);
        $this->assertSame(1, $statistics['cohort']['not_attending_without_result']);
    }

    #[Test]
    public function h1_an_external_result_with_only_a_level_code_is_not_counted_as_without_classification(): void
    {
        ['class' => $class, 'period' => $period, 'notAttending' => $notAttending] = $this->scenario();

        // Só `level_code` — sem `scale_level_id` — nunca resolve a um nível
        // desta escala (req H4): um código sem se saber a que escala pertence
        // não pode ser comparado ao código dos níveis desta escala.
        $this->inTenant($this->organization, function () use ($notAttending): void {
            ExternalSubjectResult::factory()->recycle($this->organization)->create([
                'enrollment_id' => $notAttending->id,
                'period_id' => null,
                'origin' => 'PLNM',
                'scale_level_id' => null,
                'level_code' => '4',
                'numeric_value' => null,
                'recorded_on' => '2026-09-14',
            ]);
        });

        $statistics = $this->statisticsFor($class, $period, CohortUniverse::AllClassStudents);

        $this->assertSame(1, $statistics['summary']['success']['without_classification']);
        $this->assertSame(1, $statistics['summary']['students_without_result']);
        $this->assertSame(0, $statistics['cohort']['external_included_count']);
        $this->assertSame(1, $statistics['cohort']['not_attending_without_result']);
    }

    #[Test]
    public function h1_an_external_result_with_only_a_numeric_value_is_not_counted_as_without_classification(): void
    {
        ['class' => $class, 'period' => $period, 'notAttending' => $notAttending] = $this->scenario();

        $this->inTenant($this->organization, function () use ($notAttending): void {
            ExternalSubjectResult::factory()->recycle($this->organization)->create([
                'enrollment_id' => $notAttending->id,
                'period_id' => null,
                'origin' => 'PLNM',
                'scale_level_id' => null,
                'level_code' => null,
                'numeric_value' => '4.00',
                'recorded_on' => '2026-09-14',
            ]);
        });

        $statistics = $this->statisticsFor($class, $period, CohortUniverse::AllClassStudents);

        $this->assertSame(1, $statistics['summary']['success']['without_classification']);
        $this->assertSame(1, $statistics['summary']['students_without_result']);
        $this->assertSame(0, $statistics['cohort']['external_included_count']);
        $this->assertSame(1, $statistics['cohort']['not_attending_without_result']);
    }

    #[Test]
    public function h2_a_valid_level_with_a_foreign_numeric_value_never_appears_as_a_distribution_bucket_or_band(): void
    {
        // H2 só se manifesta numa escala NUMÉRICA desta turma: só aí
        // `assignedDistribution()` desvia para `assignedValueDistribution()`,
        // que agrupa por `classification.final_value` — o campo que a
        // primeira ronda ainda deixava carregar o `numeric_value` em bruto
        // quando um nível se resolvia. Numa escala de níveis (kind=level) o
        // agrupamento é sempre por `scale_level_id`, e este bug nunca a
        // atinge — por isso não se usa aqui a «Escala 1 a 5» do cenário
        // partilhado.
        $result = $this->inTenant($this->organization, function (): array {
            $class = $this->schoolClassFor();

            $period = AcademicPeriod::factory()->recycle($this->organization)->create([
                'academic_year_id' => $class->academic_year_id,
                'sequence' => 1,
                'starts_on' => self::YEAR_STARTS_ON,
                'ends_on' => '2026-12-15',
            ]);

            $scale = Scale::factory()->recycle($this->organization)->create([
                'kind' => 'numeric',
                'min_value' => 0,
                'max_value' => 20,
            ]);

            // Uma banda qualitativa configurada nesta escala numérica (§10.4
            // permite-o) — o único nível que um resultado externo pode
            // legitimamente resolver aqui. O código é deliberadamente «99»,
            // bem distinto do `numeric_value` do PLNM («4»), para que o
            // teste distinga sem ambiguidade o CÓDIGO DO NÍVEL (correto) do
            // NÚMERO EM BRUTO (proibido).
            $level = $scale->levels()->create([
                'code' => '99',
                'label' => 'Excelente',
                'sequence' => 1,
                'is_negative' => false,
                'band_min_normalized' => '0.000000',
                'band_max_normalized' => '100.000000',
            ]);

            $profile = AssessmentProfile::factory()->recycle($this->organization)->create([
                'academic_year_id' => $class->academic_year_id,
                'subject_id' => $class->subject_id,
            ]);
            $version = AssessmentProfileVersion::factory()->recycle($this->organization)->create([
                'assessment_profile_id' => $profile->id,
                'scale_id' => $scale->id,
                'status' => ProfileVersionStatus::Active,
            ]);
            $class->forceFill(['assessment_profile_version_id' => $version->id])->save();

            $notAttending = $this->enrollStudent($class);
            app(MarkNotAttendingSubject::class)->execute(
                $notAttending,
                SubjectParticipationReason::AlternativeSubject,
                self::YEAR_STARTS_ON,
                reasonDetail: 'PLNM',
            );

            // Um nível VÁLIDO desta escala, mas acompanhado de um
            // `numeric_value` que pertence à escala de ORIGEM do PLNM (1 a
            // 5), nunca à desta turma (0–20). `final_value` nunca pode
            // carregar esse número em bruto: um `bandForNumericDecision()`
            // sobre ele colocaria "4" numa banda desta escala por
            // coincidência de número, não porque alguém o decidiu.
            ExternalSubjectResult::factory()->recycle($this->organization)->create([
                'enrollment_id' => $notAttending->id,
                'period_id' => null,
                'origin' => 'PLNM',
                'scale_level_id' => $level->id,
                'level_code' => null,
                'numeric_value' => '4.00',
                'recorded_on' => '2026-09-14',
            ]);

            return compact('class', 'period', 'notAttending');
        });

        ['class' => $class, 'period' => $period, 'notAttending' => $notAttending] = $result;

        $statistics = $this->statisticsFor($class, $period, CohortUniverse::AllClassStudents);

        $student = collect($statistics['students'])->firstWhere('enrollment_id', $notAttending->id);
        $this->assertNotNull($student);
        $this->assertNotNull($student['classification']['final'] ?? null);
        // O NÚMERO EM BRUTO NUNCA CHEGA a `final_value` — mesmo com um nível
        // resolvido.
        $this->assertNull($student['classification']['final_value'] ?? null);

        $this->assertSame('values', $statistics['assigned_distribution']['mode']);

        // O grupo deste aluno é o CÓDIGO DO NÍVEL resolvido nesta escala
        // («99»), nunca o `numeric_value` estrangeiro («4»).
        $this->assertSame('99', $student['assigned_key'] ?? null);

        // Nenhuma banda da distribuição atribuída fica identificada pelo
        // número em bruto do PLNM.
        $band4 = collect($statistics['assigned_distribution']['bands'])->firstWhere('key', '4');
        $this->assertNull($band4);
    }

    /**
     * Cria uma classificação interna REAL e confirmada para
     * `$notAttending`, sem depender de `ProposeClassifications` — que nunca
     * gera uma linha para quem não frequenta (não tem resultado
     * computável). A proposta é criada diretamente, na mesma forma «sem
     * proposta» que `ConfirmClassification::confirm()` já sabe tratar (um
     * período cujas propostas nunca foram geradas).
     */
    private function confirmRealClassification(SchoolClass $class, AcademicPeriod $period, Enrollment $notAttending, int $scaleLevelId): void
    {
        $this->inTenant($this->organization, function () use ($class, $period, $notAttending, $scaleLevelId): void {
            $classification = Classification::create([
                'enrollment_id' => $notAttending->id,
                'academic_period_id' => $period->id,
                'scope' => ClassificationScope::Period,
                'assessment_profile_version_id' => $class->assessment_profile_version_id,
                'status' => ClassificationStatus::Proposed,
            ]);

            app(ConfirmClassification::class)->confirm($classification, $this->teacher, $scaleLevelId);
        });
    }

    #[Test]
    public function h3_a_resolved_external_result_becomes_the_final_reading_over_a_pre_existing_internal_classification(): void
    {
        // DECISÃO DO PRODUCT OWNER (substitui a redação original de H3): uma
        // classificação interna pré-existente nunca é apagada — não se
        // escreve nada aqui, é só uma leitura em memória — mas também não é
        // automaticamente a resposta final desta análise para um aluno que,
        // à data de referência, não frequenta a disciplina. Quando um
        // resultado externo RESOLVE a um nível, é essa a resposta.
        ['class' => $class, 'period' => $period, 'notAttending' => $notAttending] = $this->scenario();

        $scale = $this->activeScale();
        $realLevel = $scale->levels->firstWhere('code', '2');

        $this->confirmRealClassification($class, $period, $notAttending, $realLevel->id);

        // «Bom» — o oposto do «Insuficiente» interno — para que a
        // substituição, agora esperada, seja inequívoca no resultado.
        $this->externalResultFor($notAttending, '4');

        $statistics = $this->statisticsFor($class, $period, CohortUniverse::AllClassStudents);

        $student = collect($statistics['students'])->firstWhere('enrollment_id', $notAttending->id);
        $this->assertNotNull($student);

        // O resultado externo é a resposta final desta leitura.
        $this->assertSame('4', $student['classification']['final']['code'] ?? null);
        $this->assertSame('external', $student['classification']['external']['source'] ?? null);
        $this->assertSame('external', $student['external']['source'] ?? null);
        $this->assertSame(1, $statistics['cohort']['external_included_count']);

        // A classificação interna, real, continua na base de dados —
        // ninguém a apagou nem a reescreveu.
        $this->inTenant($this->organization, function () use ($notAttending, $realLevel): void {
            $this->assertDatabaseHas('classifications', [
                'enrollment_id' => $notAttending->id,
                'final_scale_level_id' => $realLevel->id,
                'status' => ClassificationStatus::Confirmed->value,
            ]);
        });
    }

    #[Test]
    public function h3_a_pre_existing_internal_classification_is_not_read_as_final_when_no_external_result_resolves(): void
    {
        // O ESPELHO do teste acima: sem resultado externo nenhum que
        // resolva, a classificação interna pré-existente também não é
        // promovida a resposta final desta análise para quem não frequenta
        // à data de referência — fica de fora, como qualquer outro
        // não-frequentante sem resultado aplicável (H1), nunca «reaproveitada»
        // silenciosamente.
        ['class' => $class, 'period' => $period, 'notAttending' => $notAttending] = $this->scenario();

        $scale = $this->activeScale();
        $realLevel = $scale->levels->firstWhere('code', '2');

        $this->confirmRealClassification($class, $period, $notAttending, $realLevel->id);

        $statistics = $this->statisticsFor($class, $period, CohortUniverse::AllClassStudents);

        $student = collect($statistics['students'])->firstWhere('enrollment_id', $notAttending->id);
        $this->assertNotNull($student);
        $this->assertNull($student['classification'] ?? null);
        $this->assertSame(0, $statistics['cohort']['external_included_count']);
        $this->assertSame(1, $statistics['cohort']['not_attending_without_result']);
        $this->assertSame(0, $statistics['summary']['success']['placed']);

        // A classificação interna, real, continua na base de dados —
        // esta leitura só deixou de a apresentar como a resposta final.
        $this->inTenant($this->organization, function () use ($notAttending, $realLevel): void {
            $this->assertDatabaseHas('classifications', [
                'enrollment_id' => $notAttending->id,
                'final_scale_level_id' => $realLevel->id,
                'status' => ClassificationStatus::Confirmed->value,
            ]);
        });
    }

    #[Test]
    public function m2_a_student_included_by_external_result_is_not_counted_as_without_result(): void
    {
        ['class' => $class, 'period' => $period, 'notAttending' => $notAttending] = $this->scenario();

        $this->externalResultFor($notAttending, '4');

        $statistics = $this->statisticsFor($class, $period, CohortUniverse::AllClassStudents);

        // O aluno TEM um resultado — registado noutro sítio — e nunca deve
        // ser contado como «ainda sem qualquer elemento avaliado» por não ter
        // uma `weighted_average` que esta disciplina nunca poderia calcular.
        // O único aluno sem resultado neste cenário é o que frequenta e não
        // tem instrumentos.
        $this->assertSame(1, $statistics['summary']['students_without_result']);
    }

    #[Test]
    public function m5_evolution_denominators_are_unaffected_by_external_rows(): void
    {
        $result = $this->inTenant($this->organization, function (): array {
            $class = $this->schoolClassFor();

            $first = AcademicPeriod::factory()->recycle($this->organization)->create([
                'academic_year_id' => $class->academic_year_id,
                'sequence' => 1,
                'starts_on' => self::YEAR_STARTS_ON,
                'ends_on' => '2026-12-15',
            ]);
            $second = AcademicPeriod::factory()->recycle($this->organization)->create([
                'academic_year_id' => $class->academic_year_id,
                'sequence' => 2,
                'starts_on' => '2026-12-16',
                'ends_on' => '2027-03-15',
            ]);

            $scale = $this->activeScale();
            $profile = AssessmentProfile::factory()->recycle($this->organization)->create([
                'academic_year_id' => $class->academic_year_id,
                'subject_id' => $class->subject_id,
            ]);
            $version = AssessmentProfileVersion::factory()->recycle($this->organization)->create([
                'assessment_profile_id' => $profile->id,
                'scale_id' => $scale->id,
                'status' => ProfileVersionStatus::Active,
            ]);
            $class->forceFill(['assessment_profile_version_id' => $version->id])->save();

            $notAttending = $this->enrollStudent($class);
            app(MarkNotAttendingSubject::class)->execute(
                $notAttending,
                SubjectParticipationReason::AlternativeSubject,
                self::YEAR_STARTS_ON,
                reasonDetail: 'PLNM',
            );

            return compact('class', 'second', 'notAttending');
        });

        ['class' => $class, 'second' => $second, 'notAttending' => $notAttending] = $result;

        $this->externalResultFor($notAttending, '4');

        $statistics = $this->statisticsFor($class, $second, CohortUniverse::AllClassStudents);

        // Um resultado externo não é uma trajetória — não deve inflacionar
        // `no_comparison` nem encolher as outras percentagens só por não ter
        // segundo momento comparável. O aluno externo fica de fora do
        // denominador de `evolution()` inteiramente, tal como já ficava fora
        // de `transitions()`/`continuous_evolution()`.
        $this->assertSame(0, $statistics['evolution']['progressed']);
        $this->assertSame(0, $statistics['evolution']['stable']);
        $this->assertSame(0, $statistics['evolution']['regressed']);
        $this->assertSame(0, $statistics['evolution']['no_comparison']);
        $this->assertSame(0, $statistics['evolution']['comparable']);
    }
}
