<?php

namespace Tests\Feature\Entitlements;

use App\Models\Module;
use Database\Seeders\EntitlementsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Catalogue hygiene (Lote 1, item 4) — guards against the kind of drift that
 * let `class_analysis`, `imports`, `data_protection` and `institution_policies`
 * sit in EntitlementsSeeder::MODULES with nothing anywhere actually enforcing
 * them, until this Lote removed them.
 *
 * Deliberately NOT real static analysis. A plain substring search across the
 * raw contents of routes/ and app/ is exactly the simple, robust check the
 * brief asked for — good enough to notice "nobody ever wired this key to
 * anything", not meant to understand PHP. It also catches the inverse drift:
 * a key composed into a plan (module_plan) that the catalogue no longer has.
 */
class CatalogCoherenceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Real, actively-enforced capabilities that the plain-string search below
     * cannot see, because each is reached through a named constant or an enum
     * method rather than a literal `allows('key')` / `allowsFor(..., 'key')`
     * at the call site. Every entry was verified against its actual call
     * chain (see the Lote 1 report for the exact file:line references) —
     * nothing here is a guess.
     */
    private const INDIRECTLY_ENFORCED = [
        // ReportCapabilities::WRITING_ASSISTANT_MODULE and
        // InterventionStrategySuggester::AI_MODULE both hold this key; both
        // are consumed as `$this->entitlements->allows(self::AI_MODULE)`.
        'ai_assistance',
        // SectionCatalogue::PEDAGOGICAL_MODULE holds this key, consumed by
        // ReportCapabilities::allowsPedagogicalAnalysis().
        'report_pedagogical_analysis',
        // ReportTemplateKind::moduleToCreate() returns this key for the
        // Institutional case, consumed by ReportTemplatePolicy::createKind().
        'institution_library',
        // ReportType::module() returns this key for ReportType::School,
        // consumed by ReportCapabilities::allowsType().
        'institution_reports',
        // The two AI Core capabilities. Both are held by
        // `App\Services\Ai\Gateway\AiCapability` — where the enum CASE VALUE is
        // the module key, by design — and consumed by AiGateway as
        // `allows($capability->moduleKey())` and
        // `allowsFor($organization, $capability->moduleKey())`. The enforcement
        // is real and is tested in AiGatewayTest; it is simply not a literal
        // string at the call site, which is exactly what this list is for.
        //
        // Both are catalogued and in NO plan, on purpose: which subscription
        // includes them is an open commercial question (CLAUDE.md §31). That
        // does not make them unenforced — it makes them denied to everybody,
        // which is the safe end of the same mechanism.
        'help_assistant',
        'ai_pedagogical_analysis',
        // NOT indirection — a genuinely independent-gate-free key, found
        // while writing this test. Enrollment management ("Alunos") lives
        // entirely inside the `module:classes` route group (routes/web.php);
        // nothing checks `students` on its own anywhere. The concept is real
        // (it is in Base, same as `classes`) but structurally identical to
        // the `class_analysis` situation this Lote resolved for Results —
        // except `students` was not one of the four keys the owner approved
        // for removal, so it is flagged here rather than acted on. Left for a
        // future Lote to decide: fold it away like class_analysis, or wire an
        // independent check.
        'students',
    ];

    #[Test]
    public function every_catalogued_module_key_has_real_enforcement_or_an_explicit_reason_not_to(): void
    {
        $routesContent = $this->concatenatedFiles(base_path('routes'));
        $appContent = $this->concatenatedFiles(app_path());

        $unenforced = array_values(array_filter(
            EntitlementsSeeder::moduleKeys(),
            fn (string $key) => ! in_array($key, self::INDIRECTLY_ENFORCED, strict: true)
                && ! $this->isEnforced($key, $routesContent, $appContent),
        ));

        $this->assertSame(
            [],
            $unenforced,
            'Catalogued module key(s) with no route gate, no allows()/allowsFor() call, and no whitelist entry: '.implode(', ', $unenforced),
        );
    }

    #[Test]
    public function every_module_actually_composed_into_a_plan_is_still_in_the_catalogue(): void
    {
        $catalogueKeys = EntitlementsSeeder::moduleKeys();

        $keysInPlans = Module::query()
            ->whereIn('id', DB::table('module_plan')->pluck('module_id'))
            ->pluck('key')
            ->all();

        $orphanedInPlans = array_values(array_diff($keysInPlans, $catalogueKeys));

        $this->assertSame(
            [],
            $orphanedInPlans,
            'module_plan references key(s) no longer in the catalogue: '.implode(', ', $orphanedInPlans),
        );
    }

    #[Test]
    public function the_removed_dead_keys_are_orphaned_not_deleted(): void
    {
        // The non-destructive path this Lote took: MODULES no longer lists
        // these, so a fresh seed never creates them (asserted not-in-catalogue
        // below) — but the real dev database, which had them from before,
        // keeps the `modules` rows physically present with zero `module_plan`
        // associations after re-seeding. Either end state passes here.
        $removedKeys = ['class_analysis', 'imports', 'data_protection', 'institution_policies'];

        foreach ($removedKeys as $key) {
            $this->assertNotContains($key, EntitlementsSeeder::moduleKeys());

            $module = Module::where('key', $key)->first();

            if ($module === null) {
                continue;
            }

            $this->assertSame(
                0,
                DB::table('module_plan')->where('module_id', $module->id)->count(),
                "{$key} must not be composed into any plan any more.",
            );
        }
    }

    #[Test]
    public function the_detector_actually_catches_a_key_nothing_enforces(): void
    {
        // Proves the generic-ness the brief asked for, without reintroducing
        // a dead key into the real seeder: a made-up key that appears nowhere
        // is exactly what the first test above would have caught for
        // class_analysis / imports / data_protection / institution_policies
        // before this Lote removed them.
        $routesContent = $this->concatenatedFiles(base_path('routes'));
        $appContent = $this->concatenatedFiles(app_path());

        $this->assertFalse($this->isEnforced('totally_fake_capability_zzz', $routesContent, $appContent));
    }

    private function isEnforced(string $key, string $routesContent, string $appContent): bool
    {
        if (str_contains($routesContent, "module:{$key}")) {
            return true;
        }

        if (str_contains($appContent, "allows('{$key}')")) {
            return true;
        }

        return preg_match('/allowsFor\([^;]*?\''.preg_quote($key, '/')."'/s", $appContent) === 1;
    }

    private function concatenatedFiles(string $directory): string
    {
        return collect(File::allFiles($directory))
            ->filter(fn ($file) => $file->getExtension() === 'php')
            ->map(fn ($file) => File::get($file->getPathname()))
            ->implode("\n");
    }
}
