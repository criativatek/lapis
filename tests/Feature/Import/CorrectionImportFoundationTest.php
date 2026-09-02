<?php

namespace Tests\Feature\Import;

use App\Domain\Import\Correction\CorrectionGridSource;
use App\Models\CorrectionImport;
use App\Models\CorrectionImportStatus;
use App\Models\InstrumentStatus;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SchoolClass;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Support\Entitlements\Entitlements;
use App\Support\Import\CorrectionImportTempStorage;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\EntitlementsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The parts of the import that are about custody rather than about assessment:
 * whose data it is, where the uploaded file lives, how long it lives, and who is
 * allowed to start one at all.
 *
 * These files carry the names and marks of real students, most of them minors,
 * so the tests here are less about features than about promises — the upload is
 * private, its name on disk is not the teacher's name for it, and nobody comes
 * back tomorrow to find it still sitting there.
 */
class CorrectionImportFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(EntitlementsSeeder::class);
        $this->teacher = User::factory()->create();
        $this->organization = $this->teacher->personalOrganization();
    }

    protected function inTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->organization, $callback);
    }

    protected function subscribe(Organization $organization, string $planKey): void
    {
        // Same shape as tests/Feature/Entitlements/RequireModuleTest: replacing
        // the subscription outright, so a downgrade is a downgrade rather than
        // two overlapping plans.
        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->delete();

        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->getKey(),
            'plan_id' => Plan::where('key', $planKey)->firstOrFail()->getKey(),
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::parse('2026-01-01 00:00:00'),
        ]);

        app(Entitlements::class)->flush();
    }

    protected function makeClass(?Organization $organization = null): SchoolClass
    {
        $organization ??= $this->organization;

        return app(CurrentOrganization::class)->runFor(
            $organization,
            fn (): SchoolClass => SchoolClass::factory()->recycle($organization)->create(),
        );
    }

    protected function makeImport(array $attributes = []): CorrectionImport
    {
        return $this->inTenant(fn (): CorrectionImport => CorrectionImport::create([
            'class_id' => $this->makeClass()->getKey(),
            'source' => CorrectionGridSource::Plickers->value,
            'status' => CorrectionImportStatus::Uploaded->value,
            'uploaded_by' => $this->teacher->getKey(),
            ...$attributes,
        ]));
    }

    #[Test]
    public function an_import_belongs_to_the_organization_that_created_it(): void
    {
        $import = $this->makeImport();

        // organization_id is never accepted from a request; it is stamped from
        // the resolved tenant, like everywhere else.
        $this->assertSame($this->organization->getKey(), $import->organization_id);
    }

    #[Test]
    public function another_organization_cannot_see_it_even_knowing_its_ulid(): void
    {
        $import = $this->makeImport();

        $stranger = User::factory()->create();

        app(CurrentOrganization::class)->runFor($stranger->personalOrganization(), function () use ($import): void {
            // Guessing the identifier buys nothing: the scope is on the query,
            // not on the route.
            $this->assertNull(CorrectionImport::where('ulid', $import->ulid)->first());
            $this->assertSame(0, CorrectionImport::count());
        });
    }

    #[Test]
    public function it_is_addressed_by_ulid_and_never_by_a_sequential_id(): void
    {
        $import = $this->makeImport();

        $this->assertSame('ulid', $import->getRouteKeyName());
        $this->assertSame($import->ulid, $import->getRouteKey());
        $this->assertNotSame((string) $import->getKey(), $import->getRouteKey());
    }

    #[Test]
    public function the_class_it_belongs_to_is_scoped_to_the_same_organization(): void
    {
        $import = $this->makeImport();

        $this->inTenant(function () use ($import): void {
            $this->assertSame($this->organization->getKey(), $import->schoolClass->organization_id);
        });
    }

    #[Test]
    public function the_status_is_an_enum_and_not_a_loose_string(): void
    {
        $import = $this->makeImport();

        $this->assertInstanceOf(CorrectionImportStatus::class, $import->status);
        $this->assertTrue($import->status->isOpen());
        $this->assertFalse($import->status->canBeConfirmed(), 'Só um import decidido pode ser confirmado.');

        // And it is deliberately not the instrument's own lifecycle.
        $this->assertNotSame(InstrumentStatus::class, CorrectionImportStatus::class);
    }

    #[Test]
    public function only_a_ready_import_may_be_confirmed_and_never_twice(): void
    {
        $this->assertTrue(CorrectionImportStatus::Ready->canBeConfirmed());

        // Everything else, including one already written: confirming twice must
        // be impossible rather than merely unlikely (§77).
        foreach ([
            CorrectionImportStatus::Uploaded,
            CorrectionImportStatus::Parsed,
            CorrectionImportStatus::NeedsMapping,
            CorrectionImportStatus::Imported,
            CorrectionImportStatus::Failed,
            CorrectionImportStatus::Cancelled,
        ] as $status) {
            $this->assertFalse($status->canBeConfirmed(), "{$status->value} não pode ser confirmado.");
        }
    }

    #[Test]
    public function the_snapshots_survive_a_round_trip_through_the_database(): void
    {
        $import = $this->makeImport([
            'source_metadata' => ['header_row' => 3, 'has_answer_key' => true],
            'canonical_snapshot' => ['items' => [['source_key' => 'i:1', 'points_possible' => null]]],
            'mapping_snapshot' => ['students' => ['student:1' => 42]],
        ]);

        $this->inTenant(function () use ($import): void {
            $fresh = CorrectionImport::findOrFail($import->getKey());

            $this->assertSame(3, $fresh->source_metadata['header_row']);
            $this->assertTrue($fresh->source_metadata['has_answer_key']);
            $this->assertNull($fresh->canonical_snapshot['items'][0]['points_possible'], 'Um null tem de continuar null, e não virar 0.');
            $this->assertSame(42, $fresh->mapping_snapshot['students']['student:1']);
        });
    }

    #[Test]
    public function the_uploaded_file_is_stored_privately_and_never_under_public(): void
    {
        Storage::fake('local');

        $storage = app(CorrectionImportTempStorage::class);
        $source = tempnam(sys_get_temp_dir(), 'lapis');
        file_put_contents($source, "Card Number,First name\n1,Ana");

        $path = $storage->store($source, 'grelha da turma.csv');

        Storage::disk('local')->assertExists($path);
        $this->assertStringStartsWith('correction-imports/', $path);
        $this->assertStringNotContainsString('public', $path);

        @unlink($source);
    }

    #[Test]
    public function the_name_on_disk_is_not_the_name_the_teacher_gave_it(): void
    {
        Storage::fake('local');

        $storage = app(CorrectionImportTempStorage::class);
        $source = tempnam(sys_get_temp_dir(), 'lapis');
        file_put_contents($source, 'x');

        // A filename is user input. User input that becomes a path is how a
        // traversal happens — and the teacher's own name for the file is worth
        // keeping only as a label.
        $path = $storage->store($source, '../../etc/passwd.csv');

        $this->assertStringNotContainsString('passwd', $path);
        $this->assertStringNotContainsString('..', $path);
        $this->assertStringEndsWith('.csv', $path, 'A extensão vem de uma allowlist, não do nome.');

        @unlink($source);
    }

    #[Test]
    public function an_extension_outside_the_allowlist_is_not_carried_onto_disk(): void
    {
        Storage::fake('local');

        $storage = app(CorrectionImportTempStorage::class);
        $source = tempnam(sys_get_temp_dir(), 'lapis');
        file_put_contents($source, 'x');

        // PDF is not supported and never will be by accident (§36).
        $path = $storage->store($source, 'grelha.pdf');

        $this->assertStringNotContainsString('.pdf', $path);

        @unlink($source);
    }

    #[Test]
    public function the_file_is_hashed_so_the_same_upload_can_be_recognised(): void
    {
        $storage = app(CorrectionImportTempStorage::class);
        $source = tempnam(sys_get_temp_dir(), 'lapis');
        file_put_contents($source, 'conteúdo fictício');

        $hash = $storage->hash($source);

        $this->assertSame(64, strlen($hash));
        $this->assertSame(hash('sha256', 'conteúdo fictício'), $hash);

        @unlink($source);
    }

    #[Test]
    public function an_abandoned_import_is_pruned_and_its_file_deleted(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('correction-imports/abandonado.csv', 'dados de alunos');

        $import = $this->makeImport([
            'stored_path' => 'correction-imports/abandonado.csv',
            'canonical_snapshot' => ['items' => []],
        ]);

        // Older than the retention window, and nobody came back.
        $import->forceFill(['updated_at' => now()->subDays(2)])->saveQuietly();

        $this->artisan('correction-imports:prune')->assertSuccessful();

        Storage::disk('local')->assertMissing('correction-imports/abandonado.csv');

        $this->inTenant(function () use ($import): void {
            $fresh = CorrectionImport::findOrFail($import->getKey());

            $this->assertSame(CorrectionImportStatus::Cancelled, $fresh->status);
            $this->assertNull($fresh->stored_path);
            // What the file said is kept; the file is not. That is what makes
            // deleting it safe rather than lossy.
            $this->assertNotNull($fresh->canonical_snapshot);
        });
    }

    #[Test]
    public function a_recent_import_is_left_alone(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('correction-imports/em-curso.csv', 'dados');

        $import = $this->makeImport(['stored_path' => 'correction-imports/em-curso.csv']);

        $this->artisan('correction-imports:prune')->assertSuccessful();

        Storage::disk('local')->assertExists('correction-imports/em-curso.csv');

        $this->inTenant(function () use ($import): void {
            $this->assertSame(CorrectionImportStatus::Uploaded, CorrectionImport::findOrFail($import->getKey())->status);
        });
    }

    #[Test]
    public function an_orphan_file_with_no_import_behind_it_is_pruned_too(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('correction-imports/orfao.csv', 'dados');

        // Backdated past the window: a row deleted, a write that raced a
        // failure — either way nothing will ever come for it.
        touch(Storage::disk('local')->path('correction-imports/orfao.csv'), now()->subDays(3)->getTimestamp());

        $this->artisan('correction-imports:prune')->assertSuccessful();

        Storage::disk('local')->assertMissing('correction-imports/orfao.csv');
    }

    #[Test]
    public function the_base_plan_does_not_include_correction_grid_import(): void
    {
        $this->subscribe($this->organization, 'base');

        $this->inTenant(function (): void {
            $this->assertFalse(app(Entitlements::class)->allows('correction_grid_import'));

            // And the Base plan stays a complete assessment tool: what it loses
            // is a shortcut, not the ability to assess (§4).
            foreach (['instruments', 'assessments', 'results', 'classes', 'students'] as $module) {
                $this->assertTrue(app(Entitlements::class)->allows($module), "A Base tem de manter «{$module}».");
            }
        });
    }

    #[Test]
    public function the_pro_plan_includes_it(): void
    {
        $this->subscribe($this->organization, 'pro');

        $this->inTenant(function (): void {
            $this->assertTrue(app(Entitlements::class)->allows('correction_grid_import'));
        });
    }

    #[Test]
    public function the_institutional_plan_includes_it(): void
    {
        $this->subscribe($this->organization, 'institutional');

        $this->inTenant(function (): void {
            $this->assertTrue(app(Entitlements::class)->allows('correction_grid_import'));
        });
    }

    #[Test]
    public function there_is_one_capability_for_every_source_and_not_one_per_platform(): void
    {
        // Plickers and Intuitivo are file formats this reads, not products sold
        // separately. Gating them one by one would make the commercial offer
        // depend on which parsers happen to exist (§3).
        $modules = Module::pluck('key')->all();

        $this->assertContains('correction_grid_import', $modules);

        foreach (['plickers_import', 'intuitivo_import', 'excel_import'] as $forbidden) {
            $this->assertNotContains($forbidden, $modules);
        }
    }

    #[Test]
    public function losing_the_plan_does_not_delete_anything_already_imported(): void
    {
        $this->subscribe($this->organization, 'pro');
        $import = $this->makeImport(['status' => CorrectionImportStatus::Imported->value]);

        // Downgrade. The organization loses the ability to START an import; the
        // marks it already produced are its own academic record and are not the
        // subscription's to take away (§34, §89).
        $this->subscribe($this->organization, 'base');

        $this->inTenant(function () use ($import): void {
            $this->assertFalse(app(Entitlements::class)->allows('correction_grid_import'));
            $this->assertNotNull(CorrectionImport::find($import->getKey()), 'A provenance da importação não desaparece com o plano.');
        });
    }
}
