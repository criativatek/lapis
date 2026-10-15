<?php

namespace Tests\Feature\Lessons;

use App\Models\AcademicYear;
use App\Models\ClassGroup;
use App\Models\Lesson;
use App\Models\LessonPlan;
use App\Models\LessonStatus;
use App\Models\LessonSummary;
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
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Import\TimetablePdfBuilder;
use Tests\TestCase;

/**
 * Corrigir o horário depois de uma importação imperfeita (0.168.0), pelas
 * rotas reais `lesson-slots` — as mesmas que o editor da turma e o diálogo do
 * Horário do Professor usam.
 *
 * O que estes casos provam, e porquê é a parte que não pode falhar:
 *
 *  · uma alteração a um tempo COM histórico respeita a data de aplicação —
 *    fecha a linha antiga na véspera e abre outra;
 *  · um sumário lecionado, uma aula com plano, nunca se perdem nem mudam de
 *    turma, antes ou depois da data de aplicação; só aulas vazias geradas pelo
 *    horário saem;
 *  · eliminar respeita o mesmo, e aceita «deixa de vigorar a partir de»;
 *  · mudar a TURMA de um tempo (e com ela a disciplina) edita no lugar quando
 *    não há histórico e versiona quando há.
 *
 * Relógio fixado ANTES de qualquer dado: quarta-feira, 14/10/2026.
 */
class TimetableCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private const TIMEZONE = 'Europe/Lisbon';

    private User $teacher;

    private Organization $organization;

    private AcademicYear $academicYear;

    private SchoolClass $seventhC;

    private SchoolClass $eighthA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00:00', self::TIMEZONE));

        $this->teacher = User::factory()->create();
        $this->organization = $this->teacher->personalOrganization();
        $this->subscribeToPro($this->organization);

        $this->academicYear = $this->inTenant(
            fn (): AcademicYear => AcademicYear::factory()
                ->recycle($this->organization)
                ->create(['label' => '2026/2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30']),
        );

        $this->seventhC = $this->schoolClassFor($this->teacher, '7.º C', 'MAT', 'Matemática');
        $this->eighthA = $this->schoolClassFor($this->teacher, '8.º A', 'CN', 'Ciências Naturais');
    }

    // -------------------------------------------------- (a) data de aplicação

    #[Test]
    public function a_change_to_a_slot_with_history_closes_the_old_row_and_opens_a_new_one_at_the_application_date(): void
    {
        $slot = $this->slot($this->seventhC, 1, '08:30', '09:20', ['starts_on' => '2026-09-14']);
        $taught = $this->lesson($slot, '2026-10-12 08:30:00', LessonStatus::Taught);
        $this->summary($taught);

        $this->putSlot($slot, $this->seventhC, [
            'starts_at' => '08:40',
            'ends_at' => '09:30',
            'starts_on' => '2026-09-14',
            'effective_from' => '2026-10-19',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->inTenant(function () use ($slot, $taught): void {
            $this->assertSame('2026-10-18', $slot->fresh()->ends_on->toDateString());
            $this->assertStringStartsWith('08:30', $slot->fresh()->starts_at);

            $new = RecurringLessonSlot::query()->where('id', '!=', $slot->id)->sole();
            $this->assertSame('2026-10-19', $new->starts_on->toDateString());
            $this->assertStringStartsWith('08:40', $new->starts_at);
            $this->assertSame($this->seventhC->id, $new->class_id);

            $this->assertTaughtLessonIntact($taught, $slot);
        });
    }

    #[Test]
    public function the_application_date_cannot_be_in_the_past(): void
    {
        $slot = $this->slot($this->seventhC, 1, '08:30', '09:20', ['starts_on' => '2026-09-14']);
        $this->summary($this->lesson($slot, '2026-10-12 08:30:00', LessonStatus::Taught));

        $this->putSlot($slot, $this->seventhC, ['starts_on' => '2026-09-14', 'effective_from' => '2026-10-10'])
            ->assertSessionHasErrors('effective_from');

        $this->assertDatabaseCount('recurring_lesson_slots', 1);
    }

    // ------------------------------------- (b) o que nunca se perde nem muda

    #[Test]
    public function a_taught_summary_and_a_prepared_lesson_with_a_plan_stay_in_the_original_turma_on_both_sides_of_the_date(): void
    {
        $slot = $this->slot($this->seventhC, 1, '08:30', '09:20', ['starts_on' => '2026-09-14']);

        // Antes da data de aplicação: lecionada com sumário.
        $taught = $this->lesson($slot, '2026-10-12 08:30:00', LessonStatus::Taught);
        $this->summary($taught);

        // Depois da data de aplicação: preparada, com plano — conteúdo.
        $prepared = $this->lesson($slot, '2026-10-26 08:30:00', LessonStatus::Preparation);
        $this->plan($prepared);

        // Depois da data de aplicação: preparação VAZIA gerada pelo horário —
        // a única que pode sair.
        $empty = $this->lesson($slot, '2026-11-02 08:30:00', LessonStatus::Preparation);

        $this->putSlot($slot, $this->eighthA, [
            'starts_on' => '2026-09-14',
            'effective_from' => '2026-10-19',
        ], originalClass: $this->seventhC)->assertRedirect()->assertSessionHasNoErrors();

        $this->inTenant(function () use ($slot, $taught, $prepared, $empty): void {
            $this->assertTaughtLessonIntact($taught, $slot);

            $preparedNow = Lesson::query()->findOrFail($prepared->id);
            $this->assertSame($this->seventhC->id, $preparedNow->class_id);
            $this->assertSame($slot->id, $preparedNow->recurring_lesson_slot_id);
            $this->assertNotNull($preparedNow->plan);

            $this->assertNull(Lesson::query()->find($empty->id));

            // A linha antiga fechou-se na véspera, na turma original; a nova
            // nasceu na turma nova (com a sua disciplina) a partir da data.
            $this->assertSame('2026-10-18', $slot->fresh()->ends_on->toDateString());
            $this->assertSame($this->seventhC->id, $slot->fresh()->class_id);

            $new = RecurringLessonSlot::query()->where('id', '!=', $slot->id)->sole();
            $this->assertSame($this->eighthA->id, $new->class_id);
            $this->assertSame('2026-10-19', $new->starts_on->toDateString());
            $this->assertNull($new->class_group_id);
            $this->assertNull($new->split_lesson_key);
        });
    }

    #[Test]
    public function an_empty_prepared_lesson_of_a_slot_without_history_goes_and_a_content_lesson_never_does(): void
    {
        // Sem histórico relevante: só preparações vazias.
        $slot = $this->slot($this->seventhC, 1, '08:30', '09:20');
        $empty = $this->lesson($slot, '2026-10-19 08:30:00', LessonStatus::Preparation);

        $this->putSlot($slot, $this->eighthA, [], originalClass: $this->seventhC)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->inTenant(function () use ($slot, $empty): void {
            // Atualizado no lugar: uma só linha, agora na turma nova.
            $this->assertSame(1, RecurringLessonSlot::query()->count());
            $this->assertSame($this->eighthA->id, $slot->fresh()->class_id);
            $this->assertNull($slot->fresh()->class_group_id);
            $this->assertNull($slot->fresh()->split_lesson_key);
            // A preparação vazia da turma antiga saiu; a nova gera-se ao abrir a semana.
            $this->assertNull(Lesson::query()->find($empty->id));
        });

        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->get('/lessons?week=2026-10-19')->assertOk();

        $this->inTenant(function (): void {
            $lesson = Lesson::query()->where('starts_at', '>=', '2026-10-19 00:00:00')->sole();
            $this->assertSame($this->eighthA->id, $lesson->class_id);
        });
    }

    #[Test]
    public function an_attendance_draft_alone_makes_a_class_change_require_the_application_date(): void
    {
        $slot = $this->slot($this->seventhC, 1, '08:30', '09:20');
        $lesson = $this->lesson($slot, '2026-10-19 08:30:00', LessonStatus::Preparation);
        $this->inTenant(fn () => $lesson->forceFill(['attendance_recorded_at' => CarbonImmutable::now()])->save());

        $this->putSlot($slot, $this->eighthA, [], originalClass: $this->seventhC)
            ->assertSessionHasErrors('effective_from');

        $this->inTenant(function () use ($slot, $lesson): void {
            $this->assertSame($this->seventhC->id, $slot->fresh()->class_id);
            $this->assertNotNull(Lesson::query()->find($lesson->id));
        });
    }

    // ------------------------------------------------ (2) mudar de turma

    #[Test]
    public function the_new_turma_must_be_taught_by_the_teacher_in_the_same_organization(): void
    {
        $slot = $this->slot($this->seventhC, 1, '08:30', '09:20');

        $colleague = User::factory()->create();
        $colleague->organizations()->attach($this->organization, ['joined_at' => now()]);
        $notMine = $this->schoolClassFor($colleague, '9.º A', 'MAT', 'Matemática');

        $this->putSlot($slot, $notMine, [], originalClass: $this->seventhC)->assertForbidden();

        $outsider = User::factory()->create();
        $foreign = $this->schoolClassFor($outsider, '9.º B', 'MAT', 'Matemática', $outsider->personalOrganization());

        $this->putSlot($slot, $foreign, [], originalClass: $this->seventhC)->assertForbidden();

        $this->assertSame($this->seventhC->id, $this->inTenant(fn () => $slot->fresh()->class_id));
    }

    #[Test]
    public function the_new_turma_cannot_be_archived_or_from_another_academic_year(): void
    {
        $slot = $this->slot($this->seventhC, 1, '08:30', '09:20');

        $archived = $this->schoolClassFor($this->teacher, '8.º Z', 'MAT', 'Matemática');
        $this->inTenant(fn () => $archived->forceFill(['archived_at' => CarbonImmutable::now()])->save());

        $this->putSlot($slot, $archived, [], originalClass: $this->seventhC)
            ->assertSessionHasErrors('class_id');

        $otherYear = $this->inTenant(fn (): AcademicYear => AcademicYear::factory()
            ->recycle($this->organization)
            ->create(['label' => '2025/2026', 'starts_on' => '2025-09-01', 'ends_on' => '2026-06-30']));
        $oldClass = $this->schoolClassFor($this->teacher, '9.º Y', 'MAT', 'Matemática', year: $otherYear);

        $this->putSlot($slot, $oldClass, [], originalClass: $this->seventhC)
            ->assertSessionHasErrors('class_id');

        $this->assertSame($this->seventhC->id, $this->inTenant(fn () => $slot->fresh()->class_id));
    }

    #[Test]
    public function a_class_change_clears_the_group_and_the_same_lesson_link(): void
    {
        $slot = $this->slot($this->seventhC, 1, '08:30', '09:20', ['split_lesson_key' => '01J00000000000000000000000']);
        $group = $this->inTenant(fn () => ClassGroup::factory()
            ->recycle($this->organization)
            ->create(['class_id' => $this->seventhC->id, 'label' => 'T1']));
        $this->inTenant(fn () => $slot->update(['class_group_id' => $group->id]));

        // O pedido de um cliente que mantém o grupo da turma antiga: recusado.
        $this->putSlot($slot, $this->eighthA, ['class_group_id' => $group->id], originalClass: $this->seventhC)
            ->assertSessionHasErrors('class_group_id');

        $this->putSlot($slot, $this->eighthA, ['class_group_id' => null], originalClass: $this->seventhC)
            ->assertSessionHasNoErrors();

        $this->inTenant(function () use ($slot): void {
            $fresh = $slot->fresh();
            $this->assertSame($this->eighthA->id, $fresh->class_id);
            $this->assertNull($fresh->class_group_id);
            $this->assertNull($fresh->split_lesson_key);
        });
    }

    #[Test]
    public function a_class_change_cannot_overlap_the_new_turmas_own_schedule(): void
    {
        $slot = $this->slot($this->seventhC, 1, '08:30', '09:20');
        $this->slot($this->eighthA, 1, '09:00', '09:50');

        $this->putSlot($slot, $this->eighthA, [], originalClass: $this->seventhC)
            ->assertSessionHasErrors('starts_at');

        $this->assertSame($this->seventhC->id, $this->inTenant(fn () => $slot->fresh()->class_id));
    }

    // --------------------------------------------------- (c) eliminar

    #[Test]
    public function deleting_with_an_effective_date_closes_the_slot_the_day_before_and_keeps_every_content_lesson(): void
    {
        $slot = $this->slot($this->seventhC, 1, '08:30', '09:20', ['starts_on' => '2026-09-14']);
        $taught = $this->lesson($slot, '2026-10-12 08:30:00', LessonStatus::Taught);
        $this->summary($taught);
        $planned = $this->lesson($slot, '2026-10-26 08:30:00', LessonStatus::Preparation);
        $this->plan($planned);
        $empty = $this->lesson($slot, '2026-11-02 08:30:00', LessonStatus::Preparation);

        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->delete("/lesson-slots/{$slot->ulid}", ['effective_from' => '2026-10-19'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->inTenant(function () use ($slot, $taught, $planned, $empty): void {
            $this->assertSame('2026-10-18', $slot->fresh()->ends_on->toDateString());
            $this->assertTaughtLessonIntact($taught, $slot);
            $this->assertNotNull(Lesson::query()->find($planned->id)?->plan);
            $this->assertNull(Lesson::query()->find($empty->id));
        });
    }

    #[Test]
    public function deleting_without_a_date_keeps_the_current_behaviour(): void
    {
        $withHistory = $this->slot($this->seventhC, 1, '08:30', '09:20', ['starts_on' => '2026-09-14']);
        $this->summary($this->lesson($withHistory, '2026-10-12 08:30:00', LessonStatus::Taught));
        $neverUsed = $this->slot($this->eighthA, 2, '08:30', '09:20');

        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->delete("/lesson-slots/{$withHistory->ulid}")->assertRedirect();
        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->delete("/lesson-slots/{$neverUsed->ulid}")->assertRedirect();

        $this->inTenant(function () use ($withHistory, $neverUsed): void {
            // Com histórico: fecha-se na véspera de hoje, nunca se apaga.
            $this->assertSame('2026-10-13', $withHistory->fresh()->ends_on->toDateString());
            // Sem histórico: apaga-se.
            $this->assertNull(RecurringLessonSlot::query()->find($neverUsed->id));
        });
    }

    #[Test]
    public function the_deletion_date_cannot_be_in_the_past_or_before_the_slot_started(): void
    {
        $slot = $this->slot($this->seventhC, 1, '08:30', '09:20', ['starts_on' => '2026-10-05']);

        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->delete("/lesson-slots/{$slot->ulid}", ['effective_from' => '2026-10-10'])
            ->assertSessionHasErrors('effective_from');

        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->delete("/lesson-slots/{$slot->ulid}", ['effective_from' => 'amanhã'])
            ->assertSessionHasErrors('effective_from');

        $this->assertNull($this->inTenant(fn () => $slot->fresh()->ends_on));
    }

    // ------------------------------------------------------ o percurso inteiro

    #[Test]
    public function the_whole_journey_import_confirm_correct_save_and_reopen(): void
    {
        // 1. Importar o PDF (a pré-visualização não escreve nada) e confirmar.
        $rows = [];
        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->post('/timetable-imports', ['timetable' => UploadedFile::fake()->createWithContent('horario.pdf', TimetablePdfBuilder::example()->bytes())])
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$rows): void {
                foreach ($page->toArray()['props']['groups'] as $group) {
                    foreach ($group['rows'] as $row) {
                        $rows[] = [
                            'class_id' => $group['class_id'],
                            'day_of_week' => $row['day_of_week'],
                            'starts_at' => $row['starts_at'],
                            'ends_at' => $row['ends_at'],
                            'include' => true,
                        ];
                    }
                }
            });
        $this->assertDatabaseCount('recurring_lesson_slots', 0);

        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->post('/timetable-imports/confirm', ['starts_on' => null, 'ends_on' => null, 'unassociated_count' => 2, 'rows' => $rows])
            // O destino é o Horário do Professor, com um convite a rever.
            ->assertRedirect('/timetable');

        $this->assertStringContainsString(
            'Reveja o horário e corrija',
            json_encode(session('inertia.flash_data') ?? session()->all(), JSON_UNESCAPED_UNICODE) ?: '',
        );

        // O que a importação deixou: duas aulas da 7.º C à segunda e quatro da 8.º A.
        $monday0830 = $this->findSlot($this->seventhC, 1, '08:30');   // devia ser da 8.º A
        $monday0920 = $this->findSlot($this->seventhC, 1, '09:20');   // horas erradas
        $friday = $this->findSlot($this->eighthA, 5, '11:20');        // a eliminar

        // Antes da correção, a segunda-feira 12/10 já teve uma aula lecionada
        // com sumário no bloco que estava na turma errada — histórico.
        $taught = $this->lesson($monday0830, '2026-10-12 08:30:00', LessonStatus::Taught);
        $this->summary($taught);
        $empty = $this->lesson($monday0830, '2026-10-26 08:30:00', LessonStatus::Preparation);
        // Uma aula vazia gerada para o bloco de horas erradas (sem histórico).
        $emptyWrongHours = $this->lesson($monday0920, '2026-10-19 09:20:00', LessonStatus::Preparation);

        // 2. Corrigir: mudar a turma de um bloco (com histórico → versiona)…
        $this->putSlot($monday0830, $this->eighthA, ['effective_from' => '2026-10-19'], originalClass: $this->seventhC)
            ->assertRedirect()->assertSessionHasNoErrors();

        // …as horas de outro (sem histórico → no lugar)…
        $this->putSlot($monday0920, $this->seventhC, ['starts_at' => '09:30', 'ends_at' => '10:20'])
            ->assertRedirect()->assertSessionHasNoErrors();

        // …apagar um…
        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->delete("/lesson-slots/{$friday->ulid}")->assertRedirect();

        // …acrescentar uma aula e uma atividade não letiva.
        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->post('/lesson-slots', [
                'class_id' => $this->seventhC->id,
                'day_of_week' => 4,
                'starts_at' => '14:20',
                'ends_at' => '15:10',
            ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->post('/timetable-activities', [
                'kind' => 'class_direction',
                'title' => 'DT 8.º A',
                'class_ulid' => $this->eighthA->ulid,
                'day_of_week' => 2,
                'starts_at' => '10:30',
                'ends_at' => '11:20',
            ])->assertRedirect()->assertSessionHasNoErrors();

        // 3. Reabrir: a semana de 12/10 ainda mostra o bloco na turma original…
        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->get('/timetable?week=2026-10-12')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $monday = collect($page->toArray()['props']['slots'])->where('day_of_week', 1)->values();
                $this->assertSame(['7.º C', '7.º C'], $monday->pluck('school_class.label')->all());
                $this->assertSame(['08:30', '09:30'], $monday->pluck('starts_at')->all());
                $this->assertSame(['10:20'], $monday->where('starts_at', '09:30')->pluck('ends_at')->values()->all());
            });

        // …e a semana de 19/10 já o mostra na 8.º A, com a atividade, sem a sexta apagada.
        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->get('/timetable?week=2026-10-19')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $slots = collect($page->toArray()['props']['slots']);

                $this->assertSame('8.º A', $slots->firstWhere(fn (array $slot) => $slot['day_of_week'] === 1 && $slot['starts_at'] === '08:30')['school_class']['label']);
                $this->assertSame('Ciências Naturais', $slots->firstWhere(fn (array $slot) => $slot['day_of_week'] === 1 && $slot['starts_at'] === '08:30')['subject']);
                $this->assertNotNull($slots->firstWhere(fn (array $slot) => $slot['day_of_week'] === 4 && $slot['starts_at'] === '14:20'));
                $this->assertNull($slots->firstWhere(fn (array $slot) => $slot['day_of_week'] === 5));
                $this->assertSame(['DT 8.º A'], collect($page->toArray()['props']['activities'])->pluck('title')->all());
            });

        // A página de cada turma bate certo com o Horário do Professor.
        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->get("/classes/{$this->eighthA->ulid}")
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $monday = collect($page->toArray()['props']['recurringLessonSlots'])->where('day_of_week', 1);
                $this->assertTrue($monday->contains(fn (array $slot) => $slot['starts_at'] === '08:30' && $slot['starts_on'] === '2026-10-19'));
            });

        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->get("/classes/{$this->seventhC->ulid}")
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $slots = collect($page->toArray()['props']['recurringLessonSlots']);
                // O bloco antigo ainda vigora até 18/10 na 7.º C; o corrigido de horas e o novo estão lá.
                $this->assertTrue($slots->contains(fn (array $slot) => $slot['starts_at'] === '08:30' && $slot['ends_on'] === '2026-10-18'));
                $this->assertTrue($slots->contains(fn (array $slot) => $slot['starts_at'] === '09:30' && $slot['ends_at'] === '10:20'));
                $this->assertTrue($slots->contains(fn (array $slot) => $slot['day_of_week'] === 4 && $slot['starts_at'] === '14:20'));
            });

        // 4. O histórico sobreviveu intacto na turma original.
        $this->inTenant(function () use ($monday0830, $taught, $empty, $emptyWrongHours): void {
            $this->assertTaughtLessonIntact($taught, $monday0830);
            $this->assertNull(Lesson::query()->find($empty->id));
            // Mudar só as horas nunca toca nas aulas já materializadas (o
            // contrato do horário): a preparação vazia fica onde estava.
            $this->assertNotNull(Lesson::query()->find($emptyWrongHours->id));
        });

        // 5. E nada disto criou aulas por causa da atividade.
        $this->assertDatabaseMissing('lessons', ['class_id' => null]);
    }

    // ---------------------------------------------------------------- helpers

    private function assertTaughtLessonIntact(Lesson $taught, RecurringLessonSlot $slot): void
    {
        $now = Lesson::query()->findOrFail($taught->id);

        $this->assertSame($this->seventhC->id, $now->class_id);
        $this->assertSame($slot->id, $now->recurring_lesson_slot_id);
        $this->assertSame(LessonStatus::Taught, $now->status);
        $this->assertNotNull($now->summary);
        $this->assertSame('Sumário lecionado.', $now->summary->content);
    }

    private function findSlot(SchoolClass $schoolClass, int $day, string $startsAt): RecurringLessonSlot
    {
        return $this->inTenant(fn (): RecurringLessonSlot => RecurringLessonSlot::query()
            ->where('class_id', $schoolClass->id)
            ->where('day_of_week', $day)
            ->where('starts_at', 'like', $startsAt.'%')
            ->sole());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function putSlot(
        RecurringLessonSlot $slot,
        SchoolClass $schoolClass,
        array $overrides = [],
        ?SchoolClass $originalClass = null,
    ): TestResponse {
        $current = $this->inTenant(fn (): RecurringLessonSlot => RecurringLessonSlot::query()->findOrFail($slot->id));

        return $this->actingAs($this->teacher)
            ->withSession($this->tenantSession())
            ->put("/lesson-slots/{$slot->ulid}", [
                'class_id' => $schoolClass->id,
                'day_of_week' => $current->day_of_week,
                'starts_at' => substr($current->starts_at, 0, 5),
                'ends_at' => substr($current->ends_at, 0, 5),
                'starts_on' => $current->starts_on?->toDateString(),
                'ends_on' => $current->ends_on?->toDateString(),
                ...$overrides,
            ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function slot(SchoolClass $schoolClass, int $day, string $startsAt, string $endsAt, array $attributes = []): RecurringLessonSlot
    {
        return $this->inTenant(fn (): RecurringLessonSlot => RecurringLessonSlot::create([
            'class_id' => $schoolClass->id,
            'day_of_week' => $day,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            ...$attributes,
        ]));
    }

    private function lesson(RecurringLessonSlot $slot, string $startsAt, LessonStatus $status): Lesson
    {
        $end = CarbonImmutable::parse($startsAt, self::TIMEZONE)->addMinutes(50)->format('Y-m-d H:i:s');

        return $this->inTenant(fn (): Lesson => Lesson::create([
            'class_id' => $slot->class_id,
            'class_group_id' => $slot->class_group_id,
            'recurring_lesson_slot_id' => $slot->id,
            'starts_at' => $startsAt,
            'ends_at' => $end,
            'status' => $status,
            'created_by' => $this->teacher->id,
        ]));
    }

    private function summary(Lesson $lesson): void
    {
        $this->inTenant(fn () => LessonSummary::create(['lesson_id' => $lesson->id, 'content' => 'Sumário lecionado.']));
    }

    private function plan(Lesson $lesson): void
    {
        $this->inTenant(fn () => LessonPlan::create([
            'lesson_id' => $lesson->id,
            'planned_summary' => 'Plano.',
            'created_by' => $this->teacher->id,
        ]));
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

    private function schoolClassFor(
        User $teacher,
        string $label,
        string $subjectCode,
        string $subjectName,
        ?Organization $organization = null,
        ?AcademicYear $year = null,
    ): SchoolClass {
        $organization ??= $this->organization;

        return $this->inTenant(function () use ($organization, $teacher, $label, $subjectCode, $subjectName, $year): SchoolClass {
            $academicYear = $year ?? ($organization->is($this->organization)
                ? $this->academicYear
                : AcademicYear::factory()->recycle($organization)->create());

            $subject = Subject::query()->firstOrCreate(['code' => $subjectCode], ['name' => $subjectName]);

            $schoolClass = SchoolClass::factory()
                ->recycle($organization)
                ->create([
                    'label' => $label,
                    'academic_year_id' => $academicYear->getKey(),
                    'subject_id' => $subject->getKey(),
                ]);
            $schoolClass->teachers()->attach($teacher, ['role' => 'owner']);

            return $schoolClass;
        }, $organization);
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

    private function inTenant(callable $callback, ?Organization $organization = null): mixed
    {
        return app(CurrentOrganization::class)->runFor($organization ?? $this->organization, $callback);
    }
}
