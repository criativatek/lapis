<?php

namespace Tests\Feature\Dashboard;

use App\Models\CalendarEvent;
use App\Models\InstrumentStatus;
use App\Models\User;
use App\Services\Dashboard\UpcomingEvents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Dashboard\Concerns\BuildsDashboardFixtures;
use Tests\TestCase;

class UpcomingEventsTest extends TestCase
{
    use BuildsDashboardFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDashboardFixtures();
    }

    #[Test]
    public function the_horizon_is_fourteen_days_from_tomorrow_and_the_period_end_always_closes_the_list(): void
    {
        $this->calendarEvent('Hoje não conta', '2026-10-26');
        $this->calendarEvent('Amanhã', '2026-10-27', null, 'meeting', '18:00');
        $this->calendarEvent('Dia catorze', '2026-11-09');
        $this->calendarEvent('Dia quinze', '2026-11-10');
        $this->instrumentFor($this->schoolClass, 'Teste de leitura', '2026-10-31', InstrumentStatus::Prepared);

        $items = $this->within(fn () => app(UpcomingEvents::class)->for($this->context()));

        $this->assertSame(
            [['2026-10-27', 'calendar', 1], ['2026-10-31', 'instrument', 5], ['2026-11-09', 'calendar', 14], ['2026-12-17', 'period_end', 52]],
            array_map(fn (array $item): array => [$item['date'], $item['kind'], $item['days_away']], $items),
        );
        $this->assertSame('Reunião · 18:00', $items[0]['subtitle']);
        $this->assertSame('Fim do 1.º Período', $items[3]['title']);
    }

    #[Test]
    public function a_period_that_ends_sooner_than_an_event_comes_first(): void
    {
        $this->freeze('2026-12-15 10:00:00');
        $this->calendarEvent('Reunião de balanço', '2026-12-18');

        $items = $this->within(fn () => app(UpcomingEvents::class)->for($this->context()));

        $this->assertSame([['2026-12-17', 'period_end', 2], ['2026-12-18', 'calendar', 3]], array_map(fn (array $item): array => [$item['date'], $item['kind'], $item['days_away']], $items));
    }

    #[Test]
    public function another_teachers_calendar_never_leaks_in(): void
    {
        $colleague = User::factory()->create();
        $this->organization->members()->attach($colleague, ['joined_at' => now()]);
        $this->inTenant($this->organization, fn () => CalendarEvent::create([
            'user_id' => $colleague->id, 'type' => 'meeting', 'title' => 'Reunião do colega', 'starts_on' => '2026-10-28', 'ends_on' => '2026-10-28',
        ]));
        $this->calendarEvent('A minha reunião', '2026-10-29');

        $items = $this->within(fn () => app(UpcomingEvents::class)->for($this->context()));

        $this->assertSame(['A minha reunião', 'Fim do 1.º Período'], array_column($items, 'title'));
    }
}
