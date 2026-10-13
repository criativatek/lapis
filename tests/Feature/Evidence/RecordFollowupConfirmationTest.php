<?php

namespace Tests\Feature\Evidence;

use App\Models\AuditEvent;
use App\Models\Enrollment;
use App\Models\EvidenceKind;
use App\Models\EvidenceRecord;
use App\Models\RecordFollowupCase;
use App\Models\RecordFollowupConfirmation;
use App\Models\SchoolClass;
use App\Services\EnrollmentHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Evidence\Concerns\BuildsFollowupFixtures;
use Tests\TestCase;

/**
 * «Marcar como registado no Inovar»: a declaration by the teacher, never a
 * verification and never a change to a pedagogical record — who, when and
 * which occurrences; undoable without losing history; refused when the
 * records moved under the teacher, during a support session, or outside the
 * classes the teacher teaches.
 */
class RecordFollowupConfirmationTest extends TestCase
{
    use BuildsFollowupFixtures, RefreshDatabase;

    private Enrollment $ana;

    /** @var Collection<int, EvidenceRecord> */
    private Collection $lateness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootFollowupFixtures();

        $this->ana = $this->enroll('Ana Costa', ['class_number' => 5]);
        $this->lateness = collect(['2026-10-01', '2026-10-02', '2026-10-05'])
            ->map(fn (string $date): EvidenceRecord => $this->record($this->ana, EvidenceKind::Lateness, $date));
    }

    /**
     * The occurrences as the dialog shows them — ULID and date.
     *
     * @param  iterable<EvidenceRecord>  $records
     * @return list<array{ulid: string, date: string}>
     */
    private function shown(iterable $records): array
    {
        $shown = [];

        foreach ($records as $record) {
            $shown[] = ['ulid' => $record->ulid, 'date' => $record->occurred_at->format('Y-m-d')];
        }

        return $shown;
    }

    /**
     * @param  list<array{ulid: string, date: string}>|null  $occurrences
     */
    private function confirm(?array $occurrences = null, ?SchoolClass $class = null, bool $impersonating = false): TestResponse
    {
        $request = $this->actingAs($this->teacher)->withSession(array_filter([
            'organization_id' => $this->organization->id,
            'impersonator_id' => $impersonating ? 999 : null,
        ]));

        return $request->from('/records?view=lateness')->post('/records/acompanhamento/confirmacoes', [
            'class' => ($class ?? $this->schoolClass)->ulid,
            'student' => $this->ana->ulid,
            'category' => 'lateness',
            'period' => $this->firstPeriod->ulid,
            'occurrences' => $occurrences ?? $this->shown($this->lateness),
        ]);
    }

    private function openCase(): TestResponse
    {
        return $this->asTeacher()->get('/records?'.http_build_query([
            'view' => 'lateness',
            'case_class' => $this->schoolClass->ulid,
            'case_student' => $this->ana->ulid,
            'case_category' => 'lateness',
        ]));
    }

    #[Test]
    public function preparing_and_reading_the_message_never_marks_the_case_as_handled(): void
    {
        $this->openCase()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('openCase.status', 'pending')
            ->where('openCase.confirmation', null)
            ->where('openCase.message.missing', []));

        $this->assertSame(0, $this->confirmations());
    }

    #[Test]
    public function confirming_records_who_when_and_exactly_which_occurrences(): void
    {
        $this->confirm()->assertRedirect('/records?view=lateness');

        $confirmation = $this->inTenant($this->organization, fn () => RecordFollowupConfirmation::query()->sole());
        $this->assertSame($this->teacher->id, $confirmation->confirmed_by);
        $this->assertSame(3, $confirmation->occurrence_count);
        // assertEquals, not assertSame: MySQL stores JSON with its keys
        // reordered; the code reads them by name.
        $this->assertEquals(
            $this->lateness->map(fn (EvidenceRecord $record): array => ['ulid' => $record->ulid, 'date' => $record->occurred_at->format('Y-m-d')])->all(),
            $confirmation->occurrences,
        );
        $this->assertSame('Marcado como registado no Inovar.', $this->toast()['message']);

        // A pre-existing case gets its row now — without an alert.
        $case = $this->caseFor($this->ana, 'lateness');
        $this->assertNotNull($case);
        $this->assertNull($case->alerted_at);

        $this->openCase()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('openCase.status', 'registered')
            ->where('openCase.count', 3)
            ->has('openCase.occurrences', 3)
            ->where('openCase.confirmation.confirmed_by', $this->teacher->name)
            ->where('openCase.confirmation.changes.added', [])
            ->where('openCase.confirmation.changes.removed', []));

        $this->inTenant($this->organization, function (): void {
            $event = AuditEvent::query()->where('event', 'record_followup.confirmed')->sole();
            $this->assertSame(3, $event->properties['occurrence_count']);
            $this->assertStringNotContainsString('Ana', json_encode($event->properties, JSON_THROW_ON_ERROR));
        });

        // The source records are untouched.
        $this->assertSame(3, $this->inTenant($this->organization, fn () => EvidenceRecord::query()->count()));
    }

    #[Test]
    public function a_double_click_never_stacks_a_second_declaration(): void
    {
        $this->confirm()->assertRedirect();
        $this->confirm()->assertRedirect();

        $this->assertSame(1, $this->confirmations());
        $this->assertSame('Este caso já estava marcado como registado no Inovar.', $this->toast()['message']);
    }

    #[Test]
    public function a_case_below_three_cannot_be_declared_so_its_alert_is_never_swallowed(): void
    {
        $this->asTeacher()->delete('/records/'.$this->lateness[2]->ulid)->assertRedirect();

        $this->confirm($this->shown($this->lateness->take(2)))->assertSessionHasErrors('occurrences');
        $this->assertSame(0, $this->confirmations());

        // The third arrives later: it alerts, nothing took the alert before.
        $this->postRecord(['enrollment_ids' => [$this->ana->id], 'occurred_at' => '2026-10-09'])->assertRedirect();
        $this->assertSame('warning', $this->toast()['type']);
        $this->assertNotNull($this->caseFor($this->ana, 'lateness')?->alerted_at);
    }

    #[Test]
    public function a_date_moved_inside_the_period_after_opening_is_refused_too(): void
    {
        $shown = $this->shown($this->lateness);
        $this->inTenant($this->organization, fn () => $this->lateness[0]->update(['occurred_at' => '2026-10-07']));

        $this->confirm($shown)->assertSessionHasErrors('occurrences');
        $this->assertSame(0, $this->confirmations());
    }

    #[Test]
    public function records_that_changed_since_the_detail_was_opened_are_refused(): void
    {
        $shown = $this->shown($this->lateness);
        $this->record($this->ana, EvidenceKind::Lateness, '2026-10-06');

        $this->confirm($shown)->assertSessionHasErrors('occurrences');

        $this->assertSame(0, $this->confirmations());
        $this->assertSame(0, $this->inTenant($this->organization, fn () => RecordFollowupCase::query()->count()));
    }

    #[Test]
    public function undoing_keeps_the_history_and_returns_the_case_to_its_live_status(): void
    {
        $this->confirm();
        $confirmation = $this->inTenant($this->organization, fn () => RecordFollowupConfirmation::query()->sole());

        $this->asTeacher()->from('/records')->delete('/records/acompanhamento/confirmacoes/'.$confirmation->ulid)->assertRedirect('/records');
        $this->asTeacher()->from('/records')->delete('/records/acompanhamento/confirmacoes/'.$confirmation->ulid)->assertRedirect('/records');

        $undone = $confirmation->fresh();
        $this->assertNotNull($undone?->undone_at);
        $this->assertSame($this->teacher->id, $undone->undone_by);

        $this->openCase()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('openCase.status', 'pending')
            ->where('openCase.confirmation', null)
            ->has('openCase.history', 1)
            ->where('openCase.history.0.undone_by', $this->teacher->name));

        // And it can be declared again.
        $this->confirm()->assertRedirect();
        $this->openCase()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('openCase.status', 'registered')
            ->has('openCase.history', 2));
    }

    #[Test]
    public function a_correction_after_the_declaration_is_shown_without_claiming_the_inovar_changed(): void
    {
        $this->confirm();

        $this->asTeacher()->delete('/records/'.$this->lateness[1]->ulid)->assertRedirect();
        $this->record($this->ana, EvidenceKind::Lateness, '2026-10-09');

        $this->openCase()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('openCase.status', 'registered')
            ->where('openCase.count', 3)
            ->where('openCase.confirmation.count', 3)
            ->where('openCase.confirmation.changes.removed', ['2026-10-02'])
            ->where('openCase.confirmation.changes.added', ['2026-10-09']));

        // A date moved after the declaration: the old date no longer counts,
        // the new one was never declared.
        $this->inTenant($this->organization, fn () => $this->lateness[2]->update(['occurred_at' => '2026-10-07']));
        $this->openCase()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('openCase.confirmation.changes.removed', ['2026-10-02', '2026-10-05'])
            ->where('openCase.confirmation.changes.added', ['2026-10-07', '2026-10-09']));

        // Falling below three keeps the declaration and its history.
        $this->asTeacher()->delete('/records/'.$this->lateness[0]->ulid)->assertRedirect();
        $this->openCase()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('openCase.status', 'registered')
            ->where('openCase.count', 2));
    }

    #[Test]
    public function a_correction_before_the_declaration_takes_the_case_out_of_por_tratar(): void
    {
        $this->asTeacher()->delete('/records/'.$this->lateness[2]->ulid)->assertRedirect();

        $this->openCase()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('openCase.status', 'none')
            ->where('openCase.count', 2));
    }

    #[Test]
    public function a_support_session_reads_the_case_but_never_declares(): void
    {
        $this->actingAs($this->teacher)
            ->withSession(['organization_id' => $this->organization->id, 'impersonator_id' => 999])
            ->get('/records?'.http_build_query(['view' => 'lateness', 'case_class' => $this->schoolClass->ulid, 'case_student' => $this->ana->ulid, 'case_category' => 'lateness']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('can.confirm', false)
                ->where('openCase.can_confirm', false)
                ->where('openCase.count', 3));

        $this->confirm(impersonating: true)->assertForbidden()->assertSessionHasNoErrors();

        // Refused before validation: an invalid body is still a 403.
        $this->actingAs($this->teacher)
            ->withSession(['organization_id' => $this->organization->id, 'impersonator_id' => 999])
            ->postJson('/records/acompanhamento/confirmacoes', ['class' => $this->schoolClass->ulid])
            ->assertForbidden();

        $this->assertSame(0, $this->confirmations());
    }

    #[Test]
    public function a_support_session_never_undoes_a_declaration(): void
    {
        $this->confirm()->assertRedirect();
        $confirmation = $this->inTenant($this->organization, fn () => RecordFollowupConfirmation::query()->sole());

        $this->actingAs($this->teacher)
            ->withSession(['organization_id' => $this->organization->id, 'impersonator_id' => 999])
            ->delete('/records/acompanhamento/confirmacoes/'.$confirmation->ulid)
            ->assertForbidden();

        $this->assertNull($confirmation->fresh()?->undone_at);
    }

    #[Test]
    public function a_teacher_who_does_not_teach_the_class_can_neither_declare_nor_undo(): void
    {
        $this->confirm();
        $confirmation = $this->inTenant($this->organization, fn () => RecordFollowupConfirmation::query()->sole());
        $colleague = $this->colleagueTeaching();

        $this->actingAs($colleague)->withSession(['organization_id' => $this->organization->id])
            ->post('/records/acompanhamento/confirmacoes', [
                'class' => $this->schoolClass->ulid, 'student' => $this->ana->ulid, 'category' => 'lateness',
                'period' => $this->firstPeriod->ulid, 'occurrences' => $this->shown($this->lateness),
            ])->assertForbidden();

        $this->actingAs($colleague)->withSession(['organization_id' => $this->organization->id])
            ->delete('/records/acompanhamento/confirmacoes/'.$confirmation->ulid)
            ->assertForbidden();

        $this->assertNull($confirmation->fresh()?->undone_at);
    }

    #[Test]
    public function a_custom_date_range_shows_the_case_but_offers_no_declaration(): void
    {
        $this->asTeacher()->get('/records?'.http_build_query([
            'mode' => 'range', 'from' => '2026-10-01', 'to' => '2026-10-31', 'view' => 'lateness',
            'case_class' => $this->schoolClass->ulid, 'case_student' => $this->ana->ulid, 'case_category' => 'lateness',
        ]))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('openCase.status', null)
            ->where('openCase.can_confirm', false)
            ->where('openCase.message.text', 'O aluno n.º 5, Ana Costa, acumulou três faltas por atraso na disciplina de '.$this->schoolClass->subject->name.', no período de 01/10/2026 a 31/10/2026, nos dias 01/10, 02/10 e 05/10. Solicita-se acompanhamento no sentido de promover o cumprimento do horário de início das aulas.'));
    }

    #[Test]
    public function a_case_with_a_declaration_blocks_removing_the_period_class_and_student_with_a_sentence(): void
    {
        $this->confirm();

        $this->inTenant($this->organization, function (): void {
            $this->assertContains('acompanhamento dos registos', app(EnrollmentHistory::class)->blocking($this->ana));
        });
    }
}
