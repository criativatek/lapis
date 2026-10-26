<?php

namespace Tests\Feature\Assessment;

use App\Actions\Organizations\CreateInstitutionalOrganization;
use App\Models\AcademicPeriod;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\ClassificationStatus;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\SchoolClass;
use App\Models\User;
use App\Policies\ClassificationPolicy;
use App\Services\Assessment\ProposeClassifications;
use App\Support\Assessment\RectificationAccess;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Who may rectify a published classification (spec §4), read from the
 * organization the classification belongs to — never the user's current one.
 */
class RectificationAccessTest extends TestCase
{
    use RefreshDatabase;

    private const REASON = 'O período está encerrado. Só o responsável da instituição pode retificar.';

    private User $ana;

    private Classification $classification;

    private SchoolClass $class;

    private AcademicPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ana = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        $this->classification = app(CurrentOrganization::class)->runFor(
            $this->ana->personalOrganization(),
            function (): Classification {
                $this->class = SchoolClass::where('label', '7.º A')->firstOrFail();
                $this->period = $this->class->academicYear->periods()->where('sequence', 1)->firstOrFail();

                app(ProposeClassifications::class)->forPeriod($this->class, $this->period);

                return Classification::query()
                    ->where('academic_period_id', $this->period->id)
                    ->where('scope', ClassificationScope::Period)
                    ->firstOrFail();
            },
        );

        // The fixture row is made live and published by hand: the access rule is
        // what is under test, not the confirmation/publication flow.
        $this->setClassification(['status' => ClassificationStatus::Published->value, 'superseded_by_id' => null, 'final_value' => '3']);
        $this->setStates('active', 'open');
    }

    /** @param array<string, mixed> $attributes */
    private function setClassification(array $attributes): void
    {
        DB::table('classifications')->where('id', $this->classification->id)->update($attributes);
        $this->classification = Classification::withoutGlobalScopes()->findOrFail($this->classification->id);
    }

    private function setStates(string $yearStatus, string $periodStatus): void
    {
        DB::table('academic_years')->where('id', $this->period->academic_year_id)->update(['status' => $yearStatus]);
        DB::table('academic_periods')->where('id', $this->period->id)->update(['status' => $periodStatus]);
    }

    private function makeInstitutional(User $head): void
    {
        DB::table('organizations')->where('id', $this->classification->organization_id)->update([
            'type' => OrganizationType::Institutional->value,
            'owner_id' => $head->getKey(),
        ]);
    }

    private function teach(User $user): void
    {
        // By id: once the organization is made institutional it is no longer Ana's "personal" one.
        $organization = Organization::query()->findOrFail($this->classification->organization_id);

        app(CurrentOrganization::class)->runFor($organization, function () use ($user): void {
            $this->class->teachers()->syncWithoutDetaching([$user->getKey() => ['role' => 'co_teacher']]);
        });
    }

    private function user(string $who, bool $institutional): User
    {
        $head = User::factory()->create();

        if ($institutional) {
            $this->makeInstitutional($head);
        }

        return match ($who) {
            'teacher' => $this->ana,
            'stranger' => User::factory()->create(),
            'head' => $head,
            'head_teaching' => tap($head, fn (User $head) => $this->teach($head)),
        };
    }

    /**
     * Columns: institutional, who, year status, period status, allowed,
     * needsConfirmation, closed, has the "only the head" reason.
     *
     * @return array<string, array{bool, string, string, string, bool, bool, bool, bool}>
     */
    public static function matrix(): array
    {
        $rows = [];
        $closings = [
            'open' => ['active', 'open', false],
            'draft' => ['draft', 'draft', false],
            'year closed' => ['closed', 'open', true],
            'year archived' => ['archived', 'open', true],
            'period closed' => ['active', 'closed', true],
            'period archived' => ['active', 'archived', true],
        ];

        foreach ($closings as $name => [$year, $period, $closed]) {
            // Personal account: whoever teaches; an extra confirmation once closed.
            $rows["personal, $name, teacher"] = [false, 'teacher', $year, $period, true, $closed, $closed, false];
            $rows["personal, $name, stranger"] = [false, 'stranger', $year, $period, false, false, $closed, false];

            // Institution: open -> who teaches; closed -> the head alone.
            $rows["institution, $name, teacher"] = [true, 'teacher', $year, $period, ! $closed, false, $closed, $closed];
            $rows["institution, $name, stranger"] = [true, 'stranger', $year, $period, false, false, $closed, false];
            $rows["institution, $name, head not teaching"] = [true, 'head', $year, $period, $closed, $closed, $closed, false];
            $rows["institution, $name, head teaching"] = [true, 'head_teaching', $year, $period, true, $closed, $closed, false];
        }

        return $rows;
    }

    #[Test]
    #[DataProvider('matrix')]
    public function the_matrix(bool $institutional, string $who, string $year, string $period, bool $allowed, bool $confirm, bool $closed, bool $hasReason): void
    {
        $user = $this->user($who, $institutional);
        $this->setStates($year, $period);

        $access = RectificationAccess::for($user, $this->classification);

        $this->assertSame($allowed, $access->allowed);
        $this->assertSame($confirm, $access->needsConfirmation);
        $this->assertSame($closed, $access->closed);
        $this->assertSame($hasReason ? self::REASON : null, $access->blockedReason);
    }

    #[Test]
    public function a_classification_that_is_not_the_live_published_one_is_never_rectifiable(): void
    {
        foreach ([ClassificationStatus::Proposed, ClassificationStatus::Confirmed] as $status) {
            // «Confirmada» exige autor (CHECK confirmed_has_author).
            $this->setClassification([
                'status' => $status->value,
                'confirmed_by' => $status === ClassificationStatus::Confirmed ? $this->ana->getKey() : null,
            ]);
            $this->assertFalse(RectificationAccess::for($this->ana, $this->classification)->allowed, $status->value);
        }

        $this->setClassification(['status' => ClassificationStatus::Published->value]);
        $this->assertTrue(RectificationAccess::for($this->ana, $this->classification)->allowed);

        // Superseded: the status and the pointer are kept in step by a CHECK.
        $other = Classification::withoutGlobalScopes()->where('id', '!=', $this->classification->id)->firstOrFail();
        $this->setClassification([
            'status' => ClassificationStatus::Superseded->value, 'superseded_by_id' => $other->id,
        ]);
        $this->assertFalse(RectificationAccess::for($this->ana, $this->classification)->allowed);

        // The pointer alone is enough to refuse, whatever the status says.
        $this->classification->status = ClassificationStatus::Published;
        $this->assertFalse(RectificationAccess::for($this->ana, $this->classification)->allowed);
    }

    #[Test]
    public function uses_the_organization_of_the_classification_not_the_current_one(): void
    {
        // Bruno owns his personal account and is a plain member of the school
        // that holds the classification (the demo organization, made institutional).
        $head = User::factory()->create();
        $this->makeInstitutional($head);
        $bruno = User::factory()->create();
        DB::table('organization_memberships')->insert([
            'organization_id' => $this->classification->organization_id,
            'user_id' => $bruno->getKey(),
            'joined_at' => now(),
        ]);
        $this->teach($bruno);
        $this->setStates('closed', 'open');

        $brunoPersonal = $bruno->personalOrganization();
        $this->assertNotNull($brunoPersonal);
        $this->assertTrue($bruno->owns($brunoPersonal));

        // Tenant on HIS personal account: he owns it, but the data belongs to the school.
        $access = app(CurrentOrganization::class)->runFor(
            $brunoPersonal,
            fn () => RectificationAccess::for($bruno, $this->classification),
        );
        $this->assertFalse($access->allowed);
        $this->assertSame(self::REASON, $access->blockedReason);
        $this->assertFalse((new ClassificationPolicy)->rectify($bruno, $this->classification));

        // Inverse: Ana's classification is on her personal account (Personal rule),
        // and stays so when the tenant is a school she also belongs to.
        DB::table('organizations')->where('id', $this->classification->organization_id)->update([
            'type' => OrganizationType::Personal->value, 'owner_id' => $this->ana->getKey(),
        ]);
        $school = app(CreateInstitutionalOrganization::class)->create('Escola', User::factory()->create());
        DB::table('organization_memberships')->insert([
            'organization_id' => $school->id, 'user_id' => $this->ana->getKey(), 'joined_at' => now(),
        ]);

        $access = app(CurrentOrganization::class)->runFor(
            $school,
            fn () => RectificationAccess::for($this->ana, $this->classification),
        );
        $this->assertTrue($access->allowed);
        $this->assertTrue($access->needsConfirmation);
        $this->assertNull($access->blockedReason);
    }

    #[Test]
    public function personal_account_never_gets_a_blocked_reason_mentioning_the_head(): void
    {
        $users = [$this->ana, User::factory()->create()];
        $closings = [['active', 'open'], ['draft', 'draft'], ['closed', 'open'], ['archived', 'open'], ['active', 'closed'], ['active', 'archived']];

        foreach ($closings as [$year, $period]) {
            $this->setStates($year, $period);

            foreach ($users as $user) {
                $this->assertNull(RectificationAccess::for($user, $this->classification)->blockedReason);
            }
        }
    }

    #[Test]
    public function policy_rectify_matches_access(): void
    {
        $head = User::factory()->create();
        $this->makeInstitutional($head);
        $this->setStates('closed', 'open');
        $policy = new ClassificationPolicy;

        foreach ([$this->ana, $head, User::factory()->create()] as $user) {
            $allowed = RectificationAccess::for($user, $this->classification)->allowed;

            $this->assertSame($allowed, $policy->rectify($user, $this->classification));
            // Resolved by name, so the gate really finds the policy.
            $this->assertSame($allowed, $user->can('rectify', $this->classification));
        }

        $this->assertTrue($head->can('rectify', $this->classification));
        $this->assertFalse($this->ana->can('rectify', $this->classification));
    }

    #[Test]
    public function view_history(): void
    {
        $head = User::factory()->create();
        $this->makeInstitutional($head);
        $policy = new ClassificationPolicy;

        $this->assertTrue($policy->viewHistory($this->ana, $this->classification));
        // The head sees the history of a class they do not teach (they may rectify it once closed).
        $this->assertTrue($policy->viewHistory($head, $this->classification));
        $this->assertFalse($policy->viewHistory(User::factory()->create(), $this->classification));

        // On a personal account, owning the account without teaching gives nothing extra.
        DB::table('organizations')->where('id', $this->classification->organization_id)->update([
            'type' => OrganizationType::Personal->value, 'owner_id' => $head->getKey(),
        ]);
        $this->assertFalse($policy->viewHistory($head, $this->classification));
    }
}
