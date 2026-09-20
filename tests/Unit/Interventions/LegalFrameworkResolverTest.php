<?php

namespace Tests\Unit\Interventions;

use App\Models\InterventionType;
use App\Models\LegalFrameworkStatus;
use App\Models\LegalMappingMode;
use App\Models\Organization;
use App\Support\Interventions\LegalFrameworkRegistry;
use App\Support\Interventions\LegalFrameworkResolver;
use App\Support\Interventions\NullLegalFramework;
use App\Support\Interventions\OverlappingLegalFrameworksException;
use App\Support\Interventions\PortugalInclusiveEducationFramework;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Interventions\FictitiousLegalFramework;
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
        // Two versions of one jurisdiction's law that SUCCEED one another: the
        // first ends on the day before the second begins. Both are fictitious
        // and live only in this test — no speculative framework ships with the
        // application.
        //
        // They abut rather than overlap, which is the whole reason validUntil
        // is inclusive: 2027-08-31 is the last day of the old regime and
        // 2027-09-01 the first of the new, so no date is claimed twice and
        // none falls between them.
        $earlier = new FictitiousLegalFramework(
            code: 'pt-test-earlier',
            jurisdiction: 'PT',
            validFrom: Carbon::parse('2018-07-07'),
            validUntil: Carbon::parse('2027-08-31'),
        );

        $future = new FictitiousLegalFramework(
            code: 'pt-test-future',
            jurisdiction: 'PT',
            validFrom: Carbon::parse('2027-09-01'),
        );

        $resolver = $this->resolver(new LegalFrameworkRegistry([$future, $earlier]));

        // An intervention that happened before the change keeps the old
        // reading, however long afterwards it is opened or edited. The future
        // framework is FIRST in the array, so this also proves the choice is
        // made by date and not by position.
        $this->assertSame('pt-test-earlier', $resolver->resolve('PT', Carbon::parse('2026-11-10'))->code());

        // The boundary, from both sides.
        $this->assertSame('pt-test-earlier', $resolver->resolve('PT', Carbon::parse('2027-08-31'))->code());
        $this->assertSame('pt-test-future', $resolver->resolve('PT', Carbon::parse('2027-09-01'))->code());
        $this->assertSame('pt-test-future', $resolver->resolve('PT', Carbon::parse('2027-09-02'))->code());
    }

    #[Test]
    public function the_regime_in_force_does_not_answer_for_dates_before_it_existed(): void
    {
        $registry = new LegalFrameworkRegistry;

        // Decreto-Lei n.º 54/2018 entered into force on 2018-07-07. A record
        // dated before that has NO framework — the honest answer, and the one
        // that keeps the app from framing a 2017 record under a 2018 regime.
        $this->assertNull($registry->find('PT', Carbon::parse('2018-07-06')));
        $this->assertSame('pt-inclusive-education-2018', $registry->find('PT', Carbon::parse('2018-07-07'))?->code());

        // And the resolver turns that absence into NullLegalFramework rather
        // than into an error: recording an intervention dated before the
        // regime is unusual, not forbidden.
        $this->assertInstanceOf(
            NullLegalFramework::class,
            $this->resolver()->resolve('PT', Carbon::parse('2017-01-01')),
        );
    }

    #[Test]
    public function validity_bounds_are_inclusive_at_both_ends(): void
    {
        $framework = new FictitiousLegalFramework(
            code: 'xx-bounded',
            jurisdiction: 'XX',
            validFrom: Carbon::parse('2020-01-10'),
            validUntil: Carbon::parse('2020-12-31'),
        );

        $this->assertFalse($framework->coversDate(Carbon::parse('2020-01-09')), 'antes do início');
        $this->assertTrue($framework->coversDate(Carbon::parse('2020-01-10')), 'no dia do início');
        $this->assertTrue($framework->coversDate(Carbon::parse('2020-06-15')), 'durante a vigência');
        $this->assertTrue($framework->coversDate(Carbon::parse('2020-12-31')), 'no último dia');
        $this->assertFalse($framework->coversDate(Carbon::parse('2021-01-01')), 'depois do fim');

        // Compared by calendar day, never by instant: a time of day would make
        // the answer depend on a timezone nobody chose.
        $this->assertTrue($framework->coversDate(Carbon::parse('2020-12-31 23:59:59')));
        $this->assertTrue($framework->coversDate(Carbon::parse('2020-01-10 00:00:01')));
    }

    #[Test]
    public function an_open_ended_version_stays_in_force_indefinitely(): void
    {
        $framework = new FictitiousLegalFramework(
            code: 'xx-open',
            jurisdiction: 'XX',
            validFrom: Carbon::parse('2020-01-10'),
        );

        $this->assertFalse($framework->coversDate(Carbon::parse('2020-01-09')));

        foreach (['2020-01-10', '2030-01-01', '2099-12-31'] as $date) {
            $this->assertTrue($framework->coversDate(Carbon::parse($date)), $date);
        }

        // An absent start is an absent bound, not "not yet in force".
        $unbounded = new FictitiousLegalFramework(code: 'xx-unbounded', jurisdiction: 'XX');
        $this->assertTrue($unbounded->coversDate(Carbon::parse('1990-01-01')));
    }

    #[Test]
    public function two_versions_claiming_the_same_date_fail_loudly_instead_of_picking_one(): void
    {
        // A configuration error, never a legitimate state. Answering it by
        // array order would make the legal reading of every record on that
        // date depend on the order somebody wrote a constructor — and change
        // silently the day that order changed.
        $registry = new LegalFrameworkRegistry([
            new FictitiousLegalFramework(code: 'xx-a', jurisdiction: 'XX', validFrom: Carbon::parse('2020-01-01')),
            new FictitiousLegalFramework(code: 'xx-b', jurisdiction: 'XX', validFrom: Carbon::parse('2020-06-01')),
        ]);

        $this->expectException(OverlappingLegalFrameworksException::class);
        $this->expectExceptionMessageMatches('/xx-a.*xx-b/');

        $registry->find('XX', Carbon::parse('2020-07-01'));
    }

    #[Test]
    public function versions_that_merely_abut_are_not_an_overlap(): void
    {
        $registry = new LegalFrameworkRegistry([
            new FictitiousLegalFramework(code: 'xx-a', jurisdiction: 'XX', validFrom: Carbon::parse('2020-01-01'), validUntil: Carbon::parse('2020-05-31')),
            new FictitiousLegalFramework(code: 'xx-b', jurisdiction: 'XX', validFrom: Carbon::parse('2020-06-01')),
        ]);

        // The day they meet resolves to exactly one of them, from either side.
        $this->assertSame('xx-a', $registry->find('XX', Carbon::parse('2020-05-31'))?->code());
        $this->assertSame('xx-b', $registry->find('XX', Carbon::parse('2020-06-01'))?->code());
    }

    #[Test]
    public function a_draft_version_never_counts_as_an_overlap(): void
    {
        // A draft sharing a date with the regime in force is the NORMAL state
        // while a revision is being prepared. It must not be applied, and it
        // must not make the applicable version unresolvable either.
        $registry = new LegalFrameworkRegistry([
            new FictitiousLegalFramework(code: 'xx-active', jurisdiction: 'XX', validFrom: Carbon::parse('2020-01-01')),
            new FictitiousLegalFramework(code: 'xx-draft', jurisdiction: 'XX', status: LegalFrameworkStatus::Draft, validFrom: Carbon::parse('2020-01-01')),
        ]);

        $this->assertSame('xx-active', $registry->find('XX', Carbon::parse('2021-01-01'))?->code());
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
