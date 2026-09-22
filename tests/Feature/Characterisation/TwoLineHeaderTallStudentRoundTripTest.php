<?php

namespace Tests\Feature\Characterisation;

use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Characterisation\TwoLineHeaderTallStudentFixture as Fixture;

/**
 * JANELA N — the same two-request round trip JANELA L covered, on the
 * geometry a real «Medidas» workbook turned out to have (see the fixture).
 *
 * Extends the JANELA L case so request B is built exactly as
 * CharacterisationImportDialog.vue's applyStructuralCorrections() builds it,
 * out of request A's own `structural` response — the only preview that
 * counts is the one the teacher actually gets, and it is the SECOND request.
 *
 * The class list is enrolled from THIS fixture's names, not the parent's, so
 * matching has real students to find.
 */
class TwoLineHeaderTallStudentRoundTripTest extends CharacterisationStructuralRoundTripTest
{
    protected function enrolledStudentNames(): array
    {
        return array_map(
            fn (string $name): string => trim((string) preg_replace('/^\d{1,3}\s*-\s*/u', '', $name)),
            Fixture::studentNames(),
        );
    }

    #[Test]
    public function the_real_geometry_survives_the_round_trip_as_xlsx(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'janela_n').'.xlsx';
        Fixture::writeXlsx($path);

        try {
            [$first, $structuralRows] = $this->requestA([
                'file' => new UploadedFile($path, 'medidas.xlsx', null, null, true),
            ]);
        } finally {
            @unlink($path);
        }

        $this->assertRoundTrip($first, $structuralRows);
    }

    #[Test]
    public function the_real_geometry_survives_the_round_trip_as_word_html(): void
    {
        [$first, $structuralRows] = $this->requestA([
            'pasted_html' => Fixture::toWordClipboardHtml(),
        ]);

        $this->assertRoundTrip($first, $structuralRows);
    }

    /**
     * @param  list<array{number: int, kind: string, cells: list<string>}>  $structuralRows
     */
    private function assertRoundTrip(mixed $first, array $structuralRows): void
    {
        $kinds = array_column($structuralRows, 'kind');

        // Request A — twelve students, two captions, and a second header row
        // that is a header rather than a thirteenth student.
        $this->assertSame(
            count(Fixture::studentNames()),
            count(array_filter($kinds, fn (string $kind): bool => $kind === 'data')),
            'Request A already lost or duplicated a student — the round trip is not what failed here.',
        );

        $this->assertSame(2, count(array_filter($kinds, fn (string $kind): bool => $kind === 'group')));
        $this->assertSame(2, count(array_filter($kinds, fn (string $kind): bool => $kind === 'header')));

        // Request B — what the dialog posts when she clicks «Continuar».
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

        $this->assertSame(array_column($first->json('preview.rows'), 'raw_name'), $names);
        $this->assertNotEmpty($names);

        $this->assertNotContains(Fixture::groupCaptionWithRtp(), $names);
        $this->assertNotContains(Fixture::groupCaptionWithoutRtp(), $names);

        // The gate the teacher feels: students the importer is ready to
        // write, not merely rows it managed to read.
        $ready = array_filter(
            $second->json('preview.rows'),
            fn (array $row): bool => ($row['match']['enrollment_ulid'] ?? null) !== null,
        );

        $this->assertNotEmpty($ready, 'No student in the preview was matched to a real enrolment.');
    }
}
