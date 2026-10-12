<?php

namespace Tests\Feature\Usage;

use App\Models\Enrollment;
use App\Models\UsageDailyRollup;
use App\Models\UsageEvent;
use App\Models\UsageUserState;
use App\Services\Usage\AggregateUsage;
use App\Support\Usage\UsageEventType;
use App\Support\Usage\UsageFeature;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\Feature\Usage\Concerns\SetsUsageCoverage;
use Tests\TestCase;

/**
 * A reimportação da lista de alunos e os outros lotes discriminam o que lhes
 * aconteceu: criados, alterados e inalterados (docs/usage-insights.md). Uma
 * ação por lote; `item_count` são os elementos efetivamente alterados.
 */
class UsageHooksBatchBreakdownTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase, SetsUsageCoverage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-12 10:00:00', 'Europe/Lisbon'));
        $this->startUsageCollectionAt('2026-10-01 00:00:00');
        $this->bootLessonFixtures();
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function row(string $name, ?Enrollment $existing = null, array $overrides = []): array
    {
        return [
            'name' => $name,
            'class_number' => null,
            'birth_date' => null,
            'situation_code' => null,
            'note' => null,
            'process_number' => null,
            'photo_index' => null,
            'photo_extension' => null,
            'include' => true,
            'enrollment_id' => $existing?->id,
            ...$overrides,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function confirm(array $rows, ?string $token = null): TestResponse
    {
        return $this->asTeacher()->post(
            "/classes/{$this->schoolClass->ulid}/roster-imports/".($token ?? Str::uuid()).'/confirm',
            ['rows' => $rows],
        );
    }

    private function onlyImport(): UsageEvent
    {
        return UsageEvent::query()->where('event', UsageEventType::StudentsImported->value)->sole();
    }

    private function importCount(): int
    {
        return UsageEvent::query()->where('event', UsageEventType::StudentsImported->value)->count();
    }

    #[Test]
    public function a_mixed_reimport_is_one_action_with_created_changed_and_unchanged(): void
    {
        $changed = $this->enroll('Nome Antigo');
        $same = [$this->enroll('Igual Um'), $this->enroll('Igual Dois'), $this->enroll('Igual Três')];

        $this->confirm([
            $this->row('Novo Um'),
            $this->row('Novo Dois'),
            $this->row('Nome Corrigido', $changed),
            ...array_map(fn (Enrollment $enrollment): array => $this->row($enrollment->student->identity->display_name, $enrollment), $same),
        ])->assertRedirect("/classes/{$this->schoolClass->ulid}");

        $event = $this->onlyImport();
        $this->assertSame(3, $event->item_count);
        $this->assertSame(2, $event->items_created);
        $this->assertSame(1, $event->items_changed);
        $this->assertSame(3, $event->items_unchanged);
        $this->assertSame(UsageFeature::ImportExport, $event->feature);
    }

    #[Test]
    public function a_reimport_with_only_unchanged_rows_is_not_an_action(): void
    {
        $one = $this->enroll('Igual Um');
        $two = $this->enroll('Igual Dois', ['class_number' => 4]);

        $this->confirm([
            $this->row('Igual Um', $one),
            $this->row('Igual Dois', $two, ['class_number' => 4]),
        ])->assertRedirect();

        $this->assertSame(0, UsageEvent::query()->count());
    }

    #[Test]
    public function a_reimport_with_only_changes_is_one_action_with_no_students_created(): void
    {
        $one = $this->enroll('Nome Um');
        $two = $this->enroll('Nome Dois');

        $this->confirm([
            $this->row('Nome Um', $one, ['class_number' => 7]),
            $this->row('Nome Dois', $two, ['process_number' => '12345', 'birth_date' => '2012-05-03']),
        ])->assertRedirect();

        $event = $this->onlyImport();
        $this->assertSame(2, $event->item_count);
        $this->assertSame(0, $event->items_created);
        $this->assertSame(2, $event->items_changed);
        $this->assertSame(0, $event->items_unchanged);
    }

    #[Test]
    public function a_status_change_from_the_roll_counts_as_a_change(): void
    {
        $enrollment = $this->enroll('Aluno Saiu');

        // «MT» — mudou de turma: o estado administrativo muda.
        $this->confirm([$this->row('Aluno Saiu', $enrollment, ['situation_code' => 'MT'])])->assertRedirect();

        $this->assertSame(1, $this->onlyImport()->items_changed);
    }

    #[Test]
    public function a_reimport_with_only_changes_does_not_mark_the_first_students_milestone(): void
    {
        $enrollment = $this->enroll('Nome Antigo');

        $this->confirm([$this->row('Nome Novo', $enrollment)])->assertRedirect();

        app(AggregateUsage::class)->run(CarbonImmutable::parse('2026-10-13 10:00:00', 'Europe/Lisbon'));

        $state = UsageUserState::query()->where('user_id', $this->teacher->id)->sole();
        $this->assertNull($state->first_students_added_on);

        // Com alunos criados, o marco é marcado.
        $this->confirm([$this->row('Aluno Realmente Novo')])->assertRedirect();
        app(AggregateUsage::class)->run(CarbonImmutable::parse('2026-10-13 10:00:00', 'Europe/Lisbon'));

        $this->assertSame('2026-10-12', UsageUserState::query()->where('user_id', $this->teacher->id)->sole()->first_students_added_on?->toDateString());
    }

    #[Test]
    public function repeating_the_same_import_the_same_day_is_still_one_row_and_another_day_is_two(): void
    {
        $enrollment = $this->enroll('Nome Antigo');
        $token = (string) Str::uuid();

        $this->confirm([$this->row('Nome Segundo', $enrollment)], $token)->assertRedirect();
        $this->confirm([$this->row('Nome Terceiro', $enrollment)], $token)->assertRedirect();

        $this->assertSame(1, $this->importCount());

        $this->travelTo(Carbon::parse('2026-10-13 10:00:00', 'Europe/Lisbon'));
        $this->confirm([$this->row('Nome Quarto', $enrollment)], $token)->assertRedirect();

        $this->assertSame(2, $this->importCount());
    }

    #[Test]
    public function a_validation_error_records_nothing(): void
    {
        $enrollment = $this->enroll('Nome Antigo');

        $this->asTeacher()
            ->post("/classes/{$this->schoolClass->ulid}/roster-imports/".Str::uuid().'/confirm', ['rows' => [
                ['name' => 'Nome Novo', 'enrollment_id' => $enrollment->id],
            ]])
            ->assertSessionHasErrors('rows.0.include');

        $this->assertSame(0, UsageEvent::query()->count());
    }

    #[Test]
    public function a_support_session_records_nothing_for_a_reimport(): void
    {
        $enrollment = $this->enroll('Nome Antigo');

        $this->asTeacher()->withSession(['impersonator_id' => 999])
            ->post("/classes/{$this->schoolClass->ulid}/roster-imports/".Str::uuid().'/confirm', [
                'rows' => [$this->row('Nome Novo', $enrollment), $this->row('Outro Aluno')],
            ]);

        $this->assertSame(0, UsageEvent::query()->count());
    }

    #[Test]
    public function the_rollups_add_up_the_breakdown(): void
    {
        $changed = $this->enroll('Nome Antigo');
        $same = $this->enroll('Igual');

        $this->confirm([
            $this->row('Novo'),
            $this->row('Nome Corrigido', $changed),
            $this->row('Igual', $same),
        ])->assertRedirect();

        // Outra importação, noutro minuto do mesmo dia, com outro token.
        $this->confirm([$this->row('Nome Corrigido Outra Vez', $changed), $this->row('Mais Um Novo')])->assertRedirect();

        app(AggregateUsage::class)->run(CarbonImmutable::parse('2026-10-13 10:00:00', 'Europe/Lisbon'));

        $rollup = UsageDailyRollup::query()
            ->where('feature', UsageFeature::ImportExport->value)
            ->where('user_id', $this->teacher->id)
            ->sole();

        $this->assertSame(2, $rollup->actions);
        $this->assertSame(4, $rollup->items);
        $this->assertSame(2, $rollup->items_created);
        $this->assertSame(2, $rollup->items_changed);
        $this->assertSame(1, $rollup->items_unchanged);
    }

    #[Test]
    public function a_timetable_import_discriminates_created_slots_from_those_already_there(): void
    {
        $this->makeSlot(['day_of_week' => 1, 'starts_at' => '08:30', 'ends_at' => '09:20']);

        $this->asTeacher()->post('/timetable-imports/confirm', [
            'starts_on' => null,
            'ends_on' => null,
            'rows' => [
                ['class_id' => $this->schoolClass->id, 'day_of_week' => 1, 'starts_at' => '08:30', 'ends_at' => '09:20', 'include' => true],
                ['class_id' => $this->schoolClass->id, 'day_of_week' => 2, 'starts_at' => '08:30', 'ends_at' => '09:20', 'include' => true],
                ['class_id' => $this->schoolClass->id, 'day_of_week' => 3, 'starts_at' => '08:30', 'ends_at' => '09:20', 'include' => true],
            ],
        ])->assertRedirect();

        $event = UsageEvent::query()->where('event', UsageEventType::TimetableImported->value)->sole();
        $this->assertSame(2, $event->item_count);
        $this->assertSame(2, $event->items_created);
        $this->assertSame(0, $event->items_changed);
        $this->assertSame(1, $event->items_unchanged);
    }
}
