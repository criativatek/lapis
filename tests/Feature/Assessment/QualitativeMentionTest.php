<?php

namespace Tests\Feature\Assessment;

use App\Models\Scale;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Assessment\BuildResultsProgression;
use App\Services\Assessment\ClassResultsCalculator;
use App\Services\Assessment\ScaleProposalResolver;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The qualitative mention of a domain's accumulated figure.
 *
 * A percentage becomes a mention exactly once, through the scale's own bands.
 * There is no threshold written anywhere in this application: move a band in the
 * profile and the mention moves with it, which is the whole point — an export to
 * another system will later read the very band the Quadro Síntese shows, and map
 * it by its identity rather than by the word on screen.
 */
class QualitativeMentionTest extends TestCase
{
    use RefreshDatabase;

    private function seedDemo(): User
    {
        $teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        return $teacher;
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function asTenant(User $teacher, callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), $callback);
    }

    /**
     * The last period's domain rows of the first student — where the accumulated
     * figure, and therefore the mention, is read.
     *
     * @return list<array<string, mixed>>
     */
    private function lastPeriodDomains(User $teacher): array
    {
        return $this->asTenant($teacher, function (): array {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $progression = app(BuildResultsProgression::class)->for($class);
            $periods = $progression['students'][0]['periods'];

            /** @var list<array<string, mixed>> $domains */
            $domains = $periods[count($periods) - 1]['domains'];

            return $domains;
        });
    }

    // ------------------------------------------------ 1. o que a menção é

    #[Test]
    public function every_domain_carries_the_band_its_accumulated_figure_falls_in(): void
    {
        $teacher = $this->seedDemo();

        $bands = $this->asTenant($teacher, fn () => Scale::where('name', 'Escala 1 a 5')->firstOrFail()
            ->levels()->get()->keyBy('id'));

        $found = 0;

        foreach ($this->lastPeriodDomains($teacher) as $domain) {
            $this->assertArrayHasKey('mention', $domain);

            if ($domain['mention'] === null) {
                // Only where there is no accumulated figure to place.
                $this->assertNull($domain['accumulated_average']);

                continue;
            }

            $found++;
            $band = $bands[$domain['mention']['scale_level_id']];

            // The label shown is the band's own, and the figure really is inside
            // it — the placement is the scale's, not this screen's.
            $this->assertSame($band->label, $domain['mention']['label']);
            $this->assertGreaterThanOrEqual((float) $band->band_min_normalized, (float) $domain['accumulated_average']);
            $this->assertLessThanOrEqual((float) $band->band_max_normalized, (float) $domain['accumulated_average']);
        }

        $this->assertGreaterThan(0, $found, 'nenhum domínio chegou a ter menção');
    }

    #[Test]
    public function the_mention_carries_the_bands_identity_and_not_only_its_words(): void
    {
        $teacher = $this->seedDemo();

        $mention = null;

        foreach ($this->lastPeriodDomains($teacher) as $domain) {
            $mention = $mention ?? $domain['mention'];
        }

        $this->assertNotNull($mention);

        // What a later export maps from: the band, by its own id, code and rank.
        // «Bom» → «B» must never be a string comparison (§5).
        foreach (['scale_level_id', 'code', 'sequence', 'is_negative'] as $key) {
            $this->assertArrayHasKey($key, $mention);
        }

        $this->assertIsInt($mention['scale_level_id']);
        $this->assertNotSame('', $mention['code']);
    }

    #[Test]
    public function moving_a_band_moves_the_mention(): void
    {
        $teacher = $this->seedDemo();

        $before = $this->lastPeriodDomains($teacher);
        $sample = collect($before)->first(fn (array $domain): bool => $domain['mention'] !== null);
        $this->assertNotNull($sample);

        // The proof that no threshold is written in this application: widen the
        // lowest band over the whole scale and every mention becomes that band.
        $this->asTenant($teacher, function (): void {
            $scale = Scale::where('name', 'Escala 1 a 5')->firstOrFail();

            DB::table('scale_levels')->where('scale_id', $scale->id)
                ->update(['band_min_normalized' => null, 'band_max_normalized' => null]);
            DB::table('scale_levels')->where('scale_id', $scale->id)->where('code', '1')
                ->update(['band_min_normalized' => '0.000000', 'band_max_normalized' => '100.000000']);
        });

        foreach ($this->lastPeriodDomains($teacher) as $domain) {
            if ($domain['accumulated_average'] === null) {
                continue;
            }

            $this->assertSame('1', $domain['mention']['code']);
        }
    }

    #[Test]
    public function a_scale_with_no_bands_places_nothing_and_says_so(): void
    {
        $teacher = $this->seedDemo();

        // The system 0–20 has no qualitative bands of its own, and inventing one
        // is exactly what §10.4 forbids.
        $this->asTenant($teacher, function (): void {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();

            DB::table('assessment_profile_versions')
                ->where('id', $class->assessment_profile_version_id)
                ->update(['scale_id' => Scale::where('name', 'Escala 0 a 20')->firstOrFail()->id]);
        });

        foreach ($this->lastPeriodDomains($teacher) as $domain) {
            $this->assertNull($domain['mention']);
        }
    }

    // --------------------------------- 2. uma só regra, e a que já existia

    #[Test]
    public function the_resolver_places_a_value_exactly_where_the_engine_places_it(): void
    {
        $teacher = $this->seedDemo();

        $this->asTenant($teacher, function (): void {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();
            $scale = $class->profileVersion->scale()->with('levels')->firstOrFail();
            $resolver = app(ScaleProposalResolver::class);

            $checked = 0;

            foreach (app(ClassResultsCalculator::class)->forPeriod($class, $period) as $row) {
                $outcome = $row['outcome'];

                if ($outcome->normalizedValue === null) {
                    continue;
                }

                $checked++;

                // The engine matches a band inline while computing; this resolver
                // matches one on demand, for a domain the engine never placed.
                // They are the same rule, and this holds them to it.
                $this->assertSame(
                    $outcome->scaleLevelId,
                    $resolver->bandFor($scale, $outcome->normalizedValue)?->id,
                );
            }

            $this->assertGreaterThan(0, $checked);
        });
    }

    #[Test]
    public function nothing_in_the_resolution_is_a_written_threshold(): void
    {
        $resolver = (string) file_get_contents(app_path('Services/Assessment/ScaleProposalResolver.php'));
        $readModel = (string) file_get_contents(app_path('Services/Assessment/BuildResultsProgression.php'));

        // The bands come from the scale's own columns…
        $this->assertStringContainsString('band_min_normalized', $resolver);
        $this->assertStringContainsString('band_max_normalized', $resolver);
        // …and the read model asks the resolver rather than deciding anything.
        $this->assertStringContainsString('$this->proposals->bandFor(', $readModel);

        foreach (['Suficiente', 'Muito Bom', '>= 80', '>= 50'] as $invented) {
            $this->assertStringNotContainsString($invented, $resolver);
            $this->assertStringNotContainsString($invented, $readModel);
        }
    }

    // ------------------------------------------------------- 3. no ecrã

    #[Test]
    public function the_mention_closes_each_domain_block_and_borrows_the_scales_colour(): void
    {
        $screen = (string) preg_replace(
            '/\s+/u',
            ' ',
            (string) file_get_contents(base_path('resources/js/pages/results/Summary.vue')),
        );

        // A small chip, in the scale's own tone — never a threshold or a colour
        // chosen on the page.
        $this->assertStringContainsString('mention?.label', $screen);
        $this->assertStringContainsString(':class="levelClasses(domainCell(student.periods[student.periods.length - 1], domain.id)?.mention ?? null)"', $screen);
        $this->assertStringContainsString('Menção qualitativa acumulada', $screen);

        // And «—» where there is no band, which is a real answer.
        $this->assertStringContainsString('<span v-else class="text-muted-foreground">—</span>', $screen);
    }
}
