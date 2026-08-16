<?php

namespace Tests\Feature\Classes;

use App\Models\Enrollment;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentIdentity;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Export\InovarExportPreviewBuilder;
use App\Services\Export\InovarTemplateReader;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InovarGridFixture;
use Tests\TestCase;

/**
 * Typing in the N.º de processo, for a class that never came from a Relação de
 * Turma.
 *
 * The same field the roster import writes — `student_identities.school_number`,
 * per (student, organization) — reached a second way. It stays optional
 * everywhere: what changes is that a teacher who needs it for INOVAR no longer
 * has to re-import a class to get it.
 */
class ProcessNumberEditingTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);
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

    /**
     * @return list<Enrollment>
     */
    private function enrollments(): array
    {
        return $this->asTenant(fn (): array => $this->schoolClass()
            ->enrollments()->with('student.identity')->orderBy('class_number')->get()->all());
    }

    /**
     * @param  array<string, string|null>  $byUlid
     */
    private function save(array $byUlid): TestResponse
    {
        $numbers = [];

        foreach ($byUlid as $ulid => $number) {
            $numbers[] = ['enrollment_ulid' => $ulid, 'process_number' => $number];
        }

        return $this->actingAs($this->teacher)
            ->put("/classes/{$this->schoolClass()->ulid}/process-numbers", ['numbers' => $numbers]);
    }

    private function processNumberOf(Enrollment $enrollment): ?string
    {
        return $this->asTenant(fn (): ?string => Enrollment::with('student.identity')
            ->findOrFail($enrollment->id)->student->processNumber());
    }

    // -------------------------------------------- 1. o que já era verdade

    #[Test]
    public function a_class_typed_in_by_hand_starts_with_none_and_is_perfectly_valid(): void
    {
        foreach ($this->enrollments() as $enrollment) {
            $this->assertNull($this->processNumberOf($enrollment));
        }

        // The class page opens, as it always did.
        $this->actingAs($this->teacher)->get("/classes/{$this->schoolClass()->ulid}")->assertOk();
    }

    // ------------------------------------------------ 2. escrever à mão

    #[Test]
    public function a_teacher_can_type_the_numbers_in_for_the_whole_class(): void
    {
        $enrollments = $this->enrollments();

        $this->save([
            $enrollments[0]->ulid => '4821',
            $enrollments[1]->ulid => '4822',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('4821', $this->processNumberOf($enrollments[0]));
        $this->assertSame('4822', $this->processNumberOf($enrollments[1]));
        // Untouched rows stay untouched.
        $this->assertNull($this->processNumberOf($enrollments[2]));
    }

    #[Test]
    public function a_leading_zero_is_part_of_the_identifier_and_survives(): void
    {
        $enrollment = $this->enrollments()[0];

        $this->save([$enrollment->ulid => '007421']);

        // Never cast to an integer: «007421» is not 7421.
        $this->assertSame('007421', $this->processNumberOf($enrollment));
    }

    #[Test]
    public function an_identifier_with_letters_is_accepted(): void
    {
        $enrollment = $this->enrollments()[0];

        // Plenty of schools use them, so nothing here validates «a number».
        $this->save([$enrollment->ulid => 'AE-2026/0182'])->assertSessionHasNoErrors();

        $this->assertSame('AE-2026/0182', $this->processNumberOf($enrollment));
    }

    #[Test]
    public function clearing_the_field_records_no_number_rather_than_an_empty_one(): void
    {
        $enrollment = $this->enrollments()[0];

        $this->save([$enrollment->ulid => '4821']);
        $this->assertSame('4821', $this->processNumberOf($enrollment));

        $this->save([$enrollment->ulid => null]);
        $this->assertNull($this->processNumberOf($enrollment));

        // And a field of spaces is the same thing as an empty one.
        $this->save([$enrollment->ulid => '   ']);
        $this->assertNull($this->processNumberOf($enrollment));
    }

    #[Test]
    public function the_edit_touches_the_identity_and_nothing_else(): void
    {
        $enrollment = $this->enrollments()[0];

        $before = $this->asTenant(fn (): array => Enrollment::findOrFail($enrollment->id)->only([
            'class_number', 'enrolled_on', 'status', 'is_late_entry', 'import_note', 'student_id',
        ]));
        $name = $this->asTenant(fn (): string => Enrollment::with('student.identity')
            ->findOrFail($enrollment->id)->student->identity->display_name);

        $this->save([$enrollment->ulid => '4821']);

        $after = $this->asTenant(fn (): array => Enrollment::findOrFail($enrollment->id)->only([
            'class_number', 'enrolled_on', 'status', 'is_late_entry', 'import_note', 'student_id',
        ]));

        // The enrolment is a fact about a year; this is not.
        $this->assertEquals($before, $after);
        // And the name, the birth date and the photo are other flows' business.
        $this->assertSame($name, $this->asTenant(fn (): string => Enrollment::with('student.identity')
            ->findOrFail($enrollment->id)->student->identity->display_name));
    }

    // ------------------------------------------------------ 3. quem pode

    #[Test]
    public function a_teacher_from_another_organization_cannot_edit_this_class(): void
    {
        $classUlid = $this->schoolClass()->ulid;
        $enrollment = $this->enrollments()[0];

        $this->actingAs(User::factory()->create())
            ->put("/classes/{$classUlid}/process-numbers", [
                'numbers' => [['enrollment_ulid' => $enrollment->ulid, 'process_number' => '9999']],
            ])
            ->assertNotFound();

        $this->assertNull($this->processNumberOf($enrollment));
    }

    #[Test]
    public function an_enrolment_from_another_class_is_skipped_rather_than_written(): void
    {
        $class = $this->schoolClass();
        $enrollment = $this->enrollments()[0];

        $other = $this->asTenant(function () use ($class): SchoolClass {
            $second = SchoolClass::factory()->recycle($this->teacher->personalOrganization())->create([
                'academic_year_id' => $class->academic_year_id,
                'subject_id' => $class->subject_id,
                'label' => '7.º B',
            ]);
            $second->teachers()->attach($this->teacher, ['role' => 'owner']);

            return $second;
        });

        // A valid ulid, of a student in a different class of mine: resolved
        // through THIS class it finds nothing, so nothing is written.
        $this->actingAs($this->teacher)
            ->put("/classes/{$other->ulid}/process-numbers", [
                'numbers' => [['enrollment_ulid' => $enrollment->ulid, 'process_number' => '9999']],
            ])
            ->assertRedirect();

        $this->assertNull($this->processNumberOf($enrollment));
    }

    // --------------------------------- 4. e depois disto, o INOVAR passa

    #[Test]
    public function filling_them_in_unblocks_the_inovar_export(): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $this->teacher->personalOrganization()->getKey())->delete();
        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $this->teacher->personalOrganization()->getKey(),
            'plan_id' => Plan::where('key', 'pro')->firstOrFail()->getKey(),
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::now()->subDay(),
        ]);
        app(Entitlements::class)->flush();

        $missing = 'Existem alunos sem N.º de processo. Complete esta informação para poder exportar para o INOVAR.';

        $numbers = [];
        $next = 4821;

        foreach ($this->enrollments() as $enrollment) {
            $numbers[$enrollment->ulid] = (string) $next++;
        }

        $this->save($numbers)->assertSessionHasNoErrors();

        // Read back through the very method the export calls — no cache, no
        // second path.
        foreach ($this->enrollments() as $enrollment) {
            $this->assertNotNull($this->processNumberOf($enrollment));
        }

        $blocking = $this->asTenant(function (): array {
            $class = $this->schoolClass();
            $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();
            $grid = (new InovarGridFixture)->build([
                'domains' => ['D' => 'Leitura', 'E' => 'Escrita'],
                'students' => array_flip(array_map(
                    fn (Enrollment $row): string => (string) $row->student->processNumber(),
                    $class->enrollments()->with('student.identity')->orderBy('class_number')->get()->all(),
                )),
            ]);

            $template = app(InovarTemplateReader::class)->read($grid);

            /** @var list<string> $errors */
            $errors = app(InovarExportPreviewBuilder::class)
                ->build($class, $period, $template)['summary']['blocking_errors'];

            return $errors;
        });

        $this->assertNotContains($missing, $blocking);
    }

    // ------------------------------- 5. o EB058e continua a escrever o mesmo

    #[Test]
    public function the_roster_import_writes_the_very_same_field(): void
    {
        $enrollment = $this->enrollments()[0];

        $this->save([$enrollment->ulid => '4821']);

        $this->asTenant(function () use ($enrollment): void {
            $identity = Enrollment::with('student.identity')->findOrFail($enrollment->id)->student->identity;

            // One column, one meaning. The roster import fills in exactly this,
            // and there is no second field anywhere meaning the same thing.
            $this->assertSame('4821', $identity->school_number);
            $this->assertArrayHasKey('school_number', $identity->getAttributes());

            foreach (['inovar_number', 'process_number', 'external_id'] as $absent) {
                $this->assertArrayNotHasKey($absent, $identity->getAttributes());
                $this->assertArrayNotHasKey($absent, Enrollment::findOrFail($enrollment->id)->getAttributes());
            }
        });
    }

    #[Test]
    public function typing_a_number_creates_no_second_student_and_no_second_identity(): void
    {
        $before = $this->asTenant(fn (): array => [
            Student::count(), StudentIdentity::count(), Enrollment::count(),
        ]);

        $enrollments = $this->enrollments();
        $this->save([$enrollments[0]->ulid => '4821']);
        $this->save([$enrollments[0]->ulid => '4822']);

        $this->assertSame($before, $this->asTenant(fn (): array => [
            Student::count(), StudentIdentity::count(), Enrollment::count(),
        ]));
        $this->assertSame('4822', $this->processNumberOf($enrollments[0]));
    }
}
