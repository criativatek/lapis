<?php

namespace Tests\Feature\Characterisation;

use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Services\StudentEnrollmentService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Characterisation\MultilevelHeaderFixture;
use Tests\TestCase;

/**
 * JANELA L — the round trip BETWEEN the two preview requests.
 *
 * The structural review step (§38/§39) is two requests, not one: request A
 * reads the source and proposes a classification per row; request B sends
 * that classification back — possibly corrected by the teacher — and expects
 * a per-student preview. Every existing test covers ONE of the two, with a
 * hand-written payload in between. That is exactly the gap this class closes:
 * request B here is built the way CharacterisationImportDialog.vue's
 * applyStructuralCorrections() builds it, out of request A's OWN `structural`
 * response, so a classification that survives on screen and dies on the wire
 * has somewhere to fail.
 *
 * Production 0.152.2: request A classified the rows correctly and request B
 * answered «todas as linhas foram identificadas como rodapé ou totais».
 */
class CharacterisationStructuralRoundTripTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->user = User::factory()->create();
        $this->class = $this->createClass($this->user);

        $organization = $this->user->personalOrganization();

        app(CurrentOrganization::class)->runFor($organization, function () {
            foreach (MultilevelHeaderFixture::studentNames() as $name) {
                app(StudentEnrollmentService::class)->enrollNew($this->class, ['name' => $name]);
            }
        });
    }

    private function createClass(User $owner, string $label = '7.º A'): SchoolClass
    {
        $organization = $owner->personalOrganization();

        $context = app(CurrentOrganization::class)->runFor($organization, fn (): array => [
            'year' => AcademicYear::factory()->recycle($organization)
                ->create(['starts_on' => '2026-09-14', 'ends_on' => '2027-06-30'])->id,
            'subject' => Subject::factory()->recycle($organization)->create()->id,
        ]);

        $this->actingAs($owner)->post('/classes', [
            'label' => $label,
            'academic_year_id' => $context['year'],
            'subject_id' => $context['subject'],
        ]);

        return SchoolClass::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->where('label', $label)
            ->firstOrFail();
    }

    private function previewUrl(): string
    {
        return '/classes/'.$this->class->ulid.'/characterisation-imports/preview';
    }

    /**
     * What applyStructuralCorrections() posts: every non-ignored row, in
     * order, re-indexed from 0, cells re-numbered from 0, kind carried
     * verbatim from what the grid showed.
     *
     * @param  list<array{number: int, kind: string, cells: list<string>}>  $structuralRows
     * @param  array<int, string>  $kindOverrides  row number => kind ('ignore' drops the row)
     * @return array<string, mixed>
     */
    private function correctedTableFrom(array $structuralRows, string $sourceType, array $kindOverrides = []): array
    {
        $rows = [];

        foreach ($structuralRows as $structuralRow) {
            $kind = $kindOverrides[$structuralRow['number']] ?? $structuralRow['kind'];

            if ($kind === 'ignore') {
                continue;
            }

            $rowIndex = count($rows);

            $cells = [];

            foreach (array_values($structuralRow['cells']) as $column => $text) {
                $cells[] = [
                    'text' => $text,
                    'row' => $rowIndex,
                    'column' => $column,
                    'colspan' => 1,
                    'rowspan' => 1,
                    'confidence' => null,
                ];
            }

            $rows[] = [
                'index' => $rowIndex,
                'kind' => $kind,
                'cells' => $cells,
            ];
        }

        return [
            'source_type' => $sourceType,
            'source_filename' => null,
            'warnings' => [],
            'extraction_confidence' => 1,
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{0: TestResponse, 1: list<array{number: int, kind: string, cells: list<string>}>}
     */
    private function requestA(array $body): array
    {
        $response = $this->actingAs($this->user)->postJson($this->previewUrl(), $body);
        $response->assertOk();

        return [$response, $response->json('structural.rows')];
    }

    #[Test]
    public function a_word_html_table_survives_the_round_trip_between_the_two_requests(): void
    {
        [$first, $structuralRows] = $this->requestA([
            'pasted_html' => MultilevelHeaderFixture::toWordClipboardHtml(),
        ]);

        $first->assertJsonPath('show_structural_step', true);

        $kinds = array_column($structuralRows, 'kind');

        // Request A: the classification the teacher sees and approves.
        $this->assertContains('header', $kinds);
        $this->assertContains('group', $kinds);
        $this->assertSame(
            count(MultilevelHeaderFixture::studentNames()),
            count(array_filter($kinds, fn (string $kind) => $kind === 'data')),
            'Request A already lost a student row — the round trip is not what failed here.',
        );

        // Request B: exactly what the dialog posts when she clicks «Continuar».
        $second = $this->actingAs($this->user)->postJson($this->previewUrl(), [
            'extracted_table' => json_encode($this->correctedTableFrom($structuralRows, 'corrected_pasted_html')),
        ]);

        $second->assertOk()->assertJsonPath('show_structural_step', false);

        $this->assertNotSame(
            $second->json('preview.data_row_count'),
            $second->json('preview.footer_row_count'),
            'Every data row was skipped as a footer/total on the second request.',
        );

        $names = array_column($second->json('preview.rows'), 'raw_name');

        // Request A and request B must describe the SAME table. A student
        // whose row carries nothing to import is legitimately absent from
        // both previews (BuildCharacterisationPreview::hasContent()); what
        // must never differ is WHICH students each request saw.
        $this->assertSame(array_column($first->json('preview.rows'), 'raw_name'), $names);
        $this->assertNotEmpty($names);

        $this->assertNotContains(MultilevelHeaderFixture::groupCaptionWithRtp(), $names);
        $this->assertNotContains(MultilevelHeaderFixture::groupCaptionWithoutRtp(), $names);
        $this->assertNotContains(MultilevelHeaderFixture::legendLine(), $names);
    }

    #[Test]
    public function an_xlsx_table_survives_the_round_trip_between_the_two_requests(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'carac').'.xlsx';
        MultilevelHeaderFixture::writeXlsx($path);

        [$first, $structuralRows] = $this->requestA([
            'file' => new UploadedFile($path, 'caracterizacao.xlsx', null, null, true),
        ]);

        $first->assertJsonPath('show_structural_step', true);

        $second = $this->actingAs($this->user)->postJson($this->previewUrl(), [
            'extracted_table' => json_encode($this->correctedTableFrom($structuralRows, 'corrected_pasted_html')),
        ]);

        $second->assertOk();

        $this->assertNotSame(
            $second->json('preview.data_row_count'),
            $second->json('preview.footer_row_count'),
        );

        $this->assertSame(
            array_column($first->json('preview.rows'), 'raw_name'),
            array_column($second->json('preview.rows'), 'raw_name'),
        );

        $this->assertNotEmpty($second->json('preview.rows'));

        @unlink($path);
    }

    #[Test]
    public function a_row_the_teacher_reclassifies_by_hand_keeps_her_kind_through_the_round_trip(): void
    {
        [, $structuralRows] = $this->requestA([
            'pasted_html' => MultilevelHeaderFixture::toWordClipboardHtml(),
        ]);

        // She demotes the first student row to a Group caption.
        $firstDataRow = null;

        foreach ($structuralRows as $structuralRow) {
            if ($structuralRow['kind'] === 'data') {
                $firstDataRow = $structuralRow;

                break;
            }
        }

        $this->assertNotNull($firstDataRow);

        $second = $this->actingAs($this->user)->postJson($this->previewUrl(), [
            'extracted_table' => json_encode($this->correctedTableFrom(
                $structuralRows,
                'corrected_pasted_html',
                [$firstDataRow['number'] => 'group'],
            )),
        ]);

        $second->assertOk();

        $names = array_column($second->json('preview.rows'), 'raw_name');

        $this->assertNotEmpty($names);
        $this->assertNotContains(MultilevelHeaderFixture::studentNames()[0], $names);
    }

    /**
     * THE PRODUCTION DEFECT, reduced to its smallest honest shape.
     *
     * A banner above the header split into TWO merged blocks — «Diretor de
     * turma: …» and «N.º de alunos: 24», the ordinary top of a real
     * caracterização sheet. Request A ignores it correctly and reads the
     * student-name column by content. Request B used to receive that banner
     * tagged 'header' and join it into every column name — «N.º de alunos: 24
     * Medidas MU» — which loses the name column outright, and a table with no
     * name column reports every row as a footer/total.
     */
    #[Test]
    public function a_banner_above_the_header_never_becomes_part_of_the_columns_on_the_second_request(): void
    {
        $html = <<<'HTML'
            <html><body><table>
            <tr><td colspan="6">Caracterização da turma — 8.º A</td></tr>
            <tr><td colspan="2">Diretor de turma: A. B.</td><td colspan="4">N.º de alunos: 24</td></tr>
            <tr><td rowspan="2"></td><td rowspan="2">RTP/PEI</td><td colspan="2">Medidas</td><td rowspan="2">Apoios</td><td rowspan="2">Observações</td></tr>
            <tr><td>MU</td><td>MS</td></tr>
            <tr><td colspan="6">Alunos com RTP</td></tr>
            <tr><td>Mariana Fonseca</td><td>X</td><td>MU a)</td><td></td><td>M</td><td>Observação.</td></tr>
            <tr><td>Tiago Nogueira</td><td>X</td><td></td><td>MS b)</td><td></td><td>Observação.</td></tr>
            </table></body></html>
            HTML;

        [$first, $structuralRows] = $this->requestA(['pasted_html' => $html]);

        $first->assertJsonPath('show_structural_step', true);

        $second = $this->actingAs($this->user)->postJson($this->previewUrl(), [
            'extracted_table' => json_encode($this->correctedTableFrom($structuralRows, 'corrected_pasted_html')),
        ]);

        $second->assertOk();

        $this->assertNotSame(
            $second->json('preview.data_row_count'),
            $second->json('preview.footer_row_count'),
            'Every data row was skipped as a footer/total on the second request.',
        );

        $this->assertSame(
            array_column($first->json('preview.rows'), 'raw_name'),
            array_column($second->json('preview.rows'), 'raw_name'),
        );

        $this->assertContains('Mariana Fonseca', array_column($second->json('preview.rows'), 'raw_name'));

        // The banner's own text never reaches a column name.
        foreach ($second->json('structural.headers') as $header) {
            $this->assertStringNotContainsString('N.º de alunos', (string) $header);
            $this->assertStringNotContainsString('Diretor de turma', (string) $header);
        }
    }

    #[Test]
    public function a_banner_above_the_header_reaches_the_review_grid_as_an_ignored_row_not_a_header(): void
    {
        $html = <<<'HTML'
            <html><body><table>
            <tr><td colspan="6">Caracterização da turma — 8.º A</td></tr>
            <tr><td colspan="2">Diretor de turma: A. B.</td><td colspan="4">N.º de alunos: 24</td></tr>
            <tr><td rowspan="2"></td><td rowspan="2">RTP/PEI</td><td colspan="2">Medidas</td><td rowspan="2">Apoios</td><td rowspan="2">Observações</td></tr>
            <tr><td>MU</td><td>MS</td></tr>
            <tr><td colspan="6">Alunos com RTP</td></tr>
            <tr><td>Mariana Fonseca</td><td>X</td><td>MU a)</td><td></td><td>M</td><td>Observação.</td></tr>
            </table></body></html>
            HTML;

        [, $structuralRows] = $this->requestA(['pasted_html' => $html]);

        $byNumber = collect($structuralRows)->keyBy('number');

        // The banner is still SHOWN — the teacher must be able to see and
        // reclassify it — but never as «Cabeçalho».
        $this->assertSame('legend', $byNumber[2]['kind']);

        // The real header levels keep their own kind.
        $this->assertSame('header', $byNumber[3]['kind']);
        $this->assertSame('header', $byNumber[4]['kind']);
        $this->assertSame('data', $byNumber[6]['kind']);
    }

    #[Test]
    public function an_ignored_column_stays_ignored_across_the_round_trip(): void
    {
        [, $structuralRows] = $this->requestA([
            'pasted_html' => MultilevelHeaderFixture::toWordClipboardHtml(),
        ]);

        // Drop the observations column (the last one) the way
        // toggleIgnoredColumn() + keptColumnIndices do.
        $corrected = $this->correctedTableFrom($structuralRows, 'corrected_pasted_html');

        foreach ($corrected['rows'] as $rowIndex => $row) {
            $cells = $row['cells'];
            array_pop($cells);
            $corrected['rows'][$rowIndex]['cells'] = $cells;
        }

        $second = $this->actingAs($this->user)->postJson($this->previewUrl(), [
            'extracted_table' => json_encode($corrected),
        ]);

        $second->assertOk();

        $this->assertNotContains(
            'Outras medidas/recursos / Observações',
            $second->json('structural.headers'),
        );

        $this->assertNotEmpty($second->json('preview.rows'));
    }

    /**
     * JANELA L §7: the absence of a classification is a structural error on
     * a corrected resubmission, never a silent fallback to «rodapé».
     */
    #[Test]
    public function a_corrected_table_with_an_unclassified_row_is_refused_by_name(): void
    {
        [, $structuralRows] = $this->requestA([
            'pasted_html' => MultilevelHeaderFixture::toWordClipboardHtml(),
        ]);

        $corrected = $this->correctedTableFrom($structuralRows, 'corrected_pasted_html');
        unset($corrected['rows'][1]['kind']);

        $this->actingAs($this->user)
            ->postJson($this->previewUrl(), ['extracted_table' => json_encode($corrected)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('extracted_table');
    }
}
