<?php

namespace Tests\Feature\Reports;

use App\Domain\Reporting\SectionKey;
use App\Models\AcademicPeriod;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\Report;
use App\Models\ReportTemplate;
use App\Models\ReportTemplateKind;
use App\Models\ReportTone;
use App\Models\ReportType;
use App\Models\SchoolClass;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Reporting\CreateReport;
use App\Services\Reporting\FinalizeReport;
use App\Services\Reporting\Templates\SaveReportAsTemplate;
use App\Services\Reporting\Templates\TemplateResolver;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\EntitlementsSeeder;
use Database\Seeders\ReportTemplatesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Report templates (§0–§46).
 *
 * A TEMPLATE CONFIGURES THE ENGINE; IT IS NOT A SECOND ENGINE. The two failures
 * worth guarding against are both quiet: a template that grants a section the
 * school's plan does not include, and a template made from a real report that
 * carries that report's numbers and names into the next class it is used on.
 *
 * The third is the one a teacher would notice months later — a template edited
 * in March silently rewriting a report finished in February.
 */
class ReportTemplateTest extends TestCase
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
        $this->seed(ReportTemplatesSeeder::class);

        $this->givePlan('pro');
    }

    protected function givePlan(string $key): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')->updateOrCreate(
            ['organization_id' => $this->organization->id],
            [
                'plan_id' => Plan::where('key', $key)->firstOrFail()->id,
                'status' => SubscriptionStatus::Active,
                'starts_at' => Carbon::parse('2026-01-01 00:00:00'),
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
    private function asTenant(callable $callback, ?Organization $organization = null): mixed
    {
        return app(CurrentOrganization::class)->runFor($organization ?? $this->organization, $callback);
    }

    private function schoolClass(): SchoolClass
    {
        return $this->asTenant(fn (): SchoolClass => SchoolClass::where('label', '7.º A')->firstOrFail());
    }

    private function period(): AcademicPeriod
    {
        return $this->asTenant(fn (): AcademicPeriod => $this->schoolClass()
            ->academicYear->periods()->orderBy('sequence')->firstOrFail());
    }

    /**
     * @param  list<string>  $order
     */
    private function personalTemplate(array $order, ?User $owner = null): ReportTemplate
    {
        $owner ??= $this->teacher;

        return $this->asTenant(fn () => ReportTemplate::create([
            'kind' => ReportTemplateKind::Personal,
            'user_id' => $owner->getKey(),
            'report_type' => ReportType::SchoolClass,
            'name' => 'Relatório semestral de Português',
            'settings' => [
                'sections' => array_map(
                    fn (string $key, int $index): array => ['key' => $key, 'included' => true, 'position' => ($index + 1) * 10],
                    $order,
                    array_keys($order),
                ),
                'tone' => ReportTone::Objective->value,
                'options' => ['name_students' => false],
            ],
            'is_default' => false,
            'is_active' => true,
        ]));
    }

    // ------------------------------------------------------------ §46 plans

    #[Test]
    public function every_plan_sees_the_system_templates(): void
    {
        $this->givePlan('base');

        $available = $this->asTenant(fn () => app(TemplateResolver::class)
            ->availableTo($this->teacher, ReportType::SchoolClass));

        $this->assertCount(1, $available);
        $this->assertSame(ReportTemplateKind::System, $available->first()->kind);
    }

    #[Test]
    public function base_cannot_create_a_template_of_its_own(): void
    {
        $this->givePlan('base');

        $this->asTenant(function (): void {
            $this->assertFalse(Gate::forUser($this->teacher)
                ->allows('createKind', [ReportTemplate::class, ReportTemplateKind::Personal]));
            $this->assertFalse(Gate::forUser($this->teacher)
                ->allows('createKind', [ReportTemplate::class, ReportTemplateKind::Institutional]));
        });
    }

    #[Test]
    public function pro_creates_personal_templates_and_not_institutional_ones(): void
    {
        $this->asTenant(function (): void {
            $this->assertTrue(Gate::forUser($this->teacher)
                ->allows('createKind', [ReportTemplate::class, ReportTemplateKind::Personal]));
            $this->assertFalse(Gate::forUser($this->teacher)
                ->allows('createKind', [ReportTemplate::class, ReportTemplateKind::Institutional]));
        });
    }

    #[Test]
    public function an_institutional_plan_lets_the_owner_create_institutional_templates(): void
    {
        $this->givePlan('institutional');

        $this->asTenant(function (): void {
            $this->assertTrue(Gate::forUser($this->teacher)
                ->allows('createKind', [ReportTemplate::class, ReportTemplateKind::Institutional]));
        });
    }

    #[Test]
    public function a_member_uses_an_institutional_template_but_never_changes_it(): void
    {
        $this->givePlan('institutional');

        $member = User::factory()->create();
        $this->organization->members()->syncWithoutDetaching([$member->id => ['joined_at' => now()]]);

        $template = $this->asTenant(fn () => ReportTemplate::create([
            'kind' => ReportTemplateKind::Institutional,
            'user_id' => $this->teacher->getKey(),
            'report_type' => ReportType::SchoolClass,
            'name' => 'Padrão da escola',
            'settings' => ['sections' => [], 'tone' => ReportTone::Objective->value, 'options' => []],
        ]));

        $this->asTenant(function () use ($member, $template): void {
            $this->assertTrue(Gate::forUser($member)->allows('view', $template));
            $this->assertFalse(Gate::forUser($member)->allows('update', $template));
            // The owner administers.
            $this->assertTrue(Gate::forUser($this->teacher)->allows('update', $template));
        });
    }

    // --------------------------------------------------------- §38 isolation

    #[Test]
    public function a_personal_template_is_invisible_to_a_colleague(): void
    {
        $colleague = User::factory()->create();
        $this->organization->members()->syncWithoutDetaching([$colleague->id => ['joined_at' => now()]]);

        $mine = $this->personalTemplate([SectionKey::OverallAssessment->value]);

        $visible = $this->asTenant(fn () => ReportTemplate::query()
            ->visibleTo($colleague)
            ->pluck('ulid')
            ->all());

        $this->assertNotContains($mine->ulid, $visible);
        $this->asTenant(fn () => $this->assertFalse(Gate::forUser($colleague)->allows('view', $mine)));
    }

    #[Test]
    public function another_organizations_templates_are_never_in_scope(): void
    {
        $mine = $this->personalTemplate([SectionKey::OverallAssessment->value]);

        $stranger = User::factory()->create();

        $visible = app(CurrentOrganization::class)->runFor(
            $stranger->personalOrganization(),
            fn () => ReportTemplate::query()->pluck('ulid')->all(),
        );

        $this->assertNotContains($mine->ulid, $visible);
        // The system ones are shared, so the list is not simply empty.
        $this->assertNotEmpty($visible);
    }

    // ------------------------------------------------------------ §43 active

    #[Test]
    public function a_deactivated_template_stops_being_offered_for_new_reports(): void
    {
        $template = $this->personalTemplate([SectionKey::OverallAssessment->value]);

        $before = $this->asTenant(fn () => app(TemplateResolver::class)
            ->availableTo($this->teacher, ReportType::SchoolClass)->pluck('ulid')->all());

        $this->assertContains($template->ulid, $before);

        $this->asTenant(fn () => $template->update(['is_active' => false]));

        $after = $this->asTenant(fn () => app(TemplateResolver::class)
            ->availableTo($this->teacher, ReportType::SchoolClass)->pluck('ulid')->all());

        $this->assertNotContains($template->ulid, $after);
        // Still there in the management area, and still resolvable by the
        // reports that were built from it (§21).
        $this->assertTrue($this->asTenant(fn () => ReportTemplate::query()->whereKey($template->id)->exists()));
    }

    // -------------------------------------------------- §13, §14 starting point

    #[Test]
    public function a_template_decides_the_initial_order_of_the_sections(): void
    {
        $template = $this->personalTemplate([
            SectionKey::FinalSynthesis->value,
            SectionKey::ClassIdentification->value,
            SectionKey::OverallAssessment->value,
        ]);

        $report = $this->asTenant(fn () => app(CreateReport::class)->forClass(
            class: $this->schoolClass(),
            author: $this->teacher,
            period: $this->period(),
            template: $template,
        ));

        $keys = $this->asTenant(fn () => $report->sections()->orderBy('position')->pluck('key')->all());

        $this->assertSame(SectionKey::FinalSynthesis->value, $keys[0]);
        $this->assertSame(SectionKey::ClassIdentification->value, $keys[1]);
        $this->assertSame(SectionKey::OverallAssessment->value, $keys[2]);
        // Sections the template did not mention are appended, not lost.
        $this->assertContains(SectionKey::DomainResults->value, $keys);
    }

    #[Test]
    public function editing_a_template_afterwards_changes_nothing_that_already_exists(): void
    {
        $template = $this->personalTemplate([
            SectionKey::FinalSynthesis->value,
            SectionKey::ClassIdentification->value,
        ]);

        $report = $this->asTenant(fn () => app(CreateReport::class)->forClass(
            class: $this->schoolClass(),
            author: $this->teacher,
            period: $this->period(),
            template: $template,
        ));

        $before = $this->asTenant(fn () => $report->sections()->orderBy('position')->pluck('key')->all());

        // The template is rewritten, top to bottom.
        $this->asTenant(fn () => $template->update([
            'name' => 'Outro nome completamente',
            'settings' => [
                'sections' => [['key' => SectionKey::ClassRecords->value, 'included' => true, 'position' => 10]],
                'tone' => ReportTone::Objective->value,
                'options' => [],
            ],
        ]));

        $after = $this->asTenant(fn () => $report->fresh()->sections()->orderBy('position')->pluck('key')->all());

        $this->assertSame($before, $after);
        // And the report still knows what the template said when it was created.
        $this->assertSame('Relatório semestral de Português', $report->template_snapshot['name']);
    }

    #[Test]
    public function a_finalized_report_keeps_the_template_it_was_built_from(): void
    {
        $template = $this->personalTemplate([SectionKey::ClassIdentification->value]);

        $report = $this->asTenant(fn () => app(CreateReport::class)->forClass(
            class: $this->schoolClass(),
            author: $this->teacher,
            period: $this->period(),
            template: $template,
        ));

        $finalized = $this->asTenant(fn () => app(FinalizeReport::class)->finalize($report, $this->teacher));

        $snapshot = $finalized->document['template'];

        $this->assertSame($template->ulid, $snapshot['ulid']);
        $this->assertSame('Relatório semestral de Português', $snapshot['name']);
        $this->assertSame(ReportTemplateKind::Personal->value, $snapshot['kind']);
        $this->assertNotEmpty($snapshot['settings']['sections']);
    }

    // ------------------------------------------------------- §23 capabilities

    #[Test]
    public function a_template_cannot_grant_a_section_the_plan_does_not_include(): void
    {
        // Written while the school is on Pro, listing an interpretive section.
        $template = $this->personalTemplate([
            SectionKey::ClassIdentification->value,
            SectionKey::Difficulties->value,
        ]);

        // The subscription lapses back to Base.
        $this->givePlan('base');

        $report = $this->asTenant(fn () => app(CreateReport::class)->forClass(
            class: $this->schoolClass(),
            author: $this->teacher,
            period: $this->period(),
            template: $template,
        ));

        $keys = $this->asTenant(fn () => $report->sections()->pluck('key')->all());

        $this->assertContains(SectionKey::ClassIdentification->value, $keys);
        $this->assertNotContains(SectionKey::Difficulties->value, $keys);
    }

    #[Test]
    public function a_template_cannot_turn_on_a_tone_the_plan_forbids(): void
    {
        $template = $this->personalTemplate([SectionKey::ClassIdentification->value]);

        $this->asTenant(fn () => $template->update([
            'settings' => array_replace($template->settings, ['tone' => ReportTone::Formal->value]),
        ]));

        $this->givePlan('base');

        $report = $this->asTenant(fn () => app(CreateReport::class)->forClass(
            class: $this->schoolClass(),
            author: $this->teacher,
            period: $this->period(),
            template: $template->fresh(),
        ));

        $this->assertSame(ReportTone::Objective, $report->tone);
    }

    // ------------------------------------------------- §18, §37 save as template

    #[Test]
    public function saving_a_report_as_a_template_carries_no_academic_data(): void
    {
        $report = $this->asTenant(fn () => app(CreateReport::class)->forClass(
            class: $this->schoolClass(),
            author: $this->teacher,
            period: $this->period(),
        ));

        // A report full of real content: figures, a characterisation, a
        // validated difficulty and a paragraph the teacher wrote.
        $this->asTenant(function () use ($report): void {
            $report->update(['teacher_input' => [
                'behaviour' => 'good',
                'observation' => 'A Maria tem revelado grande empenho.',
                'difficulties' => [[
                    'code' => 'writing_planning',
                    'label' => 'Planificação da escrita',
                    'domain' => null,
                    'note' => 'Sobretudo no João.',
                    'strategies' => [],
                ]],
            ]]);

            $report->sections()->where('key', SectionKey::FinalSynthesis->value)
                ->update(['body' => 'A turma obteve 58,1% e a Maria destacou-se.', 'edited' => true]);
        });

        $template = $this->asTenant(fn () => app(SaveReportAsTemplate::class)->save(
            report: $report->fresh(),
            author: $this->teacher,
            kind: ReportTemplateKind::Personal,
            name: 'Relatório semestral',
        ));

        $encoded = json_encode($template->settings, JSON_UNESCAPED_UNICODE);

        // Nothing from the case travels.
        foreach (['Maria', 'João', '58,1', '58.1', 'empenho', 'Planificação da escrita', 'destacou-se'] as $leak) {
            $this->assertStringNotContainsString($leak, (string) $encoded, "«{$leak}» chegou ao modelo.");
        }

        // And the arrangement did.
        $this->assertNotEmpty($template->sections());
        $this->assertSame(ReportTone::Objective->value, $template->settings['tone']);
        $this->assertArrayNotHasKey('teacher_input', $template->settings);
    }

    #[Test]
    public function saving_a_report_as_a_template_keeps_the_order_the_teacher_chose(): void
    {
        $report = $this->asTenant(fn () => app(CreateReport::class)->forClass(
            class: $this->schoolClass(),
            author: $this->teacher,
            period: $this->period(),
        ));

        // Move the final synthesis to the top.
        $this->asTenant(function () use ($report): void {
            $report->sections()->where('key', SectionKey::FinalSynthesis->value)->update(['position' => 1]);
        });

        $template = $this->asTenant(fn () => app(SaveReportAsTemplate::class)->save(
            report: $report->fresh(),
            author: $this->teacher,
            kind: ReportTemplateKind::Personal,
            name: 'A minha ordem',
        ));

        $this->assertSame(SectionKey::FinalSynthesis->value, $template->sections()[0]['key']);
    }

    // ------------------------------------------------------------ §44 default

    #[Test]
    public function only_one_personal_template_per_type_is_the_preferred_one(): void
    {
        $first = $this->personalTemplate([SectionKey::ClassIdentification->value]);
        $second = $this->personalTemplate([SectionKey::OverallAssessment->value]);

        $this->asTenant(function () use ($first, $second): void {
            app(SaveReportAsTemplate::class)->makeDefault($first);
            app(SaveReportAsTemplate::class)->makeDefault($second);
        });

        $this->assertFalse($this->asTenant(fn () => $first->fresh()->is_default));
        $this->assertTrue($this->asTenant(fn () => $second->fresh()->is_default));

        // And it is what the creation screen pre-selects (§17).
        $preferred = $this->asTenant(fn () => app(TemplateResolver::class)
            ->preferredFor($this->teacher, ReportType::SchoolClass));

        $this->assertSame($second->ulid, $preferred?->ulid);
    }

    // ------------------------------------------------------------------- HTTP

    #[Test]
    public function the_templates_page_lists_what_this_teacher_may_see(): void
    {
        $this->personalTemplate([SectionKey::ClassIdentification->value]);

        $this->actingAs($this->teacher)
            ->get('/reports/modelos')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('reports/templates/Index')
                ->where('templates', fn ($templates) => collect($templates)
                    ->contains(fn ($row) => $row['name'] === 'Relatório semestral de Português')));
    }

    #[Test]
    public function another_users_personal_template_is_not_even_reachable(): void
    {
        $stranger = User::factory()->create();

        $template = $this->personalTemplate([SectionKey::ClassIdentification->value]);

        // 404 rather than 403, and deliberately so: the tenant scope refuses
        // before the policy is consulted, which is the stronger answer — the
        // response does not confirm that the template exists at all. The policy
        // is asserted directly in a_personal_template_is_invisible_to_a_colleague.
        $this->actingAs($stranger)->get("/reports/modelos/{$template->ulid}")->assertNotFound();
    }

    #[Test]
    public function a_system_template_cannot_be_edited_through_the_application(): void
    {
        $system = $this->asTenant(fn () => ReportTemplate::query()
            ->where('kind', ReportTemplateKind::System)
            ->where('report_type', ReportType::SchoolClass)
            ->firstOrFail());

        $this->actingAs($this->teacher)
            ->put("/reports/modelos/{$system->ulid}", ['name' => 'Alterado'])
            ->assertForbidden();

        $this->assertSame('Relatório de turma — padrão Lapispro', $this->asTenant(fn () => $system->fresh()->name));
    }

    #[Test]
    public function duplicating_produces_a_personal_copy_owned_by_whoever_pressed_it(): void
    {
        $system = $this->asTenant(fn () => ReportTemplate::query()
            ->where('kind', ReportTemplateKind::System)
            ->where('report_type', ReportType::SchoolClass)
            ->firstOrFail());

        $this->actingAs($this->teacher)
            ->post("/reports/modelos/{$system->ulid}/duplicar")
            ->assertRedirect();

        $copy = $this->asTenant(fn () => ReportTemplate::query()
            ->where('kind', ReportTemplateKind::Personal)
            ->latest('id')
            ->firstOrFail());

        $this->assertSame('Cópia de Relatório de turma — padrão Lapispro', $copy->name);
        $this->assertSame($this->teacher->id, $copy->user_id);
        $this->assertSame($system->settings, $copy->settings);
    }

    #[Test]
    public function a_report_created_without_a_template_records_none(): void
    {
        $report = $this->asTenant(fn () => app(CreateReport::class)->forClass(
            class: $this->schoolClass(),
            author: $this->teacher,
            period: $this->period(),
        ));

        $this->assertNull($report->template_snapshot);
        $this->assertNull($report->template_key);
    }

    #[Test]
    public function the_creation_screen_preselects_a_template(): void
    {
        $this->actingAs($this->teacher)
            ->get('/reports/novo')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('reports/Create')
                ->where('preferredTemplate', fn ($ulid) => is_string($ulid) && $ulid !== ''));
    }
}
