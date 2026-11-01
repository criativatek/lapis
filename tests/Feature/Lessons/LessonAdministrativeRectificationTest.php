<?php

namespace Tests\Feature\Lessons;

use App\Actions\Lessons\RecordLessonOutcome;
use App\Actions\Organizations\CreateInstitutionalOrganization;
use App\Models\AcademicYear;
use App\Models\AuditEvent;
use App\Models\Lesson;
use App\Models\LessonNotHeldCause;
use App\Models\LessonOutcome;
use App\Models\LessonOutcomeRectification;
use App\Models\Organization;
use App\Models\SchoolClass;
use App\Models\TeacherAbsenceReason;
use App\Models\TeacherStrikeSituation;
use App\Models\User;
use App\Support\Lessons\LessonOutcomeDetails;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

/**
 * A correção ADMINISTRATIVA excecional: o titular do registo deixou a turma, a causa está errada, e quem pode
 * corrigi-la é a direção da instituição — a competência que o modelo atual já lhe reconhece para as retificações
 * (`reviewRectifications`) — sem passar a LER nada do que o titular reservou.
 */
class LessonAdministrativeRectificationTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase;

    private User $head;

    private User $remaining;

    private Lesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'Europe/Lisbon'));
        $this->bootLessonFixtures();
        config(['lapis.lesson_not_held_enabled' => true, 'lapis.strike_adhesion_recording' => true]);

        // A turma passa a viver numa instituição cuja direção NÃO leciona.
        $this->head = User::factory()->create();
        $institution = app(CreateInstitutionalOrganization::class)->create('Escola Teste', $this->head);
        $this->subscribeToPro($institution);
        $this->organization = $institution;
        $this->remaining = $this->member($institution);
        $this->teacher->organizations()->attach($institution, ['joined_at' => now()]);

        $this->schoolClass = $this->inTenant($institution, function (): SchoolClass {
            $class = SchoolClass::factory()->recycle($this->organization)->create([
                'academic_year_id' => AcademicYear::factory()->recycle($this->organization)->create(['starts_on' => '2026-09-01', 'ends_on' => '2027-06-30'])->id,
            ]);
            $class->teachers()->attach($this->teacher, ['role' => 'owner']);
            $class->teachers()->attach($this->remaining, ['role' => 'co_teacher']);

            return $class;
        });

        // O titular regista a aula, com uma especificação pessoal reservada.
        $this->lesson = $this->makeLesson(['starts_at' => '2026-10-13 09:30:00']);
        $this->inTenant($institution, fn () => app(RecordLessonOutcome::class)->execute(
            $this->lesson, LessonOutcome::NotHeld, $this->teacher, null, null, LessonNotHeldCause::Strike, TeacherStrikeSituation::Adhered,
        ));
    }

    private function member(Organization $organization): User
    {
        $user = User::factory()->create();
        $user->organizations()->attach($organization, ['joined_at' => now()]);

        return $user;
    }

    private function titularLeaves(): void
    {
        $this->inTenant($this->organization, fn () => $this->schoolClass->teachers()->detach($this->teacher));
    }

    /** @param  array<string, mixed>  $body */
    private function administrative(User $as, array $body, ?Lesson $lesson = null)
    {
        return $this->actingAs($as)->withSession(['organization_id' => $this->organization->id])
            ->patchJson('/lessons/'.($lesson ?? $this->lesson)->ulid.'/outcome/administrative', $body);
    }

    private function valid(): array
    {
        return ['cause' => 'weather_closure', 'rectification_note' => 'A causa registada estava errada'];
    }

    #[Test]
    public function the_head_corrects_only_the_cause_once_the_holder_left_and_the_history_keeps_the_previous_values(): void
    {
        $this->titularLeaves();
        $numbers = $this->inTenant($this->organization, fn () => Lesson::query()->orderBy('id')->pluck('lesson_number', 'id')->all());

        $this->administrative($this->head, $this->valid())->assertRedirect();

        $this->lesson->refresh();
        $this->assertSame(LessonNotHeldCause::WeatherClosure, $this->lesson->outcome_cause);
        $this->assertNull($this->lesson->outcome_teacher_situation, 'A direção não declara nada em nome de um professor.');
        $this->assertSame($this->teacher->id, $this->lesson->outcome_recorded_by, 'O titular original não muda.');
        $this->assertSame($numbers, $this->inTenant($this->organization, fn () => Lesson::query()->orderBy('id')->pluck('lesson_number', 'id')->all()));

        $row = $this->inTenant($this->organization, fn () => LessonOutcomeRectification::query()->sole());
        $this->assertSame('administrative', $row->kind);
        $this->assertSame($this->head->id, $row->rectified_by);
        $this->assertSame('A causa registada estava errada', $row->motive);
        $this->assertSame('strike', $row->previous['cause']);
        $this->assertSame('adhered', $row->previous['situation'], 'O que o titular reservou fica no histórico, não se perde.');
        $this->assertNull($row->current['situation']);
    }

    #[Test]
    public function the_head_never_gets_to_read_what_the_holder_reserved_nor_the_lesson_page(): void
    {
        $this->titularLeaves();
        $this->administrative($this->head, $this->valid())->assertRedirect();

        // Competência para corrigir ≠ competência para consultar.
        $this->actingAs($this->head)->withSession(['organization_id' => $this->organization->id])->get("/lessons/{$this->lesson->ulid}")->assertForbidden();
        $this->assertFalse(LessonOutcomeDetails::mayRead($this->lesson->outcome_recorded_by, $this->head->id, [$this->remaining->id]));

        // E o professor que ficou vê a causa nova, mas nenhum histórico nem detalhe reservado.
        $props = AssertableInertia::fromTestResponse(
            $this->actingAs($this->remaining)->withSession(['organization_id' => $this->organization->id])->get("/lessons/{$this->lesson->ulid}")->assertOk(),
        )->toArray()['props']['lesson'];
        $this->assertNull($props['outcome_rectification']);
        $this->assertSame('Encerramento por mau tempo', $props['outcome_cause_label']);
        $this->assertStringNotContainsString('A causa registada estava errada', json_encode($props, JSON_THROW_ON_ERROR));
        $this->assertNull($props['outcome_teacher_situation_label'], 'A declaração individual do titular não chega a um colega.');
    }

    #[Test]
    public function the_only_remaining_teacher_is_never_granted_the_correction_automatically(): void
    {
        $this->titularLeaves();

        $this->administrative($this->remaining, $this->valid())->assertForbidden();
        $this->actingAs($this->remaining)->withSession(['organization_id' => $this->organization->id])
            ->patchJson("/lessons/{$this->lesson->ulid}/outcome", ['outcome' => 'not_held', 'cause' => 'weather_closure', 'rectification_note' => 'Tentativa'])
            ->assertForbidden();
        $this->assertSame(LessonNotHeldCause::Strike, $this->lesson->refresh()->outcome_cause);
    }

    #[Test]
    public function nobody_else_may_use_it_and_not_while_the_holder_is_still_in_the_class(): void
    {
        // O titular ainda está na turma: nem a direção corrige.
        $this->administrative($this->head, $this->valid())->assertForbidden();

        $this->titularLeaves();
        $this->administrative($this->member($this->organization), $this->valid())->assertForbidden();

        $otherHead = User::factory()->create();
        $other = app(CreateInstitutionalOrganization::class)->create('Outra Escola', $otherHead);
        $this->actingAs($otherHead)->withSession(['organization_id' => $other->id])
            ->patchJson("/lessons/{$this->lesson->ulid}/outcome/administrative", $this->valid())->assertNotFound();

        $this->assertSame(0, $this->inTenant($this->organization, fn () => LessonOutcomeRectification::query()->count()));
    }

    #[Test]
    public function it_needs_the_feature_a_not_held_lesson_a_real_change_a_motive_and_a_description_for_other(): void
    {
        $this->titularLeaves();

        $this->administrative($this->head, ['cause' => 'strike', 'rectification_note' => 'Igual'])->assertJsonValidationErrors('cause');
        $this->administrative($this->head, ['cause' => 'weather_closure'])->assertJsonValidationErrors('rectification_note');
        $this->administrative($this->head, ['cause' => 'other', 'rectification_note' => 'Sem texto'])->assertJsonValidationErrors('cause_note');
        $this->administrative($this->head, $this->valid() + ['situation' => 'adhered', 'outcome' => 'teacher_absent'])->assertRedirect();
        $this->assertNull($this->lesson->refresh()->outcome_teacher_situation);

        $absent = $this->makeLesson(['starts_at' => '2026-10-14 09:30:00']);
        $this->inTenant($this->organization, fn () => app(RecordLessonOutcome::class)->execute($absent, LessonOutcome::TeacherAbsent, $this->teacher, TeacherAbsenceReason::Training));
        $this->administrative($this->head, $this->valid(), $absent)->assertForbidden();

        config(['lapis.lesson_not_held_enabled' => false]);
        $this->administrative($this->head, ['cause' => 'organizational', 'rectification_note' => 'Com a chave desligada'])->assertForbidden();
    }

    #[Test]
    public function the_audit_trail_records_the_administrative_correction_without_reserved_data(): void
    {
        $this->titularLeaves();
        $this->administrative($this->head, $this->valid() + ['cause_note' => null])->assertRedirect();

        $event = $this->inTenant($this->organization, fn () => AuditEvent::query()->where('event', 'lesson.outcome_rectified')->sole());
        $this->assertSame($this->head->id, $event->causer_id);
        $this->assertSame('administrative', $event->properties['kind']);
        $this->assertSame(['strike', 'weather_closure'], [$event->properties['from_cause'], $event->properties['to_cause']]);
        $this->assertTrue($event->properties['reserved_details_changed']);
        $this->assertStringNotContainsString('adhered', json_encode($event->properties, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('A causa registada estava errada', json_encode($event->properties, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function a_personal_account_has_no_head_so_the_owner_of_it_cannot_use_the_administrative_path(): void
    {
        // Caracterização: o dono de uma conta pessoal não é "direção" (reviewRectifications exige instituição).
        $solo = User::factory()->create();
        $personal = $solo->personalOrganization();
        $this->subscribeToPro($personal);
        $class = $this->inTenant($personal, function () use ($personal, $solo): SchoolClass {
            $class = SchoolClass::factory()->recycle($personal)->create([
                'academic_year_id' => AcademicYear::factory()->recycle($personal)->create(['starts_on' => '2026-09-01', 'ends_on' => '2027-06-30'])->id,
            ]);
            $class->teachers()->attach($solo, ['role' => 'owner']);

            return $class;
        });
        $former = User::factory()->create();
        $lesson = $this->inTenant($personal, fn () => Lesson::create([
            'class_id' => $class->id, 'starts_at' => '2026-10-13 09:30:00', 'ends_at' => '2026-10-13 10:20:00', 'status' => 'preparation',
            'outcome' => LessonOutcome::NotHeld, 'outcome_cause' => LessonNotHeldCause::Strike, 'outcome_recorded_by' => $former->id,
            'outcome_recorded_at' => now(), 'created_by' => $solo->id,
        ]));

        $this->actingAs($solo)->withSession(['organization_id' => $personal->id])
            ->patchJson("/lessons/{$lesson->ulid}/outcome/administrative", $this->valid())->assertForbidden();
    }
}
