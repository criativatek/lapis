<?php

namespace Tests\Feature\Navigation;

use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Support\Entitlements\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The sidebar as the teacher reads it (§40, §41, §42).
 *
 * THE MENU IS A CLAIM ABOUT THE PRODUCT, and these tests hold it to it: that
 * «Resultados» is no longer somewhere a teacher goes, that «Acompanhamento» is a
 * heading and never a page, and — the part that matters most — that
 * reorganizing information changed nothing about what any plan may reach.
 */
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
        return array_column($this->navItems($page), 'key');
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function navItems(AssertableInertia $page): array
    {
        $items = [];

        foreach ($page->toArray()['props']['nav']['sections'] as $section) {
            foreach ($section['items'] as $item) {
                $items[] = [...$item, 'section' => $section['label']];
            }
        }

        return $items;
    }

    /**
     * @return list<string>
     */
    protected function sectionLabels(AssertableInertia $page): array
    {
        return array_values(array_filter(array_map(
            fn (array $section): ?string => $section['label'],
            $page->toArray()['props']['nav']['sections'],
        )));
    }

    /**
     * The whole menu of a plan, as {key: section} — the shape the plan
     * comparisons below are written against.
     *
     * @return array<string, string|null>
     */
    protected function menuFor(string $planKey): array
    {
        $user = User::factory()->create();

        if ($planKey !== 'base') {
            $this->upgrade($user, $planKey);
        }

        $menu = [];

        $this->actingAs($user)->get('/dashboard')->assertInertia(function (AssertableInertia $page) use (&$menu): void {
            foreach ($this->navItems($page) as $item) {
                $menu[$item['key']] = $item['section'];
            }
        });

        return $menu;
    }

    // ------------------------------------------------------- §40 a estrutura

    #[Test]
    public function the_teacher_still_lands_somewhere_and_it_heads_no_group(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertInertia(function (AssertableInertia $page) {
            $dashboard = collect($this->navItems($page))->firstWhere('key', 'dashboard');

            $this->assertSame('Painel do Professor', $dashboard['label']);
            $this->assertNull($dashboard['section']);
        });
    }

    #[Test]
    public function the_groups_follow_the_teachers_work_and_none_is_empty(): void
    {
        $user = User::factory()->create();
        $this->upgrade($user, 'institutional');

        $this->actingAs($user)->get('/dashboard')->assertInertia(function (AssertableInertia $page) {
            $labels = $this->sectionLabels($page);

            // Organizar → avaliar → acompanhar → intervir → documentar, and the
            // transversal ones after.
            $this->assertSame([
                'Turmas e alunos',
                'Avaliação',
                'Acompanhamento',
                'Ação pedagógica',
                'Documentos',
                'Organização do ano',
                'Instituição',
                'Configuração',
            ], $labels);

            // A heading with nothing under it is a heading about nothing (§23).
            foreach ($page->toArray()['props']['nav']['sections'] as $section) {
                $this->assertNotEmpty($section['items'], "«{$section['label']}» has no entries.");
            }
        });
    }

    #[Test]
    public function each_area_sits_where_the_teacher_would_look_for_it(): void
    {
        $user = User::factory()->create();
        $this->upgrade($user, 'institutional');

        $this->actingAs($user)->get('/dashboard')->assertInertia(function (AssertableInertia $page) {
            $items = collect($this->navItems($page))->keyBy('key');

            $expected = [
                'classes' => ['Turmas e alunos', 'Turmas'],
                'students' => ['Turmas e alunos', 'Alunos'],
                'instruments' => ['Avaliação', 'Elementos de Avaliação'],
                'assessments' => ['Avaliação', 'Registo de Avaliações'],
                'self-assessments' => ['Avaliação', 'Autoavaliações'],
                'class-analysis' => ['Acompanhamento', 'Turma'],
                'student-progress' => ['Acompanhamento', 'Aluno'],
                'interventions' => ['Ação pedagógica', 'Estratégias e Medidas'],
                'records' => ['Ação pedagógica', 'Registos'],
                'reports' => ['Documentos', 'Relatórios'],
                'calendar' => ['Organização do ano', 'Agenda do Ano Letivo'],
                'assessment-profiles' => ['Configuração', 'Perfis de Avaliação'],
                'settings' => ['Configuração', 'Configurações'],
            ];

            foreach ($expected as $key => [$section, $label]) {
                $this->assertSame($section, $items[$key]['section'], "«{$key}» is in the wrong group.");
                $this->assertSame($label, $items[$key]['label'], "«{$key}» reads wrong.");
            }
        });
    }

    #[Test]
    public function the_labels_that_named_the_code_are_gone(): void
    {
        $user = User::factory()->create();
        $this->upgrade($user, 'institutional');

        $this->actingAs($user)->get('/dashboard')->assertInertia(function (AssertableInertia $page) {
            $labels = collect($this->navItems($page))->pluck('label');

            foreach ([
                'As Minhas Turmas',   // → Turmas
                'Avaliações',         // → Registo de Avaliações
                'Resultados',         // no longer a place a teacher goes
                'Análise da Turma',   // → Acompanhamento > Turma
                'Evolução do Aluno',  // → Acompanhamento > Aluno
                'Desempenho',         // never an entry: it is a word inside pages
                'Acompanhamento',     // a heading, never a link
                'Instrumentos',       // → Elementos de Avaliação
                'Intervenções',       // → Estratégias e Medidas
            ] as $gone) {
                $this->assertNotContains($gone, $labels, "«{$gone}» should not be a menu entry.");
            }
        });
    }

    #[Test]
    public function acompanhamento_is_a_heading_and_holds_exactly_two_entries(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertInertia(function (AssertableInertia $page) {
            $section = collect($page->toArray()['props']['nav']['sections'])
                ->firstWhere('label', 'Acompanhamento');

            $this->assertNotNull($section);
            $this->assertSame(['Turma', 'Aluno'], array_column($section['items'], 'label'));

            // A heading is not a link: there is no href on the group, and no
            // entry called «Acompanhamento» to click (§15).
            $this->assertArrayNotHasKey('href', $section);
        });
    }

    #[Test]
    public function every_entry_appears_once_and_says_what_it_is_for(): void
    {
        $user = User::factory()->create();
        $this->upgrade($user, 'institutional');

        $this->actingAs($user)->get('/dashboard')->assertInertia(function (AssertableInertia $page) {
            $keys = $this->navKeys($page);
            $this->assertSame(array_values(array_unique($keys)), $keys);

            // «Turmas» and «Turma» differ by a group and a letter, so the
            // description is doing real work here (§54, §55).
            $items = collect($this->navItems($page))->keyBy('key');
            $this->assertSame('Gerir e aceder às suas turmas.', $items['classes']['description']);
            $this->assertSame('Desempenho e evolução da turma.', $items['class-analysis']['description']);
            $this->assertSame('Percurso individual ao longo do ano.', $items['student-progress']['description']);

            // The renamed areas: the description is the tooltip and the
            // accessible name, so it must speak the new language too — a link
            // reading «Estratégias e Medidas» announced as «Intervenções» is
            // the divergence this asserts against (§17).
            $this->assertSame('Criar e gerir elementos usados na avaliação.', $items['instruments']['description']);
            $this->assertSame('Registar estratégias, medidas e ações pedagógicas.', $items['interventions']['description']);

            foreach ($items as $key => $item) {
                if ($key !== 'dashboard') {
                    $this->assertNotNull($item['description'], "«{$key}» should say what it is for.");
                }
            }
        });
    }

    // -------------------------------------------------- §36 estado ativo

    #[Test]
    public function one_entry_answers_for_the_routes_that_page_absorbed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertInertia(function (AssertableInertia $page) {
            $items = collect($this->navItems($page))->keyBy('key');

            // «Turma» is one entry over three historical routes: the reading,
            // the grid and the synthesis. All of them contain «/results».
            $this->assertContains('/results', $items['class-analysis']['match']);
            $this->assertContains('/evolucao', $items['student-progress']['match']);
            // Deciding a classification is an act of AVALIAÇÃO (§30).
            $this->assertContains('/classifications', $items['assessments']['match']);
        });
    }

    // ------------------------------------------------------ §41 os planos

    #[Test]
    public function reorganizing_changed_nothing_about_what_base_may_reach(): void
    {
        $menu = $this->menuFor('base');

        // Exactly the Base keys, and no more. The list is spelled out so a
        // capability quietly appearing or vanishing fails here.
        $this->assertSame([
            'dashboard', 'classes', 'students', 'instruments', 'assessments',
            'self-assessments', 'class-analysis', 'student-progress',
            'interventions', 'records', 'reports', 'assessment-profiles', 'settings',
        ], array_keys($menu));
    }

    #[Test]
    public function pro_still_gains_the_year_organisation_and_nothing_else(): void
    {
        $gained = array_diff(array_keys($this->menuFor('pro')), array_keys($this->menuFor('base')));

        $this->assertSame(['calendar', 'lessons'], array_values($gained));
    }

    #[Test]
    public function institutional_still_gains_only_the_administration(): void
    {
        $gained = array_diff(array_keys($this->menuFor('institutional')), array_keys($this->menuFor('pro')));

        // Fatia 3 added "Equipa"; Fatia 4 adds "Turmas a Reatribuir" alongside
        // it — same module gate (institution_admin), plus owner_only, which a
        // personal organization's owner (this helper never creates an
        // institutional-type one) always trivially satisfies over their own
        // organization.
        $this->assertSame(['team', 'class-reassignment', 'institution'], array_values($gained));
    }

    #[Test]
    public function a_group_disappears_rather_than_standing_empty(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertInertia(function (AssertableInertia $page) {
            // Base has neither the calendar nor institutional administration, so
            // the two groups that would hold them are simply not sent.
            $labels = $this->sectionLabels($page);

            $this->assertNotContains('Organização do ano', $labels);
            $this->assertNotContains('Instituição', $labels);
        });
    }

    // -------------------------------------------------- §42 as rotas antigas

    #[Test]
    public function the_routes_behind_the_new_labels_did_not_move(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertInertia(function (AssertableInertia $page) {
            $items = collect($this->navItems($page))->keyBy('key');

            // A label is copy and a route is an address. None of these moved.
            $this->assertStringEndsWith('/classes', (string) $items['classes']['href']);
            $this->assertStringEndsWith('/assessments', (string) $items['assessments']['href']);
            $this->assertStringEndsWith('/results', (string) $items['class-analysis']['href']);
            $this->assertStringEndsWith('/evolucao', (string) $items['student-progress']['href']);
            $this->assertStringEndsWith('/assessment-profiles', (string) $items['assessment-profiles']['href']);

            // Renamed in the menu, unmoved on the wire: «Elementos de
            // Avaliação» is still /instruments and «Estratégias e Medidas» is
            // still /interventions. Every bookmark, CTA and deep link into
            // Student Progress keeps working (§13).
            $this->assertStringEndsWith('/instruments', (string) $items['instruments']['href']);
            $this->assertStringEndsWith('/interventions', (string) $items['interventions']['href']);
        });
    }

    #[Test]
    public function every_screen_that_resultados_used_to_reach_still_answers(): void
    {
        $user = User::factory()->create();

        // Nothing was deleted to make «Resultados» disappear from the menu (§6).
        // The picker still answers, and now opens the class reading.
        $this->actingAs($user)->get('/results')->assertOk();

        // And every screen it used to lead to is still registered. Asserted on
        // the route table rather than by rendering: what this test claims is
        // that no address was removed, and rendering a class would drag in a
        // profile, a scale and a period that have nothing to do with it.
        foreach ([
            'results.index',
            'results.show',
            'results.statistics',
            'results.summary',
            'classifications.show',
            'classifications.decide',
            'classifications.propose',
            'classifications.publish',
            'student-progress.index',
            'student-progress.student',
        ] as $name) {
            $this->assertTrue(Route::has($name), "The route «{$name}» disappeared.");
        }
    }

    #[Test]
    public function a_placeholder_route_renders_the_placeholder_page_with_its_phase(): void
    {
        $user = User::factory()->create();

        // Alunos is still a placeholder (Fase 1); everything around it is built.
        $this->actingAs($user)->get('/students')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Placeholder')
                ->where('title', 'Alunos')
                ->where('phase', 1)
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
