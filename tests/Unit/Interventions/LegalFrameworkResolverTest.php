<?php

namespace Tests\Unit\Interventions;

use App\Models\InterventionType;
use App\Models\LegalMappingMode;
use App\Models\Organization;
use App\Support\Interventions\InterventionLegalFramework;
use App\Support\Interventions\LegalFrameworkRegistry;
use App\Support\Interventions\LegalFrameworkResolver;
use App\Support\Interventions\LegalMapping;
use App\Support\Interventions\NullLegalFramework;
use App\Support\Interventions\PortugalInclusiveEducationFramework;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * How Lapispro decides which law, if any, applies to an intervention.
 *
 * Two mistakes these tests exist to make impossible: applying one country's law
 * to another country's school, and letting a change in legislation reinterpret
 * interventions recorded before it.
 */
class LegalFrameworkResolverTest extends TestCase
{
    private function resolver(?LegalFrameworkRegistry $registry = null): LegalFrameworkResolver
    {
        return new LegalFrameworkResolver($registry ?? new LegalFrameworkRegistry);
    }

    private function organization(?string $jurisdiction, string $locale = 'pt_PT'): Organization
    {
        // Not persisted: the resolver only reads attributes, and keeping this
        // a unit test makes the jurisdiction rules easy to read in isolation.
        return new Organization(['name' => 'Escola', 'locale' => $locale, 'jurisdiction' => $jurisdiction]);
    }

    // ------------------------------------------------------- the fallback rule

    #[Test]
    public function an_organization_that_never_stated_a_jurisdiction_keeps_the_configured_default(): void
    {
        config(['lapis.default_jurisdiction' => 'PT']);

        $framework = $this->resolver()->for($this->organization(null), Carbon::parse('2026-10-01'));

        // Every organization in the current installation is in this state, so
        // this is the assertion that says "nothing changed for anybody".
        $this->assertInstanceOf(PortugalInclusiveEducationFramework::class, $framework);
    }

    #[Test]
    public function an_explicit_unsupported_jurisdiction_never_falls_back_to_portugal(): void
    {
        config(['lapis.default_jurisdiction' => 'PT']);

        // The whole point of the fallback being jurisdiction-level rather than
        // framework-level: naming a country Lapispro has no law for means "no
        // framework", not "use Portugal's".
        $framework = $this->resolver()->for($this->organization('ES'), Carbon::parse('2026-10-01'));

        $this->assertInstanceOf(NullLegalFramework::class, $framework);
        $this->assertNull($framework->jurisdiction());
    }

    #[Test]
    public function turning_the_default_off_leaves_an_unstated_organization_without_a_framework(): void
    {
        config(['lapis.default_jurisdiction' => null]);

        $this->assertInstanceOf(
            NullLegalFramework::class,
            $this->resolver()->for($this->organization(null), Carbon::parse('2026-10-01')),
        );
    }

    #[Test]
    public function an_explicit_portuguese_jurisdiction_resolves_to_portugal(): void
    {
        config(['lapis.default_jurisdiction' => null]);

        $this->assertInstanceOf(
            PortugalInclusiveEducationFramework::class,
            $this->resolver()->for($this->organization('PT'), Carbon::parse('2026-10-01')),
        );
    }

    // ------------------------------------------------------------- normalising

    #[Test]
    public function a_jurisdiction_code_is_trimmed_and_upper_cased_before_lookup(): void
    {
        foreach (['pt', ' PT ', ' pt', 'Pt'] as $written) {
            $this->assertInstanceOf(
                PortugalInclusiveEducationFramework::class,
                $this->resolver()->for($this->organization($written), Carbon::parse('2026-10-01')),
                $written,
            );
        }
    }

    #[Test]
    public function a_blank_jurisdiction_counts_as_never_stated(): void
    {
        config(['lapis.default_jurisdiction' => 'PT']);

        $this->assertInstanceOf(
            PortugalInclusiveEducationFramework::class,
            $this->resolver()->for($this->organization('   '), Carbon::parse('2026-10-01')),
        );
    }

    // ------------------------------------------------------- locale is not law

    #[Test]
    public function the_interface_language_never_decides_which_law_applies(): void
    {
        config(['lapis.default_jurisdiction' => null]);

        // A school in Portugal working in English is still under Portuguese law.
        $this->assertInstanceOf(
            PortugalInclusiveEducationFramework::class,
            $this->resolver()->for($this->organization('PT', locale: 'en'), Carbon::parse('2026-10-01')),
        );

        // A school abroad working in Portuguese is not.
        $this->assertInstanceOf(
            NullLegalFramework::class,
            $this->resolver()->for($this->organization('ES', locale: 'pt_PT'), Carbon::parse('2026-10-01')),
        );
    }

    // ------------------------------------------------------------------- dates

    #[Test]
    public function the_framework_is_chosen_by_date_so_history_is_never_reinterpreted(): void
    {
        // A second, later version of the same jurisdiction's law. It is
        // fictitious and lives only in this test — no speculative framework
        // ships with the application.
        $future = new class implements InterventionLegalFramework
        {
            public function code(): string
            {
                return 'pt-test-future';
            }

            public function jurisdiction(): string
            {
                return 'PT';
            }

            public function coversDate(CarbonInterface $date): bool
            {
                return $date->greaterThanOrEqualTo(Carbon::parse('2027-09-01'));
            }

            public function mappingFor(InterventionType $type): LegalMapping
            {
                return LegalMapping::none();
            }

            public function hasLegalTaxonomy(): bool
            {
                return true;
            }

            public function supportMeasureLevels(): array
            {
                return [];
            }

            public function evaluationAdaptations(): array
            {
                return [];
            }
        };

        $registry = new LegalFrameworkRegistry([$future, new PortugalInclusiveEducationFramework]);
        $resolver = $this->resolver($registry);

        // An intervention that happened before the change keeps the old
        // reading, however long afterwards it is opened or edited.
        $this->assertInstanceOf(
            PortugalInclusiveEducationFramework::class,
            $resolver->resolve('PT', Carbon::parse('2026-11-10')),
        );

        $this->assertSame('pt-test-future', $resolver->resolve('PT', Carbon::parse('2027-09-02'))->code());
    }

    // --------------------------------------------------- no framework is valid

    #[Test]
    public function without_a_framework_nothing_is_proposed_and_no_taxonomy_is_borrowed(): void
    {
        $framework = new NullLegalFramework;

        foreach (InterventionType::cases() as $type) {
            $mapping = $framework->mappingFor($type);

            $this->assertSame(LegalMappingMode::None, $mapping->mode, $type->value);
            $this->assertNull($mapping->toPayload(), $type->value);
        }

        // Empty, never absent: the UI contract keeps its shape everywhere.
        $this->assertFalse($framework->hasLegalTaxonomy());
        $this->assertSame([], $framework->supportMeasureLevels());
        $this->assertSame([], $framework->evaluationAdaptations());
    }

    #[Test]
    public function the_pedagogical_catalogue_is_identical_under_every_framework(): void
    {
        $portugal = InterventionType::catalogue(new PortugalInclusiveEducationFramework);
        $none = InterventionType::catalogue(new NullLegalFramework);

        $this->assertCount(count(InterventionType::cases()), $none);

        foreach ($portugal as $index => $type) {
            // Same types, same labels, same contexts — only the legal reading
            // differs, which is the whole separation this architecture buys.
            $this->assertSame($type['value'], $none[$index]['value']);
            $this->assertSame($type['label'], $none[$index]['label']);
            $this->assertSame($type['context'], $none[$index]['context']);
            $this->assertSame($type['requires_description'], $none[$index]['requires_description']);
            $this->assertNull($none[$index]['legal_mapping']);
        }
    }

    #[Test]
    public function no_speculative_future_framework_ships_with_the_application(): void
    {
        $registry = new LegalFrameworkRegistry;

        // The Portuguese revision announced for 2027 is deliberately not
        // encoded: it will be a second framework once the final text exists.
        foreach (['2026-10-01', '2027-10-01', '2030-01-01'] as $date) {
            $this->assertInstanceOf(
                PortugalInclusiveEducationFramework::class,
                $registry->find('PT', Carbon::parse($date)),
                $date,
            );
        }

        // And no other jurisdiction was invented.
        foreach (['ES', 'FR', 'BR', 'GB'] as $jurisdiction) {
            $this->assertNull($registry->find($jurisdiction, Carbon::parse('2026-10-01')), $jurisdiction);
        }
    }
}
