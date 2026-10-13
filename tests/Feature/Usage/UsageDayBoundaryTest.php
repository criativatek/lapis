<?php

namespace Tests\Feature\Usage;

use App\Models\UsageEvent;
use App\Models\User;
use App\Support\Usage\UsageEventType;
use App\Support\Usage\UsageRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Usage\Concerns\SetsUsageCoverage;
use Tests\TestCase;

/**
 * Um dia é um dia de calendário LOCAL (Europe/Lisbon): a mudança de hora não o
 * encurta nem o alonga, e uma hora a seguir à meia-noite pertence ao dia novo.
 */
class UsageDayBoundaryTest extends TestCase
{
    use RefreshDatabase, SetsUsageCoverage;

    /**
     * @return array<string, array{string, string}>
     */
    public static function boundaries(): array
    {
        return [
            // Fim do horário de verão: 2026-10-25 02:00 -> 01:00.
            'autumn change, just after midnight' => ['2026-10-25 00:30:00', '2026-10-25'],
            'autumn change, just before midnight' => ['2026-10-24 23:30:00', '2026-10-24'],
            // Início do horário de verão: 2027-03-28 01:00 -> 02:00.
            'spring change, just after midnight' => ['2027-03-28 00:30:00', '2027-03-28'],
            'spring change, just before midnight' => ['2027-03-27 23:30:00', '2027-03-27'],
        ];
    }

    #[Test]
    #[DataProvider('boundaries')]
    public function an_event_lands_on_its_local_calendar_day(string $localInstant, string $expectedDay): void
    {
        // O relógio ANTES de criar dados; e em UTC, para provar que o fuso da
        // aplicação é que decide o dia.
        $this->travelTo(Carbon::parse($localInstant, 'Europe/Lisbon')->setTimezone('UTC'));
        $this->startUsageCollectionAt('2026-01-01 00:00:00');

        $teacher = User::factory()->create();

        Route::middleware(['web', 'auth', 'organization'])->post('/_usage/day', function (UsageRecorder $recorder) {
            $recorder->action(UsageEventType::LessonSummarySaved, 'lesson-1');

            return response('ok');
        });

        $this->actingAs($teacher)
            ->withSession(['organization_id' => $teacher->personalOrganization()->id])
            ->post('/_usage/day')
            ->assertOk();

        $event = UsageEvent::query()->sole();
        $this->assertSame($expectedDay, $event->occurred_on->toDateString());
        $this->assertSame($localInstant, $event->occurred_at->format('Y-m-d H:i:s'));

        $this->travelBack();
    }
}
