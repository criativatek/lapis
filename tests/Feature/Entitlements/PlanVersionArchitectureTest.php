<?php

namespace Tests\Feature\Entitlements;

use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\PlanVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * GUARDS AGAINST THE DEFECT COMING BACK.
 *
 * ADR-0008 removed `Plan::modules()` rather than deprecating it, on the
 * grounds that a live composition left beside a versioned one keeps answering
 * — plausibly, wrongly, in silence — for every caller nobody remembered to
 * migrate. That reasoning applies just as much to the day somebody adds it
 * back «just for a report». So the absence is asserted, not assumed.
 *
 * DELIBERATELY NOT REAL STATIC ANALYSIS, the same trade-off
 * `CatalogCoherenceTest` already makes: a reflection check plus a plain
 * substring search over `app/` and `database/`. Good enough to notice a
 * resolver reading the live plan again; not meant to understand PHP.
 */
class PlanVersionArchitectureTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function plan_has_no_modules_relation_and_no_limits(): void
    {
        $plan = new ReflectionClass(Plan::class);

        $this->assertFalse(
            $plan->hasMethod('modules'),
            'Plan::modules() is back. A plan is an identity; its composition belongs to a PlanVersion (ADR-0008 §3).'
        );

        $this->assertFalse(Schema::hasColumn('plans', 'limits'), 'plans.limits is back. A contracted cap belongs to the version it was sold with.');
        $this->assertFalse(Schema::hasTable('module_plan'));

        // And the one place a composition does live still does.
        $this->assertTrue((new ReflectionClass(PlanVersion::class))->hasMethod('modules'));
        $this->assertTrue(Schema::hasTable('module_plan_version'));
    }

    #[Test]
    public function nothing_in_the_application_reads_a_plans_composition_directly(): void
    {
        foreach (['plan->modules', "plan.modules'", 'plan->limits', "'module_plan'"] as $forbidden) {
            $this->assertSame(
                [],
                $this->filesContaining($forbidden),
                "«{$forbidden}» reads the live composition of a plan. Read the subscription's planVersion instead (ADR-0008)."
            );
        }
    }

    #[Test]
    public function the_two_resolvers_read_the_contracted_version(): void
    {
        // The positive half of the guard above: absence of the old pattern is
        // only half the claim, and a resolver that read NEITHER would satisfy
        // it while answering nothing.
        $entitlements = $this->codeWithoutComments(File::get(app_path('Support/Entitlements/Entitlements.php')));
        $limits = $this->codeWithoutComments(File::get(app_path('Support/Limits/Limits.php')));

        $this->assertStringContainsString("with('planVersion.modules')", $entitlements);
        $this->assertStringContainsString('planVersion->modules', $entitlements);

        $this->assertStringContainsString("with('planVersion.plan')", $limits);
        $this->assertStringContainsString('planVersion', $limits);
    }

    #[Test]
    public function the_entitlement_resolver_never_reads_the_commercial_snapshot(): void
    {
        // The line ADR-0008 §8 draws: these four columns are commercial proof.
        // A resolver that read `commercial_term_ends_at` would have turned «the
        // promotional price ended» into automatic expiry of access — a much
        // larger decision, deliberately not taken.
        foreach (['contracted_price_cents', 'contracted_currency', 'billing_period', 'commercial_term_ends_at', 'commercial_condition'] as $forbidden) {
            $this->assertSame(
                [],
                $this->filesContaining($forbidden, [app_path('Support/Entitlements'), app_path('Support/Limits')]),
                "the resolvers must not read {$forbidden}",
            );
        }

        // Nor does the access-window predicate itself.
        $this->assertStringNotContainsString(
            'commercial_term_ends_at',
            $this->methodSourceOf(OrganizationSubscription::class, 'isInForce'),
        );
    }

    #[Test]
    public function only_the_publisher_ever_writes_a_versions_composition(): void
    {
        // A published version's modules are attached once, when it is
        // published. `sync()` anywhere near a composition is the old defect
        // wearing a new table's name.
        $this->assertSame([], $this->filesContaining('modules()->sync('));
        $this->assertSame([], $this->filesContaining('modules()->detach('));
    }

    #[Test]
    public function the_detector_would_actually_catch_a_regression(): void
    {
        // Proves the search is capable of failing — it finds the pattern in a
        // file that really does contain it (this one, in the string just
        // above) and finds nothing in the production code it guards.
        $this->assertNotSame([], $this->filesContaining('modules()->sync(', [__DIR__]));
        $this->assertSame([], $this->filesContaining('modules()->sync(', [app_path()]));
    }

    /**
     * Files whose CODE contains $needle — comments and docblocks stripped, so
     * a docblock that describes the defect (as `Entitlements` does, at length)
     * is not mistaken for the defect.
     *
     * `database/migrations` is out of scope by construction: a migration that
     * copies `module_plan` into the versioned tables has to name
     * `module_plan`, and rewriting history to satisfy a guard would be the
     * wrong repair.
     *
     * @param  list<string>|null  $directories
     * @return list<string>
     */
    private function filesContaining(string $needle, ?array $directories = null): array
    {
        $roots = $directories ?? [app_path(), database_path('seeders')];
        $found = [];

        foreach ($roots as $root) {
            foreach (File::allFiles($root) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                if (str_contains($this->codeWithoutComments(File::get($file->getPathname())), $needle)) {
                    $found[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
                }
            }
        }

        sort($found);

        return $found;
    }

    private function codeWithoutComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    private function methodSourceOf(string $class, string $method): string
    {
        $reflection = (new ReflectionClass($class))->getMethod($method);
        $lines = File::lines((string) $reflection->getFileName())->all();

        return implode("\n", array_slice(
            $lines,
            (int) $reflection->getStartLine() - 1,
            (int) $reflection->getEndLine() - (int) $reflection->getStartLine() + 1,
        ));
    }
}
