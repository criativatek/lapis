<?php

namespace Tests\Unit\Assessment;

use App\Services\Assessment\Ai\ClassAnalysisContext;
use App\Support\Privacy\AiPayloadSanitizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What leaves the building when a class is read by the AI.
 *
 * THE FIXTURE DELIBERATELY CARRIES IDENTIFIERS. It is shaped like a real
 * `BuildClassStatistics::for()` payload — names, class numbers and enrollment
 * ids included, because the real one has them — precisely so the assertions
 * below can prove they are dropped or replaced. A fixture that was already
 * clean would prove nothing at all.
 *
 * TWO LAYERS ARE ASSERTED SEPARATELY, and that is the point of using
 * `AiContext::fields()` as well as the finished payload. `fields()` shows the
 * values as they were AFTER `add()` and BEFORE serialisation — so a test can
 * tell «pseudonymised while still a named scalar» from «pseudonymised after
 * being joined into a paragraph», which are the two things the Core's ordering
 * rule is about. A test that could only see the final string could not.
 */
class ClassAnalysisContextTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function statistics(): array
    {
        return [
            'selected_period' => ['id' => 3, 'ulid' => '01JABCDEF', 'label' => '2.º Período', 'sequence' => 2],
            'primary' => ['kind' => 'accumulated', 'label' => 'Média Ponderada Acumulada'],
            'scale' => ['name' => 'Escala de 0 a 100', 'kind' => 'percentage'],
            'summary' => [
                'students_total' => 3,
                'students_with_result' => 3,
                'students_without_result' => 0,
                'primary_average' => '61.20',
                'partial_coverage_count' => 1,
                'most_common_band' => ['scale_level_id' => 7, 'code' => 'S', 'label' => 'Suficiente', 'is_negative' => false, 'count' => 2],
                'success' => ['succeeded' => 2, 'failed' => 1, 'placed' => 3, 'rate' => '66.7', 'failure_rate' => '33.3'],
            ],
            'distribution' => [
                ['scale_level_id' => 5, 'code' => 'I', 'label' => 'Insuficiente', 'sequence' => 1, 'is_negative' => true, 'count' => 1, 'percentage' => '33.3'],
                ['scale_level_id' => 7, 'code' => 'S', 'label' => 'Suficiente', 'sequence' => 2, 'is_negative' => false, 'count' => 2, 'percentage' => '66.7'],
            ],
            'evolution' => [
                'comparable' => 3,
                'average_change' => '2.40',
                'percentages' => ['progressed' => '66.7', 'stable' => '0.0', 'regressed' => '33.3', 'no_comparison' => '0.0'],
            ],
            'domain_statistics' => [
                [
                    'domain_id' => 11, 'label' => 'Compreensão leitora', 'period_average' => '58.00',
                    'accumulated_average' => '60.00', 'evolution_average' => '1.50', 'students_with_result' => 3,
                    'students_without_result' => 0, 'partial_coverage_count' => 0, 'succeeded' => 2, 'placed' => 3,
                    'success_rate' => '66.7', 'qualitative_band' => ['code' => 'S', 'label' => 'Suficiente'],
                ],
            ],
            'students' => [
                // Roster order — alphabetical, as the real read model hands
                // them over. «Ana» first, and she is NOT the top result.
                [
                    'enrollment_id' => 901, 'name' => 'Ana Sofia Melo', 'class_number' => 1,
                    'primary_average' => '48.00', 'coverage_warning' => true,
                    'band' => ['code' => 'I', 'label' => 'Insuficiente', 'is_negative' => true],
                    'evolution' => ['direction' => 'down', 'points' => '-3.00'],
                ],
                [
                    'enrollment_id' => 902, 'name' => 'Bruno Alves', 'class_number' => 2,
                    'primary_average' => '72.00', 'coverage_warning' => false,
                    'band' => ['code' => 'S', 'label' => 'Suficiente', 'is_negative' => false],
                    'evolution' => ['direction' => 'up', 'points' => '5.00'],
                ],
                [
                    'enrollment_id' => 903, 'name' => 'Carla Nunes', 'class_number' => 3,
                    'primary_average' => '63.60', 'coverage_warning' => false,
                    'band' => ['code' => 'S', 'label' => 'Suficiente', 'is_negative' => false],
                    'evolution' => ['direction' => 'up', 'points' => '4.00'],
                ],
            ],
        ];
    }

    private function rendered(?array $statistics = null): string
    {
        return ClassAnalysisContext::build($statistics ?? $this->statistics(), 'Português', '7.º')
            ->toPayload(new AiPayloadSanitizer)
            ->text;
    }

    #[Test]
    public function no_student_name_number_or_identifier_survives_into_the_payload(): void
    {
        $rendered = $this->rendered();

        foreach (['Ana Sofia Melo', 'Bruno Alves', 'Carla Nunes', 'Ana', 'Bruno', 'Carla'] as $identifier) {
            $this->assertStringNotContainsString($identifier, $rendered);
        }

        // The enrollment ids and class numbers are never read at all — the
        // allowlist builds a new row from four named keys rather than removing
        // the dangerous ones from the row it was given.
        $this->assertStringNotContainsString('901', $rendered);
        $this->assertStringNotContainsString('enrollment', $rendered);
        $this->assertStringNotContainsString('class_number', $rendered);
    }

    #[Test]
    public function the_core_pseudonymiser_is_what_replaced_the_names_and_it_did_so_before_serialising(): void
    {
        $context = ClassAnalysisContext::build($this->statistics(), 'Português', '7.º');

        // Read BEFORE `toPayload()`: the values are already substituted while
        // each is still a single named field, which is the ordering rule.
        $rows = $context->fields()['Resultado por aluno'] ?? '';

        $this->assertStringContainsString('Aluno A', $rows);
        $this->assertStringNotContainsString('Bruno', $rows);

        // And the payload says so out loud, rather than leaving it to be
        // inferred from the absence of a name.
        $payload = $context->toPayload(new AiPayloadSanitizer);

        $this->assertTrue($payload->wasPseudonymised());
    }

    #[Test]
    public function pseudonyms_are_assigned_by_result_not_by_roster_order(): void
    {
        $rows = ClassAnalysisContext::build($this->statistics(), 'Português', '7.º')
            ->fields()['Resultado por aluno'] ?? '';

        // Bruno (72,00) is third on the roster and first by result, so «Aluno A»
        // is the top result and not the alphabetically-first student. That is
        // what severs the pseudonym from the pauta position.
        //
        // The figures arrive at `BuildClassStatistics::PRECISION` — one
        // decimal, the precision every screen in the application shows. The
        // read model hands per-student averages over unrounded; sending them
        // raw would both quote a number no page displays and trip the
        // sanitiser's six-digit rule. See `ClassAnalysisContext::displayed()`.
        $this->assertStringContainsString('Aluno A: 72.0', $rows);
        $this->assertStringContainsString('Aluno B: 63.6', $rows);
        $this->assertStringContainsString('Aluno C: 48.0', $rows);
    }

    #[Test]
    public function a_long_unrounded_average_is_sent_at_display_precision_and_survives_the_sanitiser(): void
    {
        $statistics = $this->statistics();
        // What the progression actually hands over: `normalizedValue`, raw.
        $statistics['students'][1]['primary_average'] = '72.12345678';

        $rendered = $this->rendered($statistics);

        $this->assertStringContainsString('Aluno A: 72.1', $rendered);

        // The failure this guards against: a six-digit run inside the decimals
        // is exactly what `AiPayloadSanitizer` removes, and a model reading
        // «72.[número removido]» is reading a mutilated figure.
        $this->assertStringNotContainsString('[número removido]', $rendered);
    }

    #[Test]
    public function a_field_added_to_the_read_model_later_does_not_leak_by_default(): void
    {
        $statistics = $this->statistics();
        $statistics['students'][0]['guardian_email'] = 'encarregado@exemplo.pt';
        $statistics['students'][0]['citizen_card'] = '12345678';

        $rendered = $this->rendered($statistics);

        // The allowlist, doing its job: an unknown key is not carried, because
        // the row is BUILT from named fields rather than filtered.
        $this->assertStringNotContainsString('encarregado@exemplo.pt', $rendered);
        $this->assertStringNotContainsString('12345678', $rendered);
    }

    #[Test]
    public function the_figures_the_page_shows_are_carried_verbatim_and_never_recomputed(): void
    {
        $rendered = $this->rendered();

        // Every one of these was decided by the calculation engine. The
        // context's whole job is to hand them over unchanged.
        $this->assertStringContainsString('61.20', $rendered);
        $this->assertStringContainsString('Suficiente', $rendered);
        $this->assertStringContainsString('66.7', $rendered);
        $this->assertStringContainsString('Compreensão leitora', $rendered);
        $this->assertStringContainsString('2.40', $rendered);
        $this->assertStringContainsString('Português', $rendered);
        $this->assertStringContainsString('2.º Período', $rendered);
    }

    #[Test]
    public function a_teacher_authored_domain_name_containing_a_student_name_is_pseudonymised_too(): void
    {
        $statistics = $this->statistics();
        $statistics['domain_statistics'][0]['label'] = 'Apoio a Bruno Alves';

        $rendered = $this->rendered($statistics);

        // Domain labels are free text somebody typed. Passing them through
        // `add()` like everything else is what makes this true — the allowlist
        // alone would have carried the name straight through.
        $this->assertStringNotContainsString('Bruno Alves', $rendered);
        $this->assertStringContainsString('Apoio a Aluno A', $rendered);
    }

    #[Test]
    public function a_very_large_class_is_summarised_by_its_distribution_rather_than_row_by_row(): void
    {
        $statistics = $this->statistics();
        $statistics['students'] = [];

        for ($i = 0; $i < 45; $i++) {
            $statistics['students'][] = [
                'enrollment_id' => 1000 + $i,
                'name' => "Aluno Real {$i}",
                'class_number' => $i + 1,
                'primary_average' => (string) (40 + $i),
                'coverage_warning' => false,
                'band' => ['code' => 'S', 'label' => 'Suficiente', 'is_negative' => false],
                'evolution' => null,
            ];
        }

        $rows = ClassAnalysisContext::build($statistics, 'Português', '7.º')
            ->fields()['Resultado por aluno'] ?? '';

        // Joined with ' | ' by AiContext's repeated-label rule, so counting the
        // separators counts the rows.
        $this->assertCount(ClassAnalysisContext::MAX_STUDENT_ROWS, explode(' | ', $rows));
    }

    #[Test]
    public function students_without_a_result_sort_last_and_never_become_a_zero(): void
    {
        $statistics = $this->statistics();
        $statistics['students'][] = [
            'enrollment_id' => 904, 'name' => 'Diogo Sá', 'class_number' => 4,
            'primary_average' => null, 'coverage_warning' => false,
            'band' => null, 'evolution' => null,
        ];

        $rows = ClassAnalysisContext::build($statistics, 'Português', '7.º')
            ->fields()['Resultado por aluno'] ?? '';

        $this->assertStringContainsString('Aluno D: não disponível', $rows);
        $this->assertStringNotContainsString('Aluno D: 0', $rows);
    }

    #[Test]
    public function a_missing_value_is_reported_as_unavailable_rather_than_invented(): void
    {
        $context = ClassAnalysisContext::build(['summary' => [], 'students' => []], 'Português', null);

        // A dropped field is not an empty one: `AiContext::add()` refuses to
        // send «Ano de escolaridade: », which would tell a model that the field
        // exists and is blank.
        $this->assertArrayNotHasKey('Ano de escolaridade', $context->fields());
        $this->assertArrayHasKey('Disciplina', $context->fields());
    }
}
