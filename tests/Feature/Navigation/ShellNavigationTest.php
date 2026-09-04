<?php

namespace Tests\Feature\Navigation;

use App\Http\Controllers\PlaceholderController;
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
        // Both columns: a bulk `update()` on the query builder skips the
        // model's reconciliation, and the composite foreign key then
        // (correctly) refuses a plan and a version naming different plans.
        $version = Plan::where('key', $planKey)->firstOrFail()->currentVersionOrFail();

        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $user->personalOrganization()->getKey())
            ->update(['plan_id' => $version->plan_id, 'plan_version_id' => $version->getKey(), 'status' => SubscriptionStatus::Active, 'starts_at' => Carbon::now()->subDay()]);
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
                'Organização do Ano Letivo',
                'Instituição',
                'Configuração',
                'Ajuda',
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
                'assessments' => ['Avaliação', 'Grelhas de correção'],
                'self-assessments' => ['Avaliação', 'Autoavaliações'],
                'class-analysis' => ['Acompanhamento', 'Turma'],
                'student-progress' => ['Acompanhamento', 'Aluno'],
                'interventions' => ['Ação pedagógica', 'Estratégias e Medidas'],
                'records' => ['Ação pedagógica', 'Registos'],
                'reports' => ['Documentos', 'Relatórios'],
                // Fase 5.1. «Organização do Ano Letivo» is now the year as a
                // whole: the calendar of it, its structure — which moved here
                // out of «Configuração» — and the teacher's own week inside
                // it. «Calendário», not «Agenda»: same key, same module, only
                // the copy changed (§17).
                'calendar' => ['Organização do Ano Letivo', 'Calendário do Ano Letivo'],
                'academic-structure' => ['Organização do Ano Letivo', 'Estrutura do Ano Letivo'],
                'teacher-timetable' => ['Organização do Ano Letivo', 'Horário do Professor'],
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
                'Avaliações',         // → Grelhas de correção
                'Registo de Avaliações', // → Grelhas de correção
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

    // ------------------------------------------------- Centro de Ajuda (§Onboarding & Help)

    /**
     * The sidebar's own entry point into the Centro de Ajuda: its own
     * section, last — after «Configuração» and before the sidebar's footer
     * (the account/user area, which `nav.sections` does not carry at all) —
     * pointing at the real named route, never a hardcoded URL, and available
     * to every plan (`module => null`).
     *
     * A CENTRAL DE SUPORTE ENTROU NESTA MESMA SECÇÃO, logo a seguir (ADR-0011):
     * quem tem uma dúvida procura primeiro o artigo e só depois fala connosco,
     * e as duas coisas pertencem ao mesmo sítio da barra lateral. Ambas com
     * `module => null` — nenhuma é capability.
     */
    #[Test]
    public function the_help_center_has_its_own_section_last_in_the_menu(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertInertia(function (AssertableInertia $page) {
            $sections = $page->toArray()['props']['nav']['sections'];
            $last = end($sections);

            $this->assertSame('Ajuda', $last['label']);
            $this->assertSame(['help', 'support'], array_column($last['items'], 'key'));

            $help = $last['items'][0];
            $this->assertSame('Centro de Ajuda', $help['label']);
            $this->assertStringEndsWith('/help', (string) $help['href']);
            $this->assertSame(route('help.index'), $help['href']);
        });
    }

    /**
     * Searching or reading an article are still «being in the Centro de
     * Ajuda» as far as the sidebar is concerned — the same one-entry-many-
     * routes pattern §36 already established for «Turma»/«Aluno».
     */
    #[Test]
    public function the_help_center_entry_answers_for_search_and_any_article(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertInertia(function (AssertableInertia $page) {
            $help = collect($this->navItems($page))->firstWhere('key', 'help');

            $this->assertContains('/help/', $help['match']);
        });
    }

    // ------------------------------------------------------ §41 os planos

    #[Test]
    public function reorganizing_changed_nothing_about_what_base_may_reach(): void
    {
        $menu = $this->menuFor('base');

        // Exactly the Base keys, and no more. The list is spelled out so a
        // capability quietly appearing or vanishing fails here.
        //
        // «academic-structure» is in the same place in this list as it always
        // was, and deliberately so: Fase 5.1 moved it from «Configuração» to
        // «Organização do Ano Letivo», a group that sits immediately after
        // «Documentos» and before «Configuração», so a Base teacher reaches
        // the very same entries in the very same order — under a different
        // heading (§23, §41).
        // «calendar» JOINED THIS LIST IN THE BASE/PRO REALIGNMENT, in the
        // position config/navigation.php already gave it — first inside
        // «Organização do Ano Letivo», before «Estrutura do Ano Letivo».
        // Matriz Mestre §2 ticks «Calendário mensal/anual» for all three
        // plans; the entry is not new, only reachable.
        $this->assertSame([
            'dashboard', 'classes', 'students', 'instruments', 'assessments',
            // «Pautas de Avaliação» reutiliza o módulo `results`, já presente em
            // BASE_MODULES desde `classifications.*`/`avaliacoes-intercalares`
            // — nenhuma entitlement nova, apenas mais uma porta para a mesma.
            'self-assessments', 'evaluation-sheets', 'class-analysis', 'student-progress',
            'interventions', 'records', 'reports', 'calendar', 'academic-structure', 'assessment-profiles', 'settings',
            // Centro de Ajuda (§Onboarding & Help): module => null, so every
            // plan reaches it — never gated by Base/Pro/Institucional.
            'help',
            // Central de Suporte (ADR-0011): `module => null` pela mesma razão,
            // e a razão é mais forte aqui — um professor no Base que não
            // consegue entrar na conta é quem mais precisa de falar connosco.
            // Suporte humano não é capability nem entitlement e não entra em
            // nenhuma PlanVersion: a composição continua 13 / 28 / 34.
            'support',
        ], array_keys($menu));
    }

    #[Test]
    public function pro_still_gains_the_year_organisation_and_nothing_else(): void
    {
        $gained = array_diff(array_keys($this->menuFor('pro')), array_keys($this->menuFor('base')));

        // Fatia 1 (config-sharing) added "Partilhar configuração" and "Importar
        // configuração", gated by the pre-existing template_sharing module —
        // present in PRO_MODULES, absent from BASE_MODULES.
        //
        // Fase 5.1 adds "Horário do Professor", gated by the SAME `lessons`
        // module that already gates "Aulas e Sumários", classes.schedule-setup
        // and timetable-imports.*: a Pro organization gains a new destination
        // onto a capability it already had, and no new entitlement was
        // invented for it — which is why Base's own list above is unchanged.
        // «calendar» IS NO LONGER IN THIS LIST, and that is the assertion:
        // after the Base/Pro realignment the calendar is something Base
        // already has, so Pro cannot gain it. What Pro still gains is the
        // lessons workspace, the timetable built on the same key, and
        // configuration sharing.
        $this->assertSame(
            ['teacher-timetable', 'lessons', 'configuration-sharing', 'configuration-import'],
            array_values($gained),
        );
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
            // Base has no institutional administration, so the group that would
            // hold it is simply not sent.
            $labels = $this->sectionLabels($page);

            $this->assertNotContains('Instituição', $labels);

            // «Organização do Ano Letivo» USED to vanish for Base too, when the
            // only things in it were the Pro calendar and Pro lessons. Since
            // Fase 5.1 it also holds «Estrutura do Ano Letivo», whose module is
            // null — always available — so the group now stands for every plan,
            // carrying exactly the entries that plan is entitled to. It is
            // still not standing empty: that is the rule, and this is it
            // holding.
            $this->assertContains('Organização do Ano Letivo', $labels);

            $section = collect($page->toArray()['props']['nav']['sections'])
                ->firstWhere('label', 'Organização do Ano Letivo');

            // Two entries on Base since the realignment, and «Horário do
            // Professor» still not among them: the calendar is Base, the
            // timetable is Pro, and this is the group where the difference
            // between the two is visible.
            $this->assertSame(
                ['Calendário do Ano Letivo', 'Estrutura do Ano Letivo'],
                array_column($section['items'], 'label'),
            );
        });
    }

    // ------------------------------------------------- Fase 5.1 o ano letivo

    /**
     * The group reads top to bottom as the product decided, and not in the
     * order the three were built: the calendar of the year, then its
     * structure, then the teacher's own week inside it. «Aulas e Sumários»
     * stays where it always was, below them.
     */
    #[Test]
    public function the_year_group_is_ordered_as_the_product_decided(): void
    {
        $user = User::factory()->create();
        $this->upgrade($user, 'pro');

        $this->actingAs($user)->get('/dashboard')->assertInertia(function (AssertableInertia $page) {
            $section = collect($page->toArray()['props']['nav']['sections'])
                ->firstWhere('label', 'Organização do Ano Letivo');

            $this->assertNotNull($section);
            $this->assertSame([
                'Calendário do Ano Letivo',
                'Estrutura do Ano Letivo',
                'Horário do Professor',
                'Aulas e Sumários',
            ], array_column($section['items'], 'label'));
        });
    }

    /**
     * FASE 5.2 — «Calendário do Ano Letivo» stopped being a placeholder. The
     * entry that carried no route until now points at a real page, at the very
     * address the placeholder answered at, so a bookmark made before it existed
     * still lands on it. Everything else about the entry — key, module, label,
     * description, position — is untouched: what changed is that there is now
     * something behind it (§17, §23).
     */
    #[Test]
    public function the_calendar_is_now_a_real_destination_and_no_longer_a_placeholder(): void
    {
        $user = User::factory()->create();
        $this->upgrade($user, 'pro');

        $configured = collect(config('navigation.sections'))
            ->flatMap(fn (array $section): array => $section['items'])
            ->firstWhere('key', 'calendar');

        $this->assertSame('Calendário do Ano Letivo', $configured['label']);
        $this->assertSame('calendar', $configured['module']);
        $this->assertSame('Organizar o ano letivo.', $configured['description']);
        $this->assertSame('calendar.index', $configured['route']);
        $this->assertTrue($configured['built']);

        $this->actingAs($user)->get('/dashboard')->assertInertia(function (AssertableInertia $page) {
            $calendar = collect($this->navItems($page))->firstWhere('key', 'calendar');

            $this->assertSame('Calendário do Ano Letivo', $calendar['label']);
            $this->assertTrue($calendar['built']);
            $this->assertStringEndsWith('/calendar', (string) $calendar['href']);
            // The Ano view lights the same entry: one menu item over the two
            // views of one calendar, never two entries (§36).
            $this->assertContains('/calendar/ano', $calendar['match']);
        });

        // The placeholder route routes/app.php used to generate for this key is
        // gone with the `built` flag, and the real page answers in its place.
        $this->actingAs($user)->get('/calendar')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page->component('calendar/Month')
        );
        $this->actingAs($user)->get('/calendar/ano')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page->component('calendar/Year')
        );
    }

    /**
     * «Alunos» stopped being a placeholder, and this is the same assertion the
     * calendar's above makes, for the same reason: the entry that carried no
     * route until now points at a real page, AT THE VERY ADDRESS THE PLACEHOLDER
     * ANSWERED AT, so a bookmark made before the page existed still lands on it.
     * Key, module, label, description and position are untouched — what changed
     * is that there is something behind it (§17, §23).
     *
     * The route NAME moved from `students` to `students.index`, which is what
     * every other real destination uses. Nothing referenced the old one; the URL
     * is what a teacher has, and the URL did not move.
     */
    #[Test]
    public function alunos_is_now_a_real_destination_and_no_longer_a_placeholder(): void
    {
        $user = User::factory()->create();

        $configured = collect(config('navigation.sections'))
            ->flatMap(fn (array $section): array => $section['items'])
            ->firstWhere('key', 'students');

        $this->assertSame('Alunos', $configured['label']);
        $this->assertSame('students', $configured['module']);
        $this->assertSame('Consultar os alunos.', $configured['description']);
        $this->assertSame('students.index', $configured['route']);
        $this->assertTrue($configured['built']);

        // Base, deliberately: `students` was a Base module before this page
        // existed and this slice did not move it.
        $this->actingAs($user)->get('/dashboard')->assertInertia(function (AssertableInertia $page) {
            $students = collect($this->navItems($page))->firstWhere('key', 'students');

            $this->assertSame('Alunos', $students['label']);
            $this->assertSame('Turmas e alunos', $students['section']);
            $this->assertTrue($students['built']);
            $this->assertStringEndsWith('/students', (string) $students['href']);
        });

        $this->assertSame('/students', route('students.index', absolute: false));

        $this->actingAs($user)->get('/students')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page->component('students/Index')
        );
    }

    /**
     * The neighbour «Alunos» deliberately did not touch. Turmas keeps its own
     * route and its own `built` flag, and it is still where a roll is created,
     * edited and imported — the directory points at it and recreates none of it.
     */
    #[Test]
    public function turmas_is_untouched_by_the_students_directory(): void
    {
        $user = User::factory()->create();

        $items = collect(config('navigation.sections'))
            ->flatMap(fn (array $section): array => $section['items'])
            ->keyBy('key');

        $this->assertSame('classes.index', $items['classes']['route']);
        $this->assertTrue($items['classes']['built']);
        $this->assertSame('classes', $items['classes']['module']);

        $this->actingAs($user)->get('/classes')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page->component('classes/Index')
        );
    }

    /**
     * The two neighbours this phase deliberately did not touch: «Estrutura do
     * Ano Letivo» and «Horário do Professor» (Fase 5.1) keep their own routes,
     * their own `built` flag and their place in the same group.
     */
    #[Test]
    public function the_other_entries_of_the_year_group_are_untouched_by_the_calendar(): void
    {
        $user = User::factory()->create();
        $this->upgrade($user, 'pro');

        $items = collect(config('navigation.sections'))
            ->flatMap(fn (array $section): array => $section['items'])
            ->keyBy('key');

        $this->assertSame('academic-years.index', $items['academic-structure']['route']);
        $this->assertTrue($items['academic-structure']['built']);
        $this->assertNull($items['academic-structure']['module']);

        $this->assertSame('timetable.index', $items['teacher-timetable']['route']);
        $this->assertTrue($items['teacher-timetable']['built']);
        $this->assertSame('lessons', $items['teacher-timetable']['module']);

        $this->actingAs($user)->get('/academic-years')->assertOk();
        $this->actingAs($user)->get('/timetable')->assertOk();
    }

    /**
     * Moved, not rebuilt: «Estrutura do Ano Letivo» still points at the same
     * academic-years.index it always did, and still answers there.
     */
    #[Test]
    public function the_moved_academic_structure_still_points_at_its_unchanged_route(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertInertia(function (AssertableInertia $page) {
            $items = collect($this->navItems($page))->keyBy('key');

            $this->assertStringEndsWith('/academic-years', (string) $items['academic-structure']['href']);
            $this->assertContains('/subjects', $items['academic-structure']['match']);
        });

        $this->actingAs($user)->get('/academic-years')->assertOk();
    }

    /**
     * «Horário do Professor» is a real destination, not a placeholder, and it
     * is a DIFFERENT one from Turmas' own «Configurar horários» shortcut,
     * which this phase left exactly where it was.
     */
    #[Test]
    public function the_teacher_timetable_entry_opens_its_own_real_page(): void
    {
        $user = User::factory()->create();
        $this->upgrade($user, 'pro');

        $this->actingAs($user)->get('/dashboard')->assertInertia(function (AssertableInertia $page) {
            $timetable = collect($this->navItems($page))->firstWhere('key', 'teacher-timetable');

            $this->assertSame('Horário do Professor', $timetable['label']);
            $this->assertTrue($timetable['built']);
            $this->assertStringEndsWith('/timetable', (string) $timetable['href']);
        });

        $this->actingAs($user)->get('/timetable')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('timetable/Index'));

        $this->actingAs($user)->get('/classes/schedule-setup')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('classes/ScheduleSetup'));
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

    /**
     * «Alunos» WAS THE LAST PLACEHOLDER, and this test is what that one used to
     * be. It asserted that /students rendered the «em construção» page with its
     * phase; there is a directory behind that address now, so the assertion
     * moved up one level rather than being deleted: NO navigation entry renders
     * the placeholder any more, and the loop in routes/app.php generates no
     * route at all.
     *
     * The machinery itself stays exactly where it is — PlaceholderController,
     * the Placeholder page and the loop that would register a route for any
     * future entry are all untouched, and the second half of this test proves
     * they still work. What changed is that nothing needs them today.
     */
    #[Test]
    public function no_navigation_entry_renders_the_placeholder_any_more(): void
    {
        $user = User::factory()->create();
        $this->upgrade($user, 'institutional');

        $items = collect(config('navigation.sections'))
            ->flatMap(fn (array $section): array => $section['items'])
            ->concat(config('navigation.footer'));

        foreach ($items as $item) {
            $this->assertTrue(
                $item['phase'] === 0 || ! empty($item['built']),
                "«{$item['label']}» would still be registered as a placeholder route.",
            );
        }

        // /students is the entry that changed, asked directly: a real page, at
        // the address the placeholder answered at.
        $this->actingAs($user)->get('/students')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page->component('students/Index')
        );

        // And the placeholder is not gone, only unused: registered here the way
        // routes/app.php would register it, it still renders label and phase.
        Route::middleware(['web', 'auth', 'verified', 'organization'])
            ->get('_test/placeholder', PlaceholderController::class)
            ->defaults('navLabel', 'Módulo futuro')
            ->defaults('navPhase', 9);

        $this->actingAs($user)->get('/_test/placeholder')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Placeholder')
                ->where('title', 'Módulo futuro')
                ->where('phase', 9)
        );
    }

    #[Test]
    public function lessons_opens_its_operational_page_and_never_the_classes_index(): void
    {
        // The weekly operational page remains distinct from the classes index.
        $user = User::factory()->create();
        $this->upgrade($user, 'pro');

        $this->actingAs($user)->get('/lessons')
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('lessons/Index')
            );

        $this->actingAs($user)->get('/classes')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('classes/Index'));
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
