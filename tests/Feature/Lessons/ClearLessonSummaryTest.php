<?php

namespace Tests\Feature\Lessons;

use App\Models\LessonStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

/**
 * §43 — corrigir e limpar um sumário ainda não lecionado, sem apagar a aula e
 * sem levar atrás as notas, os recursos e o TPC.
 */
class ClearLessonSummaryTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLessonFixtures();
    }

    /** §5: editar é gravar por cima da MESMA aula, sem criar nada de novo. */
    #[Test]
    public function editing_a_not_yet_taught_summary_updates_the_same_row(): void
    {
        $lesson = $this->makeLesson();

        $this->asTeacher()
            ->put("/lessons/{$lesson->ulid}/summary", ['content' => 'Primeira versão.'])
            ->assertRedirect();

        $this->asTeacher()
            ->put("/lessons/{$lesson->ulid}/summary", ['content' => 'Versão corrigida.'])
            ->assertRedirect();

        $this->inTenant($this->organization, function () use ($lesson): void {
            $this->assertSame(1, $lesson->refresh()->summary()->count());
            $this->assertSame('Versão corrigida.', $lesson->summary->content);
        });

        $this->assertDatabaseCount('lessons', 1);
    }

    #[Test]
    public function clearing_removes_the_text_and_keeps_the_lesson(): void
    {
        $slot = $this->makeSlot();
        $lesson = $this->makeLesson([
            'recurring_lesson_slot_id' => $slot->id,
            'status' => LessonStatus::Prepared,
        ]);
        $this->inTenant(
            $this->organization,
            fn () => $lesson->summary()->create(['content' => 'Sumário errado.']),
        );

        $this->asTeacher()->delete("/lessons/{$lesson->ulid}/summary")->assertRedirect();

        $this->assertDatabaseHas('lessons', ['id' => $lesson->id]);
        $this->assertDatabaseHas('recurring_lesson_slots', ['id' => $slot->id]);
        $this->inTenant($this->organization, function () use ($lesson): void {
            $this->assertNull($lesson->refresh()->summary);
            // §7: sem sumário, «Preparado» seria falso.
            $this->assertSame(LessonStatus::Preparation, $lesson->status);
        });
    }

    /**
     * §6: «Limpar sumário» apaga o SUMÁRIO. As notas do professor, os recursos
     * e o TPC são campos com significado próprio e ninguém pediu que fossem.
     */
    #[Test]
    public function clearing_preserves_notes_resources_and_homework(): void
    {
        $lesson = $this->makeLesson(['status' => LessonStatus::Prepared]);
        $this->inTenant($this->organization, fn () => $lesson->summary()->create([
            'content' => 'Sumário errado.',
            'private_notes' => 'Falar com o encarregado.',
            'resources' => 'Manual, p. 42',
            'homework' => 'Exercícios 1 a 5.',
        ]));

        $this->asTeacher()->delete("/lessons/{$lesson->ulid}/summary")->assertRedirect();

        $this->inTenant($this->organization, function () use ($lesson): void {
            $summary = $lesson->refresh()->summary;
            $this->assertNotNull($summary);
            $this->assertSame('', $summary->content);
            $this->assertSame('Falar com o encarregado.', $summary->private_notes);
            $this->assertSame('Manual, p. 42', $summary->resources);
            $this->assertSame('Exercícios 1 a 5.', $summary->homework);
            $this->assertSame(LessonStatus::Preparation, $lesson->status);
        });
    }

    /** §8: o sumário de uma aula dada não é esvaziado. */
    #[Test]
    public function a_taught_lesson_summary_cannot_be_cleared(): void
    {
        $lesson = $this->makeLesson(['status' => LessonStatus::Taught]);
        $this->inTenant(
            $this->organization,
            fn () => $lesson->summary()->create(['content' => 'O que aconteceu.']),
        );

        $this->asTeacher()
            ->from('/lessons')
            ->delete("/lessons/{$lesson->ulid}/summary")
            ->assertSessionHasErrors('summary');

        $this->inTenant($this->organization, fn () => $this->assertSame(
            'O que aconteceu.',
            $lesson->refresh()->summary->content,
        ));
    }

    /** Editar o sumário de uma aula dada continua a ser possível — não mudou. */
    #[Test]
    public function a_taught_lesson_summary_can_still_be_edited(): void
    {
        $lesson = $this->makeLesson(['status' => LessonStatus::Taught]);
        $this->inTenant(
            $this->organization,
            fn () => $lesson->summary()->create(['content' => 'Primeira redação.']),
        );

        $this->asTeacher()
            ->put("/lessons/{$lesson->ulid}/summary", ['content' => 'Redação revista.'])
            ->assertRedirect();

        $this->inTenant($this->organization, function () use ($lesson): void {
            $summary = $lesson->refresh()->summary;
            $this->assertSame('Redação revista.', $summary->content);
            $this->assertNotNull($summary->reviewed_at);
            $this->assertSame(LessonStatus::Taught, $lesson->status);
        });
    }

    #[Test]
    public function clearing_an_empty_summary_is_a_no_op(): void
    {
        $lesson = $this->makeLesson();

        $this->asTeacher()->delete("/lessons/{$lesson->ulid}/summary")->assertRedirect();

        $this->inTenant($this->organization, fn () => $this->assertSame(
            LessonStatus::Preparation,
            $lesson->refresh()->status,
        ));
    }

    #[Test]
    public function a_teacher_not_assigned_to_the_class_is_forbidden(): void
    {
        $lesson = $this->makeLesson(['status' => LessonStatus::Prepared]);
        $this->inTenant(
            $this->organization,
            fn () => $lesson->summary()->create(['content' => 'Sumário.']),
        );
        $other = User::factory()->create();
        $this->organization->members()->attach($other, ['joined_at' => now()]);

        $this->actingAs($other)
            ->withSession(['organization_id' => $this->organization->id])
            ->delete("/lessons/{$lesson->ulid}/summary")
            ->assertForbidden();

        $this->inTenant($this->organization, fn () => $this->assertSame(
            'Sumário.',
            $lesson->refresh()->summary->content,
        ));
    }

    #[Test]
    public function impersonation_blocks_clearing_a_summary(): void
    {
        $lesson = $this->makeLesson(['status' => LessonStatus::Prepared]);
        $this->inTenant(
            $this->organization,
            fn () => $lesson->summary()->create(['content' => 'Sumário.']),
        );

        $this->actingAs($this->teacher)
            ->withSession([
                'organization_id' => $this->organization->id,
                'impersonator_id' => 999,
            ])
            ->delete("/lessons/{$lesson->ulid}/summary")
            ->assertForbidden();

        $this->inTenant($this->organization, fn () => $this->assertSame(
            'Sumário.',
            $lesson->refresh()->summary->content,
        ));
    }
}
