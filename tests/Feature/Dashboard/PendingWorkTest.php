<?php

namespace Tests\Feature\Dashboard;

use App\Models\ClassificationStatus;
use App\Models\EvidenceKind;
use App\Models\EvidenceRecord;
use App\Models\InstrumentStatus;
use App\Models\Intervention;
use App\Models\InterventionDomainRelation;
use App\Models\InterventionStatus;
use App\Models\InterventionTargetType;
use App\Models\RecordFollowupCase;
use App\Services\Dashboard\PendingWork;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Dashboard\Concerns\BuildsDashboardFixtures;
use Tests\TestCase;

class PendingWorkTest extends TestCase
{
    use BuildsDashboardFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDashboardFixtures();
    }

    #[Test]
    public function missing_summaries_follow_the_product_rule_and_older_ones_are_counted_not_hidden(): void
    {
        $recent = $this->lessonFor($this->schoolClass, '2026-10-23 10:00:00');          // sexta passada, sem sumário → lista
        $this->lessonFor($this->schoolClass, '2026-10-05 10:00:00');                    // há 21 dias, sem sumário → contagem
        $this->lessonFor($this->schoolClass, '2026-10-26 08:30:00', 90, [                // professor ausente → dispensa sumário
            'outcome' => 'teacher_absent', 'outcome_reason' => 'other', 'outcome_recorded_at' => '2026-10-26 09:00:00', 'outcome_recorded_by' => $this->teacher->id,
        ]);
        $this->lessonFor($this->schoolClass, '2026-10-26 17:00:00');                    // ainda não começou
        $this->summarise($this->lessonFor($this->schoolClass, '2026-10-22 10:00:00'));  // com sumário
        $this->lessonFor($this->schoolClass, '2026-10-26 15:00:00', 50, ['status' => 'taught']); // lecionada, sem sumário → lista
        $archived = $this->anotherClass('8.º D');
        $this->lessonFor($archived, '2026-10-23 11:00:00');
        $this->inTenant($this->organization, fn () => $archived->update(['archived_at' => now()]));

        $pending = $this->within(fn () => app(PendingWork::class)->for($this->context()));
        $summaries = $pending['summaries'];

        $this->assertTrue($summaries['available']);
        $this->assertSame(['2026-10-23 10:00', '2026-10-26 15:00'], array_map(fn (array $item): string => $item['date'].' '.$item['start_time'], $summaries['recent']));
        $this->assertSame($recent->ulid, $summaries['recent'][0]['ulid']);
        $this->assertSame(1, $summaries['older_count']);
        $this->assertStringContainsString('summary=sem', (string) $summaries['older_href']);
        $this->assertStringContainsString('week=2026-10-05', (string) $summaries['older_href']);
        $this->assertSame(3, $pending['counts']['summaries_missing']);
        $this->assertSame(3, $pending['per_class'][$this->schoolClass->id]['summaries_missing']);
        $this->assertArrayNotHasKey($archived->id, $pending['per_class'], 'a turma arquivada saiu do painel');
    }

    #[Test]
    public function only_instruments_in_correction_with_students_left_are_pending_work(): void
    {
        $this->enroll('Ana Costa');
        $this->enroll('Rui Pires');
        $inCorrection = $this->instrumentFor($this->schoolClass, 'Ficha de leitura', '2026-10-20', InstrumentStatus::InCorrection);
        $this->instrumentFor($this->schoolClass, 'Teste aplicado sem correção', '2026-10-22', InstrumentStatus::Prepared);
        $this->instrumentFor($this->schoolClass, 'Teste marcado', '2026-11-05', InstrumentStatus::Prepared);
        $this->instrumentFor($this->schoolClass, 'Teste concluído', '2026-10-01', InstrumentStatus::Completed);

        $pending = $this->within(fn () => app(PendingWork::class)->for($this->context()));

        $this->assertSame([$inCorrection->ulid], array_column($pending['corrections']['items'], 'ulid'));
        $this->assertSame(2, $pending['corrections']['items'][0]['pending_students']);
        $this->assertSame(1, $pending['corrections']['applied_without_correction'], 'aplicado sem correção é informação, não alerta');
        $this->assertSame(1, $pending['counts']['corrections']);
    }

    #[Test]
    public function proposed_and_confirmed_classifications_are_counted_per_class_and_superseded_ones_are_not(): void
    {
        $ana = $this->enroll('Ana Costa');
        $rui = $this->enroll('Rui Pires');
        $this->classificationFor($ana, ClassificationStatus::Proposed);
        $this->classificationFor($rui, ClassificationStatus::Proposed, ['scope' => 'accumulated']);
        $this->classificationFor($rui, ClassificationStatus::Confirmed, ['confirmed_by' => $this->teacher->id, 'confirmed_at' => '2026-10-20 10:00:00']);
        $this->classificationFor($ana, ClassificationStatus::Superseded, ['scope' => 'accumulated']);

        $pending = $this->within(fn () => app(PendingWork::class)->for($this->context()));

        $this->assertSame([['7.º A', 2, 1]], array_map(fn (array $item): array => [$item['class']['label'], $item['proposed'], $item['confirmed']], $pending['classifications']));
        $this->assertSame(2, $pending['per_class'][$this->schoolClass->id]['classifications_proposed']);
    }

    #[Test]
    public function overdue_reviews_and_record_alerts_are_pending_and_future_or_closed_ones_are_not(): void
    {
        $ana = $this->enroll('Ana Costa');
        $this->inTenant($this->organization, function () use ($ana): void {
            $base = [
                'class_id' => $this->schoolClass->id, 'enrollment_id' => $ana->id, 'academic_period_id' => $this->firstPeriod->id,
                'target_type' => InterventionTargetType::Student, 'domain_relation' => InterventionDomainRelation::None,
                'intervention_type' => 'other', 'purpose' => 'recovery', 'description' => 'Apoio à leitura.', 'started_on' => '2026-10-01',
            ];
            $overdue = Intervention::create($base + ['title' => 'Leitura orientada', 'status' => InterventionStatus::InProgress, 'review_on' => '2026-10-23']);
            $overdue->participants()->attach($ana->id);
            Intervention::create($base + ['title' => 'Ainda não', 'status' => InterventionStatus::InProgress, 'review_on' => '2026-10-27']);
            Intervention::create($base + ['title' => 'Concluída', 'status' => InterventionStatus::Concluded, 'review_on' => '2026-10-20', 'concluded_on' => '2026-10-21']);

            foreach (['2026-10-01', '2026-10-02', '2026-10-05'] as $date) {
                EvidenceRecord::create(['class_id' => $this->schoolClass->id, 'enrollment_id' => $ana->id, 'occurred_at' => $date, 'kind' => EvidenceKind::Lateness, 'description' => 'Atraso.', 'created_by' => $this->teacher->id]);
            }
            RecordFollowupCase::create([
                'class_id' => $this->schoolClass->id, 'enrollment_id' => $ana->id, 'academic_period_id' => $this->firstPeriod->id,
                'category' => 'lateness', 'alerted_at' => '2026-10-05 10:00:00', 'alerted_count' => 3,
            ]);
        });

        $pending = $this->within(fn () => app(PendingWork::class)->for($this->context()));

        $this->assertSame(['Leitura orientada'], array_column($pending['reviews'], 'title'));
        $this->assertSame('Ana Costa', $pending['reviews'][0]['target_label']);
        $this->assertSame('2026-10-23', $pending['reviews'][0]['review_on']);
        $this->assertSame(1, $pending['record_alerts']['count']);
        $this->assertSame(1, $pending['per_class'][$this->schoolClass->id]['alerts']);
        $this->assertSame(1, $pending['per_class'][$this->schoolClass->id]['reviews']);
    }

    #[Test]
    public function a_base_account_keeps_everything_but_the_summaries(): void
    {
        $this->lessonFor($this->schoolClass, '2026-10-23 10:00:00');
        $this->enroll('Ana Costa');
        $this->instrumentFor($this->schoolClass, 'Ficha', '2026-10-20', InstrumentStatus::InCorrection);
        $this->downgradeToBase();

        $pending = $this->within(fn () => app(PendingWork::class)->for($this->context()));

        $this->assertFalse($pending['summaries']['available']);
        $this->assertSame(0, $pending['counts']['summaries_missing']);
        $this->assertSame(1, $pending['counts']['corrections']);
    }
}
