<?php

namespace Tests\Feature\Evidence;

use App\Models\Enrollment;
use App\Models\EnrollmentStatus;
use App\Models\EvidenceKind;
use App\Models\EvidenceRecord;
use App\Models\HomeworkStatus;
use App\Models\UsageEvent;
use App\Support\Usage\UsageEventType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Evidence\Concerns\BuildsFollowupFixtures;
use Tests\Feature\Usage\Concerns\SetsUsageCoverage;
use Tests\TestCase;

/**
 * The homework grid when two sessions (two people, two tabs) work on the same
 * day — no change is ever overwritten silently — and who may receive a
 * marking of a given day: whoever belonged to the class on it.
 */
class HomeworkGridConcurrencyTest extends TestCase
{
    use BuildsFollowupFixtures, RefreshDatabase, SetsUsageCoverage;

    private Enrollment $ana;

    private Enrollment $rui;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootFollowupFixtures();
        $this->ana = $this->enroll('Ana Costa', ['class_number' => 1]);
        $this->rui = $this->enroll('Rui Sousa', ['class_number' => 2]);
    }

    /**
     * @return array<string, array<string, mixed>> keyed by enrollment id
     */
    private function grid(string $date): array
    {
        return collect($this->asTeacher()
            ->getJson("/classes/{$this->schoolClass->ulid}/records/homework-batch?occurred_at={$date}")
            ->assertOk()
            ->json('enrollments'))
            ->keyBy('id')
            ->all();
    }

    /**
     * @param  list<array{0: Enrollment, 1: string|null, 2: string|null}>  $rows  [student, status, expected version]
     */
    private function save(string $date, array $rows): TestResponse
    {
        return $this->asTeacher()->putJson("/classes/{$this->schoolClass->ulid}/records/homework-batch", [
            'occurred_at' => $date,
            'rows' => array_map(fn (array $row): array => [
                'enrollment_id' => $row[0]->id,
                'homework_status' => $row[1],
                'description' => null,
                'expected' => $row[2],
            ], $rows),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function outcome(): array
    {
        return session('inertia.flash_data')['homework_save'];
    }

    private function statusOf(Enrollment $enrollment, string $date): ?string
    {
        return $this->inTenant($this->organization, fn () => EvidenceRecord::query()
            ->where('enrollment_id', $enrollment->id)->whereDate('occurred_at', $date)->first()?->homework_status?->value);
    }

    private function homeworkCount(): int
    {
        return $this->inTenant($this->organization, fn (): int => EvidenceRecord::query()->where('kind', EvidenceKind::Homework->value)->count());
    }

    // --- Conflitos -------------------------------------------------------------------

    #[Test]
    public function a_marking_changed_in_another_session_is_kept_and_the_other_students_are_saved(): void
    {
        $this->saveHomework('2026-10-12', [$this->ana->id => 'done'])->assertRedirect();
        $opened = $this->grid('2026-10-12');

        // Another session changes Ana in between.
        $this->save('2026-10-12', [[$this->ana, 'partially_done', $opened[$this->ana->id]['version']]])->assertRedirect();
        $this->assertSame([$this->ana->id], $this->outcome()['saved']);

        // This session still holds the old version of Ana, and changes Rui too.
        $this->save('2026-10-12', [
            [$this->ana, 'not_done', $opened[$this->ana->id]['version']],
            [$this->rui, 'done', $opened[$this->rui->id]['version']],
        ])->assertRedirect();

        $outcome = $this->outcome();
        $this->assertSame([$this->rui->id], $outcome['saved']);
        $this->assertCount(1, $outcome['conflicts']);
        $this->assertSame($this->ana->id, $outcome['conflicts'][0]['enrollment_id']);
        $this->assertSame('partially_done', $outcome['conflicts'][0]['homework_status']);
        $this->assertSame('changed', $outcome['conflicts'][0]['change']);
        $this->assertNotNull($outcome['conflicts'][0]['at']);
        $this->assertSame('warning', $this->toast()['type']);
        $this->assertStringContainsString('Gravação parcial', $this->toast()['message']);
        $this->assertStringNotContainsString('Trabalho de casa guardado', $this->toast()['message']);

        // Never overwritten; the other student saved normally.
        $this->assertSame('partially_done', $this->statusOf($this->ana, '2026-10-12'));
        $this->assertSame('done', $this->statusOf($this->rui, '2026-10-12'));

        // After reviewing, keeping the choice is deliberate: sent with the version now seen.
        $this->save('2026-10-12', [[$this->ana, 'not_done', $outcome['conflicts'][0]['version']]])->assertRedirect();
        $this->assertSame([], $this->outcome()['conflicts']);
        $this->assertSame('not_done', $this->statusOf($this->ana, '2026-10-12'));
    }

    #[Test]
    public function two_tabs_creating_the_same_first_marking_never_duplicate_it(): void
    {
        $this->save('2026-10-12', [[$this->ana, 'done', 'none']])->assertRedirect();
        $this->assertSame([$this->ana->id], $this->outcome()['saved']);

        // The second tab still thought there was no marking.
        $this->save('2026-10-12', [[$this->ana, 'not_done', 'none']])->assertRedirect();
        $this->assertSame('created', $this->outcome()['conflicts'][0]['change']);
        $this->assertSame('done', $this->outcome()['conflicts'][0]['homework_status']);

        // The same choice in the other tab is simply already saved.
        $this->save('2026-10-12', [[$this->ana, 'done', 'none']])->assertRedirect();
        $this->assertSame([], $this->outcome()['conflicts']);
        $this->assertSame([$this->ana->id], $this->outcome()['unchanged']);

        $this->assertSame(1, $this->homeworkCount());
        $this->assertSame('done', $this->statusOf($this->ana, '2026-10-12'));
    }

    #[Test]
    public function a_marking_put_back_to_unverified_after_the_grid_opened_is_never_recreated_silently(): void
    {
        $this->saveHomework('2026-10-12', [$this->ana->id => 'done']);
        $opened = $this->grid('2026-10-12')[$this->ana->id]['version'];

        $this->save('2026-10-12', [[$this->ana, null, $opened]])->assertRedirect();
        $this->assertNull($this->statusOf($this->ana, '2026-10-12'));

        $this->save('2026-10-12', [[$this->ana, 'partially_done', $opened]])->assertRedirect();
        $conflict = $this->outcome()['conflicts'][0];
        $this->assertSame('removed', $conflict['change']);
        $this->assertNull($conflict['homework_status']);
        $this->assertSame('none', $conflict['version']);
        $this->assertNotNull($conflict['at']);
        $this->assertStringContainsString('Nada foi gravado', $this->toast()['message']);
        $this->assertNull($this->statusOf($this->ana, '2026-10-12'));

        // Asking for «Não verificado» too is already true.
        $this->save('2026-10-12', [[$this->ana, null, $opened]])->assertRedirect();
        $this->assertSame([$this->ana->id], $this->outcome()['unchanged']);
    }

    #[Test]
    public function a_request_repeated_after_a_lost_connection_is_saved_once_and_counted_once(): void
    {
        $this->startUsageCollectionAt('2026-10-01 00:00:00');
        $rows = [[$this->ana, 'not_done', 'none'], [$this->rui, 'done', 'none']];

        $this->save('2026-10-12', $rows)->assertRedirect();
        $this->save('2026-10-12', $rows)->assertRedirect();

        $this->assertSame([], $this->outcome()['conflicts']);
        $this->assertEqualsCanonicalizing([$this->ana->id, $this->rui->id], $this->outcome()['unchanged']);
        $this->assertSame('Não havia alterações por guardar.', $this->toast()['message']);
        $this->assertSame(2, $this->homeworkCount());
        $this->assertSame(1, UsageEvent::query()->where('event', UsageEventType::HomeworkBatchSaved->value)->count());
        $this->assertSame(1, DB::table('audit_events')->where('event', 'record.homework_batch_updated')->count());
    }

    #[Test]
    public function a_conflict_never_moves_the_follow_up_nor_raises_an_alert(): void
    {
        $this->saveHomework('2026-10-01', [$this->ana->id => 'not_done']);
        $this->saveHomework('2026-10-05', [$this->ana->id => 'not_done']);

        // Someone marks Ana «Realizou» on the 12th; this session still had nothing there.
        $this->save('2026-10-12', [[$this->ana, 'done', 'none']]);
        $this->save('2026-10-12', [[$this->ana, 'not_done', 'none']])->assertRedirect();

        $this->assertCount(1, $this->outcome()['conflicts']);
        $this->assertSame(0, $this->alertCount());
        $this->assertNull($this->caseFor($this->ana, 'homework_not_done'));
    }

    #[Test]
    public function a_request_without_versions_still_saves_as_before(): void
    {
        $this->saveHomework('2026-10-12', [$this->ana->id => 'done']);
        $this->saveHomework('2026-10-12', [$this->ana->id => 'not_done'])->assertRedirect();

        $this->assertSame('not_done', $this->statusOf($this->ana, '2026-10-12'));
        $this->assertSame([], $this->outcome()['conflicts']);
    }

    // --- Elegibilidade na data -----------------------------------------------------------

    #[Test]
    public function a_student_who_left_may_be_marked_for_the_days_they_belonged_and_only_those(): void
    {
        $left = $this->enroll('Saiu a 8', ['class_number' => 3, 'status' => EnrollmentStatus::Left, 'left_on' => '2026-10-08']);

        $this->assertTrue($this->grid('2026-10-05')[$left->id]['editable']);
        $this->save('2026-10-05', [[$left, 'done', 'none']])->assertRedirect();
        $this->assertSame('done', $this->statusOf($left, '2026-10-05'));

        // The leaving day itself still belongs to the enrollment.
        $this->assertTrue($this->grid('2026-10-08')[$left->id]['editable']);
        $this->save('2026-10-08', [[$left, 'not_done', 'none']])->assertRedirect();
        $this->assertSame('not_done', $this->statusOf($left, '2026-10-08'));

        // The day after: not in the grid, refused by the server.
        $this->assertArrayNotHasKey($left->id, $this->grid('2026-10-09'));
        $this->save('2026-10-09', [[$left, 'done', 'none']])->assertStatus(422)->assertJsonValidationErrors('rows');
        $this->assertNull($this->statusOf($left, '2026-10-09'));

        // A marking of a day they belonged stays correctable after leaving.
        $version = $this->grid('2026-10-05')[$left->id]['version'];
        $this->save('2026-10-05', [[$left, 'partially_done', $version]])->assertRedirect();
        $this->assertSame('partially_done', $this->statusOf($left, '2026-10-05'));
    }

    #[Test]
    public function a_student_who_entered_later_is_never_marked_before_entering(): void
    {
        $late = $this->enroll('Entrou a 10', ['class_number' => 3, 'enrolled_on' => '2026-10-10']);

        $this->assertArrayNotHasKey($late->id, $this->grid('2026-10-09'));
        $this->save('2026-10-09', [[$late, 'done', 'none']])->assertStatus(422);

        // The entry day itself is a day in the class.
        $this->assertTrue($this->grid('2026-10-10')[$late->id]['editable']);
        $this->save('2026-10-10', [[$late, 'done', 'none']])->assertRedirect();
        $this->assertSame('done', $this->statusOf($late, '2026-10-10'));
    }

    #[Test]
    public function an_enrollment_without_a_leaving_date_cannot_be_placed_and_says_why(): void
    {
        $unknown = $this->enroll('Transferido Sem Data', ['class_number' => 3]);
        $this->save('2026-10-01', [[$unknown, 'done', 'none']])->assertRedirect();
        $this->inTenant($this->organization, fn () => $unknown->update(['status' => EnrollmentStatus::TransferredOut]));

        $row = $this->grid('2026-10-12')[$unknown->id];
        $this->assertFalse($row['editable']);
        $this->assertSame('undetermined', $row['locked_reason']);
        $this->assertSame('Transferido', $row['enrollment_status_label']);
        $this->assertNull($row['left_on']);

        $this->save('2026-10-12', [[$unknown, 'done', 'none']])
            ->assertStatus(422)
            ->assertJsonPath('errors.rows.0', 'Não se sabe se um dos alunos pertencia à turma nesta data: a inscrição não está ativa e não tem data de saída. Registe a data de saída na turma para o poder marcar.');

        // The marking that already exists stays correctable.
        $existing = $this->grid('2026-10-01')[$unknown->id];
        $this->assertTrue($existing['editable']);
        $this->save('2026-10-01', [[$unknown, 'not_done', $existing['version']]])->assertRedirect();
        $this->assertSame(HomeworkStatus::NotDone->value, $this->statusOf($unknown, '2026-10-01'));
    }
}
