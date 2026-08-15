<?php

namespace Tests\Feature\Import;

use App\Domain\Import\Correction\CorrectionGridSource;
use App\Domain\Import\Correction\ImportMapping;
use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\CorrectionImport;
use App\Models\CorrectionImportStatus;
use App\Models\Domain;
use App\Models\Enrollment;
use App\Models\Instrument;
use App\Models\InstrumentType;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SchoolClass;
use App\Models\StudentItemScore;
use App\Models\Subject;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\EntitlementsSeeder;
use Database\Seeders\InstrumentTypesSeeder;
use Database\Seeders\SystemScalesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The wizard as a teacher actually reaches it: over HTTP, with a plan, a session
 * and a policy in the way.
 *
 * Two things are being defended here that the service tests cannot reach. One is
 * the entitlement — a Base organization must be refused by the server and not
 * merely by a hidden button, including when it types the URL. The other is
 * tenancy: knowing a ULID has to buy nothing at all.
 */
class CorrectionImportHttpTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;

    protected Organization $organization;

    protected SchoolClass $class;

    protected AcademicPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(EntitlementsSeeder::class);
        $this->seed(SystemScalesSeeder::class);
        $this->seed(InstrumentTypesSeeder::class);

        $this->teacher = User::factory()->create();
        $this->organization = $this->teacher->personalOrganization();
        $this->subscribe($this->organization, 'pro');

        [$this->class, $this->period] = $this->makeClass($this->organization, $this->teacher);
    }

    protected function subscribe(Organization $organization, string $planKey): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())->delete();

        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->getKey(),
            'plan_id' => Plan::where('key', $planKey)->firstOrFail()->getKey(),
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subDay(),
        ]);

        app(Entitlements::class)->flush();
    }

    /**
     * @return array{0: SchoolClass, 1: AcademicPeriod}
     */
    protected function makeClass(Organization $organization, User $teacher): array
    {
        return app(CurrentOrganization::class)->runFor($organization, function () use ($organization, $teacher): array {
            $year = AcademicYear::factory()->recycle($organization)->create();
            $period = AcademicPeriod::factory()->recycle($organization)->for($year)->create();
            $subject = Subject::factory()->recycle($organization)->create();

            $class = SchoolClass::factory()->recycle($organization)->create([
                'academic_year_id' => $year->id,
                'subject_id' => $subject->id,
            ]);
            $class->teachers()->attach($teacher, ['role' => 'owner']);

            Domain::factory()->recycle($organization)->create();

            foreach ([1, 2, 3] as $number) {
                Enrollment::factory()->recycle($organization)->create([
                    'class_id' => $class->id,
                    'class_number' => $number,
                    'enrolled_on' => now()->subMonths(6)->toDateString(),
                ]);
            }

            return [$class, $period];
        });
    }

    protected function fixture(string $name = 'plickers-basico.csv'): UploadedFile
    {
        return new UploadedFile(base_path('tests/Fixtures/Import/'.$name), $name, 'text/csv', null, true);
    }

    // ------------------------------------------------------------------ access

    #[Test]
    public function a_pro_teacher_can_open_the_wizard(): void
    {
        $this->actingAs($this->teacher)
            ->get('/imports/correction/create')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('imports/correction/Create')->has('sources'));
    }

    #[Test]
    public function an_institutional_teacher_can_open_the_wizard(): void
    {
        $this->subscribe($this->organization, 'institutional');

        $this->actingAs($this->teacher)->get('/imports/correction/create')->assertOk();
    }

    #[Test]
    public function a_base_teacher_is_refused_even_by_typing_the_url(): void
    {
        $this->subscribe($this->organization, 'base');

        // Not a hidden button: the route itself is gated (§7).
        $this->actingAs($this->teacher)->get('/imports/correction/create')->assertForbidden();
        $this->actingAs($this->teacher)->post('/imports/correction', [])->assertForbidden();
    }

    #[Test]
    public function only_sources_with_a_working_reader_are_offered(): void
    {
        $this->actingAs($this->teacher)
            ->get('/imports/correction/create')
            ->assertInertia(function (AssertableInertia $page): void {
                $sources = collect($page->toArray()['props']['sources'])->pluck('key')->all();

                // Intuitivo has no parser yet, so it is not offered at all —
                // no dead entries teasing something that does not work (§35).
                $this->assertSame(['plickers'], $sources);
            });
    }

    // ------------------------------------------------------------------ upload

    #[Test]
    public function uploading_a_valid_export_reads_it_and_writes_nothing_academic(): void
    {
        Storage::fake('local');

        $this->actingAs($this->teacher)
            ->post('/imports/correction', [
                'class_id' => $this->class->id,
                'source' => CorrectionGridSource::Plickers->value,
                'file' => $this->fixture(),
            ])
            ->assertRedirect();

        app(CurrentOrganization::class)->runFor($this->organization, function (): void {
            $import = CorrectionImport::firstOrFail();

            $this->assertSame(CorrectionImportStatus::Parsed, $import->status);
            $this->assertCount(3, $import->canonical_snapshot['students']);
            $this->assertCount(3, $import->canonical_snapshot['items']);

            // Analysing is only reading. Nothing has been assessed.
            $this->assertSame(0, Instrument::count());
            $this->assertSame(0, StudentItemScore::count());
        });
    }

    #[Test]
    public function a_file_that_is_not_a_plickers_export_is_refused(): void
    {
        $this->actingAs($this->teacher)
            ->post('/imports/correction', [
                'class_id' => $this->class->id,
                'source' => CorrectionGridSource::Plickers->value,
                'file' => $this->fixture('plickers-malformado.csv'),
            ])
            ->assertSessionHasErrors('file');

        app(CurrentOrganization::class)->runFor($this->organization, fn () => $this->assertSame(0, CorrectionImport::count()));
    }

    #[Test]
    public function a_pdf_is_refused(): void
    {
        // PDF is not supported and must not become supported by accident (§36
        // of the foundation brief).
        $this->actingAs($this->teacher)
            ->post('/imports/correction', [
                'class_id' => $this->class->id,
                'source' => CorrectionGridSource::Plickers->value,
                'file' => UploadedFile::fake()->create('grelha.pdf', 10, 'application/pdf'),
            ])
            ->assertSessionHasErrors('file');
    }

    #[Test]
    public function a_class_from_another_organization_is_refused(): void
    {
        $stranger = User::factory()->create();
        [$otherClass] = $this->makeClass($stranger->personalOrganization(), $stranger);

        // The rule runs inside the tenant scope, so another organization's id
        // simply is not a valid class — not a 403 after the fact, but never a
        // valid input in the first place.
        $this->actingAs($this->teacher)
            ->post('/imports/correction', [
                'class_id' => $otherClass->id,
                'source' => CorrectionGridSource::Plickers->value,
                'file' => $this->fixture(),
            ])
            ->assertSessionHasErrors('class_id');
    }

    // ----------------------------------------------------------------- wizard

    protected function upload(): CorrectionImport
    {
        Storage::fake('local');

        $this->actingAs($this->teacher)->post('/imports/correction', [
            'class_id' => $this->class->id,
            'source' => CorrectionGridSource::Plickers->value,
            'file' => $this->fixture(),
        ]);

        return app(CurrentOrganization::class)->runFor($this->organization, fn () => CorrectionImport::firstOrFail());
    }

    /**
     * @return array<string, mixed>
     */
    protected function completeMapping(): array
    {
        return app(CurrentOrganization::class)->runFor($this->organization, function (): array {
            $enrollments = Enrollment::where('class_id', $this->class->id)->orderBy('class_number')->pluck('id')->all();
            $domainId = Domain::query()->firstOrFail()->id;

            return [
                'mode' => ImportMapping::MODE_CREATE,
                'students' => [
                    'student:1' => $enrollments[0],
                    'student:2' => $enrollments[1],
                    'student:3' => $enrollments[2],
                ],
                'points' => ['item:6' => '2', 'item:7' => '2', 'item:8' => '2'],
                'domains' => [
                    'item:6' => [['domain_id' => $domainId, 'allocation_percent' => '100']],
                    'item:7' => [['domain_id' => $domainId, 'allocation_percent' => '100']],
                    'item:8' => [['domain_id' => $domainId, 'allocation_percent' => '100']],
                ],
                'instrument' => [
                    'title' => 'Grelha importada',
                    'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
                    'applied_on' => now()->subDays(2)->toDateString(),
                    'academic_period_id' => $this->period->id,
                    'purpose' => 'formative',
                    'counts_toward_classification' => true,
                ],
            ];
        });
    }

    #[Test]
    public function a_complete_set_of_decisions_makes_the_import_ready(): void
    {
        $import = $this->upload();

        $this->actingAs($this->teacher)
            ->patch("/imports/correction/{$import->ulid}", $this->completeMapping())
            ->assertRedirect();

        app(CurrentOrganization::class)->runFor($this->organization, function () use ($import): void {
            $this->assertSame(CorrectionImportStatus::Ready, $import->fresh()->status);
        });
    }

    #[Test]
    public function a_student_nobody_decided_about_keeps_the_import_unready(): void
    {
        $import = $this->upload();
        $mapping = $this->completeMapping();
        unset($mapping['students']['student:3']);

        $this->actingAs($this->teacher)->patch("/imports/correction/{$import->ulid}", $mapping);

        app(CurrentOrganization::class)->runFor($this->organization, function () use ($import): void {
            $this->assertSame(CorrectionImportStatus::NeedsMapping, $import->fresh()->status);
        });

        // And confirming is refused outright, not merely discouraged.
        $this->actingAs($this->teacher)->post("/imports/correction/{$import->ulid}/confirm")->assertForbidden();
    }

    #[Test]
    public function a_student_explicitly_ignored_does_not_hold_the_import_back(): void
    {
        $import = $this->upload();
        $mapping = $this->completeMapping();
        $mapping['students']['student:3'] = null;

        $this->actingAs($this->teacher)->patch("/imports/correction/{$import->ulid}", $mapping);

        app(CurrentOrganization::class)->runFor($this->organization, function () use ($import): void {
            // Deciding to leave a row out IS a decision (§20).
            $this->assertSame(CorrectionImportStatus::Ready, $import->fresh()->status);
        });
    }

    #[Test]
    public function a_missing_cotacao_keeps_the_import_unready(): void
    {
        $import = $this->upload();
        $mapping = $this->completeMapping();
        unset($mapping['points']['item:8']);

        $this->actingAs($this->teacher)->patch("/imports/correction/{$import->ulid}", $mapping);

        app(CurrentOrganization::class)->runFor($this->organization, function () use ($import): void {
            $this->assertSame(CorrectionImportStatus::NeedsMapping, $import->fresh()->status);
        });
    }

    #[Test]
    public function a_missing_application_date_keeps_the_import_unready(): void
    {
        $import = $this->upload();
        $mapping = $this->completeMapping();
        $mapping['instrument']['applied_on'] = '';

        $this->actingAs($this->teacher)->patch("/imports/correction/{$import->ulid}", $mapping);

        app(CurrentOrganization::class)->runFor($this->organization, function () use ($import): void {
            // The export's «(25/26)» is a school year. The date is the teacher's
            // to state, and it decides who the instrument applies to (§13).
            $this->assertSame(CorrectionImportStatus::NeedsMapping, $import->fresh()->status);
        });
    }

    #[Test]
    public function an_enrollment_from_another_class_is_dropped_rather_than_trusted(): void
    {
        $stranger = User::factory()->create();
        [$otherClass] = $this->makeClass($stranger->personalOrganization(), $stranger);

        $foreignEnrollment = app(CurrentOrganization::class)->runFor(
            $stranger->personalOrganization(),
            fn () => Enrollment::where('class_id', $otherClass->id)->firstOrFail()->id,
        );

        $import = $this->upload();
        $mapping = $this->completeMapping();
        $mapping['students']['student:1'] = $foreignEnrollment;

        $this->actingAs($this->teacher)->patch("/imports/correction/{$import->ulid}", $mapping);

        app(CurrentOrganization::class)->runFor($this->organization, function () use ($import, $foreignEnrollment): void {
            $stored = $import->fresh()->mapping_snapshot['students'];

            // A request is not a source of truth about who is in a class.
            $this->assertArrayNotHasKey('student:1', $stored);
            $this->assertNotContains($foreignEnrollment, array_values($stored));
        });
    }

    // ---------------------------------------------------------------- confirm

    #[Test]
    public function confirming_creates_the_instrument_and_lands_on_its_grid(): void
    {
        $import = $this->upload();
        $this->actingAs($this->teacher)->patch("/imports/correction/{$import->ulid}", $this->completeMapping());

        $this->actingAs($this->teacher)
            ->post("/imports/correction/{$import->ulid}/confirm")
            ->assertRedirect()
            ->assertSessionHas('success');

        app(CurrentOrganization::class)->runFor($this->organization, function (): void {
            $instrument = Instrument::firstOrFail();

            $this->assertSame('Grelha importada', $instrument->title);
            $this->assertSame('in_correction', $instrument->status->value);
            // Ana's three correct answers and Bruno's one; Carla took no part.
            $this->assertSame(6, StudentItemScore::count());
        });
    }

    #[Test]
    public function confirming_a_second_time_changes_nothing(): void
    {
        $import = $this->upload();
        $this->actingAs($this->teacher)->patch("/imports/correction/{$import->ulid}", $this->completeMapping());
        $this->actingAs($this->teacher)->post("/imports/correction/{$import->ulid}/confirm");

        // An HTTP retry after a timeout looks exactly like this.
        $this->actingAs($this->teacher)->post("/imports/correction/{$import->ulid}/confirm")->assertForbidden();

        app(CurrentOrganization::class)->runFor($this->organization, function (): void {
            $this->assertSame(1, Instrument::count());
            $this->assertSame(6, StudentItemScore::count());
        });
    }

    #[Test]
    public function cancelling_removes_the_file_and_writes_nothing(): void
    {
        $import = $this->upload();
        $path = $import->stored_path;

        Storage::disk('local')->assertExists($path);

        $this->actingAs($this->teacher)->delete("/imports/correction/{$import->ulid}")->assertRedirect();

        Storage::disk('local')->assertMissing($path);

        app(CurrentOrganization::class)->runFor($this->organization, function () use ($import): void {
            $this->assertSame(CorrectionImportStatus::Cancelled, $import->fresh()->status);
            $this->assertSame(0, Instrument::count());
            $this->assertSame(0, StudentItemScore::count());
        });
    }

    // ----------------------------------------------------------------- tenancy

    #[Test]
    public function another_organization_cannot_touch_the_import_even_knowing_its_ulid(): void
    {
        $import = $this->upload();

        $stranger = User::factory()->create();
        $this->subscribe($stranger->personalOrganization(), 'pro');

        // Every verb, all four: the scope is on the query, so the record is not
        // merely forbidden — it does not exist for them.
        $this->actingAs($stranger)->get("/imports/correction/{$import->ulid}")->assertNotFound();
        $this->actingAs($stranger)->patch("/imports/correction/{$import->ulid}", [])->assertNotFound();
        $this->actingAs($stranger)->post("/imports/correction/{$import->ulid}/confirm")->assertNotFound();
        $this->actingAs($stranger)->delete("/imports/correction/{$import->ulid}")->assertNotFound();
    }

    #[Test]
    public function a_base_organization_cannot_confirm_an_import_it_started_on_pro(): void
    {
        $import = $this->upload();
        $this->actingAs($this->teacher)->patch("/imports/correction/{$import->ulid}", $this->completeMapping());

        $this->subscribe($this->organization, 'base');

        $this->actingAs($this->teacher)->post("/imports/correction/{$import->ulid}/confirm")->assertForbidden();

        app(CurrentOrganization::class)->runFor($this->organization, fn () => $this->assertSame(0, Instrument::count()));
    }

    #[Test]
    public function the_wizard_shows_who_took_no_part_without_calling_it_an_absence(): void
    {
        $import = $this->upload();

        $this->actingAs($this->teacher)
            ->get("/imports/correction/{$import->ulid}")
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $preview = $page->toArray()['props']['preview'];

                $this->assertSame(1, $preview['counts']['non_participants']);

                $carla = collect($preview['students'])->firstWhere('source_key', 'student:3');
                $this->assertFalse($carla['participated']);

                // «Não participou» is a fact about the file. «Faltou» is a
                // pedagogical judgement, and nothing here may make it (§18).
                $encoded = (string) json_encode($preview);
                $this->assertStringNotContainsString('Faltou', $encoded);
                $this->assertStringNotContainsString('absent', $encoded);
            });
    }
}
