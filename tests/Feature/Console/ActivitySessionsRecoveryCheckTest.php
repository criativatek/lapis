<?php

namespace Tests\Feature\Console;

use App\Models\Organization;
use App\Models\TimetableActivity;
use App\Models\TimetableActivitySession;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `lapis:activity-sessions-recovery-check` — que recuperação se aplica à 0.170.0
 * (docs/activity-sessions-recovery.md): 0 quando não há atividades «Clube» (um
 * rollback só de código para a 0.169.1 é seguro: a tabela e as colunas ficam
 * inertes), 1 quando há (a 0.169.1 não conhece o tipo). Só contagens.
 */
class ActivitySessionsRecoveryCheckTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00:00', 'Europe/Lisbon'));
        $this->teacher = User::factory()->create();
        $this->organization = $this->teacher->personalOrganization();
    }

    #[Test]
    public function it_exits_zero_and_counts_when_there_are_sessions_but_no_club(): void
    {
        $activity = $this->activity('student_support', numbered: true);
        $this->makeSession($activity, 'Texto secreto da sessão.');

        $this->artisan('lapis:activity-sessions-recovery-check')
            ->expectsOutputToContain('sessões guardadas: 1 (com registos: 1)')
            ->expectsOutputToContain('atividades «Clube»: 0')
            ->expectsOutputToContain('atividades com numeração das sessões: 1')
            ->expectsOutputToContain('o rollback só de código para a 0.169.1 é seguro')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_exits_zero_on_an_empty_database(): void
    {
        $this->artisan('lapis:activity-sessions-recovery-check')
            ->expectsOutputToContain('sessões guardadas: 0')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_exits_one_when_a_club_activity_exists(): void
    {
        $this->activity('club');

        $this->artisan('lapis:activity-sessions-recovery-check')
            ->expectsOutputToContain('atividades «Clube»: 1')
            ->expectsOutputToContain('Não repor código abaixo da 0.170.0')
            ->assertExitCode(1);
    }

    #[Test]
    public function it_never_prints_titles_or_summaries_and_never_writes(): void
    {
        $activity = $this->activity('club', title: 'Título que não deve sair');
        $this->makeSession($activity, 'Texto secreto da sessão.');

        $this->artisan('lapis:activity-sessions-recovery-check')
            ->doesntExpectOutputToContain('Título que não deve sair')
            ->doesntExpectOutputToContain('Texto secreto da sessão.')
            ->assertExitCode(1);

        $this->inTenant(function (): void {
            $this->assertSame(1, TimetableActivity::query()->count());
            $this->assertSame(1, TimetableActivitySession::query()->count());
        });
    }

    private function activity(string $kind, bool $numbered = false, string $title = 'Atividade'): TimetableActivity
    {
        return $this->inTenant(fn (): TimetableActivity => TimetableActivity::create([
            'user_id' => $this->teacher->id,
            'kind' => $kind,
            'title' => $title,
            'day_of_week' => 2,
            'starts_at' => '10:30',
            'ends_at' => '11:20',
            'numbering_enabled' => $numbered,
        ]));
    }

    private function makeSession(TimetableActivity $activity, string $summary): TimetableActivitySession
    {
        return $this->inTenant(function () use ($activity, $summary): TimetableActivitySession {
            $session = new TimetableActivitySession;
            $session->forceFill([
                'user_id' => $this->teacher->id,
                'timetable_activity_id' => $activity->id,
                'activity_kind' => $activity->kind->value,
                'activity_title' => $activity->title,
                'occurs_on' => '2026-10-13',
                'starts_at' => '10:30',
                'ends_at' => '11:20',
                'summary' => $summary,
                'status' => 'done',
            ])->save();

            return $session;
        });
    }

    private function inTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->organization, $callback);
    }
}
