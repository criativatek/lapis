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
| Order and grouping come from "Estrutura de menus e submenus da aplicação.docx"
| (the canonical navigation — the .png mockups are exploratory and disagree).
|
| icon: a @lucide/vue component name, resolved in resources/js/lib/navIcons.ts.
| module: the entitlement key gating it (null = always available). Keys match
| database/seeders/EntitlementsSeeder.php.
| phase: which build phase delivers the real page. Until then the route renders
| a placeholder; this is shown to the teacher as an "em breve" hint.
| description: one line saying what the page is FOR, shown in the sidebar's
| tooltip. Optional — the core items are self-explanatory and carry none.
|
| THE `key` IS AN IDENTIFIER AND THE `label` IS COPY. Renaming a label changes
| what a teacher reads; renaming a key would change a route, because
| routes/app.php registers one placeholder route per key. They move
| independently on purpose: «Evolução do Aluno» became «Acompanhamento do Aluno»
| without a single route, controller, service or capability changing name.
|
| THE GROUPING ANSWERS «PARA QUE SERVE ISTO?» BEFORE THE TEACHER CLICKS. The
| core assessment workflow — turmas, perfis, instrumentos, avaliações,
| resultados — stays as one unlabelled run, because it is one sequence a teacher
| walks in order. What follows are three different KINDS of work, and they read
| as three because they are: understanding, acting, and producing a document.
|
*/

return [

    'sections' => [

        [
            'label' => null, // The teacher's core work carries no section heading.
            'items' => [
                ['key' => 'dashboard', 'label' => 'Painel do Professor', 'icon' => 'LayoutGrid', 'module' => null, 'phase' => 0],
                ['key' => 'classes', 'label' => 'As Minhas Turmas', 'icon' => 'Users', 'module' => 'classes', 'phase' => 1, 'route' => 'classes.index', 'built' => true],
                ['key' => 'students', 'label' => 'Alunos', 'icon' => 'GraduationCap', 'module' => 'students', 'phase' => 1],
                ['key' => 'assessment-profiles', 'label' => 'Perfis de Avaliação', 'icon' => 'SlidersHorizontal', 'module' => 'assessment_profiles', 'phase' => 1, 'route' => 'assessment-profiles.index', 'built' => true],
                ['key' => 'instruments', 'label' => 'Instrumentos', 'icon' => 'ClipboardList', 'module' => 'instruments', 'phase' => 2, 'route' => 'instruments.index', 'built' => true],
                ['key' => 'assessments', 'label' => 'Avaliações', 'icon' => 'PenLine', 'module' => 'assessments', 'phase' => 2, 'route' => 'assessments.index', 'built' => true],
                ['key' => 'results', 'label' => 'Resultados', 'icon' => 'BarChart3', 'module' => 'results', 'phase' => 2, 'route' => 'results.index', 'built' => true],
                ['key' => 'self-assessments', 'label' => 'Autoavaliações', 'icon' => 'UserCheck', 'module' => 'self_assessments', 'phase' => 3, 'route' => 'self-assessments.index', 'built' => true],
            ],
        ],

        [
            // COMPREENDER. The collective reading and the individual one, side
            // by side — «como está a turma» and «como está este aluno» are the
            // same question asked of two subjects, and putting six menus between
            // them hid that.
            'label' => 'Análise',
            'items' => [
                ['key' => 'class-analysis', 'label' => 'Análise da Turma', 'icon' => 'PieChart', 'module' => 'class_analysis', 'phase' => 3, 'description' => 'Resultados e evolução da turma.'],
                // «Acompanhamento», not «Evolução»: what this page does is
                // follow one student through the year. «Evolução» stays where it
                // belongs — on the chart inside it, which is a reading of
                // results over time and not the page's purpose (§2, §11).
                ['key' => 'student-progress', 'label' => 'Acompanhamento do Aluno', 'icon' => 'Footprints', 'module' => 'student_progress', 'phase' => 3, 'route' => 'student-progress.index', 'built' => true, 'description' => 'Percurso individual ao longo do ano.'],
            ],
        ],

        [
            // AGIR. What the teacher did, and what the teacher saw. Two
            // different acts, which is why they are two entries and not one.
            'label' => 'Ação pedagógica',
            'items' => [
                ['key' => 'interventions', 'label' => 'Intervenções', 'icon' => 'HeartHandshake', 'module' => 'interventions', 'phase' => 3, 'route' => 'interventions.index', 'built' => true, 'description' => 'Ações pedagógicas e acompanhamento.'],
                ['key' => 'records', 'label' => 'Registos', 'icon' => 'NotebookPen', 'module' => 'records', 'phase' => 3, 'route' => 'records.index', 'built' => true, 'description' => 'Observações e ocorrências.'],
            ],
        ],

        [
            // RELATAR. A report is an artifact with a life of its own —
            // editable, finalizable, exportable — and the only thing here that
            // leaves the application. It sits alone because it is a different
            // kind of thing from everything above it.
            'label' => 'Documentos',
            'items' => [
                ['key' => 'reports', 'label' => 'Relatórios', 'icon' => 'FileText', 'module' => 'reports', 'phase' => 3, 'route' => 'reports.index', 'built' => true, 'description' => 'Criar, finalizar e exportar documentos.'],
            ],
        ],

        [
            'label' => 'Organização do ano',
            'items' => [
                ['key' => 'calendar', 'label' => 'Agenda do Ano Letivo', 'icon' => 'CalendarDays', 'module' => 'calendar', 'phase' => 5],
                ['key' => 'lessons', 'label' => 'Aulas e Sumários', 'icon' => 'BookOpen', 'module' => 'lessons', 'phase' => 5],
            ],
        ],

        [
            'label' => 'Instituição',
            'items' => [
                ['key' => 'institution', 'label' => 'Administração Institucional', 'icon' => 'Building2', 'module' => 'institution_admin', 'phase' => 7],
            ],
        ],

    ],

    // Sits at the foot of the sidebar, below the sections. Always available.
    'footer' => [
        ['key' => 'settings', 'label' => 'Configurações', 'icon' => 'Settings', 'module' => null, 'route' => 'profile.edit', 'phase' => 0],
    ],

];
