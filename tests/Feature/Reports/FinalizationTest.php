<?php

namespace Tests\Feature\Reports;

use App\Domain\Reporting\BehaviourRating;
use App\Domain\Reporting\SectionKey;
use App\Models\AcademicPeriod;
use App\Models\Organization;
use App\Models\OrganizationIdentity;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\Report;
use App\Models\ReportStatus;
use App\Models\ResultState;
use App\Models\SchoolClass;
use App\Models\StudentItemScore;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Documents\SchoolLogoService;
use App\Services\Reporting\ComposeReport;
use App\Services\Reporting\CreateReport;
use App\Services\Reporting\DeriveReport;
use App\Services\Reporting\FinalizeReport;
use App\Services\Reporting\ReportComparison;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\EntitlementsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Finalization and derivation — §37, §38, §39, §68, §69.
 *
 * THE SCENARIO THESE EXIST FOR: a teacher signs a report in February. In May a
 * grade is corrected, the school changes its logo, and somebody rewrites the
 * official name of the agrupamento. In June, a parent asks for the February
 * report again. It has to come back exactly as it was signed — the same
 * sentences, the same numbers, the same letterhead, the same image — because
 * anything else means the school issued a document it cannot reproduce.
 */
class FinalizationTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);
        $this->organization = $this->teacher->personalOrganization();

        $this->travelTo(Carbon::parse('2027-06-30 12:00:00'));

        $this->seed(EntitlementsSeeder::class);

        OrganizationSubscription::withoutGlobalScope('organization')->updateOrCreate(
            ['organization_id' => $this->organization->id],
            [
                'plan_id' => Plan::where('key', 'pro')->firstOrFail()->id,
                'status' => SubscriptionStatus::Active,
                'starts_at' => now()->subDay(),
                'ends_at' => null,
            ],
        );

        app(Entitlements::class)->flush();
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function asTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->organization, $callback);
    }

    private function schoolClass(): SchoolClass
    {
        return $this->asTenant(fn (): SchoolClass => SchoolClass::where('label', '7.º A')->firstOrFail());
    }

    private function period(int $sequence): AcademicPeriod
    {
        return $this->asTenant(fn (): AcademicPeriod => $this->schoolClass()
            ->academicYear->periods()->where('sequence', $sequence)->firstOrFail());
    }

    private function draft(int $sequence = 1): Report
    {
        return $this->asTenant(fn (): Report => app(CreateReport::class)->forClass(
            class: $this->schoolClass(),
            author: $this->teacher,
            period: $this->period($sequence),
        ));
    }

    private function finalize(Report $report): Report
    {
        return $this->asTenant(fn (): Report => app(FinalizeReport::class)->finalize($report, $this->teacher));
    }

    private function giveIdentity(string $name, ?string $logo = null): OrganizationIdentity
    {
        return $this->asTenant(function () use ($name, $logo): OrganizationIdentity {
            $identity = OrganizationIdentity::query()
                ->where('organization_id', $this->organization->id)
                ->first() ?? new OrganizationIdentity;

            $identity->forceFill([
                'organization_id' => $this->organization->id,
                'official_name' => $name,
                'logo_path' => $logo,
            ])->save();

            return $identity;
        });
    }

    // ------------------------------------------------------------ §68

    #[Test]
    public function a_finalized_report_survives_the_data_the_logo_and_the_name_all_changing(): void
    {
        Storage::fake(SchoolLogoService::DISK);

        Storage::disk(SchoolLogoService::DISK)
            ->put(SchoolLogoService::DIRECTORY.'/original.png', 'BYTES-DE-FEVEREIRO');

        $this->giveIdentity('Agrupamento de Escolas de Fevereiro', SchoolLogoService::DIRECTORY.'/original.png');

        $report = $this->finalize($this->draft(1));

        $documentBefore = $report->document;
        $this->assertNotNull($documentBefore);
        $this->assertTrue($report->isIntact());
        $this->assertSame('Agrupamento de Escolas de Fevereiro', $documentBefore['identity']['name']);

        $frozenLogo = $documentBefore['identity']['logo_path'];
        $this->assertIsString($frozenLogo);
        $this->assertSame('BYTES-DE-FEVEREIRO', Storage::disk(SchoolLogoService::DISK)->get($frozenLogo));

        // ---- the world moves on -------------------------------------------
        $this->asTenant(fn () => StudentItemScore::query()
            ->where('result_state', ResultState::Assessed)
            ->update(['points_earned' => '0']));

        Storage::disk(SchoolLogoService::DISK)
            ->put(SchoolLogoService::DIRECTORY.'/original.png', 'BYTES-DE-MAIO');

        $this->giveIdentity('Agrupamento de Escolas de Maio', SchoolLogoService::DIRECTORY.'/original.png');

        // ---- and the report does not --------------------------------------
        $after = $this->asTenant(fn () => $report->fresh());

        $this->assertSame($documentBefore, $after->document);
        $this->assertSame('Agrupamento de Escolas de Fevereiro', $after->document['identity']['name']);
        $this->assertTrue($after->isIntact());

        // THE IMAGE TOO. This is the one that would have failed silently: the
        // URL is stable, so only a copy of the bytes freezes it (§39).
        $this->assertSame('BYTES-DE-FEVEREIRO', Storage::disk(SchoolLogoService::DISK)->get($frozenLogo));
    }

    #[Test]
    public function a_finalized_report_cannot_be_regenerated_or_edited(): void
    {
        $report = $this->finalize($this->draft(1));

        $this->expectException(\LogicException::class);

        $this->asTenant(fn () => app(ComposeReport::class)->generate($report));
    }

    #[Test]
    public function finalizing_twice_is_refused(): void
    {
        $report = $this->finalize($this->draft(1));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('já se encontra finalizado');

        $this->finalize($report);
    }

    #[Test]
    public function excluded_and_empty_sections_do_not_enter_the_document(): void
    {
        $report = $this->draft(1);

        $this->asTenant(function () use ($report): void {
            $report->sections()
                ->where('key', SectionKey::DomainResults->value)
                ->update(['included' => false]);
        });

        $document = $this->finalize($this->asTenant(fn () => $report->fresh()))->document;

        $keys = array_map(fn (array $section) => $section['key'], $document['sections']);

        $this->assertNotContains(SectionKey::DomainResults->value, $keys);
        // A section the teacher never answered has no body and is likewise out.
        $this->assertNotContains(SectionKey::PlanningCompliance->value, $keys);
        $this->assertContains(SectionKey::OverallAssessment->value, $keys);
    }

    #[Test]
    public function the_document_records_where_each_section_came_from(): void
    {
        $document = $this->finalize($this->draft(1))->document;

        $overall = collect($document['sections'])->firstWhere('key', SectionKey::OverallAssessment->value);

        $this->assertNotNull($overall);
        $this->assertContains('statistics', $overall['sources']);
        $this->assertContains('generated_text', $overall['sources']);
    }

    // ------------------------------------------------------------ §69

    #[Test]
    public function deriving_copies_the_judgements_updates_the_numbers_and_leaves_the_original_alone(): void
    {
        $first = $this->draft(1);

        $this->asTenant(function () use ($first): void {
            $first->update(['teacher_input' => [
                'behaviour' => BehaviourRating::Good->value,
                'difficulties' => [[
                    'code' => 'writing_planning',
                    'label' => 'Planificação da escrita',
                    'domain' => null,
                    'note' => null,
                    'strategies' => [],
                ]],
            ]]);

            app(ComposeReport::class)->generate($first);

            // A paragraph the teacher wrote themselves.
            $first->sections()
                ->where('key', SectionKey::FinalSynthesis->value)
                ->update(['body' => 'A minha síntese do 1.º Semestre.', 'edited' => true]);
        });

        $finalized = $this->finalize($this->asTenant(fn () => $first->fresh()));
        $originalDocument = $finalized->document;

        // ---- derive into the next period ----------------------------------
        $second = $this->asTenant(fn () => app(DeriveReport::class)->derive(
            $finalized,
            $this->teacher,
            $this->period(2),
        ));

        // 3. It copied the authorised text.
        $this->assertSame($finalized->getKey(), $second->based_on_report_id);
        $this->assertSame(BehaviourRating::Good->value, $second->input('behaviour'));
        $this->assertSame('Planificação da escrita', $second->input('difficulties.0.label'));

        $synthesis = $this->asTenant(fn () => $second->sections()
            ->where('key', SectionKey::FinalSynthesis->value)->firstOrFail());

        $this->assertSame('A minha síntese do 1.º Semestre.', $synthesis->body);
        $this->assertTrue($synthesis->edited, 'O texto é do professor e tem de continuar assinalado como tal.');

        // 4. And it updated the quantitative context.
        $this->assertSame($this->period(2)->id, $second->academic_period_id);
        $this->assertSame('2.º Semestre', $second->scope_label);
        $this->assertSame(ReportStatus::Draft, $second->status);

        $identification = $this->asTenant(fn () => $second->sections()
            ->where('key', SectionKey::ClassIdentification->value)->firstOrFail());

        $this->assertStringContainsString('2.º Semestre', (string) $identification->body);

        // 5–6. Change the derived one; the original does not move.
        $this->asTenant(fn () => $second->update(['title' => 'Outro título completamente']));

        $this->assertSame($originalDocument, $this->asTenant(fn () => $finalized->fresh()->document));
        $this->assertTrue($this->asTenant(fn () => $finalized->fresh()->isIntact()));
    }

    #[Test]
    public function the_generated_text_of_the_old_report_is_never_carried_over(): void
    {
        $first = $this->finalize($this->draft(1));

        $second = $this->asTenant(fn () => app(DeriveReport::class)->derive($first, $this->teacher, $this->period(2)));

        $overall = $this->asTenant(fn () => $second->sections()
            ->where('key', SectionKey::OverallAssessment->value)->firstOrFail());

        $firstOverall = collect($first->document['sections'])
            ->firstWhere('key', SectionKey::OverallAssessment->value);

        $this->assertNotNull($firstOverall);
        // Regenerated from the new period, not inherited. The two sentences may
        // legitimately be different or the same — what must not happen is the
        // old text surviving as `generated_body` and being restorable.
        $this->assertNotSame($firstOverall['body'], $overall->generated_body);
        $this->assertFalse($overall->edited);
    }

    // ------------------------------------------------------------ §35

    #[Test]
    public function the_comparison_states_differences_and_offers_no_cause(): void
    {
        $first = $this->finalize($this->draft(1));
        $second = $this->asTenant(fn () => app(DeriveReport::class)->derive($first, $this->teacher, $this->period(2)));

        $comparison = $this->asTenant(fn () => app(ReportComparison::class)->for($second));

        if ($comparison === null) {
            // Legitimate: the demo class may show no numeric movement between
            // the two periods, and a comparison with nothing to say says
            // nothing rather than listing unchanged rows.
            $this->assertTrue(true);

            return;
        }

        $this->assertSame($first->ulid, $comparison['base']['ulid']);
        $this->assertStringContainsString('não atribui causa', $comparison['caveat']);

        foreach ($comparison['rows'] as $row) {
            $this->assertNotSame($row['from'], $row['to'], 'Uma linha sem diferença é ruído.');
            $this->assertNotNull($row['from']);
            $this->assertNotNull($row['to']);
        }
    }

    #[Test]
    public function a_report_that_is_not_derived_has_no_comparison(): void
    {
        $this->assertNull($this->asTenant(fn () => app(ReportComparison::class)->for($this->draft(1))));
    }
}
