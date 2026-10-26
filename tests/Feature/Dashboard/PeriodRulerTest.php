<?php

namespace Tests\Feature\Dashboard;

use App\Models\InstrumentStatus;
use App\Services\Dashboard\PeriodRuler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Dashboard\Concerns\BuildsDashboardFixtures;
use Tests\TestCase;

class PeriodRulerTest extends TestCase
{
    use BuildsDashboardFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDashboardFixtures();
    }

    #[Test]
    public function the_ruler_knows_the_day_of_the_period_and_the_real_state_of_each_dated_instrument(): void
    {
        $this->instrumentFor($this->schoolClass, 'Primeiro dia', '2026-09-15', InstrumentStatus::Completed);
        $this->instrumentFor($this->schoolClass, 'A corrigir', '2026-10-20', InstrumentStatus::InCorrection);
        $this->instrumentFor($this->schoolClass, 'Aplicado', '2026-10-22', InstrumentStatus::Prepared);
        $this->instrumentFor($this->schoolClass, 'Marcado', '2026-12-17', InstrumentStatus::Prepared);
        $this->instrumentFor($this->schoolClass, 'Rascunho', '2026-11-01', InstrumentStatus::Draft);
        $this->instrumentFor($this->schoolClass, 'Do 2.º período', '2027-02-10', InstrumentStatus::Prepared, $this->secondPeriod);

        $ruler = $this->within(fn () => app(PeriodRuler::class)->for($this->context()));

        $this->assertSame(['label' => '1.º Período', 'day' => 42, 'days' => 94, 'days_left' => 52], array_intersect_key($ruler['period'], array_flip(['label', 'day', 'days', 'days_left'])));
        $this->assertSame(
            [['2026-09-15', 'corrected', 0.0], ['2026-10-20', 'in_correction', 0.3763], ['2026-10-22', 'applied', 0.3978], ['2026-12-17', 'scheduled', 1.0]],
            array_map(fn (array $mark): array => [$mark['date'], $mark['state'], $mark['position']], $ruler['marks']),
        );
    }

    #[Test]
    public function between_two_periods_there_is_no_ruler(): void
    {
        $this->freeze('2026-12-28 10:00:00');

        $this->assertNull($this->within(fn () => app(PeriodRuler::class)->for($this->context())));
    }
}
