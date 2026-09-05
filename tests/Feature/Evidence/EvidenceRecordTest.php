<?php

namespace Tests\Feature\Evidence;

use App\Models\AcademicPeriod;
use App\Models\Domain;
use App\Models\EnrollmentStatus;
use App\Models\EvidenceRecord;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The logbook (§14): qualitative entries tied to a class and optionally a student,
 * never a grade. Targeting must stay inside the class.
 */
class EvidenceRecordTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{string, string} class ulid, an enrollment id of that class */
    private function seedClass(): array
    {
        $teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        return app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();

            return [$class->ulid, (string) $class->enrollments()->orderBy('class_number')->first()->id];
        });
    }

    /** @return array{string, string, string} class ulid, an enrollment id, a domain id of the class's subject */
    private function seedClassWithDomain(): array
    {
        [$classUlid, $enrollmentId] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();

        $domainId = app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($classUlid) {
            $class = SchoolClass::where('ulid', $classUlid)->firstOrFail();

            return (string) Domain::where('subject_id', $class->subject_id)->firstOrFail()->id;
        });

        return [$classUlid, $enrollmentId, $domainId];
    }

    /**
     * Mirrors exactly what the real form sends (resources/js/pages/records/Show.vue's
     * useForm): every kind-specific field present in the JSON body, explicit `null`
     * for whichever ones don't belong to the chosen kind. A plain PHP array with only
     * the relevant keys omitted (the old shape of these tests) does NOT reproduce
     * this — Laravel treats an absent key differently from a present, null one, and
     * that exact gap is what let a real production bug (Rule::enum() rejecting an
     * explicit null without 'nullable') pass 25 "green" tests undetected.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function postRecord(string $classUlid, array $overrides): TestResponse
    {
        $payload = array_merge([
            'kind' => null,
            'disciplinary_severity' => null,
            'homework_status' => null,
            'participation_level' => null,
            'activity_evaluation' => null,
            'activity_include_in_report' => null,
            'description' => null,
            'occurred_at' => null,
            'enrollment_ids' => [],
            'domain_id' => null,
        ], $overrides);

        return $this->postJson("/classes/{$classUlid}/records", $payload);
    }

    /** @param  array<string, mixed>  $overrides */
    private function putRecord(string $ulid, array $overrides): TestResponse
    {
        $payload = array_merge([
            'kind' => null,
            'disciplinary_severity' => null,
            'homework_status' => null,
            'participation_level' => null,
            'activity_evaluation' => null,
            'activity_include_in_report' => null,
            'description' => null,
            'occurred_at' => null,
            'enrollment_id' => null,
            'domain_id' => null,
        ], $overrides);

        return $this->putJson("/records/{$ulid}", $payload);
    }

    /** @param  list<array{enrollment_id: int, homework_status: string|null, description: string|null}>  $rows */
    private function putHomeworkBatch(string $classUlid, string $occurredAt, array $rows): TestResponse
    {
        return $this->putJson("/classes/{$classUlid}/records/homework-batch", [
            'occurred_at' => $occurredAt,
            'rows' => $rows,
        ]);
    }

    /**
     * Asserts on the flashed toast (Inertia::flash stores it under the
     * 'inertia.flash_data' session key, not a bare 'toast' key).
     *
     * @param  \Closure(array{type: string, message: string}): bool  $callback
     */
    private function assertToast(TestResponse $response, \Closure $callback): TestResponse
    {
        $response->assertSessionHas('inertia.flash_data', fn ($flash) => $callback($flash['toast']));

        return $response;
    }

    #[Test]
    public function a_teacher_records_an_entry_for_a_student(): void
    {
        [$classUlid, $enrollmentId] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();

        $this->actingAs($teacher);
        $this->postRecord($classUlid, [
            'kind' => 'participation',
            'participation_level' => 'positive',
            'description' => 'Participou de forma sustentada na discussão do texto.',
            'occurred_at' => '2026-10-20',
            'enrollment_ids' => [(int) $enrollmentId],
        ])->assertRedirect();

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($enrollmentId): void {
            $record = EvidenceRecord::firstOrFail();
            $this->assertSame('participation', $record->kind->value);
            $this->assertSame((int) $enrollmentId, $record->enrollment_id);
        });
    }

    #[Test]
    public function a_disciplinary_occurrence_requires_a_severity_grade(): void
    {
        [$classUlid, $enrollmentId] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();
        $this->actingAs($teacher);

        $this->postRecord($classUlid, [
            'kind' => 'incident',
            'description' => 'Perturbou a aula.',
            'occurred_at' => '2026-10-20',
            'enrollment_ids' => [(int) $enrollmentId],
        ])->assertJsonValidationErrors('disciplinary_severity');

        $this->postRecord($classUlid, [
            'kind' => 'incident',
            'disciplinary_severity' => 'g3',
            'description' => 'Perturbou a aula.',
            'occurred_at' => '2026-10-20',
            'enrollment_ids' => [(int) $enrollmentId],
        ])->assertRedirect();

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function (): void {
            $record = EvidenceRecord::firstOrFail();
            $this->assertSame('incident', $record->kind->value);
            $this->assertSame('g3', $record->disciplinary_severity->value);
        });
    }

    #[Test]
    public function a_severity_grade_is_rejected_for_a_non_disciplinary_kind(): void
    {
        [$classUlid, $enrollmentId] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();
        $this->actingAs($teacher);

        $this->postRecord($classUlid, [
            'kind' => 'participation',
            'participation_level' => 'positive',
            'disciplinary_severity' => 'g3',
            'description' => 'Participou bem.',
            'occurred_at' => '2026-10-20',
            'enrollment_ids' => [(int) $enrollmentId],
        ])->assertJsonValidationErrors('disciplinary_severity');
    }

    #[Test]
    public function a_class_level_note_needs_no_student(): void
    {
        [$classUlid] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();
        $this->actingAs($teacher);

        $this->postRecord($classUlid, [
            'kind' => 'note',
            'description' => 'Visita de estudo à biblioteca municipal marcada para a turma.',
            'occurred_at' => '2026-11-05',
        ])->assertRedirect();

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function (): void {
            $this->assertSame(1, EvidenceRecord::count());
            $this->assertNull(EvidenceRecord::firstOrFail()->enrollment_id);
        });
    }

    #[Test]
    public function a_student_from_another_class_is_rejected(): void
    {
        [$classUlid] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();
        $this->actingAs($teacher);

        // Enrollment id 999999 does not belong to this class.
        $this->postRecord($classUlid, [
            'kind' => 'note',
            'description' => 'Tentativa inválida.',
            'occurred_at' => '2026-10-20',
            'enrollment_ids' => [999999],
        ])->assertJsonValidationErrors('enrollment_ids');

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function (): void {
            $this->assertSame(0, EvidenceRecord::count());
        });
    }

    #[Test]
    public function selecting_several_students_creates_one_independent_record_each(): void
    {
        [$classUlid] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();

        $enrollmentIds = app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($classUlid) {
            $class = SchoolClass::where('ulid', $classUlid)->firstOrFail();

            return $class->enrollments()->orderBy('class_number')->limit(3)->pluck('id')->all();
        });

        $this->actingAs($teacher);
        $this->postRecord($classUlid, [
            'kind' => 'homework',
            'homework_status' => 'not_done',
            'occurred_at' => '2026-10-20',
            'enrollment_ids' => $enrollmentIds,
        ])->assertRedirect();

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($enrollmentIds): void {
            $this->assertSame(3, EvidenceRecord::count());
            $recordedEnrollmentIds = EvidenceRecord::pluck('enrollment_id')->sort()->values()->all();
            $this->assertSame(collect($enrollmentIds)->sort()->values()->all(), $recordedEnrollmentIds);
            // Each is its own row: deleting one must not touch the others.
            $first = EvidenceRecord::whereKey(EvidenceRecord::first()->id)->firstOrFail();
            $first->delete();
            $this->assertSame(2, EvidenceRecord::count());
        });
    }

    #[Test]
    public function removing_a_record_soft_deletes_it(): void
    {
        [$classUlid, $enrollmentId] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();
        $this->actingAs($teacher);

        $this->postRecord($classUlid, [
            'kind' => 'note', 'description' => 'A remover.', 'occurred_at' => '2026-10-20',
        ])->assertRedirect();

        $ulid = app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), fn () => EvidenceRecord::firstOrFail()->ulid);

        $this->delete("/records/{$ulid}")->assertRedirect();

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function (): void {
            $this->assertSame(0, EvidenceRecord::count());
            $this->assertSame(1, EvidenceRecord::withTrashed()->count());
        });
    }

    #[Test]
    public function the_logbook_of_another_organization_is_not_found(): void
    {
        [$classUlid] = $this->seedClass();

        $stranger = User::factory()->create();
        $this->actingAs($stranger)->get("/classes/{$classUlid}/records")->assertNotFound();
    }

    #[Test]
    public function homework_requires_a_status_but_description_is_optional(): void
    {
        [$classUlid, $enrollmentId] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();
        $this->actingAs($teacher);

        $this->postRecord($classUlid, [
            'kind' => 'homework',
            'occurred_at' => '2026-10-20',
            'enrollment_ids' => [(int) $enrollmentId],
        ])->assertJsonValidationErrors('homework_status');

        $this->postRecord($classUlid, [
            'kind' => 'homework',
            'homework_status' => 'not_done',
            'occurred_at' => '2026-10-20',
            'enrollment_ids' => [(int) $enrollmentId],
        ])->assertRedirect();

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function (): void {
            $record = EvidenceRecord::firstOrFail();
            $this->assertSame('not_done', $record->homework_status->value);
            $this->assertSame('', $record->description);
        });
    }

    #[Test]
    public function participation_requires_a_level(): void
    {
        [$classUlid, $enrollmentId] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();
        $this->actingAs($teacher);

        $this->postRecord($classUlid, [
            'kind' => 'participation',
            'occurred_at' => '2026-10-20',
            'enrollment_ids' => [(int) $enrollmentId],
        ])->assertJsonValidationErrors('participation_level');
    }

    #[Test]
    public function participation_description_is_optional(): void
    {
        [$classUlid, $enrollmentId] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();
        $this->actingAs($teacher);

        $this->postRecord($classUlid, [
            'kind' => 'participation',
            'participation_level' => 'adequate',
            'occurred_at' => '2026-10-20',
            'enrollment_ids' => [(int) $enrollmentId],
        ])->assertRedirect();

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function (): void {
            $record = EvidenceRecord::firstOrFail();
            $this->assertSame('adequate', $record->participation_level->value);
            $this->assertSame('', $record->description);
        });
    }

    #[Test]
    public function a_disciplinary_occurrence_description_is_optional(): void
    {
        [$classUlid, $enrollmentId] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();
        $this->actingAs($teacher);

        $this->postRecord($classUlid, [
            'kind' => 'incident',
            'disciplinary_severity' => 'g2',
            'occurred_at' => '2026-10-20',
            'enrollment_ids' => [(int) $enrollmentId],
        ])->assertRedirect();

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function (): void {
            $this->assertSame('', EvidenceRecord::firstOrFail()->description);
        });
    }

    #[Test]
    public function activity_requires_evaluation_include_in_report_and_description(): void
    {
        [$classUlid] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();
        $this->actingAs($teacher);

        $this->postRecord($classUlid, [
            'kind' => 'activity',
            'occurred_at' => '2026-11-05',
        ])->assertJsonValidationErrors(['description', 'activity_evaluation', 'activity_include_in_report']);

        $this->postRecord($classUlid, [
            'kind' => 'activity',
            'description' => 'Visionamento da peça Leandro, Rei da Helíria.',
            'activity_evaluation' => 'very_positive',
            'activity_include_in_report' => true,
            'occurred_at' => '2026-11-05',
        ])->assertRedirect();

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function (): void {
            $record = EvidenceRecord::firstOrFail();
            $this->assertSame('very_positive', $record->activity_evaluation->value);
            $this->assertTrue($record->activity_include_in_report);
        });
    }

    #[Test]
    public function progress_requires_description_and_accepts_an_optional_domain(): void
    {
        [$classUlid, $enrollmentId, $domainId] = $this->seedClassWithDomain();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();
        $this->actingAs($teacher);

        $this->postRecord($classUlid, [
            'kind' => 'progress',
            'occurred_at' => '2026-10-20',
            'enrollment_ids' => [(int) $enrollmentId],
        ])->assertJsonValidationErrors('description');

        $this->postRecord($classUlid, [
            'kind' => 'progress',
            'description' => 'Demonstrou maior autonomia na produção escrita.',
            'occurred_at' => '2026-10-20',
            'enrollment_ids' => [(int) $enrollmentId],
        ])->assertRedirect();

        $this->postRecord($classUlid, [
            'kind' => 'progress',
            'description' => 'Progrediu na leitura em voz alta.',
            'domain_id' => (int) $domainId,
            'occurred_at' => '2026-10-21',
            'enrollment_ids' => [(int) $enrollmentId],
        ])->assertRedirect();

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($domainId): void {
            $this->assertSame(2, EvidenceRecord::count());
            $withDomain = EvidenceRecord::whereNotNull('domain_id')->firstOrFail();
            $this->assertSame((int) $domainId, $withDomain->domain_id);
        });
    }

    #[Test]
    public function difficulty_requires_description_and_accepts_an_optional_domain(): void
    {
        [$classUlid, $enrollmentId] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();
        $this->actingAs($teacher);

        $this->postRecord($classUlid, [
            'kind' => 'difficulty',
            'occurred_at' => '2026-10-20',
            'enrollment_ids' => [(int) $enrollmentId],
        ])->assertJsonValidationErrors('description');

        $this->postRecord($classUlid, [
            'kind' => 'difficulty',
            'description' => 'Revela dificuldade na organização das ideias.',
            'occurred_at' => '2026-10-20',
            'enrollment_ids' => [(int) $enrollmentId],
        ])->assertRedirect();

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function (): void {
            $this->assertNull(EvidenceRecord::firstOrFail()->domain_id);
        });
    }

    #[Test]
    public function the_first_two_lateness_records_carry_no_accumulation_warning(): void
    {
        [$classUlid, $enrollmentId] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();
        $this->actingAs($teacher);

        for ($i = 0; $i < 2; $i++) {
            $response = $this->postRecord($classUlid, [
                'kind' => 'lateness',
                'description' => 'Chegou atrasado.',
                'occurred_at' => '2026-10-20',
                'enrollment_ids' => [(int) $enrollmentId],
            ]);
            $response->assertRedirect();
            $this->assertToast($response, fn ($toast) => $toast['type'] === 'success');
        }
    }

    #[Test]
    public function the_third_lateness_record_triggers_the_accumulation_warning_and_the_sixth_repeats_it(): void
    {
        [$classUlid, $enrollmentId] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();
        $this->actingAs($teacher);

        $post = fn () => $this->postRecord($classUlid, [
            'kind' => 'lateness',
            'description' => 'Chegou atrasado.',
            'occurred_at' => '2026-10-20',
            'enrollment_ids' => [(int) $enrollmentId],
        ]);

        // 1st and 2nd: no warning yet.
        $this->assertToast($post(), fn ($toast) => $toast['type'] === 'success');
        $this->assertToast($post(), fn ($toast) => $toast['type'] === 'success');

        // 3rd: crosses the first multiple of 3.
        $this->assertToast($post(), fn ($toast) => $toast['type'] === 'warning'
            && str_contains($toast['message'], 'acumulou 3 atrasos')
            && str_contains($toast['message'], 'Inovar (grau 2)')
            && str_contains($toast['message'], 'alertar o encarregado de educação')
            && ! str_contains($toast['message'], 'falta de presença'));

        // 4th and 5th: back to no warning.
        $this->assertToast($post(), fn ($toast) => $toast['type'] === 'success');
        $this->assertToast($post(), fn ($toast) => $toast['type'] === 'success');

        // 6th: the next multiple of 3.
        $this->assertToast($post(), fn ($toast) => $toast['type'] === 'warning'
            && str_contains($toast['message'], 'acumulou 6 atrasos'));
    }

    #[Test]
    public function the_third_missing_material_record_triggers_its_own_warning_text(): void
    {
        [$classUlid, $enrollmentId] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();
        $this->actingAs($teacher);

        $post = fn () => $this->postRecord($classUlid, [
            'kind' => 'missing_material',
            'description' => 'Não trouxe o material.',
            'occurred_at' => '2026-10-20',
            'enrollment_ids' => [(int) $enrollmentId],
        ]);

        $this->assertToast($post(), fn ($toast) => $toast['type'] === 'success');
        $this->assertToast($post(), fn ($toast) => $toast['type'] === 'success');
        $this->assertToast($post(), fn ($toast) => $toast['type'] === 'warning'
            && str_contains($toast['message'], 'acumulou 3 faltas de material')
            && str_contains($toast['message'], 'Inovar (grau 2)')
            && str_contains($toast['message'], 'consequências na avaliação'));
    }

    #[Test]
    public function a_whole_class_lateness_record_never_counts_towards_any_student(): void
    {
        [$classUlid] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();
        $this->actingAs($teacher);

        for ($i = 0; $i < 3; $i++) {
            $this->assertToast($this->postRecord($classUlid, [
                'kind' => 'lateness',
                'description' => 'Atraso da turma toda.',
                'occurred_at' => '2026-10-20',
            ]), fn ($toast) => $toast['type'] === 'success');
        }
    }

    #[Test]
    public function two_students_reaching_three_in_the_same_submission_each_get_a_warning(): void
    {
        [$classUlid] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();
        $this->actingAs($teacher);

        $enrollmentIds = app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($classUlid) {
            $class = SchoolClass::where('ulid', $classUlid)->firstOrFail();

            return $class->enrollments()->orderBy('class_number')->limit(2)->pluck('id')->all();
        });

        // Two prior records each, so this submission is the 3rd for both.
        foreach ($enrollmentIds as $enrollmentId) {
            for ($i = 0; $i < 2; $i++) {
                $this->postRecord($classUlid, [
                    'kind' => 'lateness',
                    'description' => 'Chegou atrasado.',
                    'occurred_at' => '2026-10-20',
                    'enrollment_ids' => [(int) $enrollmentId],
                ])->assertRedirect();
            }
        }

        $response = $this->postRecord($classUlid, [
            'kind' => 'lateness',
            'description' => 'Chegou atrasado.',
            'occurred_at' => '2026-10-21',
            'enrollment_ids' => $enrollmentIds,
        ]);

        $this->assertToast($response, function ($toast) {
            return $toast['type'] === 'warning'
                && substr_count($toast['message'], 'acumulou 3 atrasos') === 2;
        });
    }

    #[Test]
    public function editing_a_record_into_lateness_can_itself_trigger_the_warning(): void
    {
        [$classUlid, $enrollmentId] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();
        $this->actingAs($teacher);

        for ($i = 0; $i < 2; $i++) {
            $this->postRecord($classUlid, [
                'kind' => 'lateness',
                'description' => 'Chegou atrasado.',
                'occurred_at' => '2026-10-20',
                'enrollment_ids' => [(int) $enrollmentId],
            ])->assertRedirect();
        }

        $this->postRecord($classUlid, [
            'kind' => 'note',
            'description' => 'Observação qualquer, ainda não é atraso.',
            'occurred_at' => '2026-10-22',
            'enrollment_ids' => [(int) $enrollmentId],
        ])->assertRedirect();

        $ulid = app(CurrentOrganization::class)->runFor(
            $teacher->personalOrganization(),
            fn () => EvidenceRecord::where('kind', 'note')->firstOrFail()->ulid,
        );

        $response = $this->putRecord($ulid, [
            'kind' => 'lateness',
            'description' => 'Afinal também chegou atrasado.',
            'occurred_at' => '2026-10-22',
            'enrollment_id' => (int) $enrollmentId,
        ]);

        $this->assertToast($response, fn ($toast) => $toast['type'] === 'warning'
            && str_contains($toast['message'], 'acumulou 3 atrasos'));
    }

    #[Test]
    public function updating_a_record_persists_the_new_values(): void
    {
        [$classUlid, $enrollmentId] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();
        $this->actingAs($teacher);

        $this->postRecord($classUlid, [
            'kind' => 'note',
            'description' => 'Observação inicial.',
            'occurred_at' => '2026-10-20',
            'enrollment_ids' => [(int) $enrollmentId],
        ])->assertRedirect();

        $ulid = app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), fn () => EvidenceRecord::firstOrFail()->ulid);

        $this->putRecord($ulid, [
            'kind' => 'homework',
            'homework_status' => 'done',
            'occurred_at' => '2026-10-21',
            'enrollment_id' => (int) $enrollmentId,
        ])->assertRedirect();

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($ulid): void {
            $record = EvidenceRecord::where('ulid', $ulid)->firstOrFail();
            $this->assertSame('homework', $record->kind->value);
            $this->assertSame('done', $record->homework_status->value);
            $this->assertSame(1, EvidenceRecord::count());
        });
    }

    #[Test]
    public function a_teacher_from_another_organization_cannot_edit_the_record(): void
    {
        [$classUlid, $enrollmentId] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();
        $this->actingAs($teacher);

        $this->postRecord($classUlid, [
            'kind' => 'note',
            'description' => 'Observação.',
            'occurred_at' => '2026-10-20',
            'enrollment_ids' => [(int) $enrollmentId],
        ])->assertRedirect();

        $ulid = app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), fn () => EvidenceRecord::firstOrFail()->ulid);

        $stranger = User::factory()->create();
        $this->actingAs($stranger);
        $this->putRecord($ulid, [
            'kind' => 'note',
            'description' => 'Tentativa de outra organização.',
            'occurred_at' => '2026-10-21',
        ])->assertNotFound();
    }

    #[Test]
    public function the_listing_can_be_filtered_by_kind_student_and_period(): void
    {
        [$classUlid, $enrollmentId] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();
        $this->actingAs($teacher);

        $this->postRecord($classUlid, [
            'kind' => 'homework', 'homework_status' => 'done',
            'occurred_at' => '2026-10-20', 'enrollment_ids' => [(int) $enrollmentId],
        ])->assertRedirect();
        $this->postRecord($classUlid, [
            'kind' => 'note', 'description' => 'Nota de turma.', 'occurred_at' => '2026-11-01',
        ])->assertRedirect();
        $this->postRecord($classUlid, [
            'kind' => 'note', 'description' => 'Nota do 2.º semestre.', 'occurred_at' => '2027-03-01',
        ])->assertRedirect();

        $periodId = app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($classUlid) {
            $class = SchoolClass::where('ulid', $classUlid)->firstOrFail();

            return AcademicPeriod::where('academic_year_id', $class->academic_year_id)->where('sequence', 1)->firstOrFail()->id;
        });

        $this->get("/classes/{$classUlid}/records?kind=homework")
            ->assertInertia(fn ($page) => $page->component('records/Show')->has('records', 1));

        $this->get("/classes/{$classUlid}/records?enrollment_id={$enrollmentId}")
            ->assertInertia(fn ($page) => $page->component('records/Show')->has('records', 1));

        $this->get("/classes/{$classUlid}/records?period_id={$periodId}")
            ->assertInertia(fn ($page) => $page->component('records/Show')->has('records', 2));
    }

    #[Test]
    public function the_homework_grid_loads_only_active_enrollments_with_no_default_state(): void
    {
        [$classUlid] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();

        $inactiveId = app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($classUlid): int {
            $class = SchoolClass::where('ulid', $classUlid)->firstOrFail();
            $enrollment = $class->enrollments()->orderBy('class_number')->firstOrFail();
            $enrollment->update(['status' => EnrollmentStatus::TransferredOut]);

            return $enrollment->id;
        });

        $response = $this->actingAs($teacher)
            ->getJson("/classes/{$classUlid}/records/homework-batch?occurred_at=2026-10-20")
            ->assertOk();

        $this->assertNotContains($inactiveId, $response->json('enrollments.*.id'));
        $this->assertNotEmpty($response->json('enrollments'));
        $this->assertSame(
            array_fill(0, count($response->json('enrollments')), null),
            $response->json('enrollments.*.homework_status'),
        );
    }

    #[Test]
    public function blank_homework_rows_are_not_inferred_as_not_done(): void
    {
        [$classUlid, $enrollmentId] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();

        $this->actingAs($teacher);
        $this->putHomeworkBatch($classUlid, '2026-10-20', [[
            'enrollment_id' => (int) $enrollmentId,
            'homework_status' => null,
            'description' => null,
        ]])->assertRedirect();

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function (): void {
            $this->assertSame(0, EvidenceRecord::count());
        });
    }

    #[Test]
    public function a_homework_batch_persists_bulk_values_individual_overrides_and_descriptions(): void
    {
        [$classUlid] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();
        $enrollmentIds = app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($classUlid): array {
            return SchoolClass::where('ulid', $classUlid)->firstOrFail()
                ->activeEnrollments()->orderBy('class_number')->limit(3)->pluck('id')->all();
        });

        $rows = collect($enrollmentIds)->map(fn (int $enrollmentId): array => [
            'enrollment_id' => $enrollmentId,
            'homework_status' => 'done',
            'description' => 'Exercícios 1 a 5 da página 42',
        ])->all();
        $rows[1]['homework_status'] = 'partially_done';
        $rows[1]['description'] = 'Completou apenas até ao exercício 3.';

        $this->actingAs($teacher);
        $this->putHomeworkBatch($classUlid, '2026-10-20', $rows)->assertRedirect();

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($enrollmentIds): void {
            $this->assertSame(3, EvidenceRecord::count());
            $this->assertSame('done', EvidenceRecord::where('enrollment_id', $enrollmentIds[0])->firstOrFail()->homework_status->value);
            $override = EvidenceRecord::where('enrollment_id', $enrollmentIds[1])->firstOrFail();
            $this->assertSame('partially_done', $override->homework_status->value);
            $this->assertSame('Completou apenas até ao exercício 3.', $override->description);
            $this->assertSame('Exercícios 1 a 5 da página 42', EvidenceRecord::where('enrollment_id', $enrollmentIds[2])->firstOrFail()->description);
        });
    }

    #[Test]
    public function saving_the_same_homework_batch_updates_reopens_and_clears_without_duplicates(): void
    {
        [$classUlid, $enrollmentId] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();
        $this->actingAs($teacher);

        $this->putHomeworkBatch($classUlid, '2026-10-20', [[
            'enrollment_id' => (int) $enrollmentId,
            'homework_status' => 'done',
            'description' => 'Descrição inicial.',
        ]])->assertRedirect();
        $this->putHomeworkBatch($classUlid, '2026-10-20', [[
            'enrollment_id' => (int) $enrollmentId,
            'homework_status' => 'not_done',
            'description' => 'Não entregou.',
        ]])->assertRedirect();

        $this->getJson("/classes/{$classUlid}/records/homework-batch?occurred_at=2026-10-20")
            ->assertOk()
            ->assertJsonPath('enrollments.0.homework_status', 'not_done')
            ->assertJsonPath('enrollments.0.description', 'Não entregou.');

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function (): void {
            $this->assertSame(1, EvidenceRecord::count());
        });

        $this->putHomeworkBatch($classUlid, '2026-10-20', [[
            'enrollment_id' => (int) $enrollmentId,
            'homework_status' => null,
            'description' => '',
        ]])->assertRedirect();

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function (): void {
            $this->assertSame(0, EvidenceRecord::count());
            $this->assertSame(1, EvidenceRecord::withTrashed()->count());
        });
    }

    #[Test]
    public function a_homework_batch_failure_after_the_first_insert_rolls_back_every_row(): void
    {
        [$classUlid] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();

        $enrollmentIds = app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($classUlid): array {
            return SchoolClass::where('ulid', $classUlid)->firstOrFail()
                ->activeEnrollments()->orderBy('class_number')->limit(2)->pluck('id')->all();
        });

        // The second INSERT fails after the first has already run. The
        // transaction must roll that first row back as well.
        //
        // NO DDL HERE, AND THAT IS THE POINT. The trigger this used to create
        // was SQLite syntax, so it never ran on the engine production uses —
        // and its MySQL twin would be worse, because CREATE TRIGGER commits
        // implicitly there, taking the test's own transaction with it and
        // leaking every seeded row into the next test. A model hook proves the
        // same thing on both engines and touches no schema.
        EvidenceRecord::creating(function (EvidenceRecord $record) use ($enrollmentIds): void {
            if ((int) $record->enrollment_id === $enrollmentIds[1]) {
                throw new RuntimeException('forced batch failure');
            }
        });

        $this->actingAs($teacher);
        $this->putHomeworkBatch($classUlid, '2026-10-20', [
            ['enrollment_id' => $enrollmentIds[0], 'homework_status' => 'done', 'description' => 'Primeiro.'],
            ['enrollment_id' => $enrollmentIds[1], 'homework_status' => 'done', 'description' => 'Segundo.'],
        ])->assertServerError();

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function (): void {
            $this->assertSame(0, EvidenceRecord::count());
        });
    }

    #[Test]
    public function homework_batch_routes_enforce_tenant_and_class_teacher_authorization(): void
    {
        [$classUlid] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();

        $this->actingAs(User::factory()->create())
            ->getJson("/classes/{$classUlid}/records/homework-batch?occurred_at=2026-10-20")
            ->assertNotFound();

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($classUlid): void {
            SchoolClass::where('ulid', $classUlid)->firstOrFail()->teachers()->detach();
        });

        $this->actingAs($teacher)
            ->getJson("/classes/{$classUlid}/records/homework-batch?occurred_at=2026-10-20")
            ->assertForbidden();
    }
}
