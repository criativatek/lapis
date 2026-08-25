<?php

namespace Tests\Feature\Calendar;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\CalendarEvent;
use App\Models\CalendarEventType;
use App\Models\Instrument;
use App\Models\InstrumentType;
use App\Models\Lesson;
use App\Models\LessonStatus;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «Acontecimentos» do Calendário do Ano Letivo (Fase 5.3) — a reunião, a
 * atividade, a visita de estudo e o «outro»: as quatro coisas datadas que não
 * têm casa em mais lado nenhum da aplicação.
 *
 * SÃO PESSOAIS, E NÃO UM CALENDÁRIO DA ESCOLA. `user_id` é o dono, e a
 * CalendarEventPolicy é o que decide quem vê e quem altera — exatamente a forma
 * que LessonSequence já estabeleceu. O acontecimento de um colega não é apenas
 * não-editável: é invisível, e há teste para as duas coisas.
 *
 * A ASSERÇÃO MAIS IMPORTANTE DESTE FICHEIRO é a de que eliminar um
 * acontecimento não toca em Instrument, AcademicPeriod, Lesson nem
 * RecurringLessonSlot. Estas quatro coisas ganharam um sítio comum onde se veem
 * — o calendário — e é precisamente aí que é fácil passar a tratá-las como se
 * fossem a mesma coisa. Não são: têm ciclos de vida inteiramente próprios, e
 * as contagens antes e depois da eliminação são a prova disso.
 */
class CalendarEventTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Organization $organization;

    private AcademicYear $academicYear;

    private ?AcademicPeriod $anchorPeriod = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create();
        $this->organization = $this->teacher->personalOrganization();
        $this->subscribeToPro($this->organization);

        $this->academicYear = $this->inTenant(fn (): AcademicYear => AcademicYear::factory()
            ->recycle($this->organization)
            ->create([
                'label' => 'Acontecimentos 2026/2027',
                'starts_on' => '2026-09-01',
                'ends_on' => '2027-07-31',
            ]));
    }

    // ------------------------------------------------- as quatro espécies

    #[Test]
    public function a_teacher_creates_a_reuniao(): void
    {
        $this->asTeacher()
            ->post('/calendar/acontecimentos', $this->payload([
                'type' => 'meeting',
                'title' => 'Conselho de turma',
            ]))
            ->assertRedirect();

        $event = $this->soleEvent();

        $this->assertSame(CalendarEventType::Meeting, $event->type);
        $this->assertSame('Reunião', $event->type->label());
        $this->assertSame('Conselho de turma', $event->title);
        $this->assertSame($this->teacher->id, $event->user_id);
        $this->assertSame($this->organization->id, $event->organization_id);
    }

    #[Test]
    public function a_teacher_creates_an_atividade(): void
    {
        $this->asTeacher()
            ->post('/calendar/acontecimentos', $this->payload([
                'type' => 'activity',
                'title' => 'Semana da Leitura',
            ]))
            ->assertRedirect();

        $event = $this->soleEvent();

        $this->assertSame(CalendarEventType::Activity, $event->type);
        $this->assertSame('Atividade', $event->type->label());
        $this->assertSame('Semana da Leitura', $event->title);
    }

    #[Test]
    public function a_teacher_creates_a_visita_de_estudo(): void
    {
        $this->asTeacher()
            ->post('/calendar/acontecimentos', $this->payload([
                'type' => 'field_trip',
                'title' => 'Visita ao Oceanário',
            ]))
            ->assertRedirect();

        $event = $this->soleEvent();

        $this->assertSame(CalendarEventType::FieldTrip, $event->type);
        $this->assertSame('Visita de estudo', $event->type->label());
        $this->assertSame('Visita ao Oceanário', $event->title);
    }

    #[Test]
    public function a_teacher_creates_an_outro(): void
    {
        $this->asTeacher()
            ->post('/calendar/acontecimentos', $this->payload([
                'type' => 'other',
                'title' => 'Entrega de documentos',
            ]))
            ->assertRedirect();

        $event = $this->soleEvent();

        $this->assertSame(CalendarEventType::Other, $event->type);
        $this->assertSame('Outro', $event->type->label());
    }

    /**
     * O conjunto é FECHADO. Uma quinta espécie não é aceite «por agora» — é
     * recusada, porque cada uma das quatro existe por não ter casa noutro sítio,
     * e o que já tem casa não entra aqui.
     */
    #[Test]
    public function a_type_outside_the_closed_set_of_four_is_rejected(): void
    {
        $this->asTeacher()
            ->post('/calendar/acontecimentos', $this->payload(['type' => 'exam']))
            ->assertSessionHasErrors('type');

        $this->assertDatabaseCount('calendar_events', 0);
    }

    // -------------------------------------------------------- datas e horas

    /**
     * DIA INTEIRO: a ausência de hora é informação, e não uma hora que ninguém
     * chegou a escrever.
     */
    #[Test]
    public function an_all_day_event_keeps_both_times_empty(): void
    {
        $this->asTeacher()
            ->post('/calendar/acontecimentos', $this->payload([
                'title' => 'Dia da escola',
                'starts_at' => null,
                'ends_at' => null,
            ]))
            ->assertRedirect();

        $event = $this->soleEvent();

        $this->assertNull($event->starts_at);
        $this->assertNull($event->ends_at);
    }

    #[Test]
    public function an_event_on_one_day_carries_its_start_and_end_times(): void
    {
        $this->asTeacher()
            ->post('/calendar/acontecimentos', $this->payload([
                'title' => 'Conselho de turma',
                'starts_on' => '2026-10-15',
                'starts_at' => '17:30',
                'ends_at' => '19:00',
            ]))
            ->assertRedirect();

        $event = $this->soleEvent();

        $this->assertSame('2026-10-15', $event->starts_on->toDateString());
        $this->assertSame('17:30', substr((string) $event->starts_at, 0, 5));
        $this->assertSame('19:00', substr((string) $event->ends_at, 0, 5));
    }

    /**
     * «Data final opcional; conceptualmente igual à inicial se ausente» — e a
     * igualdade é ESCRITA, uma vez, em SaveCalendarEvent, em vez de ficar um
     * nulo que cada sítio que lê teria de se lembrar de resolver. É isso que
     * permite que a condição de sobreposição do calendário seja a simples
     * `starts_on <= to AND ends_on >= from`.
     */
    #[Test]
    public function an_event_without_an_end_date_ends_on_the_day_it_starts(): void
    {
        $this->asTeacher()
            ->post('/calendar/acontecimentos', $this->payload([
                'starts_on' => '2026-10-15',
                'ends_on' => null,
            ]))
            ->assertRedirect();

        $event = $this->soleEvent();

        $this->assertSame('2026-10-15', $event->starts_on->toDateString());
        $this->assertSame('2026-10-15', $event->ends_on->toDateString());
    }

    #[Test]
    public function a_genuinely_multi_day_event_keeps_both_of_its_dates(): void
    {
        $this->asTeacher()
            ->post('/calendar/acontecimentos', $this->payload([
                'type' => 'field_trip',
                'title' => 'Visita de estudo a Évora',
                'starts_on' => '2026-10-14',
                'ends_on' => '2026-10-16',
            ]))
            ->assertRedirect();

        $event = $this->soleEvent();

        $this->assertSame('2026-10-14', $event->starts_on->toDateString());
        $this->assertSame('2026-10-16', $event->ends_on->toDateString());
    }

    #[Test]
    public function an_end_date_before_the_start_date_is_rejected(): void
    {
        $this->asTeacher()
            ->post('/calendar/acontecimentos', $this->payload([
                'starts_on' => '2026-10-15',
                'ends_on' => '2026-10-14',
            ]))
            ->assertSessionHasErrors('ends_on');

        $this->assertDatabaseCount('calendar_events', 0);
    }

    #[Test]
    public function an_end_time_without_a_start_time_is_rejected(): void
    {
        $this->asTeacher()
            ->post('/calendar/acontecimentos', $this->payload([
                'starts_at' => null,
                'ends_at' => '19:00',
            ]))
            ->assertSessionHasErrors('ends_at');

        $this->assertDatabaseCount('calendar_events', 0);
    }

    #[Test]
    public function an_end_time_at_or_before_the_start_time_is_rejected_on_a_single_day_event(): void
    {
        foreach (['17:30', '16:00'] as $endsAt) {
            $this->asTeacher()
                ->post('/calendar/acontecimentos', $this->payload([
                    'starts_on' => '2026-10-15',
                    'ends_on' => '2026-10-15',
                    'starts_at' => '17:30',
                    'ends_at' => $endsAt,
                ]))
                ->assertSessionHasErrors('ends_at');
        }

        $this->assertDatabaseCount('calendar_events', 0);
    }

    // ------------------------------------------------------------ as turmas

    #[Test]
    public function an_event_may_have_no_turma_at_all(): void
    {
        $this->asTeacher()
            ->post('/calendar/acontecimentos', $this->payload(['school_class_ulids' => []]))
            ->assertRedirect();

        $this->assertSame([], $this->classLabelsOf($this->soleEvent()));
        $this->assertDatabaseCount('calendar_event_school_class', 0);
    }

    #[Test]
    public function an_event_may_have_exactly_one_turma(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher, '7.º C');

        $this->asTeacher()
            ->post('/calendar/acontecimentos', $this->payload([
                'school_class_ulids' => [$schoolClass->ulid],
            ]))
            ->assertRedirect();

        $this->assertSame(['7.º C'], $this->classLabelsOf($this->soleEvent()));
        $this->assertDatabaseCount('calendar_event_school_class', 1);
    }

    #[Test]
    public function an_event_may_have_several_turmas(): void
    {
        $first = $this->schoolClassFor($this->teacher, '7.º A');
        $second = $this->schoolClassFor($this->teacher, '7.º B');
        $third = $this->schoolClassFor($this->teacher, '7.º C');

        $this->asTeacher()
            ->post('/calendar/acontecimentos', $this->payload([
                'school_class_ulids' => [$first->ulid, $second->ulid, $third->ulid],
            ]))
            ->assertRedirect();

        $this->assertSame(['7.º A', '7.º B', '7.º C'], $this->classLabelsOf($this->soleEvent()));
        $this->assertDatabaseCount('calendar_event_school_class', 3);
    }

    /**
     * UMA TURMA DE UM COLEGA É RECUSADA, e não silenciosamente descartada.
     * Aceitar o pedido a fingir que correu bem, deixando a turma de fora, é a
     * forma mais rápida de o professor ficar convencido de que associou uma
     * turma que na verdade não associou.
     */
    #[Test]
    public function a_turma_the_teacher_does_not_teach_is_rejected_and_not_silently_dropped(): void
    {
        $mine = $this->schoolClassFor($this->teacher, 'Minha turma');

        $colleague = User::factory()->create();
        $this->organization->members()->attach($colleague, ['joined_at' => now()]);
        $theirs = $this->schoolClassFor($colleague, 'Turma do colega');

        $this->asTeacher()
            ->post('/calendar/acontecimentos', $this->payload([
                'school_class_ulids' => [$mine->ulid, $theirs->ulid],
            ]))
            ->assertSessionHasErrors('school_class_ulids.1');

        // Nem o acontecimento, nem a metade «válida» do pedido.
        $this->assertDatabaseCount('calendar_events', 0);
        $this->assertDatabaseCount('calendar_event_school_class', 0);
    }

    #[Test]
    public function a_turma_from_another_organization_is_rejected(): void
    {
        $stranger = User::factory()->create();
        $strangerOrganization = $stranger->personalOrganization();
        $this->subscribeToPro($strangerOrganization);
        $theirs = $this->schoolClassFor($stranger, 'Turma de outra organização', $strangerOrganization);

        $this->asTeacher()
            ->post('/calendar/acontecimentos', $this->payload([
                'school_class_ulids' => [$theirs->ulid],
            ]))
            ->assertSessionHasErrors('school_class_ulids.0');

        $this->assertDatabaseCount('calendar_events', 0);
    }

    // ------------------------------------------------------------- a edição

    #[Test]
    public function editing_changes_the_fields_and_the_whole_set_of_turmas(): void
    {
        $keep = $this->schoolClassFor($this->teacher, '7.º A');
        $drop = $this->schoolClassFor($this->teacher, '7.º B');
        $add = $this->schoolClassFor($this->teacher, '7.º C');

        $event = $this->eventFor($this->teacher, [
            'type' => CalendarEventType::Meeting,
            'title' => 'Antes',
            'starts_on' => '2026-10-15',
            'ends_on' => '2026-10-15',
        ], [$keep, $drop]);

        $this->assertSame(['7.º A', '7.º B'], $this->classLabelsOf($event));

        $this->asTeacher()
            ->put("/calendar/acontecimentos/{$event->ulid}", $this->payload([
                'type' => 'field_trip',
                'title' => 'Depois',
                'starts_on' => '2026-10-20',
                'ends_on' => '2026-10-22',
                'starts_at' => '09:00',
                'ends_at' => '17:00',
                'description' => 'Levar autorizações.',
                // 7.º B sai, 7.º C entra, 7.º A fica.
                'school_class_ulids' => [$keep->ulid, $add->ulid],
            ]))
            ->assertRedirect();

        $updated = $this->soleEvent();

        $this->assertSame(CalendarEventType::FieldTrip, $updated->type);
        $this->assertSame('Depois', $updated->title);
        $this->assertSame('2026-10-20', $updated->starts_on->toDateString());
        $this->assertSame('2026-10-22', $updated->ends_on->toDateString());
        $this->assertSame('09:00', substr((string) $updated->starts_at, 0, 5));
        $this->assertSame('17:00', substr((string) $updated->ends_at, 0, 5));
        $this->assertSame('Levar autorizações.', $updated->description);

        // A turma retirada foi mesmo retirada — sync, e não attach.
        $this->assertSame(['7.º A', '7.º C'], $this->classLabelsOf($updated));
        $this->assertDatabaseCount('calendar_event_school_class', 2);

        // E a posse nunca muda de mãos ao editar.
        $this->assertSame($this->teacher->id, $updated->user_id);
    }

    #[Test]
    public function editing_can_remove_every_turma_at_once(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher, '7.º A');
        $event = $this->eventFor($this->teacher, ['title' => 'Com turma'], [$schoolClass]);

        $this->asTeacher()
            ->put("/calendar/acontecimentos/{$event->ulid}", $this->payload([
                'title' => 'Sem turma',
                'school_class_ulids' => [],
            ]))
            ->assertRedirect();

        $this->assertSame([], $this->classLabelsOf($this->soleEvent()));
        $this->assertDatabaseCount('calendar_event_school_class', 0);
    }

    // ---------------------------------------------------------- a eliminação

    /**
     * A ASSERÇÃO QUE ESTE FICHEIRO EXISTE PARA FAZER. Eliminar um
     * acontecimento elimina um acontecimento — e as suas ligações às turmas,
     * que sem ele não querem dizer nada. As avaliações, a estrutura do ano e o
     * horário têm ciclos de vida inteiramente próprios, e é exatamente por
     * passarem a ver-se todos na mesma página que é fácil vir a tratá-los como
     * se fossem a mesma coisa. As contagens antes e depois são a prova de que
     * não são.
     */
    #[Test]
    public function deleting_an_event_touches_nothing_else_in_the_year(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher, '7.º C');
        $this->period('1.º Período', 1, '2026-09-01', '2026-12-18');
        $this->instrument($schoolClass, 'Teste de Frações', '2026-10-15');
        $this->recurringSlot($schoolClass);
        $this->lesson($schoolClass);

        $event = $this->eventFor($this->teacher, ['title' => 'Reunião'], [$schoolClass]);

        $before = [
            'instruments' => Instrument::withoutGlobalScope('organization')->count(),
            'academic_periods' => AcademicPeriod::withoutGlobalScope('organization')->count(),
            'lessons' => Lesson::withoutGlobalScope('organization')->count(),
            'recurring_lesson_slots' => RecurringLessonSlot::withoutGlobalScope('organization')->count(),
            'classes' => SchoolClass::withoutGlobalScope('organization')->count(),
        ];

        $this->asTeacher()
            ->delete("/calendar/acontecimentos/{$event->ulid}")
            ->assertRedirect();

        $this->assertDatabaseCount('calendar_events', 0);
        // A ligação à turma vai com ele; a turma, não.
        $this->assertDatabaseCount('calendar_event_school_class', 0);

        $this->assertSame($before['instruments'], Instrument::withoutGlobalScope('organization')->count());
        $this->assertSame($before['academic_periods'], AcademicPeriod::withoutGlobalScope('organization')->count());
        $this->assertSame($before['lessons'], Lesson::withoutGlobalScope('organization')->count());
        $this->assertSame($before['recurring_lesson_slots'], RecurringLessonSlot::withoutGlobalScope('organization')->count());
        $this->assertSame($before['classes'], SchoolClass::withoutGlobalScope('organization')->count());
    }

    // --------------------------------------------------- de quem, e de quem não

    /**
     * PESSOAL, E NÃO PARTILHADO. O acontecimento de um colega não é apenas
     * não-editável: é invisível. As duas metades estão aqui — a leitura não o
     * mostra, e as rotas recusam-no com 403.
     */
    #[Test]
    public function a_teacher_can_neither_see_nor_edit_nor_delete_a_colleagues_event(): void
    {
        $colleague = User::factory()->create();
        $this->organization->members()->attach($colleague, ['joined_at' => now()]);

        $theirs = $this->eventFor($colleague, [
            'title' => 'Reunião do colega',
            'starts_on' => '2026-10-15',
            'ends_on' => '2026-10-15',
        ]);

        // Invisível: não aparece no calendário deste professor.
        $this->asTeacher()->get('/calendar?month=2026-10')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $this->assertStringNotContainsString(
                'Reunião do colega',
                json_encode($page->toArray()['props'], JSON_THROW_ON_ERROR),
            ));

        // «Ver» não tem rota própria — tal como uma sequência de aulas não tem —
        // por isso a capacidade afirma-se onde ela vive: na política.
        $this->assertTrue(Gate::forUser($colleague)->allows('view', $theirs));
        $this->assertFalse(Gate::forUser($this->teacher)->allows('view', $theirs));

        $this->asTeacher()
            ->put("/calendar/acontecimentos/{$theirs->ulid}", $this->payload(['title' => 'Roubado']))
            ->assertForbidden();

        $this->asTeacher()
            ->delete("/calendar/acontecimentos/{$theirs->ulid}")
            ->assertForbidden();

        $this->assertDatabaseCount('calendar_events', 1);
        $this->assertSame('Reunião do colega', $this->soleEvent()->title);
    }

    #[Test]
    public function every_teacher_may_still_create_their_own(): void
    {
        $colleague = User::factory()->create();
        $this->organization->members()->attach($colleague, ['joined_at' => now()]);

        $this->actingAs($colleague)->withSession(['organization_id' => $this->organization->id])
            ->post('/calendar/acontecimentos', $this->payload(['title' => 'O meu']))
            ->assertRedirect();

        $this->assertDatabaseCount('calendar_events', 1);
        $this->assertSame($colleague->id, $this->soleEvent()->user_id);
    }

    #[Test]
    public function an_event_from_another_organization_is_invisible_and_unreachable(): void
    {
        $stranger = User::factory()->create();
        $strangerOrganization = $stranger->personalOrganization();
        $this->subscribeToPro($strangerOrganization);

        $theirs = app(CurrentOrganization::class)->runFor(
            $strangerOrganization,
            fn (): CalendarEvent => CalendarEvent::create([
                'user_id' => $stranger->id,
                'type' => CalendarEventType::Meeting,
                'title' => 'Reunião de outra organização',
                'starts_on' => '2026-10-15',
                'ends_on' => '2026-10-15',
            ]),
        );

        $this->asTeacher()->get('/calendar?month=2026-10')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $this->assertStringNotContainsString(
                'Reunião de outra organização',
                json_encode($page->toArray()['props'], JSON_THROW_ON_ERROR),
            ));

        // O ulid de outra organização não resolve sequer para um registo: o
        // global scope do modelo apanha-o na ligação de rota, e a resposta é um
        // 404 e não uma pista de que aquele registo existe algures.
        $this->asTeacher()
            ->put("/calendar/acontecimentos/{$theirs->ulid}", $this->payload(['title' => 'Roubado']))
            ->assertNotFound();

        $this->asTeacher()
            ->delete("/calendar/acontecimentos/{$theirs->ulid}")
            ->assertNotFound();

        $this->assertSame('Reunião de outra organização', $theirs->refresh()->title);
    }

    #[Test]
    public function impersonation_blocks_create_update_and_delete(): void
    {
        $event = $this->eventFor($this->teacher, ['title' => 'Original']);
        $session = [
            'organization_id' => $this->organization->id,
            'academic_year_id' => $this->academicYear->id,
            'impersonator_id' => 999,
        ];

        $this->actingAs($this->teacher)->withSession($session)
            ->post('/calendar/acontecimentos', $this->payload(['title' => 'Bloqueado']))
            ->assertForbidden();

        $this->actingAs($this->teacher)->withSession($session)
            ->put("/calendar/acontecimentos/{$event->ulid}", $this->payload(['title' => 'Bloqueado']))
            ->assertForbidden();

        $this->actingAs($this->teacher)->withSession($session)
            ->delete("/calendar/acontecimentos/{$event->ulid}")
            ->assertForbidden();

        $this->assertDatabaseCount('calendar_events', 1);
        $this->assertSame('Original', $this->soleEvent()->title);
    }

    #[Test]
    public function a_teacher_without_the_calendar_module_cannot_write_on_it(): void
    {
        $base = User::factory()->create();
        $baseOrganization = $base->personalOrganization();

        $this->actingAs($base)->withSession(['organization_id' => $baseOrganization->id])
            ->post('/calendar/acontecimentos', $this->payload())
            ->assertForbidden();

        $this->assertDatabaseCount('calendar_events', 0);
    }

    #[Test]
    public function a_guest_cannot_write_on_the_calendar(): void
    {
        $this->post('/calendar/acontecimentos', $this->payload())->assertRedirect('/login');

        $this->assertDatabaseCount('calendar_events', 0);
    }

    // ------------------------------------------------ o que o calendário mostra

    #[Test]
    public function an_event_appears_on_the_month_view_inside_its_range_and_nowhere_else(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher, '7.º C');

        $this->eventFor($this->teacher, [
            'type' => CalendarEventType::FieldTrip,
            'title' => 'Visita a Évora',
            'starts_on' => '2026-10-14',
            'ends_on' => '2026-10-16',
            'starts_at' => '09:00',
            'ends_at' => '17:00',
        ], [$schoolClass]);

        $this->asTeacher()->get('/calendar?month=2026-10')->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                // UM ACONTECIMENTO DE VÁRIOS DIAS COBRE cada um deles: é o que
                // está a acontecer na quarta, na quinta e na sexta.
                foreach (['2026-10-14', '2026-10-15', '2026-10-16'] as $date) {
                    $day = $this->dayOf($page, $date);

                    $this->assertCount(1, $day['events'], "Faltou o acontecimento em {$date}.");
                    $this->assertSame('Visita a Évora', $day['events'][0]['title']);
                    $this->assertSame('field_trip', $day['events'][0]['type']);
                    $this->assertSame('Visita de estudo', $day['events'][0]['type_label']);
                    $this->assertSame('VISITA', $day['events'][0]['type_short_label']);
                    $this->assertSame('09:00', $day['events'][0]['starts_at']);
                    $this->assertSame('17:00', $day['events'][0]['ends_at']);
                    $this->assertSame(
                        ['7.º C'],
                        array_column($day['events'][0]['school_classes'], 'label'),
                    );
                }

                // E em nenhum outro dia do mês.
                $this->assertSame([], $this->dayOf($page, '2026-10-13')['events']);
                $this->assertSame([], $this->dayOf($page, '2026-10-17')['events']);
            });

        // Um mês inteiramente fora do intervalo não o mostra de todo.
        $this->asTeacher()->get('/calendar?month=2026-12')->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                foreach ($page->toArray()['props']['days'] as $day) {
                    $this->assertSame([], $day['events']);
                }
            });
    }

    /**
     * O LIMITE DA GRELHA, EXATAMENTE — a mesma armadilha que já mordeu os
     * períodos e as avaliações na Fase 5.2. `starts_on`/`ends_on` são colunas
     * `date` guardadas como «Y-m-d 00:00:00»: comparadas como texto contra um
     * limite «Y-m-d», o acontecimento do último dia visível desaparecia sem
     * erro nenhum. Daí o `whereDate` dos dois lados, e daí este teste.
     */
    #[Test]
    public function an_event_touching_the_grid_on_its_very_first_or_last_day_still_appears(): void
    {
        // Outubro de 2026 abre a uma quinta-feira: a grelha vai de 28 de
        // setembro a 1 de novembro.
        $this->eventFor($this->teacher, [
            'title' => 'Acaba no primeiro dia visível',
            'starts_on' => '2026-09-20',
            'ends_on' => '2026-09-28',
        ]);
        $this->eventFor($this->teacher, [
            'title' => 'Começa no último dia visível',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-10',
        ]);
        $this->eventFor($this->teacher, [
            'title' => 'Mesmo fora, antes',
            'starts_on' => '2026-09-20',
            'ends_on' => '2026-09-27',
        ]);
        $this->eventFor($this->teacher, [
            'title' => 'Mesmo fora, depois',
            'starts_on' => '2026-11-02',
            'ends_on' => '2026-11-10',
        ]);

        $this->asTeacher()->get('/calendar?month=2026-10')->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $titles = [];
                foreach ($page->toArray()['props']['days'] as $day) {
                    $titles = [...$titles, ...array_column($day['events'], 'title')];
                }

                $this->assertContains('Acaba no primeiro dia visível', $titles);
                $this->assertContains('Começa no último dia visível', $titles);
                $this->assertNotContains('Mesmo fora, antes', $titles);
                $this->assertNotContains('Mesmo fora, depois', $titles);
            });
    }

    /**
     * O EXCESSO NUM DIA É UM SÓ MECANISMO. Um dia com duas avaliações e três
     * acontecimentos está exatamente tão cheio como um dia com cinco
     * avaliações — e o limite conta as duas espécies juntas, com um só «+N
     * mais», em vez de um segundo mecanismo de excesso ao lado do primeiro.
     */
    #[Test]
    public function a_crowded_day_counts_assessments_and_events_under_the_same_cap(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher, '7.º C');
        $this->instrument($schoolClass, 'Ficha A', '2026-10-15');
        $this->instrument($schoolClass, 'Ficha B', '2026-10-15');

        foreach (['Reunião A', 'Reunião B', 'Reunião C'] as $title) {
            $this->eventFor($this->teacher, [
                'title' => $title,
                'starts_on' => '2026-10-15',
                'ends_on' => '2026-10-15',
            ]);
        }

        $this->asTeacher()->get('/calendar?month=2026-10')->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $props = $page->toArray()['props'];
                $day = $this->dayOf($page, '2026-10-15');

                // Nada é descartado no servidor: o limite é uma decisão de
                // apresentação, e o «+N mais» tem de poder mostrar o que promete.
                $this->assertCount(2, $day['assessments']);
                $this->assertCount(3, $day['events']);
                $this->assertSame(3, $props['itemsPerDay']);
            });
    }

    #[Test]
    public function the_year_view_counts_events_by_the_months_they_cross(): void
    {
        $this->period('1.º Período', 1, '2026-09-01', '2026-12-18');

        $this->eventFor($this->teacher, [
            'title' => 'Reunião de outubro',
            'starts_on' => '2026-10-15',
            'ends_on' => '2026-10-15',
        ]);
        // Um acontecimento que atravessa a fronteira entre dois meses é uma
        // coisa que acontece nos dois, e conta-se nos dois.
        $this->eventFor($this->teacher, [
            'type' => CalendarEventType::FieldTrip,
            'title' => 'Visita entre meses',
            'starts_on' => '2026-10-30',
            'ends_on' => '2026-11-02',
        ]);

        $this->asTeacher()->get('/calendar/ano')->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $props = $page->toArray()['props'];
                $months = collect($props['months'])->keyBy('value');

                $this->assertSame(2, $props['eventsTotal']);
                $this->assertSame(2, $months['2026-10']['events_count']);
                $this->assertSame(1, $months['2026-11']['events_count']);
                $this->assertSame(0, $months['2026-09']['events_count']);

                // UMA SINOPSE, E NÃO UMA LISTA — a regra da Fase 5.2, intacta:
                // a vista de Ano conta e nunca nomeia.
                $this->assertStringNotContainsString(
                    'Reunião de outubro',
                    json_encode($props, JSON_THROW_ON_ERROR),
                );
                foreach ($props['months'] as $month) {
                    $this->assertArrayNotHasKey('events', $month);
                    $this->assertIsInt($month['events_count']);
                }
            });
    }

    #[Test]
    public function a_month_with_nothing_at_all_still_renders_a_real_grid(): void
    {
        $this->asTeacher()->get('/calendar?month=2026-10')->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $props = $page->toArray()['props'];

                $this->assertNotEmpty($props['days']);
                $this->assertSame(31, count(array_filter(
                    $props['days'],
                    fn (array $day): bool => $day['in_month'],
                )));

                foreach ($props['days'] as $day) {
                    $this->assertSame([], $day['events']);
                    $this->assertSame([], $day['assessments']);
                }
            });
    }

    /**
     * As turmas oferecidas ao formulário são as do professor, e só essas — a
     * mesma lista que CalendarEventRequest aceita, porque é a mesma pergunta
     * (SchoolClass::scopeTaughtBy) e não uma segunda cópia dela.
     */
    #[Test]
    public function the_month_view_offers_only_the_teachers_own_turmas_to_the_form(): void
    {
        $this->schoolClassFor($this->teacher, '7.º A');

        $colleague = User::factory()->create();
        $this->organization->members()->attach($colleague, ['joined_at' => now()]);
        $this->schoolClassFor($colleague, 'Turma do colega');

        $this->asTeacher()->get('/calendar?month=2026-10')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $this->assertSame(
                ['7.º A'],
                array_column($page->toArray()['props']['classes'], 'label'),
            ));
    }

    // --------------------------------------------------------------- helpers

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'type' => 'meeting',
            'title' => 'Um acontecimento',
            'starts_on' => '2026-10-15',
            'ends_on' => null,
            'starts_at' => null,
            'ends_at' => null,
            'description' => null,
            'school_class_ulids' => [],
            ...$overrides,
        ];
    }

    private function soleEvent(): CalendarEvent
    {
        return $this->inTenant(fn (): CalendarEvent => CalendarEvent::query()->with('schoolClasses')->sole());
    }

    /**
     * @return list<string>
     */
    private function classLabelsOf(CalendarEvent $event): array
    {
        return $this->inTenant(fn (): array => array_values(
            $event->schoolClasses()->pluck('label')->all(),
        ));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<SchoolClass>  $schoolClasses
     */
    private function eventFor(User $owner, array $attributes = [], array $schoolClasses = []): CalendarEvent
    {
        return $this->inTenant(function () use ($owner, $attributes, $schoolClasses): CalendarEvent {
            $event = CalendarEvent::create([
                'user_id' => $owner->getKey(),
                'type' => CalendarEventType::Meeting,
                'title' => 'Um acontecimento',
                'starts_on' => '2026-10-15',
                'ends_on' => '2026-10-15',
                ...$attributes,
            ]);

            if ($schoolClasses !== []) {
                $event->schoolClasses()->sync(array_map(
                    fn (SchoolClass $schoolClass): int => (int) $schoolClass->getKey(),
                    $schoolClasses,
                ));
            }

            return $event;
        });
    }

    private function asTeacher(): self
    {
        return $this->actingAs($this->teacher)->withSession([
            'organization_id' => $this->organization->id,
            'academic_year_id' => $this->academicYear->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function dayOf(AssertableInertia $page, string $date): array
    {
        $day = collect($page->toArray()['props']['days'])->firstWhere('date', $date);

        $this->assertNotNull($day, "A grelha não tem célula para {$date}.");

        return $day;
    }

    private function period(string $label, int $sequence, string $startsOn, string $endsOn): AcademicPeriod
    {
        return $this->inTenant(fn (): AcademicPeriod => AcademicPeriod::factory()
            ->recycle($this->organization)
            ->create([
                'academic_year_id' => $this->academicYear->getKey(),
                'label' => $label,
                'sequence' => $sequence,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
            ]));
    }

    private function instrument(SchoolClass $schoolClass, string $title, string $appliedOn): Instrument
    {
        return $this->inTenant(fn (): Instrument => Instrument::factory()
            ->recycle($this->organization)
            ->create([
                'class_id' => $schoolClass->getKey(),
                'academic_period_id' => $this->anchorPeriod()->getKey(),
                'instrument_type_id' => InstrumentType::withoutGlobalScope('typeVisibility')
                    ->firstOrCreate(
                        ['organization_id' => null, 'code' => 'TEST'],
                        ['name' => 'Teste global', 'default_purpose' => 'summative'],
                    )->id,
                'title' => $title,
                'applied_on' => $appliedOn,
            ]));
    }

    /**
     * Instrument.academic_period_id não aceita nulo, mas este calendário nunca
     * lê essa coluna — bandeia um dia pelo período do ANO que o cobre. Por isso
     * o período técnico fica estacionado bem longe de qualquer intervalo sob
     * teste, e com sequência 100 para não colidir com os que period() cria.
     */
    private function anchorPeriod(): AcademicPeriod
    {
        return $this->anchorPeriod ??= $this->inTenant(fn (): AcademicPeriod => AcademicPeriod::factory()
            ->recycle($this->organization)
            ->create([
                'academic_year_id' => $this->academicYear->getKey(),
                'label' => 'Período técnico (fora do calendário)',
                'sequence' => 100,
                'starts_on' => '2000-01-01',
                'ends_on' => '2000-06-30',
            ]));
    }

    private function recurringSlot(SchoolClass $schoolClass): RecurringLessonSlot
    {
        return $this->inTenant(fn (): RecurringLessonSlot => RecurringLessonSlot::create([
            'class_id' => $schoolClass->getKey(),
            'day_of_week' => 4,
            'starts_at' => '09:30',
            'ends_at' => '11:00',
        ]));
    }

    private function lesson(SchoolClass $schoolClass): Lesson
    {
        return $this->inTenant(fn (): Lesson => Lesson::create([
            'class_id' => $schoolClass->getKey(),
            'starts_at' => '2026-10-15 09:30:00',
            'ends_at' => null,
            'status' => LessonStatus::Preparation,
            'created_by' => $this->teacher->id,
        ]));
    }

    private function schoolClassFor(User $teacher, string $label, ?Organization $organization = null): SchoolClass
    {
        $organization ??= $this->organization;

        return app(CurrentOrganization::class)->runFor($organization, function () use ($organization, $teacher, $label): SchoolClass {
            $academicYear = $organization->is($this->organization)
                ? $this->academicYear
                : AcademicYear::factory()->recycle($organization)->create([
                    'starts_on' => '2026-09-01',
                    'ends_on' => '2027-07-31',
                ]);

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

    private function subscribeToPro(Organization $organization): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->id)
            ->delete();
        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->id,
            'plan_id' => Plan::query()->where('key', 'pro')->firstOrFail()->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::now()->subDay(),
        ]);
        app(Entitlements::class)->flush();
    }

    private function inTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->organization, $callback);
    }
}
