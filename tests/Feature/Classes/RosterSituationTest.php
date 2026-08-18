<?php

namespace Tests\Feature\Classes;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\EnrollmentStatus;
use App\Models\EnrollmentStatusReason;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentIdentity;
use App\Models\Subject;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The «SIT.» column of an EB052e/EB058e roll, read as data.
 *
 * FOUR OF THE FIVE CODES END AN ENROLMENT AND NONE OF THEM MEANS THE SAME
 * THING. The importer used to understand «TR» and treat everything else as
 * «matriculado», so a student the school had moved to another class stayed on
 * this roll — and, because the state was only ever written when a student was
 * first created, a re-import could not correct it either.
 *
 * What these assert is that the five codes reach four distinct states, that a
 * re-import records a change of state, that coming back as «X» clears the
 * reason rather than leaving a stale one behind, and that none of it ever
 * deletes a student or their history.
 */
class RosterSituationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Storage::fake('local');
    }

    protected function createClass(): SchoolClass
    {
        $context = app(CurrentOrganization::class)->runFor($this->user->personalOrganization(), fn (): array => [
            'year' => AcademicYear::factory()->recycle($this->user->personalOrganization())
                ->create(['starts_on' => '2026-09-14', 'ends_on' => '2027-06-30'])->id,
            'subject' => Subject::factory()->recycle($this->user->personalOrganization())->create()->id,
        ]);

        $this->actingAs($this->user)->post('/classes', [
            'label' => '7.º A',
            'academic_year_id' => $context['year'],
            'subject_id' => $context['subject'],
        ]);

        return SchoolClass::withoutGlobalScope('organization')->firstOrFail();
    }

    /**
     * A roll with one row per student, in the real export's shape.
     *
     * @param  array<string, string>  $students  name => SIT code
     */
    private function roll(array $students): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->setCellValue('A9', '3º CICLO');
        $sheet->setCellValue('H9', 'RELAÇÃO DE TURMA');

        foreach (['A14' => 'N.º MATR.', 'C14' => 'NOME', 'K14' => 'DATA NASC.', 'M14' => 'SIT.', 'U14' => 'N.º PROC.'] as $cell => $header) {
            $sheet->setCellValue($cell, $header);
        }

        $row = 15;

        foreach ($students as $name => $code) {
            $sheet->setCellValue("A{$row}", $row - 14);
            $sheet->setCellValue("C{$row}", $name);
            $sheet->setCellValue("M{$row}", $code);
            $row++;
        }

        $path = tempnam(sys_get_temp_dir(), 'roll').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return (string) file_get_contents($path);
    }

    /**
     * Uploads and confirms a roll, exactly as the teacher does.
     *
     * @param  array<string, string>  $students
     * @return list<array<string, mixed>> the preview rows, as the page received them
     */
    private function import(SchoolClass $class, array $students): array
    {
        $file = UploadedFile::fake()->createWithContent('relacao-de-turma.xlsx', $this->roll($students));

        $response = $this->actingAs($this->user)->post("/classes/{$class->ulid}/roster-imports", ['roster' => $file]);
        $response->assertOk();

        /** @var array<string, mixed> $page */
        $page = $response->viewData('page');

        /** @var list<array<string, mixed>> $rows */
        $rows = $page['props']['rows'];

        $this->actingAs($this->user)
            ->post("/classes/{$class->ulid}/roster-imports/{$page['props']['token']}/confirm", ['rows' => $rows])
            ->assertRedirect();

        return $rows;
    }

    /**
     * @return array<string, mixed> the preview rows of an upload that is NOT confirmed
     */
    private function preview(SchoolClass $class, array $students): array
    {
        $file = UploadedFile::fake()->createWithContent('relacao-de-turma.xlsx', $this->roll($students));

        $response = $this->actingAs($this->user)->post("/classes/{$class->ulid}/roster-imports", ['roster' => $file]);
        $response->assertOk();

        /** @var array<string, mixed> $page */
        $page = $response->viewData('page');

        return $page['props']['rows'];
    }

    private function enrollmentOf(string $name): Enrollment
    {
        return app(CurrentOrganization::class)->runFor($this->user->personalOrganization(), function () use ($name): Enrollment {
            $identity = StudentIdentity::where('organization_id', $this->user->personalOrganization()->id)
                ->get()
                ->first(fn (StudentIdentity $candidate): bool => $candidate->display_name === $name);

            return Enrollment::where('student_id', $identity->student_id)->firstOrFail();
        });
    }

    // -------------------------------------------- 1. os cinco códigos

    #[Test]
    public function each_situation_code_lands_on_its_own_state(): void
    {
        $class = $this->createClass();

        $this->import($class, [
            'Ana Matriculada' => 'X',
            'Bruno Transferido' => 'TR',
            'Carla Mudou' => 'MT',
            'Diogo Anulou' => 'AM',
            'Eva Excluida' => 'EF',
        ]);

        $expected = [
            'Ana Matriculada' => [EnrollmentStatus::Active, null],
            'Bruno Transferido' => [EnrollmentStatus::TransferredOut, EnrollmentStatusReason::Transfer],
            'Carla Mudou' => [EnrollmentStatus::Left, EnrollmentStatusReason::MovedClass],
            'Diogo Anulou' => [EnrollmentStatus::Left, EnrollmentStatusReason::CancelledEnrolment],
            'Eva Excluida' => [EnrollmentStatus::Left, EnrollmentStatusReason::ExcludedForAbsences],
        ];

        foreach ($expected as $name => [$status, $reason]) {
            $enrollment = $this->enrollmentOf($name);

            $this->assertSame($status, $enrollment->status, "estado de {$name}");
            $this->assertSame($reason, $enrollment->status_reason, "motivo de {$name}");
        }
    }

    #[Test]
    public function a_transfer_and_a_class_move_are_not_the_same_thing(): void
    {
        $class = $this->createClass();

        $this->import($class, ['Bruno Transferido' => 'TR', 'Carla Mudou' => 'MT']);

        // Both leave this roll; one left the school and the other went next
        // door, and a roll that recorded «saiu» for both would lose that.
        $this->assertNotSame(
            $this->enrollmentOf('Bruno Transferido')->status_reason,
            $this->enrollmentOf('Carla Mudou')->status_reason,
        );
    }

    #[Test]
    public function a_cancelled_enrolment_and_an_exclusion_are_not_the_same_thing(): void
    {
        $class = $this->createClass();

        $this->import($class, ['Diogo Anulou' => 'AM', 'Eva Excluida' => 'EF']);

        $this->assertNotSame(
            $this->enrollmentOf('Diogo Anulou')->status_reason,
            $this->enrollmentOf('Eva Excluida')->status_reason,
        );
    }

    #[Test]
    public function being_excluded_for_absences_is_administrative_and_never_a_grade(): void
    {
        $class = $this->createClass();

        $this->import($class, ['Eva Excluida' => 'EF']);

        $enrollment = $this->enrollmentOf('Eva Excluida');

        // No classification, no zero, no absence in any instrument (§9, §27).
        $this->assertSame(EnrollmentStatusReason::ExcludedForAbsences, $enrollment->status_reason);
        $this->assertSame(0, DB::table('classifications')->where('enrollment_id', $enrollment->id)->count());
        $this->assertSame(0, DB::table('student_item_scores')->where('enrollment_id', $enrollment->id)->count());
    }

    // ------------------------------------------- 2. a reimportação

    #[Test]
    public function a_reimport_records_the_new_situation(): void
    {
        $class = $this->createClass();

        $this->import($class, ['Carla Mudou' => 'X']);
        $this->assertSame(EnrollmentStatus::Active, $this->enrollmentOf('Carla Mudou')->status);

        // The roll is a snapshot of the class as it stands, so the second file
        // is where «X → MT» actually gets recorded.
        $this->import($class, ['Carla Mudou' => 'MT']);

        $enrollment = $this->enrollmentOf('Carla Mudou');

        $this->assertSame(EnrollmentStatus::Left, $enrollment->status);
        $this->assertSame(EnrollmentStatusReason::MovedClass, $enrollment->status_reason);
    }

    #[Test]
    public function coming_back_as_enrolled_clears_the_reason(): void
    {
        $class = $this->createClass();

        $this->import($class, ['Carla Mudou' => 'MT']);
        $this->assertSame(EnrollmentStatusReason::MovedClass, $this->enrollmentOf('Carla Mudou')->status_reason);

        $this->import($class, ['Carla Mudou' => 'X']);

        $enrollment = $this->enrollmentOf('Carla Mudou');

        // The state is written WHOLE. A student back on the roll must not keep
        // a reason describing a departure that was undone.
        $this->assertSame(EnrollmentStatus::Active, $enrollment->status);
        $this->assertNull($enrollment->status_reason, 'o motivo tem de ser limpo, não sobreviver');
    }

    #[Test]
    public function every_leaving_code_can_be_undone_by_a_later_roll(): void
    {
        $class = $this->createClass();

        // Three students, one per way of leaving, all returning in the second
        // roll — so the undo is checked for each reason and not only for «MT».
        $left = ['Ana Transferida' => 'TR', 'Bruno Anulou' => 'AM', 'Carla Excluida' => 'EF'];

        $this->import($class, $left);
        $this->import($class, array_map(fn (): string => 'X', $left));

        foreach (array_keys($left) as $name) {
            $enrollment = $this->enrollmentOf($name);

            $this->assertSame(EnrollmentStatus::Active, $enrollment->status, "«{$left[$name]}» → «X» para {$name}");
            $this->assertNull($enrollment->status_reason, "motivo de {$name} depois de voltar");
        }
    }

    #[Test]
    public function reimporting_the_same_situation_changes_nothing_and_duplicates_nothing(): void
    {
        $class = $this->createClass();

        $this->import($class, ['Carla Mudou' => 'MT']);

        $before = $this->enrollmentOf('Carla Mudou');
        $enrollmentId = $before->id;
        $studentId = $before->student_id;

        $this->import($class, ['Carla Mudou' => 'MT']);

        $after = $this->enrollmentOf('Carla Mudou');

        $this->assertSame($enrollmentId, $after->id, 'a mesma matrícula, não uma segunda');
        $this->assertSame($studentId, $after->student_id);
        $this->assertSame(EnrollmentStatusReason::MovedClass, $after->status_reason);
        $this->assertSame(1, Enrollment::withoutGlobalScope('organization')->count());
        $this->assertSame(1, Student::withoutGlobalScope('organization')->count());
    }

    // ------------------------------------- 3. o desconhecido e o vazio

    #[Test]
    public function an_unknown_code_leaves_the_state_alone(): void
    {
        $class = $this->createClass();

        $this->import($class, ['Carla Mudou' => 'MT']);
        $this->import($class, ['Carla Mudou' => 'ZZ']);

        $enrollment = $this->enrollmentOf('Carla Mudou');

        // Never silently mapped to «matriculado»: that guess would put a
        // student back on a roll they had left.
        $this->assertSame(EnrollmentStatus::Left, $enrollment->status);
        $this->assertSame(EnrollmentStatusReason::MovedClass, $enrollment->status_reason);
    }

    #[Test]
    public function an_empty_code_leaves_the_state_alone(): void
    {
        $class = $this->createClass();

        $this->import($class, ['Carla Mudou' => 'MT']);
        $this->import($class, ['Carla Mudou' => '']);

        $this->assertSame(EnrollmentStatus::Left, $this->enrollmentOf('Carla Mudou')->status);
        $this->assertSame(EnrollmentStatusReason::MovedClass, $this->enrollmentOf('Carla Mudou')->status_reason);
    }

    #[Test]
    public function an_unknown_code_on_a_new_student_enrols_them_plainly(): void
    {
        $class = $this->createClass();

        $this->import($class, ['Nova Aluna' => 'ZZ']);

        $enrollment = $this->enrollmentOf('Nova Aluna');

        // There is no earlier state to preserve, and refusing the row entirely
        // would lose a student the roll does list.
        $this->assertSame(EnrollmentStatus::Active, $enrollment->status);
        $this->assertNull($enrollment->status_reason);
    }

    // --------------------------------------------- 4. nada é apagado

    #[Test]
    public function changing_situation_never_deletes_the_student_or_their_record(): void
    {
        $class = $this->createClass();

        $this->import($class, ['Carla Mudou' => 'X']);

        $before = $this->enrollmentOf('Carla Mudou');
        $identityId = app(CurrentOrganization::class)->runFor(
            $this->user->personalOrganization(),
            fn (): int => StudentIdentity::where('student_id', $before->student_id)->firstOrFail()->id,
        );

        $this->import($class, ['Carla Mudou' => 'EF']);

        $after = $this->enrollmentOf('Carla Mudou');

        $this->assertSame($before->id, $after->id);
        $this->assertSame($before->student_id, $after->student_id);
        $this->assertSame($before->enrolled_on->toDateString(), $after->enrolled_on->toDateString());
        $this->assertNotNull(Student::withoutGlobalScope('organization')->find($before->student_id));
        $this->assertNotNull(StudentIdentity::find($identityId));
    }

    // ------------------------------------------------- 5. o preview

    #[Test]
    public function the_preview_names_the_situation_in_words(): void
    {
        $class = $this->createClass();

        $rows = $this->preview($class, [
            'Ana Matriculada' => 'X',
            'Bruno Transferido' => 'TR',
            'Carla Mudou' => 'MT',
            'Diogo Anulou' => 'AM',
            'Eva Excluida' => 'EF',
        ]);

        $labels = array_column($rows, 'situation_label', 'situation_code');

        $this->assertSame([
            'X' => 'Matriculado',
            'TR' => 'Transferência',
            'MT' => 'Mudou de turma',
            'AM' => 'Anulou matrícula',
            'EF' => 'Excluído por faltas',
        ], $labels);

        foreach ($rows as $row) {
            $this->assertTrue($row['situation_recognized']);
        }
    }

    #[Test]
    public function the_preview_shows_a_change_of_state_for_someone_already_on_the_roll(): void
    {
        $class = $this->createClass();

        $this->import($class, ['Carla Mudou' => 'X']);

        $rows = $this->preview($class, ['Carla Mudou' => 'MT']);

        $this->assertTrue($rows[0]['already_enrolled']);
        $this->assertTrue($rows[0]['state_changes']);
        $this->assertSame('Inscrito', $rows[0]['current_state']);
        $this->assertSame('Mudou de turma', $rows[0]['situation_label']);
    }

    #[Test]
    public function the_preview_does_not_announce_a_change_that_is_not_one(): void
    {
        $class = $this->createClass();

        $this->import($class, ['Carla Mudou' => 'MT']);

        $rows = $this->preview($class, ['Carla Mudou' => 'MT']);

        $this->assertFalse($rows[0]['state_changes'], 'repetir o mesmo estado não é uma mudança');
        $this->assertSame('Mudou de turma', $rows[0]['current_state']);
    }

    #[Test]
    public function the_preview_flags_a_code_it_does_not_understand(): void
    {
        $class = $this->createClass();

        $rows = $this->preview($class, ['Nova Aluna' => 'ZZ']);

        $this->assertFalse($rows[0]['situation_recognized']);
        $this->assertNull($rows[0]['situation_label']);
    }
}
