<?php

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * NOBODY GOES ROUND THE GATEWAY.
 *
 * A plain-text scan over `app/`, in the same spirit as `CatalogCoherenceTest`
 * and `PayloadProvenanceTest`: deliberately not static analysis, deliberately
 * simple, and good enough to notice the one mistake that would undo the whole
 * AI Core — a feature that resolves a provider for itself.
 *
 * WHY THIS MATTERS MORE THAN IT LOOKS. «Does Lapispro send student data to
 * Google» has to be a question with ONE answer, and it only has one answer for
 * as long as there is one door. A feature that imports `GeminiProvider` skips
 * the entitlement, the rate limit, the quota, the pool, the privacy assertion
 * and the meter — all at once, silently, and in a way that reads perfectly
 * normally at the call site. The AI-complete slice migrated the last two
 * features that did this (`ReportWritingAssistant` and
 * `InterventionStrategySuggester`); this test is what stops the third from
 * being written.
 *
 * THE ALLOWED LISTS BELOW ARE SHORT ON PURPOSE. Every entry is a file whose JOB
 * is to know about providers, and each one is named rather than matched by a
 * pattern, so adding a file to the list is a deliberate act somebody reviews.
 */
class AiArchitectureTest extends TestCase
{
    /**
     * Concrete engines. A feature that names one of these has left the
     * building on its own.
     */
    private const PROVIDER_CLASSES = [
        'GeminiProvider',
        'ChatCompletionsProvider',
        'FakeAiTextProvider',
    ];

    /**
     * The only files allowed to name a concrete provider.
     *
     * `AppServiceProvider` binds them into the container — that is what a
     * service provider is for, and it is the single place the application
     * learns that Gemini exists. Everything else lives inside the AI core
     * itself.
     */
    private const PROVIDER_ALLOWED = [
        'app/Providers/AppServiceProvider.php',
        // Names `GeminiProvider` in a comment explaining why the model field is
        // length-limited (it becomes a URL path segment there). A comment is not
        // a dependency, but this scan cannot tell — and teaching it to strip
        // comments would make it the static analyser it deliberately is not.
        'app/Http/Requests/Admin/UpdateAiSettingsRequest.php',
    ];

    /**
     * The registry that resolves an engine. Reaching it means bypassing the
     * gateway's checks — unless you are the gateway, the registry itself, or
     * the backoffice screen whose whole purpose is to report on it.
     */
    private const REGISTRY_ALLOWED = [
        'app/Providers/AppServiceProvider.php',
        // Reports «IA ativa / não configurada» and the effective driver. It
        // does NOT call `make()`; its connection test goes through the gateway
        // like everything else.
        'app/Http/Controllers/Admin/AdminAiController.php',
        // Same comment, same reason as in PROVIDER_ALLOWED above.
        'app/Http/Requests/Admin/UpdateAiSettingsRequest.php',
    ];

    #[Test]
    public function no_feature_names_a_concrete_ai_provider(): void
    {
        $offenders = [];

        foreach ($this->phpFilesOutsideAiCore() as $path => $contents) {
            if (in_array($path, self::PROVIDER_ALLOWED, strict: true)) {
                continue;
            }

            foreach (self::PROVIDER_CLASSES as $class) {
                if (str_contains($contents, $class)) {
                    $offenders[] = $path.' names '.$class;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A feature must depend on AiGateway, never on an engine. Offenders:\n".implode("\n", $offenders),
        );
    }

    #[Test]
    public function no_feature_resolves_an_engine_through_the_registry(): void
    {
        $offenders = [];

        foreach ($this->phpFilesOutsideAiCore() as $path => $contents) {
            if (in_array($path, self::REGISTRY_ALLOWED, strict: true)) {
                continue;
            }

            if (str_contains($contents, 'AiTextProviders')) {
                $offenders[] = $path.' uses AiTextProviders';
            }

            // The provider interface's own verb. Reaching it means an
            // `AiTextRequest` was built by hand — which is a payload that never
            // met `SanitisedPayload`.
            if (str_contains($contents, 'new AiTextRequest')) {
                $offenders[] = $path.' builds an AiTextRequest directly';
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Only the AI core may resolve an engine or build a raw request. Offenders:\n".implode("\n", $offenders),
        );
    }

    /**
     * THE COMMERCIAL RULE, ENFORCED AS A GREP.
     *
     * §2 of the brief: «Não usar `if ($plan === 'pro')`. Usar exclusivamente
     * capabilities, limits, entitlements, overrides.» A plan name compared as a
     * string anywhere in the application is a commercial decision frozen into
     * code, and it is invisible the day somebody renames a plan or sells a
     * fourth one.
     *
     * SCOPED TO THE AI SURFACE, AND THE SCOPE IS AN HONEST ONE RATHER THAN A
     * CONVENIENCE. Five files elsewhere in the application do compare a plan
     * key to a literal, and every one of them is legitimate: `TrialEligibility`
     * asks whether an organization is on Base (only a Base organization may
     * start a Pro trial), `CommercialMetrics` counts subscriptions per plan,
     * `PlanController` renders the plan page, `AdminAccountController` creates
     * an organization on a named plan, and `GenerateDataExport` prints the
     * organization's type. Those are PLANS AS COMMERCIAL OBJECTS — the subject
     * of the code — not access decisions dressed up as one. Widening this test
     * over them would either fail permanently or force a refactor of billing
     * code this slice has no business touching.
     *
     * WHAT IT DOES COVER is every file that participates in an AI decision: the
     * AI services, the gateway, and any controller or support class that names
     * a capability. That is exactly where §2's rule bites — «não usar
     * `if ($plan === 'pro')`; usar capabilities» — and it is where a plan-name
     * comparison would actually be a bug.
     */
    #[Test]
    public function nothing_in_the_ai_surface_branches_on_a_plan_name(): void
    {
        $offenders = [];

        // `=== 'pro'`, `== "institutional"`, `'base' ===` …
        $pattern = '/(?:===?\s*[\'"](?:base|pro|institutional)[\'"]|[\'"](?:base|pro|institutional)[\'"]\s*===?)/i';

        foreach ($this->phpFiles(app_path()) as $path => $contents) {
            if (! $this->isAiSurface($path, $contents)) {
                continue;
            }

            if (preg_match($pattern, $contents) === 1) {
                $offenders[] = $path;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "AI access must be decided by capability, never by plan name. Offenders:\n".implode("\n", $offenders),
        );
    }

    /**
     * Whether a file participates in an AI decision at all.
     *
     * By path for the services, and by CONTENT for everything else — a
     * controller that mentions `AiCapability` or `AiGateway` is making an AI
     * decision wherever it happens to live.
     */
    private function isAiSurface(string $path, string $contents): bool
    {
        return str_contains($path, '/Ai/')
            || str_contains($path, 'Ai.php')
            || str_contains($contents, 'AiCapability')
            || str_contains($contents, 'AiGateway');
    }

    /**
     * Every feature that reaches an engine names `AiGateway`.
     *
     * THE POSITIVE HALF OF THE SAME GUARANTEE. The two tests above say what may
     * not be done; this says that the services which exist to ask an engine
     * something actually go through the door — so a future service that quietly
     * stops calling the gateway shows up here rather than in an invoice.
     */
    #[Test]
    public function every_ai_feature_service_depends_on_the_gateway(): void
    {
        $services = [
            'app/Services/Help/Ai/HelpAssistant.php',
            'app/Services/Assessment/Ai/ClassAnalyst.php',
            'app/Services/Assessment/Ai/ResultsAnalyst.php',
            'app/Services/Progress/Ai/StudentFollowupSynthesist.php',
            'app/Services/Interventions/Ai/InterventionStrategySuggester.php',
            'app/Services/Reporting/Writing/ReportWritingAssistant.php',
        ];

        foreach ($services as $relative) {
            $path = base_path($relative);

            $this->assertTrue(File::exists($path), "{$relative} is missing — did a feature move without updating this list?");
            $this->assertStringContainsString(
                'AiGateway',
                File::get($path),
                "{$relative} must reach an engine through AiGateway.",
            );
        }
    }

    /**
     * Every PHP file under `app/`, keyed by its repo-relative path.
     *
     * @return array<string, string>
     */
    private function phpFiles(string $directory): array
    {
        $files = [];

        foreach (File::allFiles($directory) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));

            $files[$relative] = File::get($file->getPathname());
        }

        return $files;
    }

    /**
     * Everything outside `app/Services/Ai/`, which IS the core and is allowed
     * to know about engines.
     *
     * @return array<string, string>
     */
    private function phpFilesOutsideAiCore(): array
    {
        return array_filter(
            $this->phpFiles(app_path()),
            fn (string $path): bool => ! str_starts_with($path, 'app/Services/Ai/'),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
