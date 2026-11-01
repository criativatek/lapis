<?php

namespace Tests\Feature\Lessons;

use App\Models\Lesson;
use App\Models\LessonNotHeldCause;
use App\Models\LessonOutcome;
use App\Models\TeacherStrikeSituation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

/**
 * As três opções de uma greve — «Greve» (`null`, por defeito), «Greve do professor» (`adhered`, só com a
 * chave da adesão) e «Greve — escola encerrada» (`school_closed`, sem chave) — e o que acontece às duas
 * chaves depois de já existirem registos: nada se perde e tudo continua a ler-se.
 */
class LessonStrikeOptionsTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'Europe/Lisbon'));
        $this->bootLessonFixtures();
        config(['lapis.lesson_not_held_enabled' => true, 'lapis.strike_adhesion_recording' => false]);
    }

    /** @param  array<string, mixed>  $body */
    private function record(Lesson $lesson, array $body, ?User $as = null)
    {
        return ($as === null ? $this->asTeacher() : $this->actingAs($as)->withSession(['organization_id' => $this->organization->id]))
            ->post("/lessons/{$lesson->ulid}/outcome", $body + ['outcome' => 'not_held', 'cause' => 'strike']);
    }

    private function colleague(): User
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->organization, ['joined_at' => now()]);
        $this->inTenant($this->organization, fn () => $this->schoolClass->teachers()->attach($user, ['role' => 'co_teacher']));

        return $user;
    }

    /** @return array<string, mixed> */
    private function lessonProps(User $user, Lesson $lesson): array
    {
        return AssertableInertia::fromTestResponse(
            $this->actingAs($user)->withSession(['organization_id' => $this->organization->id])->get("/lessons/{$lesson->ulid}")->assertOk(),
        )->toArray()['props']['lesson'];
    }

    #[Test]
    public function the_three_options_are_recorded_as_null_adhered_and_school_closed_each_without_a_lesson_number(): void
    {
        config(['lapis.strike_adhesion_recording' => true]);
        [$generic, $own, $closed] = [
            $this->makeLesson(['starts_at' => '2026-10-12 09:30:00']),
            $this->makeLesson(['starts_at' => '2026-10-13 09:30:00']),
            $this->makeLesson(['starts_at' => '2026-10-14 09:30:00']),
        ];

        $this->record($generic, [])->assertSessionHasNoErrors();
        $this->record($own, ['situation' => 'adhered'])->assertSessionHasNoErrors();
        $this->record($closed, ['situation' => 'school_closed'])->assertSessionHasNoErrors();

        $this->inTenant($this->organization, function () use ($generic, $own, $closed): void {
            $this->assertNull($generic->refresh()->outcome_teacher_situation, '«Greve» é o registo sem especificação.');
            $this->assertSame(TeacherStrikeSituation::Adhered, $own->refresh()->outcome_teacher_situation);
            $this->assertSame(TeacherStrikeSituation::SchoolClosed, $closed->refresh()->outcome_teacher_situation);

            foreach ([$generic, $own, $closed] as $lesson) {
                $this->assertSame(LessonOutcome::NotHeld, $lesson->outcome);
                $this->assertSame(LessonNotHeldCause::Strike, $lesson->outcome_cause);
                $this->assertNull($lesson->lesson_number);
            }
        });
    }

    #[Test]
    public function the_adhesion_switch_controls_only_the_teachers_own_declaration(): void
    {
        $generic = $this->makeLesson(['starts_at' => '2026-10-12 09:30:00']);
        $own = $this->makeLesson(['starts_at' => '2026-10-13 09:30:00']);
        $closed = $this->makeLesson(['starts_at' => '2026-10-14 09:30:00']);

        // Chave desligada: «Greve do professor» recusa-se; as outras duas funcionam.
        $this->record($own, ['situation' => 'adhered'])->assertSessionHasErrors('situation');
        $this->record($generic, [])->assertSessionHasNoErrors();
        $this->record($closed, ['situation' => 'school_closed'])->assertSessionHasNoErrors();

        // O ecrã só oferece as que existem.
        $options = $this->lessonProps($this->teacher, $this->makeLesson(['starts_at' => '2026-10-15 09:30:00']))['not_held_options'];
        $this->assertSame(['school_closed'], array_column($options['specifications'], 'value'));
    }

    #[Test]
    public function school_closed_describes_the_school_so_a_colleague_reads_it_while_the_declaration_stays_reserved(): void
    {
        config(['lapis.strike_adhesion_recording' => true]);
        $closed = $this->makeLesson(['starts_at' => '2026-10-13 09:30:00']);
        $own = $this->makeLesson(['starts_at' => '2026-10-14 09:30:00']);
        $this->record($closed, ['situation' => 'school_closed']);
        $this->record($own, ['situation' => 'adhered']);
        $colleague = $this->colleague();

        // A escola encerrada lê-se como a causa, por toda a turma, e não fica «reservada».
        $seen = $this->lessonProps($colleague, $closed);
        $this->assertSame('Greve — escola encerrada', $seen['outcome_cause_label']);
        $this->assertFalse($seen['outcome_reason_reserved']);
        $this->assertNull($seen['outcome_teacher_situation_label'], 'Não é uma declaração individual.');

        // A adesão do professor continua reservada ao titular.
        $other = $this->lessonProps($colleague, $own);
        $this->assertSame('Greve', $other['outcome_cause_label']);
        $this->assertNull($other['outcome_teacher_situation_label']);
        $this->assertTrue($other['outcome_reason_reserved']);
        $this->assertSame('Greve do professor', $this->lessonProps($this->teacher, $own)['outcome_teacher_situation_label']);
    }

    #[Test]
    public function school_closed_is_never_read_as_not_adhered_and_the_legacy_values_stay_readable_unconverted(): void
    {
        $legacy = $this->makeLesson(['starts_at' => '2026-10-13 09:30:00']);
        $unknown = $this->makeLesson(['starts_at' => '2026-10-14 09:30:00']);
        foreach ([[$legacy, TeacherStrikeSituation::NotAdhered], [$unknown, TeacherStrikeSituation::NotInformed]] as [$lesson, $situation]) {
            $this->inTenant($this->organization, fn () => Lesson::query()->whereKey($lesson->id)->update([
                'outcome' => 'not_held', 'outcome_cause' => 'strike', 'outcome_teacher_situation' => $situation->value,
                'outcome_recorded_at' => now(), 'outcome_recorded_by' => $this->teacher->id,
            ]));
        }

        // Os registos anteriores lêem-se (ao titular) tal como estavam; nenhum virou «escola encerrada».
        $this->assertSame('Não aderi à greve', $this->lessonProps($this->teacher, $legacy)['outcome_teacher_situation_label']);
        $this->assertSame('Adesão não confirmada', $this->lessonProps($this->teacher, $unknown)['outcome_teacher_situation_label']);
        $this->inTenant($this->organization, function () use ($legacy, $unknown): void {
            $this->assertSame(TeacherStrikeSituation::NotAdhered, $legacy->refresh()->outcome_teacher_situation);
            $this->assertSame(TeacherStrikeSituation::NotInformed, $unknown->refresh()->outcome_teacher_situation);
        });
        $this->assertNotSame(TeacherStrikeSituation::NotAdhered->label(), TeacherStrikeSituation::SchoolClosed->label());

        // E já não se oferecem nem se aceitam de novo.
        $fresh = $this->makeLesson(['starts_at' => '2026-10-15 09:30:00']);
        $this->record($fresh, ['situation' => 'not_adhered'])->assertSessionHasErrors('situation');
        $this->record($fresh, ['situation' => 'not_informed'])->assertSessionHasErrors('situation');
    }

    #[Test]
    public function turning_the_flags_off_after_records_exist_loses_nothing_and_everything_still_reads(): void
    {
        config(['lapis.strike_adhesion_recording' => true]);
        $own = $this->makeLesson(['starts_at' => '2026-10-13 09:30:00']);
        $closed = $this->makeLesson(['starts_at' => '2026-10-14 09:30:00']);
        $this->record($own, ['situation' => 'adhered']);
        $this->record($closed, ['situation' => 'school_closed']);

        config(['lapis.strike_adhesion_recording' => false]);
        // A declaração gravada lê-se ao titular, e fica como está.
        $this->assertSame('Greve do professor', $this->lessonProps($this->teacher, $own)['outcome_teacher_situation_label']);

        config(['lapis.lesson_not_held_enabled' => false]);
        // Tudo desligado: as aulas continuam a abrir-se, com o resultado, a causa e a especificação...
        $page = $this->lessonProps($this->teacher, $own);
        $this->assertSame('not_held', $page['outcome']);
        $this->assertSame('Greve', $page['outcome_cause_label']);
        $this->assertSame('Greve do professor', $page['outcome_teacher_situation_label']);
        $this->assertSame('Greve — escola encerrada', $this->lessonProps($this->teacher, $closed)['outcome_cause_label']);
        // ... a semana mostra-as ...
        $this->actingAs($this->teacher)->withSession(['organization_id' => $this->organization->id])->get('/lessons?week=2026-10-12')->assertOk();
        // ... o relatório do serviço letivo continua a contá-las ...
        $report = AssertableInertia::fromTestResponse(
            $this->actingAs($this->teacher)->withSession(['organization_id' => $this->organization->id])->get('/lessons/service-report')->assertOk(),
        )->toArray()['props']['report'];
        $this->assertSame(2, $report['totals']['not_held']);
        $this->assertSame(1, $report['personal']['strike']['adhered']);
        $this->assertSame(1, $report['personal']['strike']['school_closed']);
        // ... e nada foi apagado nem convertido.
        $this->inTenant($this->organization, function () use ($own, $closed): void {
            $this->assertSame(TeacherStrikeSituation::Adhered, $own->refresh()->outcome_teacher_situation);
            $this->assertSame(TeacherStrikeSituation::SchoolClosed, $closed->refresh()->outcome_teacher_situation);
        });
        // O que se desliga é o registo e a correção: um novo pedido já não é aceite.
        $this->record($this->makeLesson(['starts_at' => '2026-10-15 09:30:00']), [])->assertSessionHasErrors();
    }
}
