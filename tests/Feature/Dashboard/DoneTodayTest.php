<?php

namespace Tests\Feature\Dashboard;

use App\Models\ClassificationStatus;
use App\Models\User;
use App\Services\Dashboard\DoneToday;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Dashboard\Concerns\BuildsDashboardFixtures;
use Tests\TestCase;

class DoneTodayTest extends TestCase
{
    use BuildsDashboardFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDashboardFixtures();
    }

    #[Test]
    public function todays_facts_are_aggregated_per_lesson_and_per_class_and_ordered_from_the_most_recent(): void
    {
        $lesson = $this->lessonFor($this->schoolClass, '2026-10-26 08:30:00', 90, [
            'attendance_recorded_at' => '2026-10-26 10:05:00', 'attendance_recorded_by' => $this->teacher->id,
        ]);
        $this->summarise($lesson, 'Leitura do capítulo 3.', '2026-10-26 10:07:00');
        $this->summarise($this->lessonFor($this->schoolClass, '2026-10-23 10:00:00'), 'Sumário de sexta.', '2026-10-23 11:00:00');   // ontem não conta

        $ana = $this->enroll('Ana Costa');
        $rui = $this->enroll('Rui Pires');
        $this->classificationFor($ana, ClassificationStatus::Confirmed, ['confirmed_by' => $this->teacher->id, 'confirmed_at' => '2026-10-26 17:40:00']);
        $this->classificationFor($rui, ClassificationStatus::Confirmed, ['confirmed_by' => $this->teacher->id, 'confirmed_at' => '2026-10-26 17:48:00']);

        $colleague = User::factory()->create();
        $this->organization->members()->attach($colleague, ['joined_at' => now()]);
        $this->classificationFor($ana, ClassificationStatus::Confirmed, ['scope' => 'accumulated', 'confirmed_by' => $colleague->id, 'confirmed_at' => '2026-10-26 18:00:00']);

        $done = $this->within(fn () => app(DoneToday::class)->for($this->context()));

        $this->assertSame(
            [['17:48', 'classifications_confirmed', '2 classificações confirmadas — 7.º A', 2], ['10:07', 'lesson', 'Sumário e assiduidade — 7.º A', 1]],
            array_map(fn (array $item): array => [$item['time'], $item['kind'], $item['title'], $item['count']], $done),
        );
    }

    #[Test]
    public function nothing_is_listed_without_a_time_stamp_of_today(): void
    {
        $this->lessonFor($this->schoolClass, '2026-10-26 08:30:00', 90);

        $this->assertSame([], $this->within(fn () => app(DoneToday::class)->for($this->context())));
    }
}
