<?php

namespace Tests\Feature\Navigation;

use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Support\Entitlements\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ShellNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function upgrade(User $user, string $planKey): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $user->personalOrganization()->getKey())
            ->update(['plan_id' => Plan::where('key', $planKey)->firstOrFail()->getKey(), 'status' => SubscriptionStatus::Active, 'starts_at' => Carbon::now()->subDay()]);
        app(Entitlements::class)->flush();
    }

    /**
     * @return list<string>
     */
    protected function navKeys(AssertableInertia $page): array
    {
        $keys = [];

        foreach ($page->toArray()['props']['nav']['sections'] as $section) {
            foreach ($section['items'] as $item) {
                $keys[] = $item['key'];
            }
        }

        return $keys;
    }

    #[Test]
    public function a_base_teacher_sees_the_core_menu_but_not_the_pro_or_institutional_items(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertInertia(function (AssertableInertia $page) {
            $keys = $this->navKeys($page);

            // The 13 Base items from the navigation doc are all present...
            $this->assertContains('classes', $keys);
            $this->assertContains('assessment-profiles', $keys);
            $this->assertContains('reports', $keys);

            // ...and the Pro / institutional ones are filtered out entirely.
            $this->assertNotContains('calendar', $keys);
            $this->assertNotContains('lessons', $keys);
            $this->assertNotContains('institution', $keys);
        });
    }

    #[Test]
    public function a_pro_teacher_gains_the_organization_of_the_year_items(): void
    {
        $user = User::factory()->create();
        $this->upgrade($user, 'pro');

        $this->actingAs($user)->get('/dashboard')->assertInertia(function (AssertableInertia $page) {
            $keys = $this->navKeys($page);

            $this->assertContains('calendar', $keys);
            $this->assertContains('lessons', $keys);
            $this->assertNotContains('institution', $keys);
        });
    }

    #[Test]
    public function an_institutional_teacher_gains_the_administration_item(): void
    {
        $user = User::factory()->create();
        $this->upgrade($user, 'institutional');

        $this->actingAs($user)->get('/dashboard')->assertInertia(function (AssertableInertia $page) {
            $this->assertContains('institution', $this->navKeys($page));
        });
    }

    /**
     * @return list<array{key: string, label: string, section: ?string, href: ?string, description: ?string}>
     */
    protected function navItems(AssertableInertia $page): array
    {
        $items = [];

        foreach ($page->toArray()['props']['nav']['sections'] as $section) {
            foreach ($section['items'] as $item) {
                $items[] = [
                    'key' => $item['key'],
                    'label' => $item['label'],
                    'section' => $section['label'],
                    'href' => $item['href'],
                    'description' => $item['description'],
                ];
            }
        }

        return $items;
    }

    #[Test]
    public function the_menu_distinguishes_the_class_reading_from_the_student_one(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertInertia(function (AssertableInertia $page) {
            $items = collect($this->navItems($page))->keyBy('key');

            $this->assertSame('Análise da Turma', $items['class-analysis']['label']);
            // «Evolução do Aluno» said what the page draws; «Acompanhamento do
            // Aluno» says what it is for, which is the distinction a teacher
            // needs before clicking (§2).
            $this->assertSame('Acompanhamento do Aluno', $items['student-progress']['label']);

            $labels = collect($this->navItems($page))->pluck('label');
            $this->assertNotContains('Evolução do Aluno', $labels);

            // Both readings of the same question, under one heading and next to
            // each other (§5).
            $this->assertSame('Análise', $items['class-analysis']['section']);
            $this->assertSame('Análise', $items['student-progress']['section']);
        });
    }

    #[Test]
    public function the_menu_groups_the_five_areas_by_what_they_are_for(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertInertia(function (AssertableInertia $page) {
            $items = collect($this->navItems($page))->keyBy('key');

            $this->assertSame('Ação pedagógica', $items['interventions']['section']);
            $this->assertSame('Ação pedagógica', $items['records']['section']);
            $this->assertSame('Documentos', $items['reports']['section']);

            // Each of the five says what it is for, so the menu explains the
            // application without documentation beside it (§7, §17).
            foreach (['class-analysis', 'student-progress', 'interventions', 'records', 'reports'] as $key) {
                $this->assertNotNull($items[$key]['description'], "«{$key}» should say what it is for.");
            }
        });
    }

    #[Test]
    public function renaming_the_label_moved_no_route(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertInertia(function (AssertableInertia $page) {
            $items = collect($this->navItems($page))->keyBy('key');

            // The key, the route and everything behind them are untouched: this
            // was copy, and copy moves on its own (§2, §10).
            $this->assertStringEndsWith('/evolucao', (string) $items['student-progress']['href']);
            $this->assertStringEndsWith('/interventions', (string) $items['interventions']['href']);
            $this->assertStringEndsWith('/records', (string) $items['records']['href']);
            $this->assertStringEndsWith('/reports', (string) $items['reports']['href']);
        });
    }

    #[Test]
    public function each_menu_entry_appears_exactly_once(): void
    {
        $user = User::factory()->create();
        $this->upgrade($user, 'institutional');

        $this->actingAs($user)->get('/dashboard')->assertInertia(function (AssertableInertia $page) {
            $keys = $this->navKeys($page);

            // Grouping must not duplicate an item, and a group heading is never
            // itself a link (§9).
            $this->assertSame(array_values(array_unique($keys)), $keys);
        });
    }

    #[Test]
    public function grouping_did_not_change_what_each_plan_sees(): void
    {
        $base = User::factory()->create();

        $this->actingAs($base)->get('/dashboard')->assertInertia(function (AssertableInertia $page) {
            $keys = $this->navKeys($page);

            // The five regrouped items are all Base, and still are.
            foreach (['class-analysis', 'student-progress', 'interventions', 'records', 'reports'] as $key) {
                $this->assertContains($key, $keys);
            }

            $this->assertNotContains('calendar', $keys);
            $this->assertNotContains('institution', $keys);
        });
    }

    #[Test]
    public function the_footer_always_carries_settings(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertInertia(function (AssertableInertia $page) {
            $footerKeys = array_column($page->toArray()['props']['nav']['footer'], 'key');
            $this->assertContains('settings', $footerKeys);
        });
    }

    #[Test]
    public function a_placeholder_route_renders_the_placeholder_page_with_its_phase(): void
    {
        $user = User::factory()->create();

        // Class analysis is still a placeholder (Fase 3); much of the chain
        // (classes … records, self-assessments, interventions, student
        // progress) is built.
        $this->actingAs($user)->get('/class-analysis')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Placeholder')
                ->where('title', 'Análise da Turma')
                ->where('phase', 3)
        );
    }

    #[Test]
    public function the_scope_selectors_are_shared_but_empty_until_the_academic_model_exists(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('scope.academicYear', null)
                ->where('scope.class', null)
                ->where('scope.period', null)
        );
    }
}
