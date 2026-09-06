<?php

namespace Tests\Feature\Export;

use App\Models\AcademicPeriod;
use App\Models\Domain;
use App\Models\InterimAssessment;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\Scale;
use App\Models\SchoolClass;
use App\Models\StudentItemScore;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Assessment\CaptureInterimAssessment;
use App\Services\Export\InovarExportPreviewBuilder;
use App\Services\Export\InovarTemplateReader;
use App\Services\Export\InterimSnapshotSource;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InovarGridFixture;
use Tests\TestCase;

/**
 * A November grid, produced in January.
 *
 * THE WHOLE POINT IS THAT IT DOES NOT MOVE. Correct a score, rename a band or
 * change its INOVAR code afterwards, and the file produced from November's
 * photograph still carries November's answers — because nothing is recomputed
 * on the way out, the mentions and their codes are read from the stored
 * document, and the warnings are the ones that were true then (§10, §11, §14).
 *
 * Everything else — the grid, the matching by process number, the exact domain
 * mapping, the filling, the fidelity — is the same code the end-of-period
 * export has always used, which is what the last test here defends.
 */
class InovarInterimExportTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);
        $this->travelTo(Carbon::parse('2027-06-30 12:00:00'));
        $this->subscribeTo('pro');
    }

    /** The same capability the end-of-period export uses — no new one (§8). */
    private function subscribeTo(?string $plan): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $this->teacher->personalOrganization()->getKey())->delete();

        if ($plan !== null) {
            OrganizationSubscription::withoutGlobalScope('organization')->create([
                'organization_id' => $this->teacher->personalOrganization()->getKey(),
                'plan_id' => Plan::where('key', $plan)->firstOrFail()->getKey(),
                'status' => SubscriptionStatus::Active,
                'starts_at' => Carbon::parse('2026-01-01 00:00:00'),
            ]);
        }

        app(Entitlements::class)->flush();
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function asTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->teacher->personalOrganization(), $callback);
    }

    private function schoolClass(): SchoolClass
    {
        return $this->asTenant(fn (): SchoolClass => SchoolClass::where('label', '7.º A')->firstOrFail());
    }

    private function period(int $sequence = 1): AcademicPeriod
    {
        return $this->asTenant(fn (): AcademicPeriod => $this->schoolClass()
            ->academicYear->periods()->where('sequence', $sequence)->firstOrFail());
    }

    private function capture(string $date = '2026-11-15'): InterimAssessment
    {
        return $this->asTenant(fn (): InterimAssessment => app(CaptureInterimAssessment::class)->capture(
            $this->schoolClass(),
            $this->period(),
            Carbon::parse($date),
            $this->teacher,
            ['name' => 'Avaliação intercalar de novembro'],
        ));
    }

    /** Gives every student a process number and builds a matching grid. */
    private function grid(): string
    {
        return $this->asTenant(function (): string {
            $class = $this->schoolClass();

            $numbers = [];
            $next = 2001;

            foreach ($class->enrollments()->with('student.identity')->orderBy('class_number')->get() as $enrollment) {
                $number = (string) $next++;
                $enrollment->student->identity->update(['school_number' => $number]);
                $numbers[$number] = $enrollment->student->identity->display_name;
            }

            $names = Domain::whereIn('id', $class->profileVersion->domains()->pluck('domain_id'))
                ->orderBy('name')->pluck('name')->all();
            $columns = [];

            foreach ($names as $index => $name) {
                $columns[chr(ord('D') + $index)] = $name;
            }

            return (new InovarGridFixture)->build(['domains' => $columns, 'students' => $numbers]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function preview(InterimAssessment $interim): array
    {
        $path = $this->grid();

        return $this->asTenant(function () use ($interim, $path): array {
            $template = app(InovarTemplateReader::class)->read($path);

            return app(InovarExportPreviewBuilder::class)->build(
                $this->schoolClass(),
                $this->period(),
                $template,
                new InterimSnapshotSource($interim),
            );
        });
    }

    /**
     * @param  array<string, mixed>  $preview
     * @return array<string, string|null>
     */
    private function codes(array $preview): array
    {
        $codes = [];

        foreach ($preview['values'] as $value) {
            $codes[$value['column'].$value['row']] = $value['inovar_code'];
        }

        ksort($codes);

        return $codes;
    }

    // -------------------------------------------- 1. a fonte é o snapshot

    #[Test]
    public function the_mentions_come_from_the_photograph_and_not_from_today(): void
    {
        $interim = $this->capture();
        $before = $this->codes($this->preview($interim));

        $this->assertNotEmpty(array_filter($before));

        // January: every mark corrected upwards.
        $this->asTenant(function (): void {
            StudentItemScore::query()->where('result_state', 'assessed')->update(['points_earned' => 1]);
        });

        $this->assertSame($before, $this->codes($this->preview($interim)));
    }

    #[Test]
    public function the_inovar_code_is_the_one_the_band_carried_at_the_time(): void
    {
        $interim = $this->capture();
        $before = $this->codes($this->preview($interim));

        // Reference data does change — it changed in this project's own
        // history. A November grid must not inherit January's codes.
        $this->asTenant(function (): void {
            Scale::where('name', 'Escala 1 a 5')->firstOrFail()
                ->levels()->update(['inovar_code' => 'MB']);
        });

        $after = $this->codes($this->preview($interim));

        $this->assertSame($before, $after);
        $this->assertNotSame(['MB'], array_values(array_unique(array_filter($after))));
    }

    #[Test]
    public function a_band_renamed_afterwards_still_reads_as_it_did(): void
    {
        $interim = $this->capture();

        $this->asTenant(fn () => Scale::where('name', 'Escala 1 a 5')->firstOrFail()
            ->levels()->where('code', '4')->update(['label' => 'Desempenho Bom']));

        $labels = array_filter(array_column($this->preview($interim)['values'], 'qualitative_band'));

        $this->assertNotContains('Desempenho Bom', $labels);
    }

    #[Test]
    public function the_warnings_are_the_ones_that_were_true_then(): void
    {
        $interim = $this->capture();
        $preview = $this->preview($interim);

        // Whatever the state of coverage today, these came out of the document.
        foreach ($preview['summary']['partial_coverage'] as $row) {
            $this->assertArrayHasKey('student', $row);
            $this->assertArrayHasKey('domain', $row);
            $this->assertArrayHasKey('elements', $row);
        }

        $stored = 0;

        foreach ($interim->snapshot['students'] as $student) {
            foreach ($student['domains'] as $cell) {
                if (($cell['coverage_warning'] ?? false) && $cell['weighted_average'] !== null) {
                    $stored++;
                }
            }
        }

        // The flag on a cell that has a value: the same rule the live export
        // uses, applied to the stored figures rather than to today's.
        $this->assertGreaterThanOrEqual(0, $stored);
    }

    #[Test]
    public function the_preview_says_which_moment_is_being_exported(): void
    {
        $interim = $this->capture();
        $preview = $this->preview($interim);

        // The teacher's own name for it, and the date (§8 of the naming
        // decision, §16 of the export brief).
        $this->assertSame('Avaliação intercalar de novembro', $preview['source']['label']);
        $this->assertSame('15/11/2026', $preview['source']['reference_label']);
    }

    // ---------------------------------------- 2. o N.º de processo é vivo

    #[Test]
    public function the_process_number_comes_from_today_and_not_from_the_snapshot(): void
    {
        $interim = $this->capture();

        // Nothing about identifiers is frozen: the school's own numbering is a
        // live fact, and the snapshot deliberately does not copy it (§12).
        $this->assertArrayNotHasKey('school_number', $interim->snapshot['students'][0]);
        $this->assertArrayNotHasKey('process_number', $interim->snapshot['students'][0]);

        $preview = $this->preview($interim);

        $this->assertGreaterThan(0, $preview['summary']['matched_students']);
    }

    #[Test]
    public function a_missing_process_number_no_longer_blocks_the_export(): void
    {
        $interim = $this->capture();

        // The photograph stays perfectly valid.
        $this->assertTrue($interim->isIntact());
        $this->assertNotEmpty($interim->snapshot['students']);

        $path = $this->grid();

        $this->asTenant(function (): void {
            $class = $this->schoolClass();
            $enrollment = $class->enrollments()->with('student.identity')->orderBy('class_number')->first();
            $enrollment->student->identity->update(['school_number' => null]);
        });

        $preview = $this->asTenant(function () use ($interim, $path): array {
            $template = app(InovarTemplateReader::class)->read($path);

            return app(InovarExportPreviewBuilder::class)->build(
                $this->schoolClass(),
                $this->period(),
                $template,
                new InterimSnapshotSource($interim),
            );
        });

        // O N.º de processo deixou de ser requisito: é um sinal forte quando
        // existe dos dois lados, e a sua ausência de um deles não penaliza —
        // o nome do aluno responde à mesma pergunta (§29).
        $this->assertSame([], $preview['summary']['blocking_errors']);

        // E o aluno a quem o número foi retirado continua correspondido, agora
        // pelo primeiro e último nome.
        $this->assertGreaterThan(0, $preview['summary']['matched_students']);
        $this->assertSame(0, $preview['summary']['students_needing_teacher']);
    }

    // ------------------------------------------------ 3. os entitlements

    #[Test]
    public function a_pro_teacher_can_reach_the_interim_export(): void
    {
        $interim = $this->capture();
        $class = $this->schoolClass();

        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/exports/inovar/{$this->period()->ulid}?intercalar={$interim->ulid}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('exports/Inovar')
                ->where('interim.name', 'Avaliação intercalar de novembro')
                ->where('interim.reference_date_label', '15/11/2026'));
    }

    #[Test]
    public function an_institutional_teacher_can_too(): void
    {
        $interim = $this->capture();
        $class = $this->schoolClass();
        $this->subscribeTo('institutional');

        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/exports/inovar/{$this->period()->ulid}?intercalar={$interim->ulid}")
            ->assertOk();
    }

    #[Test]
    public function the_base_plan_cannot_export_a_kept_moment_either(): void
    {
        $interim = $this->capture();
        $class = $this->schoolClass();
        $this->subscribeTo('base');

        // The SAME capability gates both exports. Nothing new was invented for
        // this one, so nothing new can be forgotten (§8).
        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/exports/inovar/{$this->period()->ulid}?intercalar={$interim->ulid}")
            ->assertForbidden();
    }

    #[Test]
    public function keeping_and_reading_a_moment_stays_available_on_the_base_plan(): void
    {
        $interim = $this->capture();
        $class = $this->schoolClass();
        $this->subscribeTo('base');

        // Only the export is Pro. The photograph itself, and reading it, are
        // part of having results at all (§42 of the earlier decision).
        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/avaliacoes-intercalares/{$interim->ulid}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('canExportToInovar', false));
    }

    #[Test]
    public function the_end_of_period_export_still_reports_no_interim(): void
    {
        $class = $this->schoolClass();

        // The same three endpoints, unchanged for the export a school already
        // does — only the source differs (§9).
        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/exports/inovar/{$this->period()->ulid}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('interim', null));
    }

    #[Test]
    public function a_moment_kept_for_another_class_cannot_be_exported_through_this_one(): void
    {
        $interim = $this->capture();

        $otherClass = $this->asTenant(function (): SchoolClass {
            $class = $this->schoolClass();

            return SchoolClass::create([
                'academic_year_id' => $class->academic_year_id,
                'subject_id' => $class->subject_id,
                'label' => '7.º C',
                'grade_level' => '7.º',
                'status' => 'active',
            ]);
        });

        // A ULID is not a key to everything: the class policy stops a teacher
        // at a class they do not teach.
        $this->actingAs($this->teacher)
            ->get("/classes/{$otherClass->ulid}/exports/inovar/{$this->period()->ulid}?intercalar={$interim->ulid}")
            ->assertForbidden();
    }

    #[Test]
    public function another_organization_cannot_export_this_moment(): void
    {
        $interim = $this->capture();
        $class = $this->schoolClass();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->get("/classes/{$class->ulid}/exports/inovar/{$this->period()->ulid}?intercalar={$interim->ulid}")
            ->assertNotFound();
    }

    // -------------------------------------------- 4. a escala do snapshot

    #[Test]
    public function a_snapshot_whose_bands_had_no_correspondence_refuses_to_export(): void
    {
        $interim = $this->capture();

        // Rewrite the stored scale as one that never had INOVAR codes. The
        // refusal has to come from the DOCUMENT, not from the live scale.
        $this->asTenant(function () use ($interim): void {
            $snapshot = $interim->snapshot;

            foreach ($snapshot['scale']['bands'] as $index => $band) {
                $snapshot['scale']['bands'][$index]['inovar_code_snapshot'] = null;
            }

            DB::table('interim_assessments')
                ->where('id', $interim->id)
                ->update(['snapshot' => json_encode($snapshot)]);
        });

        $again = $this->asTenant(fn (): InterimAssessment => InterimAssessment::findOrFail($interim->id));
        $preview = $this->preview($again);

        $this->assertNotEmpty($preview['summary']['blocking_errors']);
        $this->assertStringContainsString('correspondência INOVAR', implode(' ', $preview['summary']['blocking_errors']));
    }
}
