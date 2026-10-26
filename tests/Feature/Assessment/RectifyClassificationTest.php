<?php

namespace Tests\Feature\Assessment;

use App\Actions\Organizations\CreateInstitutionalOrganization;
use App\Models\AcademicPeriod;
use App\Models\AuditEvent;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\ClassificationStatus;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\RectificationReason;
use App\Models\ResultState;
use App\Models\Scale;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Assessment\BuildEvaluationSheet;
use App\Services\Assessment\BuildResultsProgression;
use App\Services\Assessment\ConfirmClassification;
use App\Services\Assessment\ProposeClassifications;
use App\Services\Assessment\PublishClassifications;
use App\Services\Assessment\RectifyClassification;
use App\Services\Export\DecidedLevelValues;
use App\Support\Assessment\ClassificationDecisionException;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Rectifying a published classification (spec §5, §6): a NEW published row, the
 * old one superseded, and nothing else in the world moved.
 *
 * The classification under test is made published by the real services —
 * proposal, confirmation, publication — and not by an UPDATE, so that what is
 * rectified is what production would hold.
 */
class RectifyClassificationTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private SchoolClass $class;

    private AcademicPeriod $period;

    private Classification $carolina;

    /** The classification's own organization, held by id: a test may change its type. */
    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ana = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);
        // Inside the demo scenario's first semester, as the other classification tests do.
        $this->travelTo(Carbon::parse('2026-12-15 10:00:00'));

        $this->organization = $this->ana->personalOrganization();
        $this->carolina = $this->asTenant(function (): Classification {
            $this->class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $this->period = $this->class->academicYear->periods()->where('sequence', 1)->firstOrFail();

            return $this->publish('Carolina Nunes', '4');
        });
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function asTenant(callable $callback, ?Organization $organization = null): mixed
    {
        return app(CurrentOrganization::class)->runFor($organization ?? Organization::query()->findOrFail($this->organization->id), $callback);
    }

    /** Propose, confirm and publish one student's period classification, on the class's level scale. */
    private function publish(string $name, string $levelCode): Classification
    {
        app(ProposeClassifications::class)->forPeriod($this->class, $this->period);

        $classification = $this->classificationFor($name);
        $level = $this->class->profileVersion->scale->levels()->where('code', $levelCode)->firstOrFail();

        app(ConfirmClassification::class)->confirm($classification, $this->ana, $level->id);
        app(PublishClassifications::class)->forPeriod($this->class, $this->period, ClassificationScope::Period);

        return $classification->fresh();
    }

    private function classificationFor(string $name, ClassificationScope $scope = ClassificationScope::Period): Classification
    {
        return Classification::query()
            ->where('academic_period_id', $this->period->id)
            ->where('scope', $scope)
            ->get()
            ->first(fn (Classification $c) => $c->enrollment->student->identity->display_name === $name);
    }

    private function levelId(string $code): int
    {
        return $this->asTenant(fn () => $this->class->profileVersion->scale->levels()->where('code', $code)->firstOrFail()->id);
    }

    /** Rectifies Carolina's published row to level $code, as the teacher, with sensible defaults. */
    private function rectify(string $code = '3', array $overrides = []): Classification
    {
        $arguments = $overrides + [
            'classification' => $this->carolina,
            'actor' => $this->ana,
            'reason' => RectificationReason::EntryError,
            'note' => null,
            'finalScaleLevelId' => $this->levelId($code),
            'finalValue' => null,
            'expectedLockVersion' => $this->carolina->lock_version,
            'closedConfirmed' => false,
        ];

        return $this->asTenant(fn () => app(RectifyClassification::class)->rectify(...$arguments));
    }

    private function setStates(string $yearStatus, string $periodStatus): void
    {
        DB::table('academic_years')->where('id', $this->period->academic_year_id)->update(['status' => $yearStatus]);
        DB::table('academic_periods')->where('id', $this->period->id)->update(['status' => $periodStatus]);
    }

    private function auditEvent(): AuditEvent
    {
        return $this->asTenant(fn () => AuditEvent::where('event', 'classification.rectified')->firstOrFail());
    }

    private function raw(int $id): object
    {
        return DB::table('classifications')->where('id', $id)->first();
    }

    private function liveCount(Classification $of): int
    {
        return DB::table('classifications')
            ->where('enrollment_id', $of->enrollment_id)
            ->where('academic_period_id', $of->academic_period_id)
            ->where('scope', $of->scope->value)
            ->where('status', '<>', ClassificationStatus::Superseded->value)
            ->count();
    }

    private function refused(callable $call, string $expected): void
    {
        try {
            $call();
        } catch (ClassificationDecisionException $exception) {
            $this->assertSame($expected, $exception->getMessage());

            return;
        }

        $this->fail('A retificação devia ter sido recusada.');
    }

    // --------------------------------------------------------- 1–2. the chain

    #[Test]
    public function rectifies_once(): void
    {
        $old = $this->carolina;
        $this->assertSame('4.000', $old->final_value);

        $new = $this->rectify('3', ['reason' => RectificationReason::CalculationError, 'note' => '  Nota errada.  ']);

        $oldRow = $this->raw($old->id);
        $this->assertSame('superseded', $oldRow->status);
        $this->assertSame($new->id, (int) $oldRow->superseded_by_id);
        $this->assertNotSame($old->id, (int) $oldRow->superseded_by_id);
        $this->assertSame($old->lock_version + 1, (int) $oldRow->lock_version);

        $newRow = $this->raw($new->id);
        $this->assertSame('published', $newRow->status);
        $this->assertSame($old->id, (int) $newRow->rectifies_id);
        $this->assertSame('calculation_error', $newRow->rectification_reason);
        $this->assertSame('Nota errada.', $newRow->rectification_note);
        $this->assertSame($this->ana->id, (int) $newRow->rectified_by);
        $this->assertNotNull($newRow->rectified_at);
        $this->assertNotNull($newRow->published_at);
        $this->assertSame($this->levelId('3'), (int) $newRow->final_scale_level_id);
        $this->assertEquals(3, $newRow->final_value);
        $this->assertSame('Nota errada.', $newRow->override_reason);
        $this->assertSame(0, (int) $newRow->lock_version);
        $this->assertNull($newRow->superseded_by_id);
        $this->assertSame($old->organization_id, (int) $newRow->organization_id);

        // The proposal, and the snapshot that explains it, are carried over untouched.
        $this->assertSame($oldRow->proposed_normalized_value, $newRow->proposed_normalized_value);
        $this->assertSame($oldRow->proposed_value, $newRow->proposed_value);
        $this->assertSame($oldRow->proposed_scale_level_id, $newRow->proposed_scale_level_id);
        $this->assertNotNull($oldRow->calculation_snapshot_id);
        $this->assertSame($oldRow->calculation_snapshot_id, $newRow->calculation_snapshot_id);

        $this->assertSame(1, $this->liveCount($old));
        $this->assertSame(0, DB::table('classifications')->whereColumn('superseded_by_id', 'id')->count());
        $this->assertSame($new->id, $this->asTenant(fn () => $old->fresh()->supersededBy->id));
    }

    #[Test]
    public function rectifies_twice(): void
    {
        $a = $this->carolina;
        $b = $this->rectify('3');
        $c = $this->asTenant(fn () => app(RectifyClassification::class)->rectify(
            $b, $this->ana, RectificationReason::ReviewOrComplaint, null, $this->levelId('2'), null, $b->lock_version, false,
        ));

        $this->assertSame('superseded', $this->raw($a->id)->status);
        $this->assertSame('superseded', $this->raw($b->id)->status);
        $this->assertSame('published', $this->raw($c->id)->status);
        $this->assertSame($b->id, (int) $this->raw($a->id)->superseded_by_id);
        $this->assertSame($c->id, (int) $this->raw($b->id)->superseded_by_id);
        $this->assertSame($a->id, (int) $this->raw($b->id)->rectifies_id);
        $this->assertSame($b->id, (int) $this->raw($c->id)->rectifies_id);
        $this->assertNull($this->raw($c->id)->superseded_by_id);
        $this->assertSame(1, $this->liveCount($a));
        $this->assertSame(0, DB::table('classifications')->whereColumn('superseded_by_id', 'id')->count());
    }

    // ----------------------------------------------------------- 3. staleness

    #[Test]
    public function second_attempt_with_stale_version_is_refused(): void
    {
        $this->rectify('3');
        $before = DB::table('classifications')->count();

        // The same screen submitted again: the row it was showing is gone.
        $this->refused(fn () => $this->rectify('2'), 'Esta classificação já foi retificada. Recarregue a página para ver o valor atual.');
        $this->assertSame($before, DB::table('classifications')->count());
    }

    #[Test]
    public function a_wrong_expected_version_is_refused_even_on_a_live_row(): void
    {
        $this->refused(
            fn () => $this->rectify('3', ['expectedLockVersion' => $this->carolina->lock_version + 5]),
            'Esta classificação já foi retificada. Recarregue a página para ver o valor atual.',
        );

        $this->assertSame('published', $this->raw($this->carolina->id)->status);
    }

    #[Test]
    public function only_a_published_row_can_be_rectified(): void
    {
        $this->asTenant(function (): void {
            app(ProposeClassifications::class)->forPeriod($this->class, $this->period);
        });
        $eva = $this->asTenant(fn () => $this->classificationFor('Eva Salgado'));
        $this->assertSame(ClassificationStatus::Proposed, $eva->status);

        $this->refused(
            fn () => $this->rectify('3', ['classification' => $eva, 'expectedLockVersion' => $eva->lock_version]),
            'Só uma classificação publicada pode ser retificada.',
        );
    }

    // ------------------------------------------------------ 4. the same value

    #[Test]
    public function same_value_refused(): void
    {
        $before = DB::table('classifications')->count();

        // The same LEVEL.
        $this->refused(fn () => $this->rectify('4'), 'O valor indicado é igual ao atual. Indique o novo valor da classificação.');

        $this->assertSame($before, DB::table('classifications')->count());
        $this->assertSame('published', $this->raw($this->carolina->id)->status);
    }

    #[Test]
    public function the_same_number_written_differently_is_the_same_value(): void
    {
        $this->onNumericScale();
        $published = $this->asTenant(function (): Classification {
            app(ProposeClassifications::class)->forPeriod($this->class, $this->period);
            $row = $this->classificationFor('Eva Salgado');
            app(ConfirmClassification::class)->confirm($row, $this->ana, null, '15.000');
            app(PublishClassifications::class)->forPeriod($this->class, $this->period, ClassificationScope::Period);

            return $row->fresh();
        });
        $this->assertSame('15.000', $published->final_value);

        // «15» against the stored «15.000»: numerically equal, never compared as strings.
        foreach (['15', '15.0', '15.000'] as $written) {
            $this->refused(
                fn () => $this->asTenant(fn () => app(RectifyClassification::class)->rectify(
                    $published, $this->ana, RectificationReason::EntryError, null, null, $written, $published->lock_version, false,
                )),
                'O valor indicado é igual ao atual. Indique o novo valor da classificação.',
            );
        }

        $new = $this->asTenant(fn () => app(RectifyClassification::class)->rectify(
            $published, $this->ana, RectificationReason::EntryError, null, null, '15.5', $published->lock_version, false,
        ));
        $this->assertSame('15.500', $new->final_value);
        $this->assertNull($new->final_scale_level_id);
    }

    // ------------------------------------------------------------ 5. the note

    #[Test]
    public function other_reason_requires_note(): void
    {
        foreach ([null, '', '   '] as $note) {
            $this->refused(
                fn () => $this->rectify('3', ['reason' => RectificationReason::Other, 'note' => $note]),
                'Explique o motivo da retificação: «Outro» exige uma nota.',
            );
        }

        $this->assertSame('published', $this->raw($this->carolina->id)->status);

        $new = $this->rectify('3', ['reason' => RectificationReason::Other, 'note' => 'Conselho de turma de 14 de dezembro.']);
        $this->assertSame('other', $this->raw($new->id)->rectification_reason);
    }

    #[Test]
    public function without_a_note_the_reason_label_stands_as_the_override_reason(): void
    {
        $new = $this->rectify('3');

        $this->assertNull($this->raw($new->id)->rectification_note);
        $this->assertSame('Erro de lançamento', $this->raw($new->id)->override_reason);
    }

    #[Test]
    public function no_explicit_value_refused(): void
    {
        // Nothing indicated would make the resolver answer «the proposal».
        // A rectification states the value it corrects TO.
        $this->refused(
            fn () => $this->rectify('3', ['finalScaleLevelId' => null, 'finalValue' => null]),
            'Indique o nível ou a classificação a atribuir.',
        );
        $this->refused(
            fn () => $this->rectify('3', ['finalScaleLevelId' => null, 'finalValue' => '  ']),
            'Indique o nível ou a classificação a atribuir.',
        );

        $this->assertSame('published', $this->raw($this->carolina->id)->status);
    }

    #[Test]
    public function a_level_of_another_scale_is_refused(): void
    {
        $this->refused(
            fn () => $this->rectify('3', ['finalScaleLevelId' => 999999]),
            'O nível indicado não pertence à escala de classificação desta turma.',
        );
        $this->assertSame('published', $this->raw($this->carolina->id)->status);
    }

    // ------------------------------------------------------------- 6. closing

    #[Test]
    public function closed_requires_confirmation(): void
    {
        $this->setStates('closed', 'open');

        $this->refused(fn () => $this->rectify('3'), 'O período está encerrado. Confirme que pretende retificar uma classificação já fechada.');
        $this->assertSame('published', $this->raw($this->carolina->id)->status);
        $this->assertSame(1, DB::table('classifications')->where('enrollment_id', $this->carolina->enrollment_id)->where('academic_period_id', $this->period->id)->count());

        $new = $this->rectify('3', ['closedConfirmed' => true]);
        $this->assertSame('published', $this->raw($new->id)->status);

        $event = $this->auditEvent();
        $this->assertTrue($event->properties['period_closed']);
    }

    #[Test]
    public function an_open_period_does_not_ask_for_the_extra_confirmation(): void
    {
        $this->setStates('active', 'open');

        $this->rectify('3', ['closedConfirmed' => false]);

        $event = $this->auditEvent();
        $this->assertFalse($event->properties['period_closed']);
    }

    // ------------------------------------------------------- 7. authorisation

    #[Test]
    public function unauthorised_refused(): void
    {
        $stranger = User::factory()->create();
        $before = $this->snapshotCounts();

        $this->refused(
            fn () => $this->rectify('3', ['actor' => $stranger, 'closedConfirmed' => true]),
            'Não tem permissão para retificar esta classificação.',
        );

        $this->assertSame($before, $this->snapshotCounts());
        $this->assertSame('published', $this->raw($this->carolina->id)->status);
        $this->assertSame(0, DB::table('audit_events')->where('event', 'classification.rectified')->count());
    }

    #[Test]
    public function a_teacher_who_is_not_the_head_cannot_rectify_a_closed_institutional_year(): void
    {
        DB::table('organizations')->where('id', $this->carolina->organization_id)->update([
            'type' => OrganizationType::Institutional->value,
            'owner_id' => User::factory()->create()->getKey(),
        ]);
        $this->setStates('closed', 'open');
        $before = $this->snapshotCounts();

        $this->refused(
            fn () => $this->rectify('3', ['closedConfirmed' => true]),
            'Não tem permissão para retificar esta classificação.',
        );

        $this->assertSame($before, $this->snapshotCounts());
        $this->assertSame('published', $this->raw($this->carolina->id)->status);
        $this->assertNull($this->raw($this->carolina->id)->superseded_by_id);
    }

    #[Test]
    public function the_head_of_the_institution_can_rectify_the_closed_year(): void
    {
        $head = User::factory()->create();
        DB::table('organizations')->where('id', $this->carolina->organization_id)->update([
            'type' => OrganizationType::Institutional->value, 'owner_id' => $head->getKey(),
        ]);
        $this->setStates('closed', 'open');

        $new = $this->rectify('3', ['actor' => $head, 'closedConfirmed' => true]);

        $this->assertSame($head->id, (int) $this->raw($new->id)->rectified_by);
        $this->assertSame('published', $this->raw($new->id)->status);
    }

    #[Test]
    public function every_rectification_the_service_makes_is_in_the_name_of_its_actor_and_never_without_one(): void
    {
        $first = $this->rectify('3');
        $second = $this->rectify('5', ['classification' => $first, 'expectedLockVersion' => $first->lock_version]);

        $this->assertSame($this->ana->id, (int) $this->raw($first->id)->rectified_by);
        $this->assertSame($this->ana->id, (int) $this->raw($second->id)->rectified_by);
        $this->assertSame(
            2,
            DB::table('classifications')->whereNotNull('rectifies_id')->where('rectified_by', $this->ana->id)->count(),
        );
        $this->assertSame(0, DB::table('classifications')->whereNotNull('rectifies_id')->whereNull('rectified_by')->count());

        // The service never takes an absent actor: the column stays empty only
        // for a restore, never for a rectification made here.
        $before = $this->snapshotCounts();

        try {
            $this->rectify('2', ['classification' => $second, 'expectedLockVersion' => $second->lock_version, 'actor' => null]);
            $this->fail('um actor nulo devia ser recusado');
        } catch (\TypeError) {
            $this->assertSame($before, $this->snapshotCounts());
        }
    }

    /** @return array<string, int> */
    private function snapshotCounts(): array
    {
        return [
            'classifications' => DB::table('classifications')->count(),
            'audit_events' => DB::table('audit_events')->count(),
            'live' => $this->liveCount($this->carolina),
        ];
    }

    // -------------------------------------------------------- 8. under review

    #[Test]
    public function under_review_does_not_block(): void
    {
        $affected = DB::table('student_item_scores')
            ->where('enrollment_id', $this->carolina->enrollment_id)
            ->limit(1)
            ->update(['result_state' => ResultState::UnderReview->value, 'points_earned' => null]);
        $this->assertSame(1, $affected, 'o aluno precisa de um score para o cenário fazer sentido');

        $new = $this->rectify('3');

        $this->assertSame('published', $this->raw($new->id)->status);
        $this->assertSame('superseded', $this->raw($this->carolina->id)->status);
    }

    // -------------------------------------------------------- 9. touches nothing

    #[Test]
    public function touches_nothing_else(): void
    {
        $tables = ['instruments', 'student_item_scores', 'scales', 'scale_levels', 'assessment_profile_versions', 'calculation_snapshots'];
        $fingerprint = function () use ($tables): array {
            $out = [];

            foreach ($tables as $table) {
                $rows = DB::table($table)->orderBy('id')->get();
                $out[$table] = [$rows->count(), md5($rows->toJson())];
                $this->assertGreaterThan(0, $rows->count(), "{$table} devia ter dados para a prova valer");
            }

            return $out;
        };

        $before = $fingerprint();
        $this->rectify('3');

        $this->assertSame($before, $fingerprint());
    }

    // --------------------------------------------------------------- 10. audit

    #[Test]
    public function audit_event(): void
    {
        $new = $this->rectify('3', ['reason' => RectificationReason::CouncilDecision, 'note' => 'Ata n.º 4.']);

        $event = $this->auditEvent();

        $this->assertSame('Classification', $event->subject_type);
        $this->assertSame($new->id, (int) $event->subject_id);
        $this->assertSame($this->ana->id, (int) $event->causer_id);
        $this->assertSame($new->organization_id, (int) $event->organization_id);
        $this->assertSame('Classificação retificada de 4 — '.$this->levelLabel('4').' para 3 — '.$this->levelLabel('3').'.', $event->summary);

        $p = $event->properties;
        $this->assertSame('4.000', $p['previous_final_value']);
        $this->assertSame($this->levelId('4'), $p['previous_final_scale_level_id']);
        $this->assertSame($this->carolina->ulid, $p['previous_classification_ulid']);
        $this->assertSame($this->carolina->published_at->toIso8601String(), $p['previous_published_at']);
        $this->assertSame('3.000', $p['final_value']);
        $this->assertSame($this->levelId('3'), $p['final_scale_level_id']);
        $this->assertSame('council_decision', $p['reason']);
        $this->assertSame('Ata n.º 4.', $p['note']);
        $this->assertFalse($p['period_closed']);
    }

    private function levelLabel(string $code): string
    {
        return $this->asTenant(fn () => $this->class->profileVersion->scale->levels()->where('code', $code)->firstOrFail()->label);
    }

    // -------------------------------------------------------------- 11. atomic

    #[Test]
    public function atomic_on_failure(): void
    {
        // Thrown while the new row is being written, i.e. AFTER the old one was
        // already marked superseded: the whole transaction has to undo.
        Classification::creating(function (Classification $row): void {
            if ($row->rectifies_id !== null) {
                throw new RuntimeException('falha forçada a meio da retificação');
            }
        });

        $before = DB::table('classifications')->count();

        try {
            $this->rectify('3');
            $this->fail('devia ter lançado');
        } catch (RuntimeException $exception) {
            $this->assertSame('falha forçada a meio da retificação', $exception->getMessage());
        }

        $old = $this->raw($this->carolina->id);
        $this->assertSame('published', $old->status);
        $this->assertNull($old->superseded_by_id);
        $this->assertSame($this->carolina->lock_version, (int) $old->lock_version);
        $this->assertSame($before, DB::table('classifications')->count());
        $this->assertSame(0, DB::table('classifications')->whereNotNull('rectifies_id')->count());
        $this->assertSame(0, DB::table('audit_events')->where('event', 'classification.rectified')->count());
    }

    // ----------------------------------------------------------- 12–13. readers

    #[Test]
    public function readers_see_only_the_new(): void
    {
        $new = $this->rectify('3');
        $enrollmentId = $this->carolina->enrollment_id;
        $three = $this->levelId('3');

        $this->asTenant(function () use ($enrollmentId, $three, $new): void {
            // Pauta.
            $sheet = app(BuildEvaluationSheet::class)->for($this->class, $this->period);
            $rows = collect($sheet['students'])->where('name', 'Carolina Nunes');
            $this->assertCount(1, $rows);
            $this->assertSame($three, $rows->first()['classification']['final_scale_level_id']);
            $this->assertSame('3.000', $rows->first()['classification']['final_value']);

            // Resultados.
            $progression = app(BuildResultsProgression::class)->for($this->class);
            $student = collect($progression['students'])->where('name', 'Carolina Nunes');
            $this->assertCount(1, $student);
            $period = collect($student->first()['periods'])->firstWhere('period_id', $this->period->id);
            $this->assertSame('3.000', $period['classification']['final_value']);

            // Exports: one level, the new one.
            $levels = app(DecidedLevelValues::class)->for($this->period, ClassificationScope::Period, [$enrollmentId]);
            $this->assertSame([$enrollmentId => '3'], $levels);

            // And it counts once.
            $this->assertSame(1, Classification::query()
                ->where('enrollment_id', $enrollmentId)->where('academic_period_id', $this->period->id)
                ->where('scope', ClassificationScope::Period)->whereNull('superseded_by_id')->count());
            $this->assertSame($new->id, Classification::query()
                ->where('enrollment_id', $enrollmentId)->where('academic_period_id', $this->period->id)
                ->where('scope', ClassificationScope::Period)->whereNull('superseded_by_id')->value('id'));
        });
    }

    #[Test]
    public function accumulated_untouched(): void
    {
        $accumulated = $this->asTenant(function (): Classification {
            app(ProposeClassifications::class)->forPeriod($this->class, $this->period, ClassificationScope::Accumulated);

            return $this->classificationFor('Carolina Nunes', ClassificationScope::Accumulated);
        });
        $before = DB::table('classifications')->where('id', $accumulated->id)->first();

        $this->rectify('3');

        $this->assertEquals($before, DB::table('classifications')->where('id', $accumulated->id)->first());
        $this->assertSame(1, DB::table('classifications')
            ->where('enrollment_id', $accumulated->enrollment_id)->where('scope', 'accumulated')->count());
    }

    // ------------------------------------------------------------- 14. tenants

    #[Test]
    public function works_whatever_the_current_tenant(): void
    {
        $school = app(CreateInstitutionalOrganization::class)->create('Escola', User::factory()->create());
        DB::table('organization_memberships')->insert([
            'organization_id' => $school->id, 'user_id' => $this->ana->getKey(), 'joined_at' => now(),
        ]);

        $arguments = fn (int $version) => [
            $this->carolina, $this->ana, RectificationReason::EntryError, null, $this->levelId('3'), null, $version, false,
        ];

        // Another organization of the actor is the current one…
        $new = $this->asTenant(
            fn () => app(RectifyClassification::class)->rectify(...$arguments($this->carolina->lock_version)),
            $school,
        );

        // …and the row is born in the classification's own.
        $this->assertSame($this->carolina->organization_id, (int) $this->raw($new->id)->organization_id);
        $this->assertNotSame($school->id, (int) $this->raw($new->id)->organization_id);
        $this->assertSame($this->carolina->organization_id, (int) DB::table('audit_events')->where('event', 'classification.rectified')->value('organization_id'));
        // The tenant that was current is current again.
        $this->assertFalse(app(CurrentOrganization::class)->isResolved());
    }

    #[Test]
    public function works_with_no_tenant_at_all(): void
    {
        $this->assertFalse(app(CurrentOrganization::class)->isResolved());

        $new = app(RectifyClassification::class)->rectify(
            $this->carolina, $this->ana, RectificationReason::EntryError, null, $this->levelId('3'), null, $this->carolina->lock_version, false,
        );

        $this->assertSame($this->carolina->organization_id, (int) $this->raw($new->id)->organization_id);
        $this->assertFalse(app(CurrentOrganization::class)->isResolved());
    }

    // ------------------------------------------------------------------ helpers

    /** Moves the demo class onto the system 0–20 scale, which has no levels of its own. */
    private function onNumericScale(): void
    {
        $this->asTenant(function (): void {
            $numeric = Scale::where('name', 'Escala 0 a 20')->firstOrFail();

            DB::table('assessment_profile_versions')
                ->where('id', $this->class->assessment_profile_version_id)
                ->update(['scale_id' => $numeric->id]);

            $this->class = $this->class->fresh();
        });
    }
}
