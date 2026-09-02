<?php

namespace Tests\Feature\Lessons;

use App\Models\AcademicYear;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\RecurringLessonSlot;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Import\TimetablePdfBuilder;
use Tests\TestCase;

/**
 * Importing a teacher's timetable export, end to end and through the real
 * routes.
 *
 * The file under test is a real PDF, generated to order — see
 * TimetablePdfBuilder for what is in it and why each awkward shape is there. The
 * horário this feature was designed against is a real teacher's own working
 * file, with real turmas and real rooms in it, and it stays off this repository
 * entirely.
 *
 * Ana Exemplo teaches 7.º C and 8.º A. Neither she nor they exist.
 */
class TimetableImportTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Organization $organization;

    private AcademicYear $academicYear;

    private SchoolClass $seventhC;

    private SchoolClass $eighthA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create();
        $this->organization = $this->teacher->personalOrganization();
        $this->subscribeToPro($this->organization);

        $this->academicYear = $this->inTenant(
            $this->organization,
            fn (): AcademicYear => AcademicYear::factory()
                ->recycle($this->organization)
                ->create(['label' => '2025/2026', 'starts_on' => '2025-09-01', 'ends_on' => '2026-06-30']),
        );

        $this->seventhC = $this->schoolClassFor($this->teacher, '7.º C');
        $this->eighthA = $this->schoolClassFor($this->teacher, '8.º A');
    }

    // ---------------------------------------------------------------- upload

    #[Test]
    public function a_file_that_is_not_a_pdf_is_refused_with_a_validation_error(): void
    {
        $this->upload(UploadedFile::fake()->createWithContent('horario.txt', 'não é um pdf'))
            ->assertRedirect()
            ->assertSessionHasErrors('timetable');

        $this->assertDatabaseCount('recurring_lesson_slots', 0);
    }

    #[Test]
    public function a_pdf_that_is_not_a_timetable_fails_with_a_sentence_rather_than_a_stack_trace(): void
    {
        $this->upload($this->pdf(TimetablePdfBuilder::withoutATimetable()))
            ->assertRedirect()
            ->assertSessionHasErrors('timetable');

        $errors = session('errors');

        $this->assertNotNull($errors);
        $this->assertStringContainsString('bloco horário', (string) $errors->first('timetable'));
        $this->assertDatabaseCount('recurring_lesson_slots', 0);
    }

    // --------------------------------------------------------------- preview

    #[Test]
    public function previewing_an_import_writes_absolutely_nothing(): void
    {
        $this->preview()->assertOk();

        $this->assertDatabaseCount('recurring_lesson_slots', 0);
        $this->assertDatabaseCount('lessons', 0);
    }

    #[Test]
    public function the_preview_groups_every_block_under_the_turma_it_matched(): void
    {
        $this->preview()->assertInertia(function (AssertableInertia $page): void {
            $props = $page->component('timetable-imports/Preview')->toArray()['props'];

            $labels = array_column($props['groups'], 'label');
            sort($labels);
            $this->assertSame(['7.º C', '8.º A'], $labels);

            $seventh = collect($props['groups'])->firstWhere('label', '7.º C');
            $this->assertSame(
                [[1, '08:30', '09:20'], [1, '09:20', '10:10']],
                array_map(
                    fn (array $row): array => [$row['day_of_week'], $row['starts_at'], $row['ends_at']],
                    $seventh['rows'],
                ),
            );

            // Everything the file records in the same grid that is not an
            // ordinary lesson: shown, and never matched to a turma.
            $unassociated = array_column($props['unassociated'], 'raw_text');
            sort($unassociated);
            $this->assertSame(['AE_3C_Mat - 7º C - S09', 'REE - Sem sala'], $unassociated);
        });
    }

    #[Test]
    public function a_block_that_already_exists_is_labelled_as_such_and_not_offered_as_new(): void
    {
        $this->existingSlot($this->seventhC, 1, '08:30', '09:20');

        $this->preview()->assertInertia(function (AssertableInertia $page): void {
            $rows = collect($page->toArray()['props']['groups'])->firstWhere('label', '7.º C')['rows'];
            $existing = collect($rows)->firstWhere('starts_at', '08:30');

            $this->assertSame('exists', $existing['status']);
            $this->assertFalse($existing['include']);
            // The hour after it is untouched by any of that.
            $this->assertSame('new', collect($rows)->firstWhere('starts_at', '09:20')['status']);
        });
    }

    #[Test]
    public function a_genuine_overlap_is_flagged_as_a_conflict_and_never_merged(): void
    {
        // The file says 08:30–09:20; the schedule already says 08:30–09:00.
        // Neither one may quietly become the other.
        $this->existingSlot($this->seventhC, 1, '08:30', '09:00');

        $this->preview()->assertInertia(function (AssertableInertia $page): void {
            $rows = collect($page->toArray()['props']['groups'])->firstWhere('label', '7.º C')['rows'];
            $conflicting = collect($rows)->firstWhere('starts_at', '08:30');

            $this->assertSame('conflict', $conflicting['status']);
            $this->assertFalse($conflicting['include']);
        });

        $this->inTenant($this->organization, function (): void {
            $slot = RecurringLessonSlot::query()->sole();

            $this->assertStringStartsWith('09:00', $slot->ends_at);
        });
    }

    #[Test]
    public function a_turma_from_another_organization_is_never_offered_even_with_the_same_label(): void
    {
        $stranger = User::factory()->create();
        $strangerOrganization = $stranger->personalOrganization();
        $this->subscribeToPro($strangerOrganization);
        $this->schoolClassFor($stranger, '7.º C', $strangerOrganization);

        $this->preview()->assertInertia(function (AssertableInertia $page): void {
            $props = $page->toArray()['props'];

            $this->assertSame(
                [$this->seventhC->id, $this->eighthA->id],
                $this->sortedInts(array_column($props['classes'], 'class_id')),
            );
            $this->assertSame(
                [$this->seventhC->id, $this->eighthA->id],
                $this->sortedInts(array_column($props['groups'], 'class_id')),
            );
        });
    }

    #[Test]
    public function a_turma_in_the_same_organization_that_the_teacher_does_not_teach_is_never_offered(): void
    {
        $colleague = User::factory()->create();
        $this->organization->members()->attach($colleague, ['joined_at' => now()]);
        $colleaguesClass = $this->schoolClassFor($colleague, '9.º B');

        $this->preview()->assertInertia(function (AssertableInertia $page) use ($colleaguesClass): void {
            $offered = array_column($page->toArray()['props']['classes'], 'class_id');

            $this->assertNotContains($colleaguesClass->id, $offered);
            $this->assertSame([$this->seventhC->id, $this->eighthA->id], $this->sortedInts($offered));
        });
    }

    #[Test]
    public function a_file_from_another_academic_year_is_warned_about_but_never_blocked(): void
    {
        $this->upload($this->pdf(TimetablePdfBuilder::example()->academicYear('2019/20')))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $props = $page->toArray()['props'];

                $this->assertTrue($props['yearMismatch']);
                $this->assertSame('2019/20', $props['fileAcademicYear']);
                $this->assertSame('2025/2026', $props['academicYear']);
                // Warned about, and still perfectly importable.
                $this->assertNotSame([], $props['groups']);
            });

        // And the selected year is never changed behind the teacher's back.
        $this->assertSame($this->academicYear->id, session('academic_year_id'));
    }

    #[Test]
    public function a_file_from_the_selected_year_raises_no_warning(): void
    {
        $this->preview()->assertInertia(
            fn (AssertableInertia $page) => $this->assertFalse($page->toArray()['props']['yearMismatch']),
        );
    }

    // --------------------------------------------------------------- confirm

    #[Test]
    public function confirming_creates_exactly_the_blocks_the_file_described(): void
    {
        $this->confirm($this->payload())->assertRedirect('/classes');

        $this->inTenant($this->organization, function (): void {
            $this->assertSame(6, RecurringLessonSlot::query()->count());

            $seventh = RecurringLessonSlot::query()
                ->where('class_id', $this->seventhC->id)
                ->orderBy('starts_at')
                ->get();

            // Two consecutive hours of the same turma, stored as two rows —
            // never collapsed into one 08:30–10:10 block.
            $this->assertCount(2, $seventh);
            $this->assertSame([1, 1], $seventh->pluck('day_of_week')->all());
            $this->assertStringStartsWith('08:30', $seventh[0]->starts_at);
            $this->assertStringStartsWith('09:20', $seventh[0]->ends_at);
            $this->assertStringStartsWith('09:20', $seventh[1]->starts_at);
            $this->assertStringStartsWith('10:10', $seventh[1]->ends_at);
        });
    }

    #[Test]
    public function importing_the_very_same_file_twice_creates_no_duplicate(): void
    {
        // Two complete cycles — upload, preview, confirm — rather than two
        // confirmations of one preview: the second pass has to re-read the
        // schedule and find its own work already there.
        $this->confirm($this->payload())->assertRedirect();
        $this->assertDatabaseCount('recurring_lesson_slots', 6);

        $this->confirm($this->payloadFromAFreshPreview())->assertRedirect();

        $this->assertDatabaseCount('recurring_lesson_slots', 6);
    }

    #[Test]
    public function a_block_the_teacher_unticked_is_never_created(): void
    {
        $payload = $this->payload();
        $payload['rows'][0]['include'] = false;

        $this->confirm($payload)->assertRedirect();

        $this->assertDatabaseCount('recurring_lesson_slots', 5);
        $this->inTenant($this->organization, function (): void {
            $this->assertSame(
                1,
                RecurringLessonSlot::query()->where('class_id', $this->seventhC->id)->count(),
            );
        });
    }

    #[Test]
    public function a_validity_window_the_teacher_filled_in_is_written_onto_every_slot(): void
    {
        $this->confirm([
            ...$this->payload(),
            'starts_on' => '2025-09-15',
            'ends_on' => '2026-06-15',
        ])->assertRedirect();

        $this->inTenant($this->organization, function (): void {
            $slots = RecurringLessonSlot::query()->get();

            $this->assertCount(6, $slots);

            foreach ($slots as $slot) {
                $this->assertSame('2025-09-15', $slot->starts_on?->toDateString());
                $this->assertSame('2026-06-15', $slot->ends_on?->toDateString());
            }
        });
    }

    #[Test]
    public function a_blank_validity_window_leaves_both_dates_null_exactly_as_manual_entry_does(): void
    {
        $this->confirm($this->payload())->assertRedirect();

        $this->inTenant($this->organization, function (): void {
            foreach (RecurringLessonSlot::query()->get() as $slot) {
                $this->assertNull($slot->starts_on);
                $this->assertNull($slot->ends_on);
            }
        });
    }

    #[Test]
    public function a_block_that_only_clashes_at_confirmation_time_is_skipped_while_the_rest_succeed(): void
    {
        // The teacher added a clashing slot by hand, in another tab, after the
        // preview was built. The preview's own verdict is worth nothing here.
        $payload = $this->payload();
        $this->existingSlot($this->seventhC, 1, '08:30', '09:00');

        $this->confirm($payload)->assertRedirect();

        // Six wanted, one refused for overlapping, five created — plus the
        // manual one that was already there.
        $this->assertDatabaseCount('recurring_lesson_slots', 6);
        $this->inTenant($this->organization, function (): void {
            $this->assertSame(
                0,
                RecurringLessonSlot::query()
                    ->where('class_id', $this->seventhC->id)
                    ->where('starts_at', '08:30:00')
                    ->where('ends_at', '09:20:00')
                    ->count(),
            );
        });
    }

    // -------------------------------------------------------------- security

    #[Test]
    public function a_confirmation_naming_another_organizations_turma_is_refused_and_creates_nothing(): void
    {
        $stranger = User::factory()->create();
        $strangersClass = $this->schoolClassFor($stranger, '7.º C', $stranger->personalOrganization());

        $payload = $this->payload();
        $payload['rows'][0]['class_id'] = $strangersClass->id;

        // 403 rather than 404: the answer must not tell anyone whether that id
        // is real.
        $this->confirm($payload)->assertForbidden();

        $this->assertDatabaseCount('recurring_lesson_slots', 0);
    }

    #[Test]
    public function a_confirmation_naming_a_turma_the_teacher_does_not_teach_is_refused(): void
    {
        $colleague = User::factory()->create();
        $this->organization->members()->attach($colleague, ['joined_at' => now()]);
        $colleaguesClass = $this->schoolClassFor($colleague, '9.º B');

        $payload = $this->payload();
        $payload['rows'][0]['class_id'] = $colleaguesClass->id;

        $this->confirm($payload)->assertForbidden();

        $this->assertDatabaseCount('recurring_lesson_slots', 0);
    }

    #[Test]
    public function one_unauthorised_row_refuses_the_whole_batch(): void
    {
        // A DELIBERATE, DOCUMENTED CHOICE (see TimetableImportConfirmRequest): a
        // payload naming a turma this teacher could never have been shown is not
        // one they produced by reviewing their own preview, so none of it is
        // written — not even the rows that would have been perfectly fine.
        $stranger = User::factory()->create();
        $strangersClass = $this->schoolClassFor($stranger, '7.º C', $stranger->personalOrganization());

        $payload = $this->payload();
        $payload['rows'][] = [
            'class_id' => $strangersClass->id,
            'day_of_week' => 2,
            'starts_at' => '15:00',
            'ends_at' => '15:50',
            'include' => true,
        ];

        $this->confirm($payload)->assertForbidden();

        $this->assertDatabaseCount('recurring_lesson_slots', 0);
    }

    #[Test]
    public function the_confirmation_refuses_impossible_weekdays_and_times(): void
    {
        foreach ([0, 8] as $weekday) {
            $payload = $this->payload();
            $payload['rows'][0]['day_of_week'] = $weekday;

            $this->confirm($payload)->assertSessionHasErrors('rows.0.day_of_week');
        }

        $payload = $this->payload();
        $payload['rows'][0]['ends_at'] = '08:00';

        $this->confirm($payload)->assertSessionHasErrors('rows.0.ends_at');

        $this->assertDatabaseCount('recurring_lesson_slots', 0);
    }

    #[Test]
    public function impersonation_blocks_the_upload_and_the_confirmation(): void
    {
        $session = [...$this->tenantSession(), 'impersonator_id' => 999];

        $this->actingAs($this->teacher)->withSession($session)
            ->post('/timetable-imports', ['timetable' => $this->pdf(TimetablePdfBuilder::example())])
            ->assertForbidden();

        $this->actingAs($this->teacher)->withSession($session)
            ->post('/timetable-imports/confirm', $this->payload())
            ->assertForbidden();

        $this->assertDatabaseCount('recurring_lesson_slots', 0);
    }

    // --------------------------------------------------------------- helpers

    private function upload(UploadedFile $file): TestResponse
    {
        return $this->actingAs($this->teacher)
            ->withSession($this->tenantSession())
            ->post('/timetable-imports', ['timetable' => $file]);
    }

    private function preview(): TestResponse
    {
        return $this->upload($this->pdf(TimetablePdfBuilder::example()));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function confirm(array $payload): TestResponse
    {
        return $this->actingAs($this->teacher)
            ->withSession($this->tenantSession())
            ->post('/timetable-imports/confirm', $payload);
    }

    /**
     * The confirmation the preview page would build for the example file: every
     * matched block, all of them ticked.
     *
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'starts_on' => null,
            'ends_on' => null,
            'unassociated_count' => 2,
            'rows' => [
                ['class_id' => $this->seventhC->id, 'day_of_week' => 1, 'starts_at' => '08:30', 'ends_at' => '09:20', 'include' => true],
                ['class_id' => $this->seventhC->id, 'day_of_week' => 1, 'starts_at' => '09:20', 'ends_at' => '10:10', 'include' => true],
                ['class_id' => $this->eighthA->id, 'day_of_week' => 2, 'starts_at' => '08:30', 'ends_at' => '09:20', 'include' => true],
                ['class_id' => $this->eighthA->id, 'day_of_week' => 3, 'starts_at' => '09:20', 'ends_at' => '10:10', 'include' => true],
                ['class_id' => $this->eighthA->id, 'day_of_week' => 4, 'starts_at' => '11:20', 'ends_at' => '12:10', 'include' => true],
                ['class_id' => $this->eighthA->id, 'day_of_week' => 5, 'starts_at' => '11:20', 'ends_at' => '12:10', 'include' => true],
            ],
        ];
    }

    /**
     * A second, genuinely fresh cycle: the file is uploaded and read again, and
     * the confirmation is built from what THAT preview holds.
     *
     * @return array<string, mixed>
     */
    private function payloadFromAFreshPreview(): array
    {
        $rows = [];

        $this->preview()->assertOk()->assertInertia(function (AssertableInertia $page) use (&$rows): void {
            foreach ($page->toArray()['props']['groups'] as $group) {
                foreach ($group['rows'] as $row) {
                    $rows[] = [
                        'class_id' => $group['class_id'],
                        'day_of_week' => $row['day_of_week'],
                        'starts_at' => $row['starts_at'],
                        'ends_at' => $row['ends_at'],
                        // Deliberately forced back on: even a teacher who ticked
                        // every box again must not end up with duplicates.
                        'include' => true,
                    ];
                }
            }
        });

        return ['starts_on' => null, 'ends_on' => null, 'unassociated_count' => 2, 'rows' => $rows];
    }

    private function pdf(TimetablePdfBuilder $builder): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('horario.pdf', $builder->bytes());
    }

    /**
     * @return array<string, mixed>
     */
    private function tenantSession(): array
    {
        return [
            'organization_id' => $this->organization->id,
            'academic_year_id' => $this->academicYear->id,
        ];
    }

    private function existingSlot(SchoolClass $schoolClass, int $dayOfWeek, string $startsAt, string $endsAt): void
    {
        $this->inTenant($this->organization, fn () => RecurringLessonSlot::create([
            'class_id' => $schoolClass->id,
            'day_of_week' => $dayOfWeek,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]));
    }

    private function schoolClassFor(User $teacher, string $label, ?Organization $organization = null): SchoolClass
    {
        $organization ??= $this->organization;

        return $this->inTenant($organization, function () use ($organization, $teacher, $label): SchoolClass {
            $academicYear = $organization->is($this->organization)
                ? $this->academicYear
                : AcademicYear::factory()->recycle($organization)->create(['label' => '2025/2026']);

            // One Matemática per organization: the code is unique per tenant,
            // and every turma in these tests is the same discipline anyway.
            $subject = Subject::query()->firstOrCreate(['code' => 'MAT'], ['name' => 'Matemática']);

            $schoolClass = SchoolClass::factory()
                ->recycle($organization)
                ->create([
                    'label' => $label,
                    'academic_year_id' => $academicYear->getKey(),
                    'subject_id' => $subject->getKey(),
                ]);
            $schoolClass->teachers()->attach($teacher, ['role' => 'owner']);

            return $schoolClass;
        });
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return list<int>
     */
    private function sortedInts(array $ids): array
    {
        $ids = array_map(intval(...), array_values($ids));
        sort($ids);

        return $ids;
    }

    private function subscribeToPro(Organization $organization): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->id)
            ->delete();
        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->id,
            'plan_id' => Plan::query()->where('key', 'pro')->firstOrFail()->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::parse('2026-01-01 00:00:00'),
        ]);
        app(Entitlements::class)->flush();
    }

    private function inTenant(Organization $organization, callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($organization, $callback);
    }
}
