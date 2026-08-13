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
                ['key' => 'records', 'label' => 'Registos', 'icon' => 'NotebookPen', 'module' => 'records', 'phase' => 3, 'route' => 'records.index', 'built' => true],
                ['key' => 'interventions', 'label' => 'Intervenções', 'icon' => 'HeartHandshake', 'module' => 'interventions', 'phase' => 3, 'route' => 'interventions.index', 'built' => true],
                ['key' => 'student-progress', 'label' => 'Evolução do Aluno', 'icon' => 'TrendingUp', 'module' => 'student_progress', 'phase' => 3],
                ['key' => 'class-analysis', 'label' => 'Análise da Turma', 'icon' => 'PieChart', 'module' => 'class_analysis', 'phase' => 3],
                ['key' => 'reports', 'label' => 'Relatórios', 'icon' => 'FileText', 'module' => 'reports', 'phase' => 3, 'route' => 'reports.index', 'built' => true],
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
