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
use App\Models\InstrumentStatus;
use App\Models\InstrumentType;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\ResultState;
use App\Models\SchoolClass;
use App\Models\StudentItemScore;
use App\Models\Subject;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Import\Correction\ImportCorrectionGrid;
use App\Services\Import\Correction\PlickersCsvParser;
use App\Support\Entitlements\Entitlements;
use App\Support\Import\CorrectionImportException;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\EntitlementsSeeder;
use Database\Seeders\InstrumentTypesSeeder;
use Database\Seeders\SystemScalesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The door where a read file becomes assessment data.
 *
 * The fixture is the same three-student Plickers export used to test the reader,
 * which means these tests exercise the real chain rather than a hand-built grid:
 * one student answered everything correctly, one got a question wrong, and one
 * took no part at all. That third student is the one most of these tests are
 * really about — «-» must not become a zero, must not become an absence, and
 * must not quietly disappear either.
 */
class ImportCorrectionGridTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;

    protected Organization $organization;

    protected SchoolClass $class;

    protected AcademicPeriod $period;

    /** @var array<string, Enrollment> */
    protected array $enrollments = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(EntitlementsSeeder::class);
        $this->seed(SystemScalesSeeder::class);
        $this->seed(InstrumentTypesSeeder::class);

        $this->teacher = User::factory()->create();
        $this->organization = $this->teacher->personalOrganization();
        $this->subscribe('pro');

        $this->inTenant(function (): void {
            $year = AcademicYear::factory()->recycle($this->organization)->create();
            $this->period = AcademicPeriod::factory()->recycle($this->organization)->for($year)->create();
            $subject = Subject::factory()->recycle($this->organization)->create();

            $this->class = SchoolClass::factory()->recycle($this->organization)->create([
                'academic_year_id' => $year->id,
                'subject_id' => $subject->id,
            ]);
            $this->class->teachers()->attach($this->teacher, ['role' => 'owner']);

            // One curricular domain for the questions to be pointed at. Plickers
            // says nothing about curriculum, so the teacher always chooses (§14).
            Domain::factory()->recycle($this->organization)->create();
        });
    }

    protected function inTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->organization, $callback);
    }

    protected function subscribe(string $planKey): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $this->organization->getKey())->delete();

        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $this->organization->getKey(),
            'plan_id' => Plan::where('key', $planKey)->firstOrFail()->getKey(),
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subDay(),
        ]);

        app(Entitlements::class)->flush();
    }

    /**
     * Three enrolments matching the fixture's three rows, plus one extra student
     * who is in the class but not in the file.
     */
    protected function enrol(): void
    {
        $this->inTenant(function (): void {
            foreach ([1 => 'student:1', 2 => 'student:2', 3 => 'student:3', 4 => null] as $number => $sourceKey) {
                $enrollment = Enrollment::factory()->recycle($this->organization)->create([
                    'class_id' => $this->class->id,
                    'class_number' => $number,
                    'enrolled_on' => now()->subMonths(6)->toDateString(),
                ]);

                if ($sourceKey !== null) {
                    $this->enrollments[$sourceKey] = $enrollment;
                }
            }
        });
    }

    protected function makeImport(ImportMapping $mapping, string $status = 'ready'): CorrectionImport
    {
        $grid = (new PlickersCsvParser)->parse(
            base_path('tests/Fixtures/Import/plickers-basico.csv'),
            'plickers-basico.csv',
        );

        return $this->inTenant(fn (): CorrectionImport => CorrectionImport::create([
            'class_id' => $this->class->id,
            'source' => CorrectionGridSource::Plickers->value,
            'status' => $status,
            'uploaded_by' => $this->teacher->getKey(),
            'stored_path' => 'correction-imports/exemplo.csv',
            'file_sha256' => str_repeat('a', 64),
            'canonical_snapshot' => $grid->toArray(),
            'mapping_snapshot' => $mapping->toArray(),
        ]));
    }

    /**
     * A complete set of decisions: every source row mapped, every question worth
     * two points, everything pointed at one domain.
     */
    protected function fullMapping(array $overrides = []): ImportMapping
    {
        // Domains and instrument types are tenant-scoped reference data, so
        // reading them has to happen inside the tenant like everything else.
        return $this->inTenant(function () use ($overrides): ImportMapping {
            $domainId = Domain::query()->firstOrFail()->id;

            return $this->buildMapping($overrides, $domainId);
        });
    }

    protected function buildMapping(array $overrides, int $domainId): ImportMapping
    {
        return new ImportMapping(
            mode: $overrides['mode'] ?? ImportMapping::MODE_CREATE,
            instrumentId: $overrides['instrumentId'] ?? null,
            students: $overrides['students'] ?? [
                'student:1' => $this->enrollments['student:1']->id,
                'student:2' => $this->enrollments['student:2']->id,
                'student:3' => $this->enrollments['student:3']->id,
            ],
            items: $overrides['items'] ?? [],
            points: $overrides['points'] ?? ['item:6' => '2', 'item:7' => '2', 'item:8' => '2'],
            domains: $overrides['domains'] ?? [
                'item:6' => [['domain_id' => $domainId, 'allocation_percent' => '100']],
                'item:7' => [['domain_id' => $domainId, 'allocation_percent' => '100']],
                'item:8' => [['domain_id' => $domainId, 'allocation_percent' => '100']],
            ],
            conflicts: $overrides['conflicts'] ?? [],
            instrumentAttributes: $overrides['instrument'] ?? [
                'title' => 'Teste importado',
                'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
                'applied_on' => now()->subDays(3)->toDateString(),
                'academic_period_id' => $this->period->id,
                'purpose' => 'formative',
                'counts_toward_classification' => true,
                'total_points' => 6,
            ],
        );
    }

    protected function confirm(CorrectionImport $import): Instrument
    {
        return $this->inTenant(fn (): Instrument => app(ImportCorrectionGrid::class)->confirm($import, $this->teacher));
    }

    #[Test]
    public function confirming_creates_exactly_one_instrument_with_an_invisible_group(): void
    {
        $this->enrol();
        $import = $this->makeImport($this->fullMapping());

        $instrument = $this->confirm($import);

        $this->inTenant(function () use ($instrument): void {
            $this->assertSame(1, Instrument::count());
            $this->assertSame('Teste importado', $instrument->title);

            // Plickers has no sections. One implicit group exists so a question
            // always has somewhere to belong, and it has no label to show (§13).
            $this->assertCount(1, $instrument->groups);
            $this->assertNull($instrument->groups->first()->label);
            $this->assertCount(3, $instrument->items);
        });
    }

    #[Test]
    public function the_marks_follow_the_answer_key_and_the_confirmed_cotacao(): void
    {
        $this->enrol();
        $instrument = $this->confirm($this->makeImport($this->fullMapping()));

        $this->inTenant(function () use ($instrument): void {
            $ana = $this->scoresFor($instrument, $this->enrollments['student:1']);
            $bruno = $this->scoresFor($instrument, $this->enrollments['student:2']);

            // Ana answered all three correctly: 2 points each.
            $this->assertSame(['2.0000', '2.0000', '2.0000'], $ana);

            // Bruno got the first right and the other two wrong. A wrong answer
            // IS a determined zero — that is the half of the rule that is not
            // about blanks.
            $this->assertSame(['2.0000', '0.0000', '0.0000'], $bruno);
        });
    }

    #[Test]
    public function a_student_who_took_no_part_receives_no_marks_at_all(): void
    {
        $this->enrol();
        $instrument = $this->confirm($this->makeImport($this->fullMapping()));

        $this->inTenant(function () use ($instrument): void {
            $carla = StudentItemScore::where('instrument_id', $instrument->id)
                ->where('enrollment_id', $this->enrollments['student:3']->id)
                ->get();

            // Not zeros, and not rows at all: the absence of a row IS «por
            // avaliar», and «-» in the file says nothing about why (§25).
            $this->assertCount(0, $carla);
        });
    }

    #[Test]
    public function a_student_who_took_no_part_is_never_marked_absent(): void
    {
        $this->enrol();
        $instrument = $this->confirm($this->makeImport($this->fullMapping()));

        $this->inTenant(function () use ($instrument): void {
            // «Não participou no Plickers» is a fact about a file. «Faltou» is a
            // pedagogical judgement only the teacher may make, and the import
            // must never make it on their behalf (§21, §26).
            $absences = StudentItemScore::where('instrument_id', $instrument->id)
                ->whereIn('result_state', [ResultState::Absent->value, ResultState::AbsentJustified->value])
                ->count();

            $this->assertSame(0, $absences);
        });
    }

    #[Test]
    public function a_student_of_the_class_missing_from_the_file_gets_nothing(): void
    {
        $this->enrol();
        $instrument = $this->confirm($this->makeImport($this->fullMapping()));

        $this->inTenant(function () use ($instrument): void {
            $fourth = Enrollment::where('class_id', $this->class->id)->where('class_number', 4)->firstOrFail();

            $this->assertSame(0, StudentItemScore::where('instrument_id', $instrument->id)
                ->where('enrollment_id', $fourth->id)->count());
        });
    }

    #[Test]
    public function an_ignored_source_row_writes_nothing_for_anybody(): void
    {
        $this->enrol();

        $mapping = $this->fullMapping(['students' => [
            'student:1' => $this->enrollments['student:1']->id,
            'student:2' => null, // explicitly left out
            'student:3' => $this->enrollments['student:3']->id,
        ]]);

        $instrument = $this->confirm($this->makeImport($mapping));

        $this->inTenant(function () use ($instrument): void {
            $this->assertSame(0, StudentItemScore::where('instrument_id', $instrument->id)
                ->where('enrollment_id', $this->enrollments['student:2']->id)->count());
            $this->assertSame(3, StudentItemScore::where('instrument_id', $instrument->id)
                ->where('enrollment_id', $this->enrollments['student:1']->id)->count());
        });
    }

    #[Test]
    public function a_source_row_nobody_decided_about_blocks_the_import(): void
    {
        $this->enrol();

        // Deciding to ignore is a decision. Never having looked is not.
        $mapping = $this->fullMapping(['students' => ['student:1' => $this->enrollments['student:1']->id]]);

        $this->expectException(CorrectionImportException::class);
        $this->confirm($this->makeImport($mapping));
    }

    #[Test]
    public function a_question_with_no_confirmed_cotacao_blocks_the_import(): void
    {
        $this->enrol();

        $mapping = $this->fullMapping(['points' => ['item:6' => '2']]);

        $this->expectException(CorrectionImportException::class);
        $this->confirm($this->makeImport($mapping));
    }

    #[Test]
    public function the_instrument_lands_in_correction_and_never_completed(): void
    {
        $this->enrol();
        $instrument = $this->confirm($this->makeImport($this->fullMapping()));

        // Even had every cell arrived filled, completing is the teacher's
        // decision and the import does not get to make it (§29).
        $this->assertSame(InstrumentStatus::InCorrection, $instrument->fresh()->status);
        $this->assertNull($instrument->fresh()->completed_at);
    }

    #[Test]
    public function confirming_twice_writes_nothing_the_second_time(): void
    {
        $this->enrol();
        $import = $this->makeImport($this->fullMapping());

        $instrument = $this->confirm($import);

        $this->inTenant(function () use ($import, $instrument): void {
            $before = StudentItemScore::where('instrument_id', $instrument->id)->count();

            try {
                app(ImportCorrectionGrid::class)->confirm($import->fresh(), $this->teacher);
                $this->fail('Uma segunda confirmação tinha de ser recusada.');
            } catch (CorrectionImportException) {
                // Expected — an HTTP retry looks exactly like this.
            }

            $this->assertSame(1, Instrument::count(), 'Nunca um segundo instrumento.');
            $this->assertSame($before, StudentItemScore::where('instrument_id', $instrument->id)->count());
        });
    }

    #[Test]
    public function the_uploaded_file_is_deleted_and_the_provenance_kept(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('correction-imports/exemplo.csv', 'dados de alunos');

        $this->enrol();
        $import = $this->makeImport($this->fullMapping());

        $this->confirm($import);

        Storage::disk('local')->assertMissing('correction-imports/exemplo.csv');

        $this->inTenant(function () use ($import): void {
            $fresh = $import->fresh();

            $this->assertNull($fresh->stored_path);
            $this->assertSame(CorrectionImportStatus::Imported, $fresh->status);
            $this->assertNotNull($fresh->confirmed_at);

            // The hash outlives the file: which file produced these marks stays
            // answerable after the file itself is gone (§10).
            $this->assertNotNull($fresh->file_sha256);
        });
    }

    #[Test]
    public function the_provenance_keeps_what_an_audit_needs_and_drops_the_source_names(): void
    {
        $this->enrol();
        $import = $this->makeImport($this->fullMapping());

        $this->confirm($import);

        $this->inTenant(function () use ($import): void {
            $snapshot = $import->fresh()->canonical_snapshot;

            // Enough to trace any mark back to its origin.
            $this->assertSame('plickers', $snapshot['source']);
            $this->assertSame('A', $snapshot['items'][0]['answer_key']);
            $this->assertSame('https://exemplo.invalido/q/aaa111', $snapshot['items'][0]['external_id']);
            $this->assertNotEmpty($snapshot['items'][0]['question_text']);

            $bruno = collect($snapshot['results'])
                ->firstWhere(fn (array $row): bool => $row['enrollment_id'] === $this->enrollments['student:2']->id
                    && $row['item_source_key'] === 'item:7');

            $this->assertSame('C', $bruno['raw_response']);
            $this->assertFalse($bruno['is_correct']);

            // And nothing more. The students are enrolments by now; a second,
            // unmanaged copy of children's names is data we no longer need.
            $encoded = (string) json_encode($snapshot);
            $this->assertStringNotContainsString('Ana Exemplo', $encoded);
            $this->assertStringNotContainsString('Bruno Exemplo', $encoded);
            $this->assertTrue($snapshot['students_minimised']);
        });
    }

    #[Test]
    public function the_base_plan_cannot_confirm_an_import(): void
    {
        $this->enrol();
        $import = $this->makeImport($this->fullMapping());

        $this->subscribe('base');

        $this->expectException(CorrectionImportException::class);
        $this->confirm($import);
    }

    #[Test]
    public function losing_the_plan_afterwards_takes_nothing_away(): void
    {
        $this->enrol();
        $instrument = $this->confirm($this->makeImport($this->fullMapping()));

        $this->subscribe('base');

        $this->inTenant(function () use ($instrument): void {
            // The marks are the school's academic record, not the
            // subscription's to reclaim (§41).
            $this->assertSame(6, StudentItemScore::where('instrument_id', $instrument->id)->count());
            $this->assertNotNull(Instrument::find($instrument->getKey()));
        });
    }

    #[Test]
    public function an_import_that_is_not_ready_writes_nothing(): void
    {
        $this->enrol();
        $import = $this->makeImport($this->fullMapping(), status: 'needs_mapping');

        try {
            $this->confirm($import);
            $this->fail('Um import por decidir não pode ser confirmado.');
        } catch (CorrectionImportException) {
            // Expected.
        }

        $this->inTenant(function (): void {
            $this->assertSame(0, Instrument::count(), 'Uma recusa não deixa meio instrumento para trás.');
            $this->assertSame(0, StudentItemScore::count());
        });
    }

    /**
     * @return list<string>
     */
    protected function scoresFor(Instrument $instrument, Enrollment $enrollment): array
    {
        return StudentItemScore::query()
            ->where('student_item_scores.instrument_id', $instrument->getKey())
            ->where('student_item_scores.enrollment_id', $enrollment->getKey())
            ->join('instrument_items', 'instrument_items.id', '=', 'student_item_scores.instrument_item_id')
            ->orderBy('instrument_items.sequence')
            ->pluck('student_item_scores.points_earned')
            ->map(fn ($value): string => (string) $value)
            ->all();
    }
}
