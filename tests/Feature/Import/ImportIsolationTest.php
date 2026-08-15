<?php

namespace Tests\Feature\Import;

use App\Domain\Import\Correction\CorrectionGridSource;
use App\Models\CorrectionImport;
use App\Models\CorrectionImportStatus;
use App\Models\Enrollment;
use App\Models\Instrument;
use App\Models\StudentItemScore;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

/**
 * One file, one import, no memory of the last one.
 *
 * A teacher who analyses a second file and is shown the first one's class will
 * either notice and lose their trust in the feature, or not notice and put
 * thirty students' marks somewhere they do not belong. Both are unacceptable,
 * and the second is unacceptable quietly.
 *
 * These tests hold the two halves apart. The server has to mint a genuinely new
 * import per analysis — new identifier, new hash, new snapshot — and the page
 * has to read only from the import named in the URL it is currently on.
 */
class ImportIsolationTest extends CorrectionImportHttpTest
{
    protected function analyse(string $fixture): CorrectionImport
    {
        Storage::fake('local');

        $this->actingAs($this->teacher)->post('/imports/correction', [
            'class_id' => $this->class->id,
            'source' => CorrectionGridSource::Plickers->value,
            'file' => new UploadedFile(base_path('tests/Fixtures/Import/'.$fixture), $fixture, 'text/csv', null, true),
        ])->assertRedirect();

        return app(CurrentOrganization::class)->runFor(
            $this->organization,
            fn () => CorrectionImport::latest('id')->firstOrFail(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function previewOf(CorrectionImport $import): array
    {
        return $this->actingAs($this->teacher)
            ->get("/imports/correction/{$import->ulid}")
            ->assertOk()
            ->viewData('page')['props']['preview'];
    }

    #[Test]
    public function analysing_a_second_file_creates_a_second_import(): void
    {
        $first = $this->analyse('plickers-basico.csv');
        $second = $this->analyse('plickers-seis-alunos.csv');

        $this->assertNotSame($first->ulid, $second->ulid, 'Cada análise é uma importação nova.');
        $this->assertNotSame($first->getKey(), $second->getKey());
        $this->assertNotSame($first->file_sha256, $second->file_sha256, 'Ficheiros diferentes, hashes diferentes.');
        $this->assertNotSame($first->stored_path, $second->stored_path);
        $this->assertSame('plickers-basico.csv', $first->original_filename);
        $this->assertSame('plickers-seis-alunos.csv', $second->original_filename);
    }

    #[Test]
    public function the_second_import_carries_only_the_second_file(): void
    {
        $this->analyse('plickers-basico.csv');
        $second = $this->analyse('plickers-seis-alunos.csv');

        $preview = $this->previewOf($second);

        $this->assertCount(6, $preview['students']);
        $this->assertCount(2, $preview['items']);
        $this->assertSame(6, $preview['counts']['students_in_file']);

        // Not one name from the first file survives into the second.
        $names = array_map(fn (array $student): ?string => $student['display_name'], $preview['students']);

        foreach (['Ana Exemplo', 'Bruno Exemplo', 'Carla Exemplo'] as $fromTheFirstFile) {
            $this->assertNotContains($fromTheFirstFile, $names);
        }

        $this->assertContains('Diogo Segundo', $names);
    }

    #[Test]
    public function going_back_to_the_first_import_still_shows_the_first_file(): void
    {
        $first = $this->analyse('plickers-basico.csv');
        $this->analyse('plickers-seis-alunos.csv');

        // B did not overwrite A. Each import is its own record and stays so.
        $preview = $this->previewOf($first);

        $this->assertCount(3, $preview['students']);
        $this->assertCount(3, $preview['items']);

        $names = array_map(fn (array $student): ?string => $student['display_name'], $preview['students']);
        $this->assertContains('Ana Exemplo', $names);
        $this->assertNotContains('Diogo Segundo', $names);
    }

    #[Test]
    public function re_analysing_the_first_file_produces_a_third_import_with_the_first_contents(): void
    {
        $this->analyse('plickers-basico.csv');
        $second = $this->analyse('plickers-seis-alunos.csv');
        $again = $this->analyse('plickers-basico.csv');

        $this->assertNotSame($second->ulid, $again->ulid);

        $preview = $this->previewOf($again);

        $this->assertCount(3, $preview['students']);
        $this->assertSame('plickers-basico.csv', $again->original_filename);

        // Re-importing the same export is legitimate, so it is a warning and
        // never a refusal — but the hash does have to be recognised.
        app(CurrentOrganization::class)->runFor($this->organization, function () use ($again): void {
            $this->assertSame(3, CorrectionImport::count());
            $this->assertNotNull($again->file_sha256);
        });
    }

    #[Test]
    public function the_page_names_the_file_it_is_working_on(): void
    {
        $second = $this->analyse('plickers-seis-alunos.csv');

        $props = $this->actingAs($this->teacher)
            ->get("/imports/correction/{$second->ulid}")
            ->viewData('page')['props'];

        // The teacher must be able to tell at a glance which file this is (§7).
        $this->assertSame('plickers-seis-alunos.csv', $props['correctionImport']['originalFilename']);
        $this->assertSame('Outro Teste - 9Z (25/26)', $props['preview']['suggested_title']);
        $this->assertSame(6, $props['preview']['counts']['students_in_file']);
    }

    #[Test]
    public function a_cancelled_import_stops_being_reachable_and_a_new_one_is_clean(): void
    {
        $a = $this->analyse('plickers-basico.csv');
        $storedPath = $a->stored_path;

        Storage::disk('local')->assertExists($storedPath);

        // Cancel through the ordinary action, and land back on step 1 — a
        // teacher cancelling is almost always about to try another file.
        $this->actingAs($this->teacher)
            ->delete("/imports/correction/{$a->ulid}")
            ->assertRedirect('/imports/correction/create');

        Storage::disk('local')->assertMissing($storedPath);

        app(CurrentOrganization::class)->runFor($this->organization, function () use ($a): void {
            $fresh = $a->fresh();

            $this->assertSame(CorrectionImportStatus::Cancelled, $fresh->status);
            $this->assertNull($fresh->stored_path);
        });

        // A is no longer a wizard: opening it sends the teacher back to step 1
        // rather than rendering a preview of something discarded.
        $this->actingAs($this->teacher)
            ->get("/imports/correction/{$a->ulid}")
            ->assertRedirect('/imports/correction/create');

        // And B carries nothing of A.
        $b = $this->analyse('plickers-seis-alunos.csv');
        $preview = $this->previewOf($b);

        $this->assertNotSame($a->ulid, $b->ulid);
        $this->assertCount(6, $preview['students']);

        $names = array_map(fn (array $student): ?string => $student['display_name'], $preview['students']);
        $this->assertNotContains('Ana Exemplo', $names);
    }

    #[Test]
    public function cancelling_touches_nothing_academic(): void
    {
        $a = $this->analyse('plickers-basico.csv');

        $before = app(CurrentOrganization::class)->runFor(
            $this->organization,
            fn (): array => [Instrument::count(), StudentItemScore::count(), Enrollment::count()],
        );

        $this->actingAs($this->teacher)->delete("/imports/correction/{$a->ulid}");

        $after = app(CurrentOrganization::class)->runFor(
            $this->organization,
            fn (): array => [Instrument::count(), StudentItemScore::count(), Enrollment::count()],
        );

        // Only the conversation is discarded. Students, instruments and marks
        // are not the import's to remove.
        $this->assertSame($before, $after);
    }

    #[Test]
    public function a_confirmed_import_cannot_be_cancelled_as_if_it_were_temporary(): void
    {
        $import = $this->analyse('plickers-basico.csv');
        $this->actingAs($this->teacher)->patch("/imports/correction/{$import->ulid}", $this->completeMapping());
        $this->actingAs($this->teacher)->post("/imports/correction/{$import->ulid}/confirm");

        // The marks exist now and belong to an instrument, which has its own
        // rules for undoing things (§6).
        $this->actingAs($this->teacher)
            ->delete("/imports/correction/{$import->ulid}")
            ->assertForbidden();

        app(CurrentOrganization::class)->runFor($this->organization, function (): void {
            $this->assertSame(1, Instrument::count());
            $this->assertSame(6, StudentItemScore::count());
        });
    }

    #[Test]
    public function opening_a_confirmed_import_goes_to_its_grid(): void
    {
        $import = $this->analyse('plickers-basico.csv');
        $this->actingAs($this->teacher)->patch("/imports/correction/{$import->ulid}", $this->completeMapping());
        $this->actingAs($this->teacher)->post("/imports/correction/{$import->ulid}/confirm");

        $instrument = app(CurrentOrganization::class)->runFor($this->organization, fn () => Instrument::firstOrFail());

        $this->actingAs($this->teacher)
            ->get("/imports/correction/{$import->ulid}")
            ->assertRedirect("/instruments/{$instrument->ulid}");
    }

    #[Test]
    public function the_wizard_offers_cancelling_from_every_step(): void
    {
        $wizard = $this->componentSource('resources/js/pages/imports/correction/Wizard.vue');

        // In the header, not buried in the last step: the moment a teacher
        // realises it is the wrong file is usually while looking at the
        // students, not three screens later.
        $this->assertStringContainsString('Cancelar importação', $wizard);
        $this->assertStringContainsString('Cancelar esta importação?', $wizard);
        $this->assertStringContainsString('Nenhuma avaliação já existente será', $wizard);
        $this->assertMatchesRegularExpression('/const canCancel = computed/', $wizard);
    }

    /**
     * The same filename, twice, carrying different classes.
     *
     * Two exports leave a platform under the same name constantly — «teste.csv»,
     * or whatever the browser saves a download as. If anything at all keyed an
     * import off that string, the second analysis would reuse the first one's
     * reading, and the teacher would be shown thirty students belonging to
     * another class while believing they were looking at six.
     *
     * The upload is deliberately given a name that has nothing to do with the
     * fixture on disk: that is exactly what a browser sends.
     */
    protected function analyseAs(string $fixture, string $sentAs): CorrectionImport
    {
        Storage::fake('local');

        $this->actingAs($this->teacher)->post('/imports/correction', [
            'class_id' => $this->class->id,
            'source' => CorrectionGridSource::Plickers->value,
            'file' => new UploadedFile(base_path('tests/Fixtures/Import/'.$fixture), $sentAs, 'text/csv', null, true),
        ])->assertRedirect();

        return app(CurrentOrganization::class)->runFor(
            $this->organization,
            fn () => CorrectionImport::latest('id')->firstOrFail(),
        );
    }

    #[Test]
    public function the_same_filename_with_different_contents_is_a_different_import(): void
    {
        // A: thirty students. B: six. Both called teste.csv.
        $a = $this->analyseAs('plickers-trinta-alunos.csv', 'teste.csv');
        $b = $this->analyseAs('plickers-seis-alunos.csv', 'teste.csv');

        $this->assertSame('teste.csv', $a->original_filename);
        $this->assertSame('teste.csv', $b->original_filename);

        // Identity comes from the content and from nothing else.
        $this->assertNotSame($a->ulid, $b->ulid);
        $this->assertNotSame($a->file_sha256, $b->file_sha256);
        $this->assertNotSame($a->stored_path, $b->stored_path);

        // The stored name is random, so two same-named uploads cannot collide
        // on disk and the second cannot overwrite the first.
        $this->assertStringNotContainsString('teste', (string) $a->stored_path);
        $this->assertStringNotContainsString('teste', (string) $b->stored_path);

        $this->assertCount(30, $a->canonical_snapshot['students']);
        $this->assertCount(6, $b->canonical_snapshot['students']);

        $preview = $this->previewOf($b);
        $this->assertCount(6, $preview['students']);
        $this->assertSame(6, $preview['counts']['students_in_file']);

        $names = array_map(fn (array $student): ?string => $student['display_name'], $preview['students']);

        foreach (['Adriana Trinta', 'Bento Trinta', 'Clara Trinta'] as $fromA) {
            $this->assertNotContains($fromA, $names);
        }

        $this->assertContains('Diogo Segundo', $names);
    }

    #[Test]
    public function the_same_filename_the_other_way_round_is_no_different(): void
    {
        // B then A, same name again: whichever came first must not leak forward.
        $b = $this->analyseAs('plickers-seis-alunos.csv', 'teste.csv');
        $a = $this->analyseAs('plickers-trinta-alunos.csv', 'teste.csv');

        $this->assertNotSame($b->ulid, $a->ulid);
        $this->assertNotSame($b->file_sha256, $a->file_sha256);
        $this->assertNotSame($b->stored_path, $a->stored_path);

        $preview = $this->previewOf($a);

        $this->assertCount(30, $preview['students']);
        $this->assertSame(30, $preview['counts']['students_in_file']);

        $names = array_map(fn (array $student): ?string => $student['display_name'], $preview['students']);

        foreach (['Diogo Segundo', 'Eva Segundo', 'Ivo Segundo'] as $fromB) {
            $this->assertNotContains($fromB, $names);
        }

        $this->assertContains('Adriana Trinta', $names);

        // And B is still itself: a second analysis is a new record, never an
        // overwrite of the previous one.
        $this->assertCount(6, $this->previewOf($b)['students']);
    }

    #[Test]
    public function choosing_a_file_again_always_reaches_the_server(): void
    {
        $create = $this->componentSource('resources/js/pages/imports/correction/Create.vue');

        // The half of this the server cannot defend. A file input fires `change`
        // only when its value changes, so re-picking a file at the same path —
        // a fresh export written over the old one — fires nothing, and the
        // PREVIOUS File object is what gets uploaded. Emptying the control after
        // reading it makes every later selection a change from nothing.
        $this->assertMatchesRegularExpression('/input\.value\s*=\s*\'\'/', $create);
        $this->assertStringContainsString('fileDetail', $create, 'O ecrã tem de distinguir dois ficheiros com o mesmo nome.');
    }

    #[Test]
    public function the_wizard_rebuilds_its_state_when_the_import_in_the_url_changes(): void
    {
        // Inertia reuses a component when only the props change, so anything
        // derived from props at setup time survives a navigation between two
        // imports. That is exactly how one file's students end up on another
        // file's screen, and it is why this watcher exists rather than being
        // left to the component lifecycle.
        $wizard = $this->componentSource('resources/js/pages/imports/correction/Wizard.vue');

        $this->assertMatchesRegularExpression(
            '/watch\(\s*\(\)\s*=>\s*props\.correctionImport\.ulid/',
            $wizard,
            'O wizard tem de reagir a uma mudança de importação na rota.',
        );
        $this->assertStringContainsString('form.defaults(', $wizard);
    }
}
