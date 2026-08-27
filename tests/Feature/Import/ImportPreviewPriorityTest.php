<?php

namespace Tests\Feature\Import;

use App\Domain\Import\Correction\CorrectionGridSource;
use PHPUnit\Framework\Attributes\Test;

/**
 * What the teacher sees first, and in what order.
 *
 * The feature imports STUDENTS' RESULTS. The first screen after reading a file
 * was showing question codes, wordings and answer keys — technically necessary,
 * but not what a teacher opens this for. They want to know their class was
 * found and what each of them scored; the shape of the test matters later, and
 * only because the marks need a cotação to land on.
 *
 * These tests pin that priority in two places: the payload has to carry the
 * per-student facts (a table cannot show what never arrived), and the interface
 * has to open on the students rather than on the questions.
 */
class ImportPreviewPriorityTest extends CorrectionImportHttpTest
{
    protected function wizard(): string
    {
        return (string) file_get_contents(base_path('resources/js/pages/imports/correction/Wizard.vue'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function previewProps(): array
    {
        $import = $this->upload();

        $response = $this->actingAs($this->teacher)->get("/imports/correction/{$import->ulid}");
        $response->assertOk();

        return $response->viewData('page')['props']['preview'];
    }

    #[Test]
    public function every_per_student_fact_the_table_needs_reaches_the_interface(): void
    {
        $students = $this->previewProps()['students'];

        $this->assertCount(3, $students);

        foreach (['display_name', 'source_score', 'source_correct', 'source_answered', 'participated', 'status', 'candidates'] as $field) {
            $this->assertArrayHasKey($field, $students[0], "A tabela de alunos precisa de «{$field}».");
        }
    }

    #[Test]
    public function the_platform_result_arrives_as_the_platform_stated_it(): void
    {
        $students = collect($this->previewProps()['students'])->keyBy('source_key');

        // Ana got all three right; the platform says 100%.
        $this->assertSame('100%', $students['student:1']['source_score']);
        $this->assertSame(3, $students['student:1']['source_correct']);
        $this->assertSame(3, $students['student:1']['source_answered']);

        // Bruno got one of three.
        $this->assertSame('33%', $students['student:2']['source_score']);
        $this->assertSame(1, $students['student:2']['source_correct']);
    }

    #[Test]
    public function a_student_with_no_platform_score_shows_nothing_rather_than_zero(): void
    {
        $students = collect($this->previewProps()['students'])->keyBy('source_key');

        // Carla's «-» must not become «0%». A platform that recorded no score
        // has said nothing about her, and «0%» would say something false.
        $this->assertNull($students['student:3']['source_score']);
        $this->assertFalse($students['student:3']['participated']);
        $this->assertSame(0, $students['student:3']['source_answered']);
    }

    #[Test]
    public function the_wizard_opens_on_the_students_and_not_on_the_questions(): void
    {
        $wizard = $this->wizard();

        // Step 2 is where the wizard lands after analysing, and step 2 is the
        // students.
        $this->assertMatchesRegularExpression('/const step = ref\(2\)/', $wizard);

        // Step 2 was «Alunos e resultados», which promised both before either
        // existed: a spreadsheet may still be working out which sheet the data
        // is on. The students keep that heading INSIDE the step, once they are
        // there — which is what this test is really about.
        $this->assertMatchesRegularExpression(
            "/STEPS = \[\s*'Origem e ficheiro',\s*'Ler resultados',\s*'Configurar avaliação',\s*'Rever e importar'/u",
            $wizard,
        );
        $this->assertStringContainsString('Alunos e resultados', $wizard);
    }

    #[Test]
    public function the_students_table_leads_with_what_a_teacher_recognises(): void
    {
        $wizard = $this->wizard();

        foreach (['Aluno no ficheiro', 'Aluno no Lapispro', 'Respostas', 'Estado'] as $column) {
            $this->assertStringContainsString($column, $wizard);
        }

        // The result column is named by the SOURCE, because «Resultado na
        // plataforma» is right for an export from a platform and wrong for the
        // teacher's own spreadsheet, which came from no platform at all. The
        // template renders whatever the source says.
        $this->assertStringContainsString('sourceResultLabel', $wizard);
        $this->assertSame('Resultado na plataforma', CorrectionGridSource::Plickers->resultLabel());
        $this->assertSame('Resultado no ficheiro', CorrectionGridSource::Generic->resultLabel());

        // The mapping is corrected in the same table, not on a page of its own.
        $this->assertStringContainsString('Ignorar esta linha', $wizard);
    }

    #[Test]
    public function the_test_structure_is_secondary_and_starts_closed(): void
    {
        $wizard = $this->wizard();

        $this->assertStringContainsString('Estrutura do teste —', $wizard);
        $this->assertStringContainsString('Ver perguntas e respostas corretas', $wizard);
        $this->assertMatchesRegularExpression('/const showQuestions = ref\(false\)/', $wizard);
    }

    #[Test]
    public function external_ids_never_reach_the_ordinary_interface(): void
    {
        // The question URLs are provenance, not something to read on screen.
        $this->assertStringNotContainsString('external_id', $this->wizard());
    }

    #[Test]
    public function the_platform_result_is_never_labelled_as_the_lapis_result(): void
    {
        $wizard = $this->wizard();

        // Two different numbers, two different names. A source score is not a
        // classification and must never be dressed as one. The source's own
        // number is named by the source — «Resultado na plataforma» for a
        // platform, «Resultado no ficheiro» for a spreadsheet — and the Lapispro
        // one is named here, always and only, as the Lapispro one.
        $this->assertStringContainsString('sourceResultLabel', $wizard);
        $this->assertStringContainsString('Resultado Lapispro', $wizard);

        foreach (CorrectionGridSource::cases() as $source) {
            $this->assertStringNotContainsString('Lapispro', $source->resultLabel());
        }
        // A short fragment on purpose: the sentence is wrapped across lines in
        // the template, and asserting the whole of it would break on reflow
        // rather than on meaning.
        $this->assertStringContainsString('reflete as cotações', $wizard);
        $this->assertStringContainsString('apresentado como referência', $wizard);

        foreach (['Classificação final', 'Nota final'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $wizard);
        }
    }

    #[Test]
    public function the_lapis_result_stays_empty_until_a_cotacao_is_decided(): void
    {
        // The detailed mode, explicitly: it is the one where a Lapispro result has
        // to be built out of cotações, and so the one where it can be missing.
        $import = $this->upload();
        $mapping = $this->completeMapping();
        $mapping['points'] = [];

        $this->actingAs($this->teacher)->patch("/imports/correction/{$import->ulid}", $mapping);

        $students = $this->actingAs($this->teacher)
            ->get("/imports/correction/{$import->ulid}")
            ->viewData('page')['props']['preview']['students'];

        // No cotação decided, so there is no Lapispro result to show — and null,
        // not zero, is how that is said.
        foreach ($students as $student) {
            $this->assertNull($student['lapis_percentage']);
        }
    }

    #[Test]
    public function the_simple_mode_has_a_result_before_anything_is_configured(): void
    {
        // The other half, and the point of the whole rewrite: on the ordinary
        // path the classification exists from the moment the file is read,
        // because the platform produced it. Nothing has to be configured for a
        // teacher to see what they are about to import (§1).
        $students = collect($this->previewProps()['students'])->keyBy('source_key');

        $this->assertSame('100.0', $students['student:1']['lapis_percentage']);
        $this->assertSame('33.0', $students['student:2']['lapis_percentage']);

        // Carla took no part. Still null, still not a zero.
        $this->assertNull($students['student:3']['lapis_percentage']);
    }

    #[Test]
    public function the_lapis_result_appears_once_the_cotacao_is_set(): void
    {
        $import = $this->upload();
        $this->actingAs($this->teacher)->patch("/imports/correction/{$import->ulid}", $this->completeMapping());

        $students = collect(
            $this->actingAs($this->teacher)
                ->get("/imports/correction/{$import->ulid}")
                ->viewData('page')['props']['preview']['students'],
        )->keyBy('source_key');

        // Ana: three correct at 2 points each, out of three judged questions.
        $this->assertSame('100.0', $students['student:1']['lapis_percentage']);

        // Bruno: one correct of three → 2 of 6.
        $this->assertSame('33.3', $students['student:2']['lapis_percentage']);

        // Carla answered nothing, so nothing was judged and there is no
        // percentage — not a zero (§7).
        $this->assertNull($students['student:3']['lapis_percentage']);
    }

    #[Test]
    public function an_unmatched_row_reaches_the_interface_with_nobody_selected(): void
    {
        // The payload, not the service in isolation: what the browser receives
        // is what the browser will show, and a stray default here is a mark on
        // the wrong child (§12).
        $students = collect($this->previewProps()['students'])->keyBy('source_key');

        // The fixture's three names exist in the class as enrolments created by
        // the factory, so none of them matches by name.
        foreach ($students as $student) {
            if ($student['status'] !== 'matched') {
                $this->assertNull($student['enrollment_id'], 'Uma linha sem correspondência não pode trazer aluno escolhido.');
            }
        }
    }

    #[Test]
    public function every_row_carries_the_whole_class_so_it_can_be_resolved_by_hand(): void
    {
        $students = $this->previewProps()['students'];

        foreach ($students as $student) {
            $this->assertNotEmpty($student['candidates'], 'Sem candidatos, o professor não tem por onde escolher.');
        }
    }

    #[Test]
    public function the_counters_add_up_for_this_file(): void
    {
        $props = $this->previewProps();
        $counts = $props['counts'];

        $participated = 0;
        $absentFromPlatform = 0;

        foreach ($props['students'] as $student) {
            $student['participated'] ? $participated++ : $absentFromPlatform++;
        }

        // Computed from this file, not carried over from an older one.
        $this->assertSame($counts['students_in_file'], $participated + $absentFromPlatform);
        $this->assertSame($counts['non_participants'], $absentFromPlatform);
        $this->assertSame(3, $counts['students_in_file']);
        $this->assertSame(1, $counts['non_participants']);
    }

    #[Test]
    public function the_wizard_shows_how_a_pairing_was_reached(): void
    {
        $wizard = $this->wizard();

        // A silent ✓ gives «nome exato» and «nome semelhante» the same weight.
        // A teacher asked to trust an automatic pairing may see what it rests on.
        $this->assertStringContainsString("exact_name: 'Nome exato'", $wizard);
        $this->assertStringContainsString("normalised_name: 'Nome normalizado'", $wizard);
        $this->assertStringContainsString('— Por associar —', $wizard);
    }

    #[Test]
    public function the_wizard_never_calls_a_non_participant_absent(): void
    {
        $wizard = $this->wizard();

        $this->assertStringContainsString('Não participou nesta aplicação', $wizard);

        foreach (['Faltou', 'Reprovado', '0 valores'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $wizard);
        }
    }
}
