<?php

namespace Tests\Feature\Lessons;

use App\Actions\Lessons\RecordLessonOutcome;
use App\Models\AcademicYear;
use App\Models\AuditEvent;
use App\Models\Lesson;
use App\Models\LessonAttendance;
use App\Models\LessonOutcome;
use App\Models\LessonSequence;
use App\Models\LessonStatus;
use App\Models\LessonSummary;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\SchoolClass;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Support\Entitlements\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

/**
 * `PATCH lessons/{lesson}/summary/content` — gravar SÓ o texto do sumário —, e
 * o bloqueio otimista (`lessons.summary_version`) que protege o texto de ser
 * escrito por cima de uma versão mais nova aberta noutra janela.
 */
class LessonSummaryContentTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLessonFixtures();
    }

    // ---------------------------------------------------------------- só o texto

    #[Test]
    public function the_patch_changes_only_the_content_and_leaves_the_other_fields_byte_for_byte(): void
    {
        $lesson = $this->makeLesson(['status' => LessonStatus::Prepared, 'lesson_number' => 7]);
        $this->seedSummary($lesson, [
            'content' => 'Texto antigo.',
            'private_notes' => "  Nota com espaços e\nquebra de linha.  ",
            'resources' => 'https://example.test/recurso?a=1&b=2',
            'homework' => "TPC:\n- ex. 1\n- ex. 2",
        ]);
        $before = $this->summaryRow($lesson);

        $this->patchContent($lesson, ['content' => 'Texto novo.'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $after = $this->summaryRow($lesson);
        $this->assertSame('Texto novo.', $after['content']);
        $this->assertSame($before['private_notes'], $after['private_notes']);
        $this->assertSame($before['resources'], $after['resources']);
        $this->assertSame($before['homework'], $after['homework']);
    }

    #[Test]
    public function the_patch_keeps_null_fields_null(): void
    {
        $lesson = $this->makeLesson(['status' => LessonStatus::Prepared]);
        $this->seedSummary($lesson, ['content' => 'Texto antigo.']);

        $this->patchContent($lesson, ['content' => 'Texto novo.'])->assertSessionHasNoErrors();

        $row = $this->summaryRow($lesson);
        $this->assertSame('Texto novo.', $row['content']);
        $this->assertNull($row['private_notes']);
        $this->assertNull($row['resources']);
        $this->assertNull($row['homework']);
    }

    #[Test]
    public function the_patch_creates_the_summary_with_only_the_content_when_there_is_none(): void
    {
        $lesson = $this->makeLesson();

        $this->patchContent($lesson, ['content' => 'Primeiro texto.'])->assertSessionHasNoErrors();

        $row = $this->summaryRow($lesson);
        $this->assertSame('Primeiro texto.', $row['content']);
        $this->assertNull($row['private_notes']);
        $this->assertNull($row['resources']);
        $this->assertNull($row['homework']);
    }

    #[Test]
    public function extra_fields_in_the_patch_payload_are_ignored(): void
    {
        $student = $this->enroll('Aluno Um');
        $lesson = $this->makeLesson(['status' => LessonStatus::Prepared]);
        $this->seedSummary($lesson, ['content' => 'Texto antigo.', 'private_notes' => 'Nota.', 'resources' => 'Recurso.', 'homework' => 'TPC.']);
        $before = $this->summaryRow($lesson);

        $this->patchContent($lesson, [
            'content' => 'Texto novo.',
            'private_notes' => 'TENTATIVA',
            'resources' => 'TENTATIVA',
            'homework' => 'TENTATIVA',
            'absent' => [$student->student->ulid],
        ])->assertSessionHasNoErrors();

        $after = $this->summaryRow($lesson);
        $this->assertSame('Texto novo.', $after['content']);
        $this->assertSame($before['private_notes'], $after['private_notes']);
        $this->assertSame($before['resources'], $after['resources']);
        $this->assertSame($before['homework'], $after['homework']);
        $this->assertSame(0, LessonAttendance::withoutGlobalScopes()->where('lesson_id', $lesson->id)->count());
    }

    #[Test]
    public function the_patch_leaves_the_attendance_draft_and_the_lesson_fields_untouched(): void
    {
        $enrollment = $this->enroll('Aluno Faltoso');
        $group = $this->makeGroup('T1');
        $lesson = $this->makeLesson([
            'class_group_id' => $group->id,
            'lesson_number' => 4,
            'status' => LessonStatus::Prepared,
        ]);
        $this->inTenant($this->organization, fn () => LessonAttendance::create([
            'lesson_id' => $lesson->id,
            'enrollment_id' => $enrollment->id,
            'student_id' => $enrollment->student_id,
            'status' => 'absent',
        ]));
        $this->seedSummary($lesson, ['content' => 'Texto antigo.']);
        $lessonBefore = Lesson::withoutGlobalScopes()->find($lesson->id)->only(['starts_at', 'ends_at', 'lesson_number', 'class_group_id', 'status', 'class_id']);
        $attendanceBefore = LessonAttendance::withoutGlobalScopes()->where('lesson_id', $lesson->id)->get()->toArray();

        $this->patchContent($lesson, ['content' => 'Texto novo.'])->assertSessionHasNoErrors();

        $lessonAfter = Lesson::withoutGlobalScopes()->find($lesson->id)->only(['starts_at', 'ends_at', 'lesson_number', 'class_group_id', 'status', 'class_id']);
        $this->assertEquals($lessonBefore, $lessonAfter);
        $this->assertSame($attendanceBefore, LessonAttendance::withoutGlobalScopes()->where('lesson_id', $lesson->id)->get()->toArray());
    }

    // ---------------------------------------------------------- estados e auditoria

    #[Test]
    public function the_patch_moves_preparation_to_prepared_and_audits_it(): void
    {
        $lesson = $this->makeLesson();

        $this->patchContent($lesson, ['content' => 'Texto.'])->assertSessionHasNoErrors();

        $this->assertSame(LessonStatus::Prepared, Lesson::withoutGlobalScopes()->find($lesson->id)->status);
        $this->assertDatabaseHas('audit_events', ['event' => 'lesson.prepared', 'subject_id' => $lesson->id]);
    }

    #[Test]
    public function the_patch_on_an_already_prepared_lesson_audits_summary_saved(): void
    {
        $lesson = $this->makeLesson(['status' => LessonStatus::Prepared]);
        $this->seedSummary($lesson, ['content' => 'Antigo.']);

        $this->patchContent($lesson, ['content' => 'Novo.'])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('audit_events', ['event' => 'lesson.summary_saved', 'subject_id' => $lesson->id]);
        $this->assertDatabaseMissing('audit_events', ['event' => 'lesson.prepared', 'subject_id' => $lesson->id]);
    }

    #[Test]
    public function the_patch_on_a_taught_lesson_marks_it_reviewed_and_keeps_the_status(): void
    {
        $lesson = $this->makeLesson(['status' => LessonStatus::Taught]);
        $this->seedSummary($lesson, ['content' => 'Antigo.']);
        Carbon::setTestNow('2026-10-08 12:30:00');

        $this->patchContent($lesson, ['content' => 'Revisto.'])->assertSessionHasNoErrors();

        $summary = LessonSummary::withoutGlobalScopes()->where('lesson_id', $lesson->id)->sole();
        $this->assertSame('Revisto.', $summary->content);
        $this->assertSame('2026-10-08 12:30:00', $summary->reviewed_at?->format('Y-m-d H:i:s'));
        $this->assertSame($this->teacher->id, $summary->reviewed_by);
        $this->assertSame(LessonStatus::Taught, Lesson::withoutGlobalScopes()->find($lesson->id)->status);
        $this->assertSame(1, AuditEvent::withoutGlobalScopes()->where('event', 'lesson.summary_reviewed')->count());
    }

    // ------------------------------------------------------------- permissões

    #[Test]
    public function a_teacher_of_another_class_is_forbidden(): void
    {
        $lesson = $this->makeLesson();
        $other = User::factory()->create();
        $this->organization->members()->attach($other, ['joined_at' => now()]);

        $this->actingAs($other)
            ->withSession(['organization_id' => $this->organization->id])
            ->patch("/lessons/{$lesson->ulid}/summary/content", ['content' => 'Intruso.', 'summary_version' => 0])
            ->assertForbidden();

        $this->assertSame(0, LessonSummary::withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_lesson_of_another_organization_is_not_found(): void
    {
        $outsider = User::factory()->create();
        $foreignOrganization = $outsider->personalOrganization();
        $this->subscribeToPro($foreignOrganization);
        $foreignLesson = $this->foreignLesson($foreignOrganization, $outsider);

        $this->patchContent($foreignLesson, ['content' => 'Tenant errado.'])->assertNotFound();

        $this->assertSame(0, LessonSummary::withoutGlobalScopes()->count());
    }

    #[Test]
    public function impersonation_refuses_the_patch(): void
    {
        $lesson = $this->makeLesson();

        $this->actingAs($this->teacher)
            ->withSession(['organization_id' => $this->organization->id, 'impersonator_id' => 999])
            ->patch("/lessons/{$lesson->ulid}/summary/content", ['content' => 'Bloqueado.', 'summary_version' => 0])
            ->assertForbidden();

        $this->assertSame(0, LessonSummary::withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_read_only_module_refuses_the_patch(): void
    {
        $lesson = $this->makeLesson();
        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $this->organization->id)
            ->update(['status' => SubscriptionStatus::Suspended]);
        app(Entitlements::class)->flush();

        $this->patchContent($lesson, ['content' => 'Só leitura.'])->assertForbidden();

        $this->assertSame(0, LessonSummary::withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_guest_is_redirected_to_login(): void
    {
        $lesson = $this->makeLesson();

        $this->patch("/lessons/{$lesson->ulid}/summary/content", ['content' => 'Anónimo.', 'summary_version' => 0])
            ->assertRedirect('/login');
    }

    // ------------------------------------------------------------- validação

    #[Test]
    public function invalid_payloads_write_nothing(): void
    {
        $lesson = $this->makeLesson();
        $version = $this->summaryVersion($lesson);

        $this->patchContent($lesson, ['content' => '   '])->assertSessionHasErrors('content');
        $this->patchContent($lesson, ['content' => str_repeat('á', 16001)])->assertSessionHasErrors('content');
        $this->asTeacher()
            ->patch("/lessons/{$lesson->ulid}/summary/content", ['content' => 'Sem versão.'])
            ->assertSessionHasErrors('summary_version');
        $this->patchContent($lesson, ['content' => 'Versão negativa.'], version: -1)->assertSessionHasErrors('summary_version');

        $this->assertSame(0, LessonSummary::withoutGlobalScopes()->count());
        $this->assertSame($version, $this->summaryVersion($lesson));
        $this->assertSame(LessonStatus::Preparation, Lesson::withoutGlobalScopes()->find($lesson->id)->status);
    }

    // --------------------------------------------------------- concorrência

    #[Test]
    public function a_stale_version_is_refused_and_nothing_changes(): void
    {
        $lesson = $this->makeLesson(['status' => LessonStatus::Prepared]);
        $this->seedSummary($lesson, [
            'content' => 'Texto da outra janela.',
            'private_notes' => 'Nota.',
            'resources' => 'Recurso.',
            'homework' => 'TPC.',
        ]);
        $staleVersion = $this->summaryVersion($lesson) - 1;
        $before = $this->snapshot($lesson);

        $this->patchContent($lesson, ['content' => 'O meu texto desatualizado.'], version: $staleVersion)
            ->assertSessionHasErrors('summary_version');

        $this->assertSame($before, $this->snapshot($lesson));
        $this->assertSame(0, AuditEvent::withoutGlobalScopes()->whereIn('event', ['lesson.summary_saved', 'lesson.summary_reviewed'])->count());
    }

    #[Test]
    public function a_stale_version_does_not_even_write_the_attendance_draft_of_the_put(): void
    {
        $enrollment = $this->enroll('Aluno');
        $lesson = $this->makeLesson(['status' => LessonStatus::Prepared]);
        $this->seedSummary($lesson, ['content' => 'Atual.']);
        $stale = $this->summaryVersion($lesson) - 1;

        $this->asTeacher()->put("/lessons/{$lesson->ulid}/summary", [
            'content' => 'Desatualizado.',
            'absent' => [$enrollment->student->ulid],
            'summary_version' => $stale,
        ])->assertSessionHasErrors('summary_version');

        $this->assertSame('Atual.', $this->summaryRow($lesson)['content']);
        $this->assertSame(0, LessonAttendance::withoutGlobalScopes()->where('lesson_id', $lesson->id)->count());
    }

    #[Test]
    public function the_stale_message_is_the_agreed_one(): void
    {
        $lesson = $this->makeLesson();

        $response = $this->patchContent($lesson, ['content' => 'X.'], version: 99);

        $response->assertSessionHasErrors([
            'summary_version' => 'O sumário desta aula foi alterado noutra janela depois de o abrires. O teu texto não foi gravado.',
        ]);
    }

    #[Test]
    public function a_successful_patch_bumps_the_version_by_exactly_one(): void
    {
        $lesson = $this->makeLesson();
        $version = $this->summaryVersion($lesson);

        $this->patchContent($lesson, ['content' => 'Um.'])->assertSessionHasNoErrors();
        $this->assertSame($version + 1, $this->summaryVersion($lesson));

        $this->patchContent($lesson, ['content' => 'Dois.'])->assertSessionHasNoErrors();
        $this->assertSame($version + 2, $this->summaryVersion($lesson));
    }

    #[Test]
    public function two_patches_with_the_same_old_version_the_second_is_refused(): void
    {
        $lesson = $this->makeLesson();
        $opened = $this->summaryVersion($lesson);

        $this->patchContent($lesson, ['content' => 'Primeira janela.'], version: $opened)->assertSessionHasNoErrors();
        $this->patchContent($lesson, ['content' => 'Segunda janela.'], version: $opened)->assertSessionHasErrors('summary_version');

        $this->assertSame('Primeira janela.', $this->summaryRow($lesson)['content']);
    }

    #[Test]
    public function the_put_with_a_stale_version_is_refused(): void
    {
        $lesson = $this->makeLesson(['status' => LessonStatus::Prepared]);
        $this->seedSummary($lesson, ['content' => 'Atual.', 'homework' => 'TPC atual.']);
        $before = $this->snapshot($lesson);

        $this->asTeacher()->put("/lessons/{$lesson->ulid}/summary", [
            'content' => 'Desatualizado.',
            'homework' => 'Desatualizado.',
            'summary_version' => $this->summaryVersion($lesson) - 1,
        ])->assertSessionHasErrors('summary_version');

        $this->assertSame($before, $this->snapshot($lesson));
    }

    #[Test]
    public function the_put_without_a_version_is_a_validation_error(): void
    {
        $lesson = $this->makeLesson();

        $this->asTeacher()->put("/lessons/{$lesson->ulid}/summary", ['content' => 'Sem versão.'])
            ->assertSessionHasErrors('summary_version');

        $this->assertSame(0, LessonSummary::withoutGlobalScopes()->count());
    }

    #[Test]
    public function the_delete_with_a_stale_version_is_refused_and_the_summary_stays(): void
    {
        $lesson = $this->makeLesson(['status' => LessonStatus::Prepared]);
        $this->seedSummary($lesson, ['content' => 'Atual.']);
        $before = $this->snapshot($lesson);

        $this->asTeacher()
            ->delete("/lessons/{$lesson->ulid}/summary", ['summary_version' => $this->summaryVersion($lesson) - 1])
            ->assertSessionHasErrors('summary_version');

        $this->assertSame($before, $this->snapshot($lesson));
    }

    #[Test]
    public function the_delete_without_a_version_is_a_validation_error(): void
    {
        $lesson = $this->makeLesson(['status' => LessonStatus::Prepared]);
        $this->seedSummary($lesson, ['content' => 'Atual.']);

        $this->asTeacher()->delete("/lessons/{$lesson->ulid}/summary")->assertSessionHasErrors('summary_version');

        $this->assertSame('Atual.', $this->summaryRow($lesson)['content']);
    }

    #[Test]
    public function the_lesson_page_carries_the_current_version(): void
    {
        $lesson = $this->makeLesson();
        $this->seedSummary($lesson, ['content' => 'Texto.']);

        $this->asTeacher()->get("/lessons/{$lesson->ulid}")
            ->assertInertia(fn ($page) => $page->where('lesson.summary_version', $this->summaryVersion($lesson)));
    }

    // ------------------------------------ todos os caminhos de escrita sobem a versão

    #[Test]
    public function a_plain_summary_save_and_delete_each_bump_the_version(): void
    {
        $lesson = $this->makeLesson();
        $version = $this->summaryVersion($lesson);

        $summary = $this->inTenant($this->organization, fn () => LessonSummary::create(['lesson_id' => $lesson->id, 'content' => 'A.']));
        $this->assertSame($version + 1, $this->summaryVersion($lesson));

        $this->inTenant($this->organization, function () use ($summary): void {
            $summary->content = 'B.';
            $summary->save();
        });
        $this->assertSame($version + 2, $this->summaryVersion($lesson));

        $this->inTenant($this->organization, fn () => $summary->delete());
        $this->assertSame($version + 3, $this->summaryVersion($lesson));
    }

    #[Test]
    public function bumping_the_version_does_not_touch_the_lessons_updated_at(): void
    {
        $lesson = $this->makeLesson();
        Lesson::withoutGlobalScopes()->whereKey($lesson->id)->toBase()->update(['updated_at' => '2026-01-01 00:00:00']);

        $this->inTenant($this->organization, fn () => LessonSummary::create(['lesson_id' => $lesson->id, 'content' => 'A.']));

        $this->assertSame(
            '2026-01-01 00:00:00',
            Lesson::withoutGlobalScopes()->find($lesson->id)->updated_at?->format('Y-m-d H:i:s'),
        );
    }

    #[Test]
    public function clearing_the_summary_bumps_the_version(): void
    {
        $lesson = $this->makeLesson(['status' => LessonStatus::Prepared]);
        $this->seedSummary($lesson, ['content' => 'Texto.', 'homework' => 'TPC.']);
        $opened = $this->summaryVersion($lesson);

        $this->asTeacher()->delete("/lessons/{$lesson->ulid}/summary", ['summary_version' => $opened])
            ->assertSessionHasNoErrors();

        $this->assertSame($opened + 1, $this->summaryVersion($lesson));
        // Um cartão aberto antes da limpeza já não grava.
        $this->patchContent($lesson, ['content' => 'Tarde demais.'], version: $opened)->assertSessionHasErrors('summary_version');
    }

    #[Test]
    public function recording_a_teacher_absence_shifts_the_planning_and_bumps_both_lessons(): void
    {
        $slot = $this->makeSlot();
        $first = $this->makeLesson(['recurring_lesson_slot_id' => $slot->id, 'starts_at' => '2026-10-08 09:30:00']);
        $second = $this->makeLesson(['recurring_lesson_slot_id' => $slot->id, 'starts_at' => '2026-10-15 09:30:00']);
        $this->seedSummary($first, ['content' => 'Planeado para a primeira.']);
        $firstOpened = $this->summaryVersion($first);
        $secondOpened = $this->summaryVersion($second);

        $this->asTeacher()
            ->post("/lessons/{$first->ulid}/outcome", ['outcome' => 'teacher_absent', 'reason' => 'training'])
            ->assertSessionHasNoErrors();

        $this->assertGreaterThan($firstOpened, $this->summaryVersion($first));
        $this->assertGreaterThan($secondOpened, $this->summaryVersion($second));
        $this->assertSame('Planeado para a primeira.', $this->summaryRow($second)['content']);
        $this->patchContent($second, ['content' => 'Cartão antigo.'], version: $secondOpened)->assertSessionHasErrors('summary_version');
    }

    #[Test]
    public function recording_an_outcome_removes_even_a_blank_summary_row_and_bumps_the_version(): void
    {
        $slot = $this->makeSlot();
        $lesson = $this->makeLesson(['recurring_lesson_slot_id' => $slot->id]);
        // Uma linha totalmente em branco: ShiftLessonPlanning não lhe toca, a
        // remoção é a de RecordLessonOutcome.
        $this->seedSummary($lesson, ['content' => '']);
        $opened = $this->summaryVersion($lesson);

        $this->inTenant($this->organization, fn () => app(RecordLessonOutcome::class)
            ->execute($lesson, LessonOutcome::ClassExternalActivity, $this->teacher));

        $this->assertSame(0, LessonSummary::withoutGlobalScopes()->where('lesson_id', $lesson->id)->count());
        $this->assertSame($opened + 1, $this->summaryVersion($lesson));
    }

    #[Test]
    public function applying_a_sequence_bumps_the_version_of_the_lessons_it_writes(): void
    {
        Carbon::setTestNow('2026-10-01 08:00:00');
        $lesson = $this->makeLesson(['starts_at' => '2026-10-06 09:00:00']);
        $opened = $this->summaryVersion($lesson);
        $sequence = $this->inTenant($this->organization, function (): LessonSequence {
            $sequence = LessonSequence::create([
                'user_id' => $this->teacher->id,
                'subject_id' => $this->schoolClass->subject_id,
                'academic_year_id' => $this->schoolClass->academic_year_id,
                'grade_level' => null,
                'name' => 'Sequência',
            ]);
            $sequence->items()->create(['position' => 1, 'summary' => 'Do plano.']);

            return $sequence;
        });

        $this->asTeacher()->post("/lessons/sequences/{$sequence->ulid}/apply", [
            'class_id' => $this->schoolClass->id,
            'summary' => true,
            'resources' => false,
            'homework' => false,
            'private_notes' => false,
        ])->assertSessionHasNoErrors();

        $this->assertSame('Do plano.', $this->summaryRow($lesson)['content']);
        $this->assertSame($opened + 1, $this->summaryVersion($lesson));
    }

    // ------------------------------------------------------------------ apoio

    /**
     * @param  array<string, mixed>  $payload
     */
    private function patchContent(Lesson $lesson, array $payload, ?int $version = null): TestResponse
    {
        return $this->asTeacher()->patch(
            "/lessons/{$lesson->ulid}/summary/content",
            $payload + ['summary_version' => $version ?? $this->summaryVersion($lesson)],
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function seedSummary(Lesson $lesson, array $attributes): void
    {
        $this->inTenant($this->organization, fn () => LessonSummary::create(['lesson_id' => $lesson->id] + $attributes));
    }

    /**
     * @return array<string, mixed>
     */
    private function summaryRow(Lesson $lesson): array
    {
        return LessonSummary::withoutGlobalScopes()->where('lesson_id', $lesson->id)->firstOrFail()->only(['content', 'private_notes', 'resources', 'homework']);
    }

    /**
     * O que um pedido recusado NÃO pode ter mexido.
     *
     * @return array<string, mixed>
     */
    private function snapshot(Lesson $lesson): array
    {
        $summary = LessonSummary::withoutGlobalScopes()->where('lesson_id', $lesson->id)->first();
        $fresh = Lesson::withoutGlobalScopes()->find($lesson->id);

        return [
            'summary' => $summary?->only(['content', 'private_notes', 'resources', 'homework', 'reviewed_at']),
            'version' => $fresh->summary_version,
            'status' => $fresh->status->value,
        ];
    }

    private function foreignLesson(Organization $organization, User $teacher): Lesson
    {
        return $this->inTenant($organization, function () use ($organization, $teacher): Lesson {
            $class = SchoolClass::factory()->recycle($organization)->create([
                'academic_year_id' => AcademicYear::factory()->recycle($organization)->create()->id,
            ]);
            $class->teachers()->attach($teacher, ['role' => 'owner']);

            return Lesson::create([
                'class_id' => $class->id,
                'starts_at' => '2026-10-08 09:30:00',
                'ends_at' => '2026-10-08 10:20:00',
                'status' => LessonStatus::Preparation,
                'created_by' => $teacher->id,
            ]);
        });
    }
}
