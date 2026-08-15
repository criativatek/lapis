<?php

namespace Tests\Feature\Import;

use App\Domain\Import\Correction\CorrectionGridSource;
use App\Domain\Import\Correction\ImportMapping;
use App\Models\CorrectionImport;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Import\GenericSpreadsheetBuilder;

/**
 * What the teacher READS, not what the importer does.
 *
 * The manual smoke found the machinery correct and the words wrong: a
 * spreadsheet a teacher made was being called «outra plataforma», a file that
 * takes .xlsx was asking for a CSV, and a sheet nobody had described yet was
 * reporting «0 alunos · 0 participaram · 0 perguntas» — three sentences that are
 * each individually false about the file in front of them.
 *
 * The rule these tests hold to is that vocabulary belongs to the SOURCE. What a
 * Plickers export may say about attendance, a spreadsheet may not, and neither
 * screen decides that by comparing against a provider name.
 */
class GenericSpreadsheetWizardTest extends CorrectionImportHttpTest
{
    protected string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = sys_get_temp_dir().'/lapis-ux-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    protected function uploadSheet(GenericSpreadsheetBuilder $builder, string $name = 'As minhas notas.csv'): CorrectionImport
    {
        Storage::fake('local');

        str_ends_with($name, '.xlsx')
            ? $builder->writeXlsx($this->file)
            : $builder->writeCsv($this->file);

        $this->actingAs($this->teacher)->post('/imports/correction', [
            'class_id' => $this->class->id,
            'source' => CorrectionGridSource::Generic->value,
            'file' => new UploadedFile($this->file, $name, null, null, true),
        ])->assertRedirect();

        return app(CurrentOrganization::class)->runFor(
            $this->organization,
            fn () => CorrectionImport::latest('id')->firstOrFail(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function props(CorrectionImport $import): array
    {
        $props = [];

        $this->actingAs($this->teacher)
            ->get("/imports/correction/{$import->ulid}")
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$props): void {
                $props = $page->toArray()['props'];
            });

        return $props;
    }

    protected function create(): string
    {
        return $this->componentSource('resources/js/pages/imports/correction/Create.vue');
    }

    protected function wizard(): string
    {
        return $this->componentSource('resources/js/pages/imports/correction/Wizard.vue');
    }

    // --------------------------------------------------- 1. the feature's name

    #[Test]
    public function the_feature_is_no_longer_called_another_platform(): void
    {
        // A spreadsheet the teacher built came from no platform at all, and the
        // title is the first thing they read (§2).
        foreach ([$this->create(), $this->wizard()] as $source) {
            $this->assertStringContainsString('title="Importar resultados"', $source);
        }

        $this->assertStringContainsString(
            'Importe resultados provenientes de outras plataformas ou de uma folha de cálculo.',
            $this->create(),
        );

        // Including where the teacher sets off from.
        $this->assertStringNotContainsString(
            'Importar resultados de outra plataforma',
            $this->componentSource('resources/js/pages/assessments/Index.vue'),
        );
    }

    #[Test]
    public function the_source_is_asked_for_as_the_origin_of_the_results(): void
    {
        $this->assertStringContainsString('Origem dos resultados', $this->create());
        $this->assertStringNotContainsString('Plataforma de origem', $this->create());
    }

    // ------------------------------------------------- 2. the source's own words

    #[Test]
    public function the_spreadsheet_source_describes_itself_without_naming_a_shape(): void
    {
        $this->assertSame('Folha de cálculo', CorrectionGridSource::Generic->label());

        $hint = CorrectionGridSource::Generic->hint();

        // The formats are stated HERE, once, and not in the name and again
        // under the file button as they were.
        $this->assertSame('Importe um ficheiro Excel (.xlsx) ou CSV (.csv).', $hint);

        // It imports a global result, results per domain OR question by
        // question. Naming any one of them sends the teacher for the wrong file.
        $this->assertStringNotContainsString('por questão', $hint);
        $this->assertStringNotContainsString('por aluno e por', $hint);
    }

    #[Test]
    public function the_file_field_does_not_claim_the_file_was_exported(): void
    {
        $create = $this->create();

        // A teacher's own spreadsheet was never exported from anything (§1).
        $this->assertStringContainsString('>Ficheiro</span', $create);
        $this->assertStringNotContainsString('Ficheiro exportado', $create);

        // And the formats are not repeated under the button.
        $this->assertStringNotContainsString('Formatos aceites', $create);
        $this->assertStringNotContainsString('Formato aceite', $create);
        $this->assertStringContainsString(
            'O ficheiro é guardado temporariamente em privado',
            $create,
        );
    }

    #[Test]
    public function the_file_button_does_not_ask_for_a_csv_when_it_takes_a_workbook(): void
    {
        $create = $this->create();

        // Named only when naming it is unambiguous — one accepted extension.
        // With two, «Selecionar ficheiro CSV» tells a teacher their .xlsx will
        // not do, which is false (§4).
        $this->assertStringContainsString('Selecionar ficheiro ${extensions[0].toUpperCase()}', $create);
        $this->assertStringContainsString('extensions.length === 1', $create);
        $this->assertStringContainsString(": 'Selecionar ficheiro'", $create);

        // Both formats are named where the teacher chooses the source.
        $this->assertStringContainsString('Excel (.xlsx)', CorrectionGridSource::Generic->hint());
        $this->assertStringContainsString('CSV (.csv)', CorrectionGridSource::Generic->hint());
    }

    #[Test]
    public function the_offered_formats_are_exactly_what_a_parser_reads(): void
    {
        $this->actingAs($this->teacher)
            ->get('/imports/correction/create')
            ->assertInertia(function (AssertableInertia $page): void {
                $sources = collect($page->toArray()['props']['sources'])->keyBy('key');

                $this->assertSame(['csv', 'xlsx'], $sources['generic']['extensions']);
                $this->assertSame('.csv,.xlsx', $sources['generic']['accept']);

                // And nothing was widened for the others while doing it.
                $this->assertSame(['csv'], $sources['plickers']['extensions']);
                $this->assertSame(['xlsx'], $sources['intuitivo']['extensions']);

                // Compared as whole extensions: «.xlsx» contains «xls», and an
                // assertion that cannot tell them apart proves nothing.
                $offered = explode(',', $sources['generic']['accept']);

                foreach (['.xls', '.xlsm', '.ods', '.pdf'] as $unsupported) {
                    $this->assertNotContains($unsupported, $offered);
                }
            });
    }

    // ------------------------------------- 3. no borrowed vocabulary, no fake zeros

    #[Test]
    public function only_a_source_that_counts_attendance_may_speak_of_it(): void
    {
        // A property of the format, stated once, so no screen decides it by
        // comparing against a provider name (§6).
        $this->assertTrue(CorrectionGridSource::Plickers->statesParticipation());
        $this->assertFalse(CorrectionGridSource::Intuitivo->statesParticipation());
        $this->assertFalse(CorrectionGridSource::Generic->statesParticipation());

        $preview = $this->props($this->uploadSheet(GenericSpreadsheetBuilder::multiDomain()))['preview'];

        $this->assertFalse($preview['source_states_participation']);
        $this->assertSame('Resultado no ficheiro', $preview['source_result_label']);
    }

    #[Test]
    public function every_sentence_about_taking_part_is_gated_on_the_source(): void
    {
        $wizard = $this->wizard();

        // The phrase may still exist — it is true of Plickers — but never
        // unguarded.
        $this->assertStringNotContainsString(
            'v-if="!student.participated"',
            $wizard,
            'a frase sobre participação não pode aparecer sem a origem a permitir',
        );

        $this->assertStringContainsString(
            'v-if="statesParticipation && !student.participated"',
            $wizard,
        );
    }

    #[Test]
    public function a_sheet_nobody_has_described_yet_reports_no_counts_at_all(): void
    {
        $props = $this->props($this->uploadSheet(GenericSpreadsheetBuilder::multiDomain()));

        // Nothing has been mapped, so there are no students and no columns. «0
        // alunos · 0 perguntas» on a file with four students in it reads as a
        // finding, and the finding is wrong (§6).
        $this->assertTrue($props['preview']['source_needs_describing']);
        $this->assertSame([], $props['preview']['students']);

        $this->assertStringContainsString(
            '!props.preview.source_needs_describing || props.preview.students.length > 0',
            $this->wizard(),
        );
    }

    #[Test]
    public function nothing_downstream_of_the_reading_is_shown_before_it_happens(): void
    {
        $wizard = $this->collapsed($this->wizard());

        // An empty table with four headings does not read as «nothing yet» — it
        // reads as «we looked and found nobody», which is a different and
        // alarming claim. Same for a «Guardar e continuar» on a step that has
        // not done its job (§8).
        $this->assertStringContainsString(
            'v-if="resultsAreRead" class="overflow-x-auto rounded-lg border border-border"',
            $wizard,
        );
        $this->assertStringContainsString(
            'v-if="resultsAreRead" class="flex flex-wrap items-center gap-3"',
            $wizard,
        );
        $this->assertStringContainsString(
            'v-if="resultsAreRead" class="rounded-md border border-border bg-muted/30',
            $wizard,
        );
    }

    #[Test]
    public function the_step_is_named_for_what_it_does_before_it_has_done_it(): void
    {
        $wizard = $this->collapsed($this->wizard());

        // «Alunos e resultados» promised both before either existed (§6).
        $this->assertStringContainsString(
            "STEPS = [ 'Origem e ficheiro', 'Ler resultados', 'Configurar avaliação', 'Rever e importar',",
            $wizard,
        );

        // The students keep their own heading, once they are there.
        $this->assertStringContainsString(
            "resultsAreRead ? 'Alunos e resultados' : 'Ler resultados'",
            $wizard,
        );
    }

    #[Test]
    public function once_the_sheet_is_read_the_class_appears_as_usual(): void
    {
        $import = $this->uploadSheet(GenericSpreadsheetBuilder::multiDomain());

        $this->actingAs($this->teacher)->patch("/imports/correction/{$import->ulid}", [
            'mode' => ImportMapping::MODE_CREATE,
            'result_mode' => ImportMapping::RESULT_PER_GROUP,
            'table' => [
                'sheet' => 'Folha 1',
                'header_row' => 1,
                'student_column' => 'A',
                'result_columns' => ['B', 'C', 'D'],
            ],
        ]);

        $preview = $this->props($import)['preview'];

        $this->assertCount(4, $preview['students']);
        $this->assertSame(4, $preview['counts']['students_in_file']);
    }

    #[Test]
    public function plickers_keeps_its_own_semantics_untouched(): void
    {
        Storage::fake('local');

        $this->actingAs($this->teacher)->post('/imports/correction', [
            'class_id' => $this->class->id,
            'source' => CorrectionGridSource::Plickers->value,
            'file' => $this->fixture(),
        ]);

        $import = app(CurrentOrganization::class)->runFor($this->organization, fn () => CorrectionImport::latest('id')->firstOrFail());
        $props = $this->props($import);

        $this->assertTrue($props['preview']['source_states_participation']);
        $this->assertSame('Resultado na plataforma', $props['preview']['source_result_label']);
        $this->assertFalse($props['preview']['source_needs_describing']);
        // And no mapping screen: its file explains itself (§7).
        $this->assertNull($props['tabular']);
        // Counts are real from the moment it is read.
        $this->assertGreaterThan(0, $props['preview']['counts']['students_in_file']);
        $this->assertSame(ImportMapping::RESULT_OVERALL, $props['preview']['result_mode']);
    }

    // ------------------------------------------------------ 4. choosing the tab

    #[Test]
    public function several_tabs_are_a_question_and_one_tab_is_not(): void
    {
        $several = $this->props($this->uploadSheet(
            GenericSpreadsheetBuilder::multiDomain()
                ->onlySheetNamed('Importar_LAPIS')
                ->sheet('Mapa_Itens')->rows([['Código', 'Descrição'], ['A', 'qualquer']])
                ->sheet('Exemplo')->rows([['Nome', 'Nota'], ['Alguém', 10]]),
            'modelo.xlsx',
        ))['tabular'];

        $this->assertTrue($several['needs_sheet_choice']);
        $this->assertNull($several['selected_sheet'], 'nunca escolher a primeira só por ser a primeira');
        $this->assertSame(['Importar_LAPIS', 'Mapa_Itens', 'Exemplo'], array_column($several['sheets'], 'name'));

        $single = $this->props($this->uploadSheet(
            GenericSpreadsheetBuilder::multiDomain()->onlySheetNamed('Notas'),
            'simples.xlsx',
        ))['tabular'];

        $this->assertFalse($single['needs_sheet_choice']);
        $this->assertSame('Notas', $single['selected_sheet']);
    }

    #[Test]
    public function the_sheet_question_is_asked_in_words_a_teacher_uses(): void
    {
        $wizard = $this->wizard();

        // «Separador» is what the file format calls it; «folha do Excel» is what
        // the teacher is looking at (§3).
        $this->assertStringContainsString(
            'Em que folha do Excel estão os resultados dos alunos?',
            $wizard,
        );
        $this->assertStringContainsString(
            'Este ficheiro contém várias folhas. Escolha aquela que contém a tabela com os nomes dos alunos e os respetivos resultados.',
            $wizard,
        );
        $this->assertStringContainsString(
            'Escolha primeiro a folha do Excel que contém os resultados.',
            $wizard,
        );

        // The word does not appear anywhere the teacher can read it.
        $this->assertStringNotContainsString('separador', $wizard);
    }

    #[Test]
    public function a_csv_is_never_asked_which_sheet_it_is_on(): void
    {
        $tabular = $this->props($this->uploadSheet(GenericSpreadsheetBuilder::multiDomain()))['tabular'];

        // A CSV has exactly one sheet by construction, so the question has no
        // second answer and is never put (§4).
        $this->assertCount(1, $tabular['sheets']);
        $this->assertFalse($tabular['needs_sheet_choice']);
        $this->assertNotNull($tabular['selected_sheet']);

        // And the panel only renders the question when there is a choice.
        $this->assertStringContainsString('v-if="sheetChoices.length > 1"', $this->wizard());
    }

    // ------------------------------------------- 5. the action and what it needs

    #[Test]
    public function the_action_says_what_is_still_missing_instead_of_only_being_disabled(): void
    {
        $wizard = $this->wizard();

        $this->assertStringContainsString('Ler resultados', $wizard);
        $this->assertStringNotContainsString('Ler a folha assim', $wizard);
        $this->assertStringContainsString(':disabled="!sheetIsDescribed || form.processing"', $wizard);

        // Each missing answer names itself, in the order the panel asks (§11).
        foreach ([
            'Escolha primeiro a folha do Excel que contém os resultados.',
            'Falta indicar a coluna com o nome dos alunos.',
            'Falta escolher a coluna com a classificação.',
            'Falta escolher pelo menos uma coluna com resultados.',
        ] as $sentence) {
            $this->assertStringContainsString($sentence, $wizard);
        }
    }

    #[Test]
    public function the_server_refuses_an_incomplete_description_whatever_the_button_does(): void
    {
        $import = $this->uploadSheet(GenericSpreadsheetBuilder::multiDomain());

        // No student column: the interface disables the action, and the server
        // does not depend on it having done so.
        $this->actingAs($this->teacher)->patch("/imports/correction/{$import->ulid}", [
            'mode' => ImportMapping::MODE_CREATE,
            'result_mode' => ImportMapping::RESULT_PER_GROUP,
            'table' => ['sheet' => 'Folha 1', 'header_row' => 1, 'result_columns' => ['B']],
        ]);

        $preview = $this->props($import)['preview'];

        $this->assertFalse($preview['can_confirm']);
        $this->assertSame([], $preview['students']);
    }

    // ---------------------------------------------------- 6. the three modes

    #[Test]
    public function the_modes_are_named_for_the_teacher_and_mapped_to_the_same_three_values(): void
    {
        $wizard = $this->wizard();

        // User-facing for a described sheet…
        foreach ([
            'Uma classificação global por aluno',
            'Resultados por domínio',
            'Resultados por questão',
        ] as $label) {
            $this->assertStringContainsString($label, $wizard);
        }

        // …and the words Intuitivo earned stay Intuitivo's, because there a
        // group is a section of the paper and not a domain.
        $this->assertStringContainsString("label: 'Resultados por grupos'", $wizard);
        $this->assertStringContainsString("label: 'Detalhe por perguntas'", $wizard);

        // Underneath, the same three values as before. Nothing technical shown.
        foreach (['overall', 'per_group', 'per_question'] as $value) {
            $this->assertStringContainsString("value: '{$value}'", $wizard);
        }
    }

    #[Test]
    public function the_three_modes_still_reach_the_pipeline_they_always_did(): void
    {
        $import = $this->uploadSheet(GenericSpreadsheetBuilder::multiDomain());

        foreach ([
            ImportMapping::RESULT_OVERALL => ['B'],
            ImportMapping::RESULT_PER_GROUP => ['B', 'C', 'D'],
            ImportMapping::RESULT_PER_QUESTION => ['B', 'C', 'D'],
        ] as $mode => $columns) {
            $this->actingAs($this->teacher)->patch("/imports/correction/{$import->ulid}", [
                'mode' => ImportMapping::MODE_CREATE,
                'result_mode' => $mode,
                'table' => [
                    'sheet' => 'Folha 1',
                    'header_row' => 1,
                    'student_column' => 'A',
                    'result_columns' => $columns,
                    'overall_maximum' => '20',
                ],
            ]);

            $preview = $this->props($import)['preview'];

            $this->assertSame($mode, $preview['result_mode']);
            $this->assertCount(4, $preview['students'], "modo {$mode} tem de ler a turma toda");
        }
    }

    // --------------------------------------------- 7. no internals on the screen

    #[Test]
    public function source_keys_never_appear_in_the_teachers_vocabulary(): void
    {
        $wizard = $this->wizard();

        // `col:B` is bookkeeping. A column LETTER is what the spreadsheet itself
        // prints at the top of the column, and is how a teacher checks the
        // answer against their own file (§12).
        $this->assertStringNotContainsString("'col:'", $wizard);
        $this->assertStringNotContainsString('col:B', $wizard);
        $this->assertStringContainsString('${heading} (coluna ${letter})', $wizard);
    }

    #[Test]
    public function the_filename_is_shown_once_and_not_three_times(): void
    {
        $wizard = $this->wizard();

        // It was in the page heading, again in a box of its own, and a third
        // time as the suggested title derived from it. Only the heading is left,
        // which is the most discreet of the three and the one already on screen
        // before the teacher scrolls (§2).
        $this->assertStringContainsString(
            "correctionImport.originalFilename ? ' · ' + correctionImport.originalFilename",
            $wizard,
        );

        $this->assertStringNotContainsString('Ficheiro:</span', $this->collapsed($wizard));
        $this->assertStringNotContainsString('Título no ficheiro:', $wizard);
    }

    // ------------------------------------------------ 8. intuitivo is untouched

    #[Test]
    public function intuitivo_keeps_the_language_its_file_earned(): void
    {
        $this->assertSame('Resultado na plataforma', CorrectionGridSource::Intuitivo->resultLabel());
        $this->assertFalse(CorrectionGridSource::Intuitivo->needsToBeDescribed());
        $this->assertSame(ImportMapping::RESULT_PER_GROUP, CorrectionGridSource::Intuitivo->defaultResultMode());

        // And the section wording is still in the component for it.
        $this->assertStringContainsString('Resultados por grupos', $this->wizard());
    }
}
