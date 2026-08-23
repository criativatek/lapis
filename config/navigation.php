<?php

/*
|--------------------------------------------------------------------------
| Teacher navigation
|--------------------------------------------------------------------------
|
| Single source of truth for the professor's side menu — order, labels and the
| module each item needs. routes/app.php registers a route per item from this
| same list, and NavigationBuilder filters it by the organization's entitlements.
| One list drives both, so a menu item and its route can never disagree.
|
| THE ORDER IS THE TEACHER'S WORK, NOT THE BUILD ORDER:
|
|     organizar → avaliar → acompanhar → intervir → documentar
|
| Everything else — the year's calendar, the configuration — is transversal and
| sits below, because it is visited occasionally and not in sequence.
|
| «RESULTADOS» IS NOT AN ENTRY ANY MORE, and no code was deleted for it. The
| routes, the controller, the services and the page all remain: what changed is
| that a teacher no longer navigates to «resultados» as a place. They ask «como
| está esta turma?» and land on the reading; the operational grid where a
| classification is decided is reached from there and from the dashboard, and is
| still the same screen it always was.
|
| icon: a @lucide/vue component name, resolved in resources/js/lib/navIcons.ts.
| module: the entitlement key gating it (null = always available). Keys match
| database/seeders/EntitlementsSeeder.php.
| phase: which build phase delivers the real page. Until then the route renders
| a placeholder; this is shown to the teacher as an "em breve" hint.
| description: one line saying what the page is FOR, shown in the sidebar's
| tooltip and in the accessible name.
| match: extra path fragments that should light this item up. Needed where one
| entry answers for several historical routes — the active state is an exact
| path match otherwise.
|
| THE `key` IS AN IDENTIFIER AND THE `label` IS COPY. Renaming a label changes
| what a teacher reads; renaming a key would change a route, because
| routes/app.php registers one placeholder route per key. They move
| independently on purpose.
|
*/

return [

    'sections' => [

        [
            'label' => null, // Where the teacher lands. It heads no category.
            'items' => [
                ['key' => 'dashboard', 'label' => 'Painel do Professor', 'icon' => 'LayoutGrid', 'module' => null, 'phase' => 0],
            ],
        ],

        [
            // ORGANIZAR — who the year is about.
            'label' => 'Turmas e alunos',
            'items' => [
                ['key' => 'classes', 'label' => 'Turmas', 'icon' => 'Users', 'module' => 'classes', 'phase' => 1, 'route' => 'classes.index', 'built' => true, 'description' => 'Gerir e aceder às suas turmas.'],
                ['key' => 'students', 'label' => 'Alunos', 'icon' => 'GraduationCap', 'module' => 'students', 'phase' => 1, 'description' => 'Consultar os alunos.'],
            ],
        ],

        [
            // AVALIAR — recolher, registar e decidir. Every act that WRITES
            // assessment information belongs to this group, including the
            // classification decision, which is reached from the class's own
            // screens (§30).
            'label' => 'Avaliação',
            'items' => [
                // «Elementos de Avaliação» is what the area is FOR — a test, a
                // worksheet, a presentation, a piece of writing. The key, the
                // route, the module and the whole Instrument* codebase are
                // untouched: a label is not a rename (§17).
                ['key' => 'instruments', 'label' => 'Elementos de Avaliação', 'icon' => 'ClipboardList', 'module' => 'instruments', 'phase' => 2, 'route' => 'instruments.index', 'built' => true, 'description' => 'Criar e gerir elementos usados na avaliação.'],
                // «Registo de Avaliações», because that is the act. The route,
                // the controller and the capability are all still `assessments`
                // — a label is not a rename (§17).
                ['key' => 'assessments', 'label' => 'Registo de Avaliações', 'icon' => 'PenLine', 'module' => 'assessments', 'phase' => 2, 'route' => 'assessments.index', 'built' => true, 'description' => 'Registar avaliações dos alunos.', 'match' => ['/classifications']],
                ['key' => 'self-assessments', 'label' => 'Autoavaliações', 'icon' => 'UserCheck', 'module' => 'self_assessments', 'phase' => 3, 'route' => 'self-assessments.index', 'built' => true, 'description' => 'Gerir as autoavaliações.'],
            ],
        ],

        [
            // ACOMPANHAR — consultar e interpretar. The heading is a heading and
            // never a link: there is no «Acompanhamento» page, because the
            // question is always about a class or about a student and never
            // about the category (§15).
            'label' => 'Acompanhamento',
            'items' => [
                // «Turma», and the group is half the meaning: «Turmas» above
                // manages them, this one reads one of them (§54). The key stays
                // `class-analysis` because that is what this concept has always
                // been called in this file.
                //
                // Gated by `results`, which is what its destination actually
                // enforces. Both keys are Base and always travel together, so no
                // plan sees a different menu than it saw before (§23).
                ['key' => 'class-analysis', 'label' => 'Turma', 'icon' => 'PieChart', 'module' => 'results', 'phase' => 3, 'route' => 'results.index', 'built' => true, 'description' => 'Desempenho e evolução da turma.', 'match' => ['/results']],
                ['key' => 'student-progress', 'label' => 'Aluno', 'icon' => 'Footprints', 'module' => 'student_progress', 'phase' => 3, 'route' => 'student-progress.index', 'built' => true, 'description' => 'Percurso individual ao longo do ano.', 'match' => ['/evolucao']],
            ],
        ],

        [
            // INTERVIR — what the teacher did, and what the teacher saw.
            'label' => 'Ação pedagógica',
            'items' => [
                // «Estratégias e Medidas», because the area is wider than the
                // formal measures: differentiation, study support, help reading
                // a prompt, self-regulation. Same key, same route, same module.
                ['key' => 'interventions', 'label' => 'Estratégias e Medidas', 'icon' => 'HeartHandshake', 'module' => 'interventions', 'phase' => 3, 'route' => 'interventions.index', 'built' => true, 'description' => 'Registar estratégias, medidas e ações pedagógicas.'],
                ['key' => 'records', 'label' => 'Registos', 'icon' => 'NotebookPen', 'module' => 'records', 'phase' => 3, 'route' => 'records.index', 'built' => true, 'description' => 'Observações e ocorrências.'],
            ],
        ],

        [
            // DOCUMENTAR — the only thing here that leaves the application.
            'label' => 'Documentos',
            'items' => [
                ['key' => 'reports', 'label' => 'Relatórios', 'icon' => 'FileText', 'module' => 'reports', 'phase' => 3, 'route' => 'reports.index', 'built' => true, 'description' => 'Criar, finalizar e exportar documentos.'],
            ],
        ],

        [
            'label' => 'Organização do ano',
            'items' => [
                ['key' => 'calendar', 'label' => 'Agenda do Ano Letivo', 'icon' => 'CalendarDays', 'module' => 'calendar', 'phase' => 5, 'description' => 'Organizar o ano letivo.'],
                // KEPT DELIBERATELY. The approved structure did not enumerate
                // it, and removing it would take a Pro entry — and its
                // placeholder route — away from organizations that have the
                // capability today. Changing what a plan sees is the one thing
                // this reorganization must not do (§23, §41).
                ['key' => 'lessons', 'label' => 'Aulas e Sumários', 'icon' => 'BookOpen', 'module' => 'lessons', 'phase' => 5, 'description' => 'Registar aulas e sumários.', 'route' => 'lessons.index', 'built' => true],
            ],
        ],

        [
            'label' => 'Instituição',
            'items' => [
                // Fatia 3. `owner_only` is read by NavigationBuilder alone — a
                // member of the school is entitled to the module (the plan
                // grants it to the ORGANIZATION) but never sees this link,
                // because TeamController is the responsável's alone.
                ['key' => 'team', 'label' => 'Equipa', 'icon' => 'Users', 'module' => 'institution_admin', 'owner_only' => true, 'phase' => 3, 'route' => 'team.index', 'built' => true, 'description' => 'Convidar e gerir os membros da organização.'],
                // Fatia 4. Same owner_only/module gating as Equipa — resolving
                // an orphaned class is a governance action, not something a
                // regular member needs (or is authorized) to see.
                ['key' => 'class-reassignment', 'label' => 'Turmas a Reatribuir', 'icon' => 'Shuffle', 'module' => 'institution_admin', 'owner_only' => true, 'phase' => 4, 'route' => 'classes.reassignment.index', 'built' => true, 'description' => 'Atribuir um novo professor a turmas que ficaram sem nenhum.'],
                ['key' => 'institution', 'label' => 'Administração Institucional', 'icon' => 'Building2', 'module' => 'institution_admin', 'owner_only' => true, 'phase' => 7, 'route' => 'institution.index', 'built' => true, 'description' => 'Gerir a instituição.'],
            ],
        ],

        [
            // TRANSVERSAL — set up once, revisited rarely. The assessment
            // profile decides how everything above is calculated, which is
            // exactly why it belongs to configuration and not to the daily run.
            //
            // «Estrutura do Ano Letivo» first (Fatia 5, §43-§53): academic
            // years and subjects are STRUCTURE — defined here — never
            // something the header's context selectors create. The header
            // (ContextBar.vue) still SELECTS an academic year or subject; it
            // has not built one since Fase 1, and this entry is simply the
            // discoverable path to the pages that already did the building
            // (academic-years.index, subjects.index — unchanged routes,
            // unchanged controllers, unchanged policies).
            'label' => 'Configuração',
            'items' => [
                ['key' => 'academic-structure', 'label' => 'Estrutura do Ano Letivo', 'icon' => 'CalendarRange', 'module' => null, 'phase' => 1, 'route' => 'academic-years.index', 'built' => true, 'description' => 'Definir anos letivos, disciplinas e períodos.', 'match' => ['/subjects']],
                ['key' => 'assessment-profiles', 'label' => 'Perfis de Avaliação', 'icon' => 'SlidersHorizontal', 'module' => 'assessment_profiles', 'phase' => 1, 'route' => 'assessment-profiles.index', 'built' => true, 'description' => 'Definir critérios, domínios, pesos e escalas.'],
                ['key' => 'configuration-sharing', 'label' => 'Partilhar configuração', 'icon' => 'Share2', 'module' => 'template_sharing', 'phase' => 1, 'route' => 'configuration-sharing.export', 'built' => true, 'description' => 'Partilhar apenas estrutura e configurações.'],
                ['key' => 'configuration-import', 'label' => 'Importar configuração', 'icon' => 'Download', 'module' => 'template_sharing', 'phase' => 1, 'route' => 'configuration-sharing.import', 'built' => true, 'description' => 'Pré-visualizar e importar configurações partilhadas.'],
                ['key' => 'settings', 'label' => 'Configurações', 'icon' => 'Settings', 'module' => null, 'route' => 'profile.edit', 'phase' => 0, 'description' => 'Configurar a aplicação e a escola.', 'match' => ['/settings']],
            ],
        ],

    ],

    // Empty on purpose: «Configurações» moved up into its own group, beside the
    // assessment profile it sits next to conceptually. The sidebar's footer
    // still carries the user menu, and renders no navigation block when there is
    // nothing here.
    'footer' => [],

];
