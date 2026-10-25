<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicPeriod;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\ClassificationStatus;
use App\Models\Enrollment;
use App\Models\EvaluationSheetExport;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\RectificationReason;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Assessment\ConfirmClassification;
use App\Services\Assessment\ExternalCommunicationNotice;
use App\Services\Assessment\ProposeClassifications;
use App\Services\Assessment\PublishClassifications;
use App\Services\Assessment\RectifyClassification;
use App\Support\Assessment\RectificationAccess;
use App\Support\Hashing\CanonicalPayload;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The HTTP surface of rectifying a classification (spec §4, §7, §9): the route,
 * the dialog's endpoint and the payload both screens draw «Retificar» from.
 *
 * The rule itself is RectificationAccessTest's and the writes are
 * RectifyClassificationTest's; this proves they are wired to the right route
 * with the right authorization — notably that the head of an institution, who
 * does not teach the class, gets through once the period is closed.
 */
class RectifyClassificationHttpTest extends TestCase
{
    use RefreshDatabase;

    private const BLOCKED = 'O período está encerrado. Só o responsável da instituição pode retificar.';

    private User $ana;

    private SchoolClass $class;

    private AcademicPeriod $period;

    private Classification $carolina;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ana = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);
        $this->travelTo(Carbon::parse('2026-12-15 10:00:00'));

        $this->organization = $this->ana->personalOrganization();
        $this->carolina = $this->asTenant(function (): Classification {
            $this->class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $this->period = $this->class->academicYear->periods()->where('sequence', 1)->firstOrFail();

            app(ProposeClassifications::class)->forPeriod($this->class, $this->period);
            $row = $this->classificationFor('Carolina Nunes');
            $level = $this->class->profileVersion->scale->levels()->where('code', '4')->firstOrFail();
            app(ConfirmClassification::class)->confirm($row, $this->ana, $level->id);
            app(PublishClassifications::class)->forPeriod($this->class, $this->period, ClassificationScope::Period);

            return $row->fresh();
        });
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function asTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor(Organization::query()->findOrFail($this->organization->id), $callback);
    }

    private function classificationFor(string $name): Classification
    {
        return Classification::query()
            ->where('academic_period_id', $this->period->id)
            ->where('scope', ClassificationScope::Period)
            ->get()
            ->first(fn (Classification $c) => $c->enrollment->student->identity->display_name === $name);
    }

    private function setStates(string $yearStatus, string $periodStatus): void
    {
        DB::table('academic_years')->where('id', $this->period->academic_year_id)->update(['status' => $yearStatus]);
        DB::table('academic_periods')->where('id', $this->period->id)->update(['status' => $periodStatus]);
    }

    /** The organization becomes a school, with a head who is a member but does not teach the class. */
    private function institution(): User
    {
        $head = User::factory()->create();
        DB::table('organizations')->where('id', $this->organization->id)->update([
            'type' => OrganizationType::Institutional->value,
            'owner_id' => $head->getKey(),
        ]);
        $this->member($head);

        return $head;
    }

    /** A user who belongs to the classification's organization and teaches nothing there. */
    private function member(User $user): User
    {
        DB::table('organization_memberships')->insertOrIgnore([
            'organization_id' => $this->organization->id,
            'user_id' => $user->getKey(),
            'joined_at' => now()->addDay(),
        ]);

        return $user;
    }

    /** Acting inside the classification's organization, as a user who belongs to several would pick it. */
    private function acting(User $user): static
    {
        return $this->actingAs($user)->withSession(['organization_id' => $this->organization->id]);
    }

    private function enrollmentUlid(): string
    {
        return (string) Enrollment::query()->withoutGlobalScopes()->findOrFail($this->carolina->enrollment_id)->ulid;
    }

    private function rectifyUrl(): string
    {
        return "/classes/{$this->class->ulid}/classifications/{$this->period->ulid}/{$this->enrollmentUlid()}/rectify";
    }

    private function showUrl(): string
    {
        return "/classes/{$this->class->ulid}/classifications/{$this->period->ulid}";
    }

    private function levelId(string $code): int
    {
        return $this->asTenant(fn () => $this->class->profileVersion->scale->levels()->where('code', $code)->firstOrFail()->id);
    }

    /** @param array<string, mixed> $overrides */
    private function rectifyAs(User $user, array $overrides = [])
    {
        return $this->acting($user)->from($this->showUrl())->post($this->rectifyUrl(), $overrides + [
            'reason' => RectificationReason::EntryError->value,
            'final_scale_level_id' => $this->levelId('3'),
            'lock_version' => $this->carolina->lock_version,
        ]);
    }

    private function liveRows(): int
    {
        return DB::table('classifications')
            ->where('enrollment_id', $this->carolina->enrollment_id)
            ->where('academic_period_id', $this->period->id)
            ->where('status', '<>', ClassificationStatus::Superseded->value)
            ->count();
    }

    private function assertUntouched(): void
    {
        $this->assertSame(1, $this->liveRows());
        $this->assertSame(1, DB::table('classifications')->where('enrollment_id', $this->carolina->enrollment_id)->where('academic_period_id', $this->period->id)->count());
        $this->assertSame('published', DB::table('classifications')->where('id', $this->carolina->id)->value('status'));
    }

    // ------------------------------------------------------- 1–2. the teacher

    #[Test]
    public function an_outsider_cannot_rectify(): void
    {
        // A member of the account who does not teach the class: refused by the policy…
        $this->rectifyAs($this->member(User::factory()->create()))->assertForbidden();
        $this->assertUntouched();

        // …and a user with no access to the organization never even finds the class.
        $this->rectifyAs(User::factory()->create())->assertNotFound();
        $this->assertUntouched();
    }

    #[Test]
    public function the_teacher_rectifies_in_an_open_period(): void
    {
        $this->rectifyAs($this->ana, ['reason' => 'calculation_error', 'note' => 'Nota errada.'])
            ->assertRedirect($this->showUrl())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('inertia.flash_data', fn ($flash) => ($flash['toast']['type'] ?? null) === 'success'
                && ($flash['toast']['message'] ?? null) === 'Classificação retificada.');

        $this->assertSame(1, $this->liveRows());
        $live = DB::table('classifications')->where('rectifies_id', $this->carolina->id)->first();
        $this->assertSame('published', $live->status);
        $this->assertSame('calculation_error', $live->rectification_reason);
        $this->assertSame('Nota errada.', $live->rectification_note);
        $this->assertSame($this->levelId('3'), (int) $live->final_scale_level_id);
        $this->assertSame('superseded', DB::table('classifications')->where('id', $this->carolina->id)->value('status'));
    }

    // ------------------------------------------------------------ 3. refusals

    #[Test]
    public function the_request_is_validated_and_the_service_refusals_reach_the_form(): void
    {
        $this->rectifyAs($this->ana, ['reason' => null])->assertSessionHasErrors('reason');
        $this->rectifyAs($this->ana, ['reason' => 'because'])->assertSessionHasErrors('reason');
        $this->rectifyAs($this->ana, ['lock_version' => null])->assertSessionHasErrors('lock_version');
        $this->rectifyAs($this->ana, ['final_value' => '1e2'])->assertSessionHasErrors('final_value');
        $this->rectifyAs($this->ana, ['note' => str_repeat('x', 1001)])->assertSessionHasErrors('note');
        $this->assertUntouched();

        $this->rectifyAs($this->ana, ['reason' => 'other', 'note' => '  '])
            ->assertRedirect($this->showUrl())
            ->assertSessionHasErrors(['rectify' => 'Explique o motivo da retificação: «Outro» exige uma nota.']);

        // The same level it already has.
        $this->rectifyAs($this->ana, ['final_scale_level_id' => $this->levelId('4')])
            ->assertSessionHasErrors(['rectify' => 'O valor indicado é igual ao atual. Indique o novo valor da classificação.']);
        $this->assertUntouched();

        // A screen that was open while someone else got there first.
        $this->rectifyAs($this->ana, ['lock_version' => $this->carolina->lock_version + 3])
            ->assertSessionHasErrors(['rectify' => 'Esta classificação já foi retificada. Recarregue a página para ver o valor atual.']);
        $this->assertUntouched();
    }

    #[Test]
    public function something_that_is_not_published_is_not_found(): void
    {
        $eva = $this->asTenant(fn () => $this->classificationFor('Eva Salgado'));
        $this->assertSame(ClassificationStatus::Proposed, $eva->status);
        $evaUlid = Enrollment::query()->withoutGlobalScopes()->findOrFail($eva->enrollment_id)->ulid;

        $this->acting($this->ana)
            ->post("/classes/{$this->class->ulid}/classifications/{$this->period->ulid}/{$evaUlid}/rectify", [
                'reason' => 'entry_error', 'final_scale_level_id' => $this->levelId('3'), 'lock_version' => 0,
            ])
            ->assertNotFound();
    }

    // ------------------------------------------------------- 4–5. institutions

    #[Test]
    public function in_a_closed_period_only_the_head_rectifies_and_with_confirmation(): void
    {
        $head = $this->institution();
        $colleague = $this->member(User::factory()->create());
        $this->asTenant(fn () => $this->class->teachers()->syncWithoutDetaching([$colleague->getKey() => ['role' => 'collaborator']]));
        $this->setStates('closed', 'open');

        // A teacher who is not the owner of the school.
        $this->rectifyAs($colleague, ['closed_confirmed' => 1])->assertForbidden();
        $this->rectifyAs($this->ana, ['closed_confirmed' => 1])->assertForbidden();
        $this->assertUntouched();

        // The head, who does not teach the class: refused without the extra confirmation…
        $this->rectifyAs($head)
            ->assertSessionHasErrors('rectify');
        $this->assertUntouched();

        // …and through with it.
        $this->rectifyAs($head, ['closed_confirmed' => 1])
            ->assertRedirect($this->showUrl())
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $this->liveRows());
        $live = DB::table('classifications')->where('rectifies_id', $this->carolina->id)->first();
        $this->assertSame($head->getKey(), (int) $live->rectified_by);
    }

    #[Test]
    public function in_an_open_period_a_head_who_does_not_teach_is_refused(): void
    {
        $head = $this->institution();

        $this->rectifyAs($head, ['closed_confirmed' => 1])->assertForbidden();
        $this->assertUntouched();

        // The class teacher still can.
        $this->rectifyAs($this->ana)->assertSessionHasNoErrors();
        $this->assertSame(1, $this->liveRows());
    }

    // ------------------------------------------------------------- 6. tenant

    #[Test]
    public function the_rule_is_the_classifications_not_the_tenant_chosen(): void
    {
        $head = $this->institution();
        $this->setStates('closed', 'open');

        // The head also has a personal account, and it is the tenant the session picked.
        $personal = $head->personalOrganization();
        $this->assertNotNull($personal);
        $this->assertNotSame($this->organization->id, $personal->id);

        // The rule is read from the classification's organization (the school's): the head
        // is allowed, whatever tenant is current…
        $access = app(CurrentOrganization::class)->runFor($personal, fn () => RectificationAccess::for($head, $this->carolina));
        $this->assertTrue($access->allowed);
        $this->assertTrue($access->needsConfirmation);

        // …but over HTTP the class itself is tenant-scoped, so this tenant simply does not
        // contain it: the request never reaches the rule, and nothing is written.
        $this->actingAs($head)->withSession(['organization_id' => $personal->id])
            ->from($this->showUrl())
            ->post($this->rectifyUrl(), [
                'reason' => 'entry_error', 'final_scale_level_id' => $this->levelId('3'),
                'lock_version' => $this->carolina->lock_version, 'closed_confirmed' => 1,
            ])
            ->assertNotFound();
        $this->assertUntouched();

        // With the school as tenant, the same request succeeds.
        $this->actingAs($head)->withSession(['organization_id' => $this->organization->id])
            ->from($this->showUrl())
            ->post($this->rectifyUrl(), [
                'reason' => 'entry_error', 'final_scale_level_id' => $this->levelId('3'),
                'lock_version' => $this->carolina->lock_version, 'closed_confirmed' => 1,
            ])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, $this->liveRows());
        $this->assertSame(2, DB::table('classifications')->where('enrollment_id', $this->carolina->enrollment_id)->where('academic_period_id', $this->period->id)->count());
    }

    // ------------------------------------------------------------ 7. payloads

    /** @return array<string, mixed> Carolina's `rectification` on the given screen. */
    private function rectificationOn(string $screen, User $user): array
    {
        $url = $screen === 'classifications/Show'
            ? $this->showUrl()
            : "/classes/{$this->class->ulid}/results/{$this->period->ulid}";
        $found = null;

        $this->acting($user)->get($url)->assertOk()->assertInertia(function (AssertableInertia $page) use ($screen, &$found): void {
            $page->component($screen);
            $rows = $page->toArray()['props']['rows'];
            $row = collect($rows)->first(fn (array $row) => $row['name'] === 'Carolina Nunes');
            $found = $row['classification']['rectification'];
        });

        return $found;
    }

    /** @return array<string, array{string}> */
    public static function screens(): array
    {
        return ['classifications' => ['classifications/Show'], 'results' => ['results/Show']];
    }

    #[Test]
    #[DataProvider('screens')]
    public function the_payload_says_what_the_user_may_do(string $screen): void
    {
        $lock = $this->carolina->lock_version;

        // Personal, open.
        $this->assertSame(
            ['allowed' => true, 'needs_confirmation' => false, 'blocked_reason' => null, 'lock_version' => $lock, 'count' => 0],
            $this->rectificationOn($screen, $this->ana),
        );

        // Personal, closed: still the teacher, now with a confirmation. Never «responsável».
        $this->setStates('closed', 'open');
        $personalClosed = $this->rectificationOn($screen, $this->ana);
        $this->assertSame(
            ['allowed' => true, 'needs_confirmation' => true, 'blocked_reason' => null, 'lock_version' => $lock, 'count' => 0],
            $personalClosed,
        );
        $this->setStates('active', 'open');

        $head = $this->institution();
        $colleague = $this->member(User::factory()->create());
        $this->asTenant(fn () => $this->class->teachers()->syncWithoutDetaching([$colleague->getKey() => ['role' => 'collaborator']]));
        $this->setStates('closed', 'open');

        // Institution, closed, teacher who is not the head: told why.
        $this->assertSame(
            ['allowed' => false, 'needs_confirmation' => false, 'blocked_reason' => self::BLOCKED, 'lock_version' => $lock, 'count' => 0],
            $this->rectificationOn($screen, $colleague),
        );

        // Institution, closed, the head (who has to teach the class to OPEN these screens,
        // which belong to the class policy): allowed, with confirmation.
        $this->asTenant(fn () => $this->class->teachers()->syncWithoutDetaching([$head->getKey() => ['role' => 'collaborator']]));
        $this->assertSame(
            ['allowed' => true, 'needs_confirmation' => true, 'blocked_reason' => null, 'lock_version' => $lock, 'count' => 0],
            $this->rectificationOn($screen, $head),
        );
    }

    #[Test]
    #[DataProvider('screens')]
    public function the_dialog_is_given_the_reasons_to_choose_from(string $screen): void
    {
        $url = $screen === 'classifications/Show'
            ? $this->showUrl()
            : "/classes/{$this->class->ulid}/results/{$this->period->ulid}";

        $this->acting($this->ana)->get($url)->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page->where('rectificationReasons', RectificationReason::options()),
        );
        $this->assertSame(
            [['value' => 'other', 'label' => 'Outro', 'requires_note' => true]],
            array_values(array_filter(RectificationReason::options(), fn (array $option) => $option['value'] === 'other')),
        );
    }

    #[Test]
    #[DataProvider('screens')]
    public function the_count_follows_the_chain(string $screen): void
    {

        foreach ([['3', 1], ['2', 2]] as [$code, $count]) {
            $row = $this->rectificationOn($screen, $this->ana);
            $this->acting($this->ana)->post($this->rectifyUrl(), [
                'reason' => 'entry_error', 'final_scale_level_id' => $this->levelId($code), 'lock_version' => $row['lock_version'],
            ])->assertSessionHasNoErrors();

            $after = $this->rectificationOn($screen, $this->ana);
            $this->assertSame($count, $after['count']);
            $this->assertSame(0, $after['lock_version']);
            $this->assertTrue($after['allowed']);
        }

    }

    #[Test]
    #[DataProvider('screens')]
    public function a_personal_account_never_hears_about_a_head(string $screen): void
    {
        foreach ([['active', 'open'], ['closed', 'open'], ['active', 'closed'], ['active', 'archived']] as [$year, $period]) {
            $this->setStates($year, $period);

            $this->assertStringNotContainsString('esponsável', json_encode($this->rectificationOn($screen, $this->ana), JSON_UNESCAPED_UNICODE));
        }
    }

    // ------------------------------------------------------------ 8. history

    private function historyUrl(Classification|string $classification): string
    {
        $ulid = $classification instanceof Classification ? $classification->ulid : $classification;

        return "/classes/{$this->class->ulid}/classifications/{$ulid}/rectification";
    }

    #[Test]
    public function the_dialog_endpoint_returns_the_chain_in_order_and_the_notice(): void
    {
        $first = $this->carolina;
        $second = $this->asTenant(fn () => app(RectifyClassification::class)->rectify(
            $first, $this->ana, RectificationReason::EntryError, null, $this->levelId('3'), null, $first->lock_version, false,
        ));
        $third = $this->asTenant(fn () => app(RectifyClassification::class)->rectify(
            $second, $this->ana, RectificationReason::Other, ' Conselho de turma. ', $this->levelId('2'), null, $second->lock_version, false,
        ));

        // A pauta kept AFTER the publication that holds the student.
        $this->asTenant(function () use ($first): void {
            $payload = ['version' => 3, 'students' => [['enrollment_id' => $first->enrollment_id]]];
            EvaluationSheetExport::create([
                'class_id' => $this->class->id,
                'academic_period_id' => $this->period->id,
                'scope' => ClassificationScope::Period,
                'adapter' => 'inovar',
                'moment_label' => 'Semestre — 1.º Semestre',
                'effective_at' => '2026-12-15',
                'payload' => $payload,
                'payload_hash' => CanonicalPayload::hash($payload),
                'exported_with_warnings' => false,
                'warning_count' => 0,
                'file_disk' => 'local',
                'exported_by' => $this->ana->id,
                'exported_at' => now()->addMinute(),
            ]);
        });

        // Asked with the ulid of a row that has since been superseded: it answers for the cell.
        $json = $this->acting($this->ana)->getJson($this->historyUrl($first))->assertOk()->json();

        $this->assertSame([$first->ulid, $second->ulid, $third->ulid], array_column($json['history'], 'ulid'));
        $this->assertSame([false, false, true], array_column($json['history'], 'is_live'));
        $this->assertSame([false, true, true], array_column($json['history'], 'rectified'));
        $this->assertSame(['superseded', 'superseded', 'published'], array_column($json['history'], 'status'));
        $this->assertSame(['4', '3', '2'], array_map(fn (array $row) => $row['final']['code'], $json['history']));
        $this->assertSame([null, 'Erro de lançamento', 'Outro'], array_column($json['history'], 'reason'));
        $this->assertSame([null, null, 'Conselho de turma.'], array_column($json['history'], 'note'));
        $this->assertSame([$this->ana->name, $this->ana->name, $this->ana->name], array_column($json['history'], 'decided_by'));
        $this->assertNotNull($json['history'][0]['decided_at']);

        $this->assertSame(ExternalCommunicationNotice::GENERIC, $json['notice']['generic']);
        $this->assertSame('inovar', $json['notice']['export']['adapter']);
    }

    #[Test]
    public function a_rectification_without_an_author_says_so_and_never_borrows_the_confirmer(): void
    {
        $first = $this->carolina;
        $second = $this->asTenant(fn () => app(RectifyClassification::class)->rectify(
            $first, $this->ana, RectificationReason::EntryError, null, $this->levelId('3'), null, $first->lock_version, false,
        ));
        $third = $this->asTenant(fn () => app(RectifyClassification::class)->rectify(
            $second, $this->ana, RectificationReason::Other, 'Conselho de turma.', $this->levelId('2'), null, $second->lock_version, false,
        ));

        // The restore of a chain whose author cannot be mapped: the second
        // rectification has no author even though someone confirmed its row;
        // the original decision has no confirmer either.
        DB::table('classifications')->where('id', $second->id)->update(['rectified_by' => null, 'confirmed_by' => $this->ana->id]);
        DB::table('classifications')->where('id', $first->id)->update(['confirmed_by' => null]);

        $json = $this->acting($this->ana)->getJson($this->historyUrl($first))->assertOk()->json();

        $this->assertSame([null, null, $this->ana->name], array_column($json['history'], 'decided_by'));
        $this->assertSame([$first->ulid, $second->ulid, $third->ulid], array_column($json['history'], 'ulid'));
        $this->assertSame(
            ['ulid', 'status', 'is_live', 'final', 'decided_by', 'decided_at', 'reason', 'note', 'rectified'],
            array_keys($json['history'][1]),
        );
    }

    #[Test]
    public function the_dialog_endpoint_has_no_export_notice_without_an_export(): void
    {
        $json = $this->acting($this->ana)->getJson($this->historyUrl($this->carolina))->assertOk()->json();

        $this->assertCount(1, $json['history']);
        $this->assertNull($json['notice']['export']);
        $this->assertNotEmpty($json['notice']['generic']);
    }

    #[Test]
    public function the_history_is_for_those_who_teach_the_class_or_head_the_school(): void
    {
        $head = $this->institution();
        $member = $this->member(User::factory()->create());

        $this->acting($head)->getJson($this->historyUrl($this->carolina))->assertOk();
        $this->acting($this->ana)->getJson($this->historyUrl($this->carolina))->assertOk();
        $this->acting($member)->getJson($this->historyUrl($this->carolina))->assertForbidden();
        $this->actingAs(User::factory()->create())->getJson($this->historyUrl($this->carolina))->assertNotFound();

        // And a classification of another class is not reachable through this one.
        $other = SchoolClass::query()->where('id', '<>', $this->class->id)->first();
        if ($other !== null) {
            $this->acting($this->ana)->getJson("/classes/{$other->ulid}/classifications/{$this->carolina->ulid}/rectification")
                ->assertNotFound();
        }
    }

    // ------------------------------------------------------------ 9. queries

    private function showQueryCount(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->acting($this->ana)->get($this->showUrl())->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    #[Test]
    public function showing_the_classifications_does_not_cost_a_query_per_published_student(): void
    {
        $withOne = $this->showQueryCount();

        $this->asTenant(function (): void {
            app(ProposeClassifications::class)->forPeriod($this->class, $this->period);

            foreach (Classification::query()->where('academic_period_id', $this->period->id)->where('status', ClassificationStatus::Proposed)->get() as $row) {
                app(ConfirmClassification::class)->confirm($row, $this->ana, $this->class->profileVersion->scale->levels()->where('code', '3')->firstOrFail()->id);
            }
            app(PublishClassifications::class)->forPeriod($this->class, $this->period, ClassificationScope::Period);
        });
        $published = Classification::query()->withoutGlobalScopes()->where('academic_period_id', $this->period->id)->where('status', 'published')->count();
        $this->assertGreaterThan(2, $published, 'fixture assumption: the class has several students');

        $withAll = $this->showQueryCount();

        $this->assertLessThanOrEqual($withOne, $withAll, "queries grew with the number of published students: {$withOne} -> {$withAll}");
    }
}
