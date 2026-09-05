<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\AiTextProvider;
use App\Services\Ai\Providers\FakeAiTextProvider;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionParameter;
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
     * AND THE CORE GOES NOWHERE NEAR A FEATURE — the same rule read backwards,
     * which is the direction that actually broke.
     *
     * Every other test in this file guards «a feature must not reach an
     * engine». This one guards the arrow that points the other way: the AI core
     * must not reach a feature. `AiCapabilityProbe` shipped in
     * `App\Services\Ai\Gateway` importing `FollowupSynthesisPrompt` and
     * `FollowupSynthesisParser` from `App\Services\Progress\Ai`, which made the
     * gateway — the one component every feature depends on — depend in turn on
     * one concrete feature of Acompanhamento. Nothing failed: it compiled, the
     * tests passed, and the dependency graph acquired a cycle that would have
     * been argued about for a year.
     *
     * It moved to `App\Services\Diagnostics\Ai` in 0.101.5, where it is what it
     * always was: a backoffice diagnostic, which is a FEATURE, and features are
     * allowed to depend on both the gateway and on Progress. The probe kept its
     * synthetic record, the real parser and the real budget; only its address
     * changed.
     *
     * `App\Support` and `App\Models` are not features and are not scanned for.
     */
    #[Test]
    public function the_ai_core_does_not_depend_on_any_feature(): void
    {
        $offenders = [];

        // Plain string scanning, like every other check in this file. A regex
        // here needs four backslashes to mean one, and the first version of
        // this test got that wrong in a way that made it pass on a real
        // violation — a guard that cannot fail is worse than no guard, because
        // it is also a claim.
        foreach ($this->phpFiles(app_path('Services/Ai')) as $path => $contents) {
            foreach (explode("\n", $contents) as $line) {
                $line = trim($line);

                if (! str_starts_with($line, 'use App\Services\\')) {
                    continue;
                }

                if (str_starts_with($line, 'use App\Services\Ai\\')) {
                    continue;
                }

                $offenders[] = $path.' imports '.rtrim($line, ';');
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'The AI core must not depend on a feature — a feature depends on the core, never the reverse. '
            ."Move the offender to its own domain (see App\Services\Diagnostics\Ai). Offenders:\n"
            .implode("\n", $offenders),
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
            'app/Services/Evidence/Ai/IncidentDescriptionAssistant.php',
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
     * NO REAL ENGINE ANSWERS WITHOUT A CEILING.
     *
     * The third guarantee of this file, and the one that was missing. An engine
     * with no output limit is the only setting in `lapis.ai` that is directly an
     * invoice: asked a two-line question it will cheerfully return two thousand
     * lines, bill for every one of them, and look like a working feature the
     * whole time. `GeminiProvider` was written with a ceiling; the older
     * `/chat/completions` driver was not, and nothing noticed for three releases
     * because no test asked.
     *
     * ASKED STRUCTURALLY, NOT PER DRIVER, so the answer stays true for the
     * fourth engine somebody adds. Every concrete `AiTextProvider` under
     * `Providers/` must take a `maxOutputTokens` and must actually put it on the
     * wire — the wire field is the driver's business (`maxOutputTokens` for
     * Gemini, `max_tokens` on the `/chat/completions` format), but HAVING one is
     * not.
     *
     * `FakeAiTextProvider` is exempt and only it: it makes no request, it is
     * refused in production, and a ceiling on a fake is a ceiling on nothing.
     */
    #[Test]
    public function every_real_provider_takes_an_explicit_output_ceiling_and_sends_it(): void
    {
        foreach ($this->realProviders() as $class => $contents) {
            $parameters = (new ReflectionClass($class))->getConstructor()?->getParameters() ?? [];
            $names = array_map(fn (ReflectionParameter $p): string => $p->getName(), $parameters);

            $this->assertContains(
                'maxOutputTokens',
                $names,
                "{$class} must be given an output ceiling from configuration.",
            );

            foreach ($parameters as $parameter) {
                if ($parameter->getName() !== 'maxOutputTokens') {
                    continue;
                }

                $this->assertSame('int', (string) $parameter->getType(), "{$class}: the ceiling must be an int.");
                // No default. A ceiling with a fallback is a ceiling somebody
                // forgets to wire, and it fails silently rather than loudly.
                $this->assertFalse(
                    $parameter->isDefaultValueAvailable(),
                    "{$class}: the ceiling must have no default — the installation decides it.",
                );
            }

            $this->assertStringContainsString(
                '$this->maxOutputTokens',
                $contents,
                "{$class} takes a ceiling but never puts it on the wire.",
            );
        }
    }

    /**
     * AND IT COMES FROM ONE PLACE. Each real driver is bound with the same
     * config key, so «what is the output ceiling» has one answer for the whole
     * installation and the backoffice field means what it says.
     */
    #[Test]
    public function the_output_ceiling_is_wired_from_the_one_config_key(): void
    {
        $bindings = File::get(base_path('app/Providers/AppServiceProvider.php'));

        $this->assertSame(
            count($this->realProviders()),
            substr_count($bindings, "maxOutputTokens: (int) config('lapis.ai.max_output_tokens')"),
            'Every real driver must take its ceiling from lapis.ai.max_output_tokens, and nothing may invent one of its own.',
        );
    }

    /**
     * The engines that actually leave the building, keyed by class name.
     *
     * `FakeAiTextProvider` is the one exemption and it is exempt by identity
     * rather than by pattern: it makes no request, it is refused in production,
     * and a ceiling on a fake is a ceiling on nothing.
     *
     * @return array<class-string, string>
     */
    private function realProviders(): array
    {
        $providers = [];

        foreach (File::files(app_path('Services/Ai/Providers')) as $file) {
            /** @var class-string $class */
            $class = 'App\\Services\\Ai\\Providers\\'.$file->getFilenameWithoutExtension();

            if (! is_subclass_of($class, AiTextProvider::class) || $class === FakeAiTextProvider::class) {
                continue;
            }

            $providers[$class] = File::get($file->getPathname());
        }

        // A guard on the guard: a move that emptied this list would turn every
        // assertion above into a test that always passes.
        $this->assertGreaterThanOrEqual(2, count($providers), 'The real providers were not found — did they move?');

        return $providers;
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
