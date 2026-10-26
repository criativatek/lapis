<?php

namespace Tests\Feature\Assessment;

use App\Actions\Organizations\CreateInstitutionalOrganization;
use App\Models\AcademicPeriod;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\ClassificationStatus;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\RectificationReason;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Assessment\ConfirmClassification;
use App\Services\Assessment\ProposeClassifications;
use App\Services\Assessment\PublishClassifications;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The head's «Retificações» pages (spec §4): the way into the published
 * classifications of a class the head does NOT teach, once its period is closed.
 *
 * What may be rectified is RectificationAccessTest's and RectifyClassificationHttpTest's;
 * this proves who reaches the pages, which classifications they show, that they
 * show nothing else, and that the head can carry the rectification through from them.
 *
 * Fixtures are valid for the real CHECKs (the suite also runs on MySQL): every
 * classification is produced by the services, never written by hand.
 */
class RectificationsPageTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private SchoolClass $class;

    private AcademicPeriod $period;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        // The Vue pages belong to the next change; only the server's props are under test.
        $this->withoutVite();
        config(['inertia.testing.ensure_pages_exist' => false]);

        $this->ana = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);
        $this->travelTo(Carbon::parse('2026-12-15 10:00:00'));

        $this->organization = $this->ana->personalOrganization();
        $this->asTenant(function (): void {
            $this->class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $this->period = $this->class->academicYear->periods()->where('sequence', 1)->firstOrFail();

            app(ProposeClassifications::class)->forPeriod($this->class, $this->period);
            $this->publish($this->period, 3);
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

    /** Confirms and publishes up to $limit more proposals of the period (inside a tenant). */
    private function publish(AcademicPeriod $period, int $limit): void
    {
        $level = $this->class->profileVersion->scale->levels()->where('code', '4')->firstOrFail();

        Classification::query()
            ->where('academic_period_id', $period->id)
            ->where('scope', ClassificationScope::Period)
            ->where('status', ClassificationStatus::Proposed)
            ->orderBy('id')->limit($limit)->get()
            ->each(fn (Classification $row) => app(ConfirmClassification::class)->confirm($row, $this->ana, $level->id));

        app(PublishClassifications::class)->forPeriod($this->class, $period, ClassificationScope::Period);
    }

    private function setStates(string $yearStatus, string $periodStatus): void
    {
        DB::table('academic_years')->where('id', $this->period->academic_year_id)->update(['status' => $yearStatus]);
        DB::table('academic_periods')->where('id', $this->period->id)->update(['status' => $periodStatus]);
    }

    /** The year is closed: the pages have something to show. */
    private function close(): void
    {
        $this->setStates('closed', 'open');
    }

    /** The organization becomes a school, with a head who is a member but does not teach the class. */
    private function institution(): User
    {
        $head = User::factory()->create();
        DB::table('organizations')->where('id', $this->organization->id)->update([
            'type' => OrganizationType::Institutional->value,
            'owner_id' => $head->getKey(),
        ]);

        return $this->member($head);
    }

    private function member(User $user): User
    {
        DB::table('organization_memberships')->insertOrIgnore([
            'organization_id' => $this->organization->id,
            'user_id' => $user->getKey(),
            'joined_at' => now()->addDay(),
        ]);

        return $user;
    }

    private function teach(User $user): void
    {
        $this->asTenant(fn () => $this->class->teachers()->syncWithoutDetaching([$user->getKey() => ['role' => 'co_teacher']]));
    }

    private function acting(User $user, ?Organization $tenant = null): static
    {
        return $this->actingAs($user)->withSession(['organization_id' => ($tenant ?? $this->organization)->id]);
    }

    private function indexUrl(): string
    {
        return '/retificacoes';
    }

    private function showUrl(?AcademicPeriod $period = null): string
    {
        return "/classes/{$this->class->ulid}/retificacoes/".($period ?? $this->period)->ulid;
    }

    private function levelId(string $code): int
    {
        return $this->asTenant(fn () => $this->class->profileVersion->scale->levels()->where('code', $code)->firstOrFail()->id);
    }

    /** @return array<string, mixed> */
    private function propsOf(User $user, string $url, string $component): array
    {
        $props = [];

        $this->acting($user)->get($url)->assertOk()->assertInertia(function (AssertableInertia $page) use ($component, &$props): void {
            $page->component($component);
            $props = $page->toArray()['props'];
        });

        return $props;
    }

    /** @return list<object> */
    private function viewedEvents(): array
    {
        return DB::table('audit_events')->where('event', 'classification.rectifications_viewed')->get()->all();
    }

    private function livePublished(): int
    {
        return DB::table('classifications')
            ->where('academic_period_id', $this->period->id)
            ->where('status', ClassificationStatus::Published->value)
            ->whereNull('superseded_by_id')->count();
    }

    private function secondPeriod(): AcademicPeriod
    {
        return $this->asTenant(fn () => $this->class->academicYear->periods()->where('sequence', 2)->firstOrFail());
    }

    // ------------------------------------------------------- authorization

    #[Test]
    public function the_head_who_does_not_teach_reaches_both_pages(): void
    {
        $head = $this->institution();
        $this->close();
        $total = $this->livePublished();
        $this->assertSame(3, $total);

        $index = $this->propsOf($head, $this->indexUrl(), 'classifications/rectifications/Index');
        $this->assertCount(1, $index['classes']);
        $this->assertSame($this->class->ulid, $index['classes'][0]['ulid']);
        $this->assertSame([$this->ana->name], $index['classes'][0]['teachers']);
        $this->assertSame(
            ['ulid' => $this->period->ulid, 'label' => $this->period->label, 'published_count' => $total, 'rectified_count' => 0],
            $index['classes'][0]['periods'][0],
        );

        $show = $this->propsOf($head, $this->showUrl(), 'classifications/rectifications/Show');
        $this->assertCount($total, $show['rows']);

        $this->assertFalse(Gate::forUser($head)->allows('view', $this->class));
    }

    #[Test]
    public function a_member_who_is_not_the_head_is_refused(): void
    {
        $this->institution();
        $this->close();
        $colleague = $this->member(User::factory()->create());

        $this->acting($colleague)->get($this->indexUrl())->assertForbidden();
        $this->acting($colleague)->get($this->showUrl())->assertForbidden();

        // A teacher of the class who is not the head is no exception.
        $this->acting($this->ana)->get($this->indexUrl())->assertForbidden();
        $this->acting($this->ana)->get($this->showUrl())->assertForbidden();
        $this->assertSame([], $this->viewedEvents());
    }

    #[Test]
    public function a_stranger_never_finds_the_class(): void
    {
        $this->institution();
        $this->close();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get($this->showUrl())->assertNotFound();
        $this->acting($stranger)->get($this->showUrl())->assertNotFound();
        $this->actingAs($stranger)->get($this->indexUrl())->assertForbidden();
        $this->assertSame([], $this->viewedEvents());
    }

    #[Test]
    public function a_personal_account_has_no_head_even_for_its_owner(): void
    {
        $this->close();

        // Ana owns her personal account, and does not teach the class any more.
        $this->asTenant(fn () => $this->class->teachers()->detach());
        $this->assertTrue($this->ana->owns($this->organization));

        $this->acting($this->ana)->get($this->indexUrl())->assertForbidden();
        $this->acting($this->ana)->get($this->showUrl())->assertForbidden();

        // …and teaching it makes no difference.
        $this->teach($this->ana);
        $this->acting($this->ana)->get($this->indexUrl())->assertForbidden();
        $this->acting($this->ana)->get($this->showUrl())->assertForbidden();
        $this->assertSame([], $this->viewedEvents());
    }

    #[Test]
    public function a_head_who_also_teaches_the_class_is_not_audited(): void
    {
        $head = $this->institution();
        $this->teach($head);
        $this->close();

        $this->propsOf($head, $this->showUrl(), 'classifications/rectifications/Show');
        $this->assertSame([], $this->viewedEvents());
    }

    #[Test]
    public function one_institution_never_reaches_another(): void
    {
        $this->institution();
        $this->close();

        // The head of a different school, who is not a member of this one.
        $otherHead = User::factory()->create();
        $other = app(CreateInstitutionalOrganization::class)->create('Outra Escola', $otherHead);

        $this->acting($otherHead, $other)->get($this->showUrl())->assertNotFound();
        $this->acting($otherHead, $other)->get($this->indexUrl())->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page->where('classes', []),
        );

        // Not by the tenant alone: the class's own organization decides.
        $this->assertFalse(Gate::forUser($otherHead)->allows('reviewRectifications', $this->class));
        $this->assertSame([], $this->viewedEvents());
    }

    #[Test]
    public function the_rule_reads_the_classes_organization_not_the_tenant_chosen(): void
    {
        $head = $this->institution();
        $this->close();

        // The head's own personal account is the tenant the session picked.
        $personal = $head->personalOrganization();
        $this->assertNotNull($personal);

        $this->acting($head, $personal)->get($this->showUrl())->assertNotFound();
        $this->acting($head, $personal)->get($this->indexUrl())->assertForbidden();

        // The ability itself, whatever tenant: the school's head.
        $this->assertTrue(app(CurrentOrganization::class)->runFor($personal, fn () => Gate::forUser($head)->allows('reviewRectifications', $this->class)));
        $this->assertSame([], $this->viewedEvents());
    }

    // ------------------------------------------------------ temporal scope

    /** @return array<string, array{string, string, bool}> */
    public static function closings(): array
    {
        return [
            'open period, active year' => ['active', 'open', false],
            'draft' => ['draft', 'draft', false],
            'year closed' => ['closed', 'open', true],
            'year archived' => ['archived', 'open', true],
            'period closed' => ['active', 'closed', true],
            'period archived' => ['active', 'archived', true],
        ];
    }

    #[Test]
    #[DataProvider('closings')]
    public function only_closed_periods_are_listed_and_shown(string $year, string $period, bool $visible): void
    {
        $head = $this->institution();
        $this->setStates($year, $period);

        $this->acting($head)->get($this->indexUrl())->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page->has('classes', $visible ? 1 : 0),
        );

        if ($visible) {
            $this->acting($head)->get($this->showUrl())->assertOk();
            $this->assertCount(1, $this->viewedEvents());
        } else {
            $this->acting($head)->get($this->showUrl())->assertNotFound();
            $this->assertSame([], $this->viewedEvents());
        }
    }

    #[Test]
    public function only_the_live_published_classification_appears(): void
    {
        $head = $this->institution();
        $this->close();
        $second = $this->secondPeriod();

        // Period 2: proposals everywhere, one decision confirmed and not published.
        $this->asTenant(function () use ($second): void {
            app(ProposeClassifications::class)->forPeriod($this->class, $second);
            $row = Classification::query()->where('academic_period_id', $second->id)
                ->where('status', ClassificationStatus::Proposed)->orderBy('id')->firstOrFail();
            app(ConfirmClassification::class)->confirm($row, $this->ana, $this->levelId('4'));
        });
        DB::table('academic_periods')->where('id', $second->id)->update(['status' => 'closed']);

        // Nothing published there: the period is not offered, and its table is empty.
        $index = $this->propsOf($head, $this->indexUrl(), 'classifications/rectifications/Index');
        $this->assertSame([$this->period->ulid], array_column($index['classes'][0]['periods'], 'ulid'));
        $this->assertSame([], $this->propsOf($head, $this->showUrl($second), 'classifications/rectifications/Show')['rows']);

        // A rectified classification: only the new, live row is shown.
        $before = $this->propsOf($head, $this->showUrl(), 'classifications/rectifications/Show');
        $old = $before['rows'][0]['classification'];
        $this->acting($head)->post($this->rectifyUrl($before['rows'][0]['enrollment_ulid']), [
            'reason' => RectificationReason::EntryError->value,
            'final_scale_level_id' => $this->levelId('3'),
            'lock_version' => $old['rectification']['lock_version'],
            'closed_confirmed' => 1,
        ])->assertSessionHasNoErrors();

        $after = $this->propsOf($head, $this->showUrl(), 'classifications/rectifications/Show');
        $this->assertCount(3, $after['rows']);
        $this->assertNotContains($old['ulid'], array_column(array_column($after['rows'], 'classification'), 'ulid'));
        $this->assertSame(3, $this->propsOf($head, $this->indexUrl(), 'classifications/rectifications/Index')['classes'][0]['periods'][0]['published_count']);
    }

    // ------------------------------------------------- classes without a teacher

    #[Test]
    public function a_class_without_a_teacher_is_the_heads_only_once_closed(): void
    {
        $head = $this->institution();
        $this->asTenant(fn () => $this->class->teachers()->detach());

        // Open: not listed, not shown, and the rectification itself is refused.
        $this->setStates('active', 'open');
        $this->acting($head)->get($this->indexUrl())->assertInertia(fn (AssertableInertia $page) => $page->has('classes', 0));
        $this->acting($head)->get($this->showUrl())->assertNotFound();

        $enrollment = $this->asTenant(fn () => Classification::query()->where('academic_period_id', $this->period->id)->where('status', ClassificationStatus::Published)->firstOrFail()->enrollment->ulid);
        $lock = (int) DB::table('classifications')->where('academic_period_id', $this->period->id)->orderBy('id')->value('lock_version');
        $payload = [
            'reason' => RectificationReason::EntryError->value,
            'final_scale_level_id' => $this->levelId('3'),
            'lock_version' => $lock,
            'closed_confirmed' => 1,
        ];

        $this->acting($head)->post($this->rectifyUrl((string) $enrollment), $payload)->assertForbidden();
        $this->assertSame(3, $this->livePublished());
        $this->assertSame(0, DB::table('classifications')->whereNotNull('rectifies_id')->count());

        // Closed: listed with no teachers, and the head rectifies.
        $this->close();
        $props = $this->propsOf($head, $this->indexUrl(), 'classifications/rectifications/Index');
        $this->assertSame([], $props['classes'][0]['teachers']);

        $this->acting($head)->post($this->rectifyUrl((string) $enrollment), $payload)->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('classifications')->whereNotNull('rectifies_id')->count());
    }

    private function rectifyUrl(string $enrollmentUlid, ?AcademicPeriod $period = null): string
    {
        return "/classes/{$this->class->ulid}/classifications/".($period ?? $this->period)->ulid."/{$enrollmentUlid}/rectify";
    }

    // ------------------------------------------------------------- privacy

    /** @return list<string> */
    private static function keysOf(array $array): array
    {
        $keys = array_keys($array);
        sort($keys);

        return $keys;
    }

    /** @return list<string> */
    private static function allKeys(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $keys = [];

        foreach ($value as $key => $child) {
            if (is_string($key)) {
                $keys[] = $key;
            }

            $keys = array_merge($keys, self::allKeys($child));
        }

        return $keys;
    }

    #[Test]
    public function the_payloads_hold_exactly_the_agreed_keys_and_nothing_private(): void
    {
        $head = $this->institution();
        $this->close();

        $index = $this->propsOf($head, $this->indexUrl(), 'classifications/rectifications/Index');
        $show = $this->propsOf($head, $this->showUrl(), 'classifications/rectifications/Show');

        // What the layout shares with every page is not the page's own. Listing it keeps
        // the check honest: any other prop would have to be named here.
        $shared = [
            'accountClosure', 'appVersion', 'auth', 'errors', 'flash', 'impersonating', 'legalEntity', 'locale', 'modules',
            'name', 'nav', 'organization', 'organizationClosure', 'organizations', 'readOnlyModules', 'scope',
            'selectableAcademicYears', 'sidebarOpen', 'supportAwaitingReply', 'supportCategories',
        ];

        $this->assertSame(['classes'], array_values(array_diff(self::keysOf($index), $shared)));
        $this->assertSame(['academic_year', 'label', 'periods', 'subject', 'teachers', 'ulid'], self::keysOf($index['classes'][0]));
        $this->assertSame(['label', 'published_count', 'rectified_count', 'ulid'], self::keysOf($index['classes'][0]['periods'][0]));
        $this->assertContainsOnly('string', $index['classes'][0]['teachers']);

        $this->assertSame(['period', 'rectificationReasons', 'rows', 'schoolClass'], array_values(array_diff(self::keysOf($show), $shared)));
        $this->assertSame(
            ['academic_year', 'classifies_by_level', 'decision_label', 'label', 'levels', 'max_value', 'min_value', 'scale_name', 'subject', 'ulid'],
            self::keysOf($show['schoolClass']),
        );
        $this->assertSame(['label', 'ulid'], self::keysOf($show['period']));
        $this->assertSame(['class_number', 'classification', 'enrollment_ulid', 'name'], self::keysOf($show['rows'][0]));
        $this->assertSame(['decision', 'published_at', 'rectification', 'ulid'], self::keysOf($show['rows'][0]['classification']));
        $this->assertSame(
            ['code', 'is_percentage', 'label', 'scale_level_id', 'scale_name'],
            self::keysOf($show['rows'][0]['classification']['decision']),
        );
        $this->assertSame(
            ['allowed', 'blocked_reason', 'count', 'lock_version', 'needs_confirmation'],
            self::keysOf($show['rows'][0]['classification']['rectification']),
        );
        $this->assertSame(RectificationReason::options(), $show['rectificationReasons']);

        // The negative: nothing from the teacher's side of the book, at any depth.
        $forbidden = [
            'weighted_average', 'accumulated_average', 'proposal', 'proposed_scale_level_id', 'self_assessment',
            'photo_url', 'observation', 'scores', 'score', 'status', 'status_label', 'override_reason', 'elements',
            'instruments', 'attendance', 'email', 'characterization', 'overridden', 'can_confirm', 'can_change',
            'is_published', 'photo', 'notes', 'note', 'average',
        ];

        foreach ([$index, $show] as $props) {
            $this->assertSame([], array_values(array_intersect(self::allKeys(array_diff_key($props, array_flip($shared))), $forbidden)));
        }
    }

    // --------------------------------------------------------------- audit

    #[Test]
    public function opening_a_class_one_does_not_teach_is_audited_once_per_opening(): void
    {
        $head = $this->institution();
        $this->close();

        $this->acting($head)->get($this->showUrl())->assertOk();

        $events = $this->viewedEvents();
        $this->assertCount(1, $events);
        $this->assertSame($head->getKey(), (int) $events[0]->causer_id);
        $this->assertSame('SchoolClass', $events[0]->subject_type);
        $this->assertSame($this->class->ulid, $events[0]->subject_ulid);
        $this->assertSame(
            ['class_ulid' => $this->class->ulid, 'period_ulid' => $this->period->ulid, 'period_label' => $this->period->label],
            json_decode((string) $events[0]->properties, true),
        );

        // The listing is not an opening of any table; a partial reload is not one either.
        $this->acting($head)->get($this->indexUrl())->assertOk();
        $version = $this->acting($head)->get($this->indexUrl())->viewData('page')['version'];
        $this->acting($head)->get($this->showUrl(), [
            'X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Inertia-Partial-Component' => 'classifications/rectifications/Show', 'X-Inertia-Partial-Data' => 'rows',
        ])->assertOk();
        $this->assertCount(1, $this->viewedEvents());

        $this->acting($head)->get($this->showUrl())->assertOk();
        $this->assertCount(2, $this->viewedEvents());
    }

    #[Test]
    public function a_refusal_leaves_no_trace(): void
    {
        $head = $this->institution();
        $this->close();

        $this->acting($this->member(User::factory()->create()))->get($this->showUrl())->assertForbidden();
        $this->acting($head)->get('/classes/'.$this->class->ulid.'/retificacoes/'.$this->secondPeriod()->ulid.'x')->assertNotFound();
        $this->acting(User::factory()->create())->get($this->showUrl())->assertNotFound();

        $this->setStates('active', 'open');
        $this->acting($head)->get($this->showUrl())->assertNotFound();

        $this->assertSame([], $this->viewedEvents());
    }

    // ---------------------------------------------------------------- flow

    #[Test]
    public function the_head_rectifies_from_the_table_and_reads_the_history(): void
    {
        $head = $this->institution();
        $this->close();

        $row = $this->propsOf($head, $this->showUrl(), 'classifications/rectifications/Show')['rows'][0];
        $this->assertSame('4', $row['classification']['decision']['code']);
        $this->assertTrue($row['classification']['rectification']['allowed']);
        $this->assertTrue($row['classification']['rectification']['needs_confirmation']);
        $this->assertSame(0, $row['classification']['rectification']['count']);

        $this->acting($head)->from($this->showUrl())->post($this->rectifyUrl($row['enrollment_ulid']), [
            'reason' => RectificationReason::CalculationError->value,
            'note' => 'Corrigido.',
            'final_scale_level_id' => $this->levelId('3'),
            'lock_version' => $row['classification']['rectification']['lock_version'],
            'closed_confirmed' => 1,
        ])->assertRedirect($this->showUrl())->assertSessionHasNoErrors();

        $after = collect($this->propsOf($head, $this->showUrl(), 'classifications/rectifications/Show')['rows'])
            ->firstWhere('enrollment_ulid', $row['enrollment_ulid']);
        $this->assertSame('3', $after['classification']['decision']['code']);
        $this->assertSame(1, $after['classification']['rectification']['count']);
        $this->assertSame(1, $this->propsOf($head, $this->indexUrl(), 'classifications/rectifications/Index')['classes'][0]['periods'][0]['rectified_count']);

        $history = $this->acting($head)
            ->getJson("/classes/{$this->class->ulid}/classifications/{$after['classification']['ulid']}/rectification")
            ->assertOk()->json('history');
        $this->assertCount(2, $history);
        $this->assertSame(['4', '3'], array_column(array_column($history, 'final'), 'code'));
    }

    #[Test]
    public function the_class_policy_view_is_unchanged(): void
    {
        $head = $this->institution();
        $this->close();

        $this->acting($head)->get("/classes/{$this->class->ulid}/classifications/{$this->period->ulid}")->assertForbidden();
        $this->acting($head)->get("/classes/{$this->class->ulid}/results/{$this->period->ulid}")->assertForbidden();
        $this->assertFalse(Gate::forUser($head)->allows('view', $this->class));
        $this->assertFalse(Gate::forUser($head)->allows('update', $this->class));
    }

    // ---------------------------------------------------------- efficiency

    #[Test]
    public function the_queries_do_not_grow_with_the_number_of_students(): void
    {
        $head = $this->institution();
        $this->close();

        $queries = function (string $url) use ($head): int {
            // Once to warm what the framework caches, once to count.
            $this->acting($head)->get($url)->assertOk();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->acting($head)->get($url)->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $smallShow = $queries($this->showUrl());
        $smallIndex = $queries($this->indexUrl());
        $smallRows = $this->livePublished();

        $this->asTenant(fn () => $this->publish($this->period, 50));
        $this->assertGreaterThan($smallRows, $this->livePublished());

        $this->assertSame($smallShow, $queries($this->showUrl()));
        $this->assertSame($smallIndex, $queries($this->indexUrl()));
    }
}
