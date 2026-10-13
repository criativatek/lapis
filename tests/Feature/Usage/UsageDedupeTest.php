<?php

namespace Tests\Feature\Usage;

use App\Models\Enrollment;
use App\Models\Instrument;
use App\Models\Report;
use App\Models\SchoolClass;
use App\Models\UsageDailyRollup;
use App\Models\UsageEvent;
use App\Models\User;
use App\Services\Usage\AggregateUsage;
use App\Support\Usage\UsageCoverage;
use App\Support\Usage\UsageEventType;
use App\Support\Usage\UsageFeature;
use App\Support\Usage\UsageRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Usage\Insights\InsightsTestCase;

/**
 * «Uma ação por registo e por dia» (docs/usage-insights.md, «Ação concluída»):
 * uma só contagem por pessoa, organização, registo e dia, seja criação ou
 * edição — e nunca a fusão de registos diferentes que partilhem um id.
 */
class UsageDedupeTest extends InsightsTestCase
{
    private const MODELS = [
        'report' => Report::class,
        'instrument' => Instrument::class,
        'class' => SchoolClass::class,
        'enrollment' => Enrollment::class,
    ];

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = $this->person('Professora');

        // Regista uma ação sobre um registo, sem tocar em dados pedagógicos:
        // o registo é um modelo por gravar, com o id que o teste escolhe.
        Route::middleware(['web', 'auth', 'organization'])->post('/_dedupe', function (Request $request, UsageRecorder $recorder) {
            $recorder->action(
                UsageEventType::from((string) $request->input('type')),
                $this->subject((string) $request->input('model'), (int) $request->input('id')),
            );

            return response('ok');
        });

        // Dois pedidos que preparam o mesmo registo e só escrevem depois do
        // commit — a corrida entre eles é decidida pela base de dados.
        Route::middleware(['web', 'auth', 'organization'])->post('/_dedupe/race', function () {
            DB::transaction(function (): void {
                foreach ([new UsageRecorder(new UsageCoverage), new UsageRecorder(new UsageCoverage)] as $request) {
                    $request->action(UsageEventType::ReportEdited, $this->subject('report', 5));
                }
            });

            return response('ok');
        });
    }

    private function subject(string $model, int $id): Model
    {
        $class = self::MODELS[$model];
        $subject = new $class;
        $subject->setAttribute($subject->getKeyName(), $id);

        return $subject;
    }

    private function act(UsageEventType $type, string $model, int $id, ?User $as = null): void
    {
        $as ??= $this->teacher;

        $this->actingAs($as)
            ->withSession(['organization_id' => $as->personalOrganization()->id])
            ->post('/_dedupe', ['type' => $type->value, 'model' => $model, 'id' => $id])
            ->assertOk();
    }

    private function actions(): int
    {
        return UsageEvent::query()->where('kind', 'action')->count();
    }

    #[Test]
    public function creating_and_editing_the_same_record_several_times_in_a_day_is_one_action(): void
    {
        $this->act(UsageEventType::ReportCreated, 'report', 5);
        $this->travelTo(Carbon::parse('2026-10-12 11:00:00', 'Europe/Lisbon'));
        $this->act(UsageEventType::ReportEdited, 'report', 5);
        $this->act(UsageEventType::ReportEdited, 'report', 5);
        $this->travelTo(Carbon::parse('2026-10-12 23:59:00', 'Europe/Lisbon'));
        $this->act(UsageEventType::ReportFinalized, 'report', 5);

        $this->assertSame(1, $this->actions());
        // A ação do dia fica com o tipo da primeira operação: a criação, que é
        // registo útil — a edição a seguir não o apaga.
        $this->assertSame(UsageEventType::ReportCreated, UsageEvent::query()->sole()->event);
    }

    #[Test]
    public function an_edit_the_next_day_is_a_new_action(): void
    {
        $this->act(UsageEventType::ReportCreated, 'report', 5);
        $this->act(UsageEventType::ReportEdited, 'report', 5);

        $this->travelTo(Carbon::parse('2026-10-13 00:00:01', 'Europe/Lisbon'));
        $this->act(UsageEventType::ReportEdited, 'report', 5);
        $this->act(UsageEventType::ReportEdited, 'report', 5);

        $this->assertSame(2, $this->actions());
        $this->assertSame(['2026-10-12', '2026-10-13'], UsageEvent::query()->orderBy('occurred_on')->get()->map(fn (UsageEvent $event) => $event->occurred_on->toDateString())->all());
    }

    #[Test]
    public function the_same_record_edited_by_different_people_counts_for_each(): void
    {
        $colleague = $this->person('Colega');

        $this->act(UsageEventType::ReportEdited, 'report', 5);
        $this->act(UsageEventType::ReportEdited, 'report', 5, $colleague);

        $this->assertSame(2, $this->actions());
        $this->assertEqualsCanonicalizing([$this->teacher->id, $colleague->id], UsageEvent::query()->pluck('user_id')->all());
    }

    #[Test]
    public function different_records_that_share_an_id_never_merge(): void
    {
        $this->act(UsageEventType::ReportCreated, 'report', 5);
        $this->act(UsageEventType::InstrumentCreated, 'instrument', 5);
        // As classificações do instrumento 5 são outro registo que o instrumento 5.
        $this->act(UsageEventType::ScoresRecorded, 'instrument', 5);
        $this->act(UsageEventType::ClassCreated, 'class', 5);
        // A caracterização da turma 5 e a do aluno (matrícula) 5: dois registos.
        $this->act(UsageEventType::CharacterisationUpdated, 'class', 5);
        $this->act(UsageEventType::CharacterisationUpdated, 'enrollment', 5);

        $this->assertSame(6, $this->actions());
    }

    #[Test]
    public function two_requests_racing_on_the_same_record_write_one_row(): void
    {
        $this->actingAs($this->teacher)
            ->withSession(['organization_id' => $this->teacher->personalOrganization()->id])
            ->post('/_dedupe/race')
            ->assertOk();

        $this->assertSame(1, $this->actions());
    }

    #[Test]
    public function the_database_itself_refuses_a_second_row_with_the_same_identity(): void
    {
        $this->act(UsageEventType::ReportCreated, 'report', 5);
        $row = (array) DB::table('usage_events')->first();
        unset($row['id']);

        $this->expectException(QueryException::class);
        DB::table('usage_events')->insert($row);
    }

    #[Test]
    public function the_aggregates_and_the_ranking_count_by_the_same_definition(): void
    {
        $this->act(UsageEventType::ReportCreated, 'report', 5);
        $this->act(UsageEventType::ReportEdited, 'report', 5);
        $this->act(UsageEventType::ReportEdited, 'report', 5);
        $this->travelTo(Carbon::parse('2026-10-13 09:00:00', 'Europe/Lisbon'));
        $this->act(UsageEventType::ReportEdited, 'report', 5);

        app(AggregateUsage::class)->run(CarbonImmutable::parse('2026-10-13 09:15:00', 'Europe/Lisbon'));

        $rollups = UsageDailyRollup::query()->where('user_id', $this->teacher->id)->orderBy('day')->get();
        $this->assertSame([1, 1], $rollups->pluck('actions')->all());
        // Dia 12: a criação (registo útil). Dia 13: uma edição (não é).
        $this->assertSame([1, 0], $rollups->pluck('useful_actions')->all());
        $this->assertSame([UsageFeature::Reports, UsageFeature::Reports], $rollups->pluck('feature')->all());

        $row = collect($this->props('admin.usage.ranking', ['sort' => 'actions'])['ranking']['data'])->firstWhere('name', 'Professora');
        $this->assertSame(2, $row['actions']);
        $this->assertSame(2, $row['active_days']);
    }

    #[Test]
    public function the_catalogue_says_which_event_types_share_a_record(): void
    {
        $groups = [];
        foreach (UsageEventType::cases() as $type) {
            if ($type->record() !== null) {
                $groups[$type->record()][] = $type;
            }
        }

        $shared = array_filter($groups, fn (array $types): bool => count($types) > 1);
        ksort($shared);

        // Os únicos registos com mais de um tipo de evento. Mudar isto é uma
        // decisão sobre o que conta como «a mesma ação»: atualizar a
        // documentação (docs/usage-insights.md) e este teste em conjunto.
        $this->assertSame([
            'calendar_event' => [UsageEventType::CalendarEventCreated, UsageEventType::CalendarEventUpdated],
            'instrument' => [UsageEventType::InstrumentCreated, UsageEventType::InstrumentUpdated],
            'intervention' => [UsageEventType::InterventionCreated, UsageEventType::InterventionUpdated],
            'lesson' => [UsageEventType::LessonTaught, UsageEventType::LessonOutcomeRecorded],
            'notebook_entry' => [UsageEventType::NotebookEntryCreated, UsageEventType::NotebookEntryUpdated],
            'report' => [UsageEventType::ReportCreated, UsageEventType::ReportFinalized, UsageEventType::ReportEdited],
        ], array_map(fn (array $types): array => array_values($types), $shared));

        foreach ($shared as $record => $types) {
            // Um registo pertence a uma funcionalidade.
            $this->assertCount(1, array_unique(array_map(fn (UsageEventType $type): string => $type->feature()->value, $types)), $record);

            // Num registo partilhado, o único tipo que pode ser registo útil ou
            // marco é o de criação — que vem sempre primeiro no dia, e é dele
            // que a ação do dia fica com o tipo.
            $significant = array_values(array_filter($types, fn (UsageEventType $type): bool => $type->milestone() !== null));
            $this->assertLessThanOrEqual(1, count($significant), $record);
            if ($significant !== []) {
                $this->assertStringEndsWith('created', $significant[0]->value, $record);
            }
        }
    }
}
