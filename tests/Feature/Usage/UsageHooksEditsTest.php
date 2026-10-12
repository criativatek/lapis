<?php

namespace Tests\Feature\Usage;

use App\Models\AcademicPeriod;
use App\Models\CalendarEvent;
use App\Models\Instrument;
use App\Models\InstrumentType;
use App\Models\Intervention;
use App\Models\Report;
use App\Models\UsageEvent;
use App\Services\Assessment\InstrumentBuilder;
use App\Support\Usage\UsageEventType;
use App\Support\Usage\UsageFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\Feature\Usage\Concerns\SetsUsageCoverage;
use Tests\TestCase;

/**
 * As edições reais (docs/usage-insights.md): relatório, medida, instrumento e
 * acontecimento. Cada uma conta UMA ação por registo e por dia, e só quando a
 * gravação mudou mesmo alguma coisa. Nenhuma é «registo útil».
 */
class UsageHooksEditsTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase, SetsUsageCoverage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-12 10:00:00', 'Europe/Lisbon'));
        $this->startUsageCollectionAt('2026-10-01 00:00:00');
        $this->bootLessonFixtures();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function eventsOf(UsageEventType $type): int
    {
        return UsageEvent::query()->where('event', $type->value)->count();
    }

    private function nextDay(): void
    {
        $this->travelTo(Carbon::parse('2026-10-13 10:00:00', 'Europe/Lisbon'));
    }

    // --- Relatórios --------------------------------------------------------

    private function makeReport(): Report
    {
        $period = $this->inTenant($this->organization, fn (): AcademicPeriod => AcademicPeriod::factory()->recycle($this->organization)->create([
            'academic_year_id' => $this->schoolClass->academic_year_id,
        ]));

        $this->asTeacher()->post('/reports', [
            'type' => 'class',
            'class_id' => $this->schoolClass->id,
            'academic_period_id' => $period->id,
        ])->assertRedirect();

        // A criação conta como registo útil; as edições partem de zero.
        UsageEvent::query()->delete();

        return $this->inTenant($this->organization, fn (): Report => Report::query()->latest('id')->firstOrFail());
    }

    #[Test]
    public function changing_a_report_is_one_action_that_is_not_a_useful_record(): void
    {
        $report = $this->makeReport();

        $this->asTeacher()->put("/reports/{$report->ulid}", ['title' => 'Título novo'])->assertRedirect();

        $event = UsageEvent::query()->sole();
        $this->assertSame(UsageEventType::ReportEdited, $event->event);
        $this->assertSame(UsageFeature::Reports, $event->feature);
        $this->assertSame(1, $event->item_count);
        $this->assertFalse($event->event->isUsefulRecord());
        $this->assertNull($event->event->milestone());
    }

    #[Test]
    public function saving_a_report_unchanged_records_nothing(): void
    {
        $report = $this->makeReport();

        $this->asTeacher()->put("/reports/{$report->ulid}", ['title' => $report->title, 'tone' => $report->tone->value])->assertRedirect();
        $this->asTeacher()->post("/reports/{$report->ulid}/gerar")->assertRedirect();

        $this->assertSame(0, UsageEvent::query()->count());
    }

    #[Test]
    public function repeating_a_report_change_counts_once_a_day_and_again_on_another_day(): void
    {
        $report = $this->makeReport();

        $this->asTeacher()->put("/reports/{$report->ulid}", ['title' => 'Primeiro'])->assertRedirect();
        $this->asTeacher()->put("/reports/{$report->ulid}", ['title' => 'Segundo'])->assertRedirect();
        $this->assertSame(1, $this->eventsOf(UsageEventType::ReportEdited));

        $this->nextDay();
        $this->asTeacher()->put("/reports/{$report->ulid}", ['title' => 'Terceiro'])->assertRedirect();
        $this->assertSame(2, $this->eventsOf(UsageEventType::ReportEdited));
    }

    #[Test]
    public function an_invalid_report_edit_and_a_support_session_record_nothing(): void
    {
        $report = $this->makeReport();

        $this->asTeacher()->put("/reports/{$report->ulid}", ['title' => str_repeat('a', 201)])
            ->assertSessionHasErrors('title');

        $this->asTeacher()->withSession(['impersonator_id' => 999])
            ->put("/reports/{$report->ulid}", ['title' => 'Título do suporte']);

        $this->assertSame(0, UsageEvent::query()->count());
    }

    #[Test]
    public function editing_regenerating_restoring_and_reordering_sections_each_count_when_something_changed(): void
    {
        $report = $this->makeReport();
        $sections = $this->inTenant($this->organization, fn () => $report->sections()->orderBy('position')->get());
        $section = $sections->firstWhere('key', 'overall_assessment') ?? $sections->first();

        // Editar o texto.
        $this->asTeacher()->put("/reports/{$report->ulid}/seccoes/{$section->ulid}", ['body' => 'Texto do professor.'])->assertRedirect();
        $this->assertSame(1, $this->eventsOf(UsageEventType::ReportEdited));

        // O mesmo texto outra vez, noutro dia: não mudou, não conta.
        $this->nextDay();
        $this->asTeacher()->put("/reports/{$report->ulid}/seccoes/{$section->ulid}", ['body' => 'Texto do professor.'])->assertRedirect();
        $this->assertSame(1, $this->eventsOf(UsageEventType::ReportEdited));

        // Repor o texto automático muda o corpo: conta (dia novo).
        $this->asTeacher()->post("/reports/{$report->ulid}/seccoes/{$section->ulid}/restaurar")->assertRedirect();
        $this->assertSame(2, $this->eventsOf(UsageEventType::ReportEdited));

        // Repor outra vez, já reposto: não muda.
        $this->travelTo(Carbon::parse('2026-10-14 10:00:00', 'Europe/Lisbon'));
        $this->asTeacher()->post("/reports/{$report->ulid}/seccoes/{$section->ulid}/restaurar")->assertRedirect();
        $this->assertSame(2, $this->eventsOf(UsageEventType::ReportEdited));

        // Escrever e regenerar (o texto volta ao automático): conta.
        $this->asTeacher()->put("/reports/{$report->ulid}/seccoes/{$section->ulid}", ['body' => 'Outra vez meu.'])->assertRedirect();
        $this->travelTo(Carbon::parse('2026-10-15 10:00:00', 'Europe/Lisbon'));
        $this->asTeacher()->post("/reports/{$report->ulid}/seccoes/{$section->ulid}/gerar")->assertRedirect();
        $this->assertSame(4, $this->eventsOf(UsageEventType::ReportEdited));

        // Reordenar: a mesma ordem não conta, uma ordem diferente conta.
        $this->travelTo(Carbon::parse('2026-10-16 10:00:00', 'Europe/Lisbon'));
        $order = $sections->pluck('ulid')->all();
        $this->asTeacher()->put("/reports/{$report->ulid}/seccoes/ordem", ['order' => $order])->assertRedirect();
        $this->assertSame(4, $this->eventsOf(UsageEventType::ReportEdited));

        $this->asTeacher()->put("/reports/{$report->ulid}/seccoes/ordem", ['order' => array_reverse($order)])->assertRedirect();
        $this->assertSame(5, $this->eventsOf(UsageEventType::ReportEdited));
    }

    #[Test]
    public function a_missing_usage_table_does_not_stop_a_report_edit(): void
    {
        $report = $this->makeReport();

        Schema::drop('usage_events');

        $this->asTeacher()->put("/reports/{$report->ulid}", ['title' => 'Continua a gravar'])->assertRedirect();

        $this->assertSame('Continua a gravar', $this->inTenant($this->organization, fn () => $report->fresh()->title));
    }

    // --- Medidas -----------------------------------------------------------

    private function makeIntervention(): Intervention
    {
        $enrollment = $this->enroll('Aluno Medida');

        $this->asTeacher()->postJson("/classes/{$this->schoolClass->ulid}/interventions", [
            'target_type' => 'student',
            'enrollment_ids' => [$enrollment->id],
            'intervention_types' => ['pedagogical_differentiation'],
            'domain_relation' => 'none',
            'description' => 'Descrição inicial.',
            'started_on' => '2026-10-01',
            'available_for_reports' => true,
        ])->assertRedirect();

        UsageEvent::query()->delete();

        return $this->inTenant($this->organization, fn (): Intervention => Intervention::query()->firstOrFail());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function interventionPayload(Intervention $intervention, array $overrides = []): array
    {
        return [
            'target_type' => 'student',
            'enrollment_ids' => [$intervention->enrollment_id],
            'intervention_type' => 'pedagogical_differentiation',
            'domain_relation' => 'none',
            'description' => 'Descrição inicial.',
            'started_on' => '2026-10-01',
            'available_for_reports' => true,
            ...$overrides,
        ];
    }

    #[Test]
    public function changing_an_intervention_is_one_action_and_the_same_save_is_none(): void
    {
        $intervention = $this->makeIntervention();

        $this->asTeacher()->putJson("/interventions/{$intervention->ulid}", $this->interventionPayload($intervention))->assertRedirect();
        $this->assertSame(0, UsageEvent::query()->count());

        $this->asTeacher()->putJson("/interventions/{$intervention->ulid}", $this->interventionPayload($intervention, ['description' => 'Descrição mudada.']))->assertRedirect();

        $event = UsageEvent::query()->sole();
        $this->assertSame(UsageEventType::InterventionUpdated, $event->event);
        $this->assertSame(UsageFeature::Interventions, $event->feature);
        $this->assertFalse($event->event->isUsefulRecord());

        // Repetir com alteração no mesmo dia: continua uma linha; noutro dia, duas.
        $this->asTeacher()->putJson("/interventions/{$intervention->ulid}", $this->interventionPayload($intervention, ['description' => 'Mudada de novo.']))->assertRedirect();
        $this->assertSame(1, $this->eventsOf(UsageEventType::InterventionUpdated));

        $this->nextDay();
        $this->asTeacher()->putJson("/interventions/{$intervention->ulid}", $this->interventionPayload($intervention, ['description' => 'E ainda outra.']))->assertRedirect();
        $this->assertSame(2, $this->eventsOf(UsageEventType::InterventionUpdated));
    }

    #[Test]
    public function changing_the_status_of_an_intervention_counts_only_when_it_changes(): void
    {
        $intervention = $this->makeIntervention();

        $this->asTeacher()->patchJson("/interventions/{$intervention->ulid}", ['status' => 'new'])->assertRedirect();
        $this->assertSame(0, $this->eventsOf(UsageEventType::InterventionUpdated));

        $this->asTeacher()->patchJson("/interventions/{$intervention->ulid}", ['status' => 'concluded'])->assertRedirect();
        $this->assertSame(1, $this->eventsOf(UsageEventType::InterventionUpdated));
    }

    #[Test]
    public function creating_an_intervention_and_editing_it_the_same_day_is_one_action(): void
    {
        $enrollment = $this->enroll('Aluno Medida');
        $this->asTeacher()->postJson("/classes/{$this->schoolClass->ulid}/interventions", [
            'target_type' => 'student',
            'enrollment_ids' => [$enrollment->id],
            'intervention_types' => ['pedagogical_differentiation'],
            'domain_relation' => 'none',
            'description' => 'Descrição inicial.',
            'started_on' => '2026-10-01',
            'available_for_reports' => true,
        ])->assertRedirect();
        $intervention = $this->inTenant($this->organization, fn (): Intervention => Intervention::query()->firstOrFail());

        $this->asTeacher()->putJson("/interventions/{$intervention->ulid}", $this->interventionPayload($intervention, ['description' => 'Corrigida.']))->assertRedirect();
        $this->asTeacher()->patchJson("/interventions/{$intervention->ulid}", ['status' => 'concluded'])->assertRedirect();

        // Uma só ação no dia, com o tipo da criação (registo útil).
        $event = UsageEvent::query()->where('kind', 'action')->sole();
        $this->assertSame(UsageEventType::InterventionCreated, $event->event);

        // Cada acompanhamento é um registo novo: dois no mesmo dia são duas ações.
        foreach (['2026-10-12', '2026-10-12'] as $day) {
            $this->asTeacher()->postJson("/interventions/{$intervention->ulid}/reviews", [
                'reviewed_on' => $day, 'effectiveness' => null, 'notes' => null,
            ])->assertRedirect();
        }
        $this->assertSame(2, $this->eventsOf(UsageEventType::InterventionFollowupAdded));
    }

    #[Test]
    public function an_invalid_intervention_edit_and_a_support_session_record_nothing(): void
    {
        $intervention = $this->makeIntervention();

        $this->asTeacher()->putJson("/interventions/{$intervention->ulid}", $this->interventionPayload($intervention, ['started_on' => 'não é data']))
            ->assertUnprocessable();

        $this->asTeacher()->withSession(['impersonator_id' => 999])
            ->putJson("/interventions/{$intervention->ulid}", $this->interventionPayload($intervention, ['description' => 'Pelo suporte.']));

        $this->assertSame(0, UsageEvent::query()->count());
    }

    #[Test]
    public function a_missing_usage_table_does_not_stop_an_intervention_edit(): void
    {
        $intervention = $this->makeIntervention();

        Schema::drop('usage_events');

        $this->asTeacher()->putJson("/interventions/{$intervention->ulid}", $this->interventionPayload($intervention, ['description' => 'Gravada mesmo assim.']))
            ->assertRedirect();

        $this->assertSame('Gravada mesmo assim.', $this->inTenant($this->organization, fn () => $intervention->fresh()->description));
    }

    // --- Instrumentos ------------------------------------------------------

    private function makeInstrument(): Instrument
    {
        return $this->inTenant($this->organization, function (): Instrument {
            $period = AcademicPeriod::factory()->recycle($this->organization)->create([
                'academic_year_id' => $this->schoolClass->academic_year_id,
            ]);

            return app(InstrumentBuilder::class)->create($this->schoolClass, [
                'academic_period_id' => $period->id,
                'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
                'title' => 'Teste',
                'applied_on' => '2026-10-15',
                'status' => 'prepared',
                'counts_toward_classification' => true,
                'purpose' => 'summative',
                'total_points' => 100,
            ], [
                ['code' => 'Q1', 'points_possible' => 60],
                ['code' => 'Q2', 'points_possible' => 40],
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  list<array<string, mixed>>|null  $items
     * @return array<string, mixed>
     */
    private function instrumentPayload(Instrument $instrument, array $overrides = [], ?array $items = null): array
    {
        return $this->inTenant($this->organization, function () use ($instrument, $overrides, $items): array {
            $group = $instrument->groups()->orderBy('sequence')->firstOrFail();

            return [
                'title' => $instrument->title,
                'academic_period_id' => $instrument->academic_period_id,
                'instrument_type_id' => $instrument->instrument_type_id,
                'applied_on' => $instrument->applied_on->toDateString(),
                'submission_intent' => 'prepare',
                'purpose' => $instrument->purpose,
                'counts_toward_classification' => true,
                'total_points' => 100,
                'allow_bonus' => false,
                'groups' => [['ulid' => $group->ulid, 'label' => $group->label]],
                'items' => $items ?? $instrument->items()->orderBy('sequence')->get()->map(fn ($item): array => [
                    'ulid' => $item->ulid,
                    'group_index' => 0,
                    'code' => $item->code,
                    'label' => $item->label,
                    'points_possible' => (float) $item->points_possible,
                    'is_bonus' => $item->is_bonus,
                    'domains' => [],
                ])->all(),
                ...$overrides,
            ];
        });
    }

    #[Test]
    public function changing_the_header_or_the_items_of_an_instrument_is_one_action_and_resaving_is_none(): void
    {
        $instrument = $this->makeInstrument();

        $this->asTeacher()->put("/instruments/{$instrument->ulid}", $this->instrumentPayload($instrument))->assertRedirect();
        $this->assertSame(0, UsageEvent::query()->count());

        $this->asTeacher()->put("/instruments/{$instrument->ulid}", $this->instrumentPayload($instrument, ['title' => 'Teste renomeado']))->assertRedirect();

        $event = UsageEvent::query()->sole();
        $this->assertSame(UsageEventType::InstrumentUpdated, $event->event);
        $this->assertSame(UsageFeature::Assessment, $event->feature);
        $this->assertFalse($event->event->isUsefulRecord());
        $this->assertSame(1, $event->item_count);

        // Só um item mudou (os pontos): conta noutro dia.
        $this->nextDay();
        $instrument->refresh();
        $items = $this->inTenant($this->organization, fn () => $instrument->items()->orderBy('sequence')->get()->map(fn ($item, $index): array => [
            'ulid' => $item->ulid,
            'group_index' => 0,
            'code' => $item->code,
            'label' => $item->label,
            'points_possible' => $index === 0 ? 70.0 : (float) $item->points_possible,
            'is_bonus' => $item->is_bonus,
            'domains' => [],
        ])->all());
        $this->asTeacher()->put("/instruments/{$instrument->ulid}", $this->instrumentPayload($instrument, ['total_points' => 110], $items))->assertRedirect();

        $this->assertSame(2, $this->eventsOf(UsageEventType::InstrumentUpdated));
    }

    #[Test]
    public function an_instrument_edit_that_the_builder_refuses_and_a_support_session_record_nothing(): void
    {
        $instrument = $this->makeInstrument();

        // Sem itens: o construtor recusa.
        $this->asTeacher()->put("/instruments/{$instrument->ulid}", $this->instrumentPayload($instrument, ['title' => 'Recusado'], []))
            ->assertSessionHasErrors();

        $this->asTeacher()->withSession(['impersonator_id' => 999])
            ->put("/instruments/{$instrument->ulid}", $this->instrumentPayload($instrument, ['title' => 'Pelo suporte']));

        $this->assertSame(0, UsageEvent::query()->count());
    }

    // --- Acontecimentos ----------------------------------------------------

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function eventPayload(array $overrides = []): array
    {
        return [
            'type' => 'meeting',
            'title' => 'Reunião',
            'starts_on' => '2026-10-15',
            'ends_on' => null,
            'starts_at' => null,
            'ends_at' => null,
            'description' => null,
            'school_class_ulids' => [],
            ...$overrides,
        ];
    }

    private function makeCalendarEvent(): CalendarEvent
    {
        $this->asTeacher()->post('/calendar/acontecimentos', $this->eventPayload())->assertRedirect();

        UsageEvent::query()->delete();

        return $this->inTenant($this->organization, fn (): CalendarEvent => CalendarEvent::query()->firstOrFail());
    }

    #[Test]
    public function changing_a_calendar_event_or_its_classes_is_one_action_and_resaving_is_none(): void
    {
        $event = $this->makeCalendarEvent();

        $this->asTeacher()->put("/calendar/acontecimentos/{$event->ulid}", $this->eventPayload())->assertRedirect();
        $this->assertSame(0, UsageEvent::query()->count());

        $this->asTeacher()->put("/calendar/acontecimentos/{$event->ulid}", $this->eventPayload(['title' => 'Reunião adiada']))->assertRedirect();

        $row = UsageEvent::query()->sole();
        $this->assertSame(UsageEventType::CalendarEventUpdated, $row->event);
        $this->assertSame(UsageFeature::Calendar, $row->feature);
        $this->assertFalse($row->event->isUsefulRecord());

        // Mesmo dia, outra alteração: continua uma linha.
        $this->asTeacher()->put("/calendar/acontecimentos/{$event->ulid}", $this->eventPayload(['title' => 'Reunião cancelada']))->assertRedirect();
        $this->assertSame(1, $this->eventsOf(UsageEventType::CalendarEventUpdated));

        // Noutro dia, só as turmas associadas mudam.
        $this->nextDay();
        $this->asTeacher()->put("/calendar/acontecimentos/{$event->ulid}", $this->eventPayload([
            'title' => 'Reunião cancelada',
            'school_class_ulids' => [$this->schoolClass->ulid],
        ]))->assertRedirect();
        $this->assertSame(2, $this->eventsOf(UsageEventType::CalendarEventUpdated));
    }

    #[Test]
    public function an_invalid_calendar_event_edit_and_a_support_session_record_nothing(): void
    {
        $event = $this->makeCalendarEvent();

        $this->asTeacher()->put("/calendar/acontecimentos/{$event->ulid}", $this->eventPayload(['title' => '']))
            ->assertSessionHasErrors('title');

        $this->asTeacher()->withSession(['impersonator_id' => 999])
            ->put("/calendar/acontecimentos/{$event->ulid}", $this->eventPayload(['title' => 'Pelo suporte']))
            ->assertForbidden();

        $this->assertSame(0, UsageEvent::query()->count());
    }

    #[Test]
    public function the_catalogue_lists_the_four_new_edits_as_actions_that_are_not_useful_records(): void
    {
        foreach ([
            UsageEventType::ReportEdited,
            UsageEventType::InterventionUpdated,
            UsageEventType::InstrumentUpdated,
            UsageEventType::CalendarEventUpdated,
        ] as $type) {
            $this->assertNotNull($type->feature());
            $this->assertFalse($type->isUsefulRecord());
            $this->assertNull($type->milestone());
            $this->assertNotSame('', $type->label());
        }
    }
}
