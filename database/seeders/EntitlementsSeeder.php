<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * The commercial composition of Base / Pro / Institucional.
 *
 * Taken from the comparison table in Apresentacao_LAPIS.docx §8 and the menu
 * structure doc. This is a starting composition, not a contract: it is seeded
 * data precisely so modules can move between plans without a code change.
 *
 * Idempotent — safe to re-run as the catalogue grows.
 */
class EntitlementsSeeder extends Seeder
{
    /**
     * Every gateable capability. Keys are stable identifiers used by the
     * `module:` middleware; names are what a teacher would recognise.
     */
    protected const MODULES = [
        // Base — the assessment core.
        'assessment_profiles' => 'Perfis de Avaliação',
        'classes' => 'Turmas',
        'students' => 'Alunos',
        'instruments' => 'Instrumentos',
        'assessments' => 'Avaliações',
        'results' => 'Resultados',
        'self_assessments' => 'Autoavaliações',
        'records' => 'Registos',
        'interventions' => 'Intervenções',
        'student_progress' => 'Evolução do Aluno',
        'class_analysis' => 'Análise da Turma',
        'reports' => 'Relatórios',
        'imports' => 'Importações',
        'data_protection' => 'Proteção de Dados',

        // Pro.
        'calendar' => 'Agenda do Ano Letivo',
        'lessons' => 'Aulas e Sumários',
        // Relatórios stays in Base — a descriptive report is part of the core
        // offer. What separates the plans is whether the report may INTERPRET:
        // characterise behaviour and attitude, name difficulties, propose
        // measures for overcoming them. Descriptive sections never consult
        // this; analytical ones cannot be produced without it (§4 of the
        // Relatórios brief).
        'report_pedagogical_analysis' => 'Análise Pedagógica nos Relatórios',
        'ai_assistance' => 'Apoio de IA',
        'advanced_analytics' => 'Análises Avançadas',
        'template_sharing' => 'Partilha de Modelos',
        'self_assessment_links' => 'Ligações de Autoavaliação',
        // One capability for every source, deliberately. Plickers and Intuitivo
        // are file formats this reads, not products sold separately, and gating
        // them one by one would make the commercial offer depend on which
        // parsers happen to exist (§3).
        'correction_grid_import' => 'Importação de Grelhas de Correção',
        'inovar_export' => 'Exportação para o INOVAR',

        // Institucional.
        'institution_admin' => 'Administração Institucional',
        'institution_library' => 'Biblioteca Institucional',
        'institution_reports' => 'Relatórios Agregados',
        'institution_policies' => 'Políticas Institucionais',
        'audit_log' => 'Auditoria e Segurança',
    ];

    protected const BASE_MODULES = [
        'assessment_profiles', 'classes', 'students', 'instruments', 'assessments',
        'results', 'self_assessments', 'records', 'interventions', 'student_progress',
        'class_analysis', 'reports', 'imports', 'data_protection',
    ];

    protected const PRO_MODULES = [
        ...self::BASE_MODULES,
        'calendar', 'lessons', 'ai_assistance', 'advanced_analytics', 'template_sharing',
        'self_assessment_links', 'correction_grid_import', 'inovar_export',
        'report_pedagogical_analysis',
    ];

    protected const INSTITUTIONAL_MODULES = [
        ...self::PRO_MODULES,
        'institution_admin', 'institution_library', 'institution_reports',
        'institution_policies', 'audit_log',
    ];

    public function run(): void
    {
        foreach (self::MODULES as $key => $name) {
            Module::updateOrCreate(['key' => $key], ['name' => $name]);
        }

        $plans = [
            ['key' => 'base', 'name' => 'LÁPIS Base', 'sort_order' => 1, 'modules' => self::BASE_MODULES],
            ['key' => 'pro', 'name' => 'LÁPIS Pro', 'sort_order' => 2, 'modules' => self::PRO_MODULES],
            ['key' => 'institutional', 'name' => 'LÁPIS Institucional', 'sort_order' => 3, 'modules' => self::INSTITUTIONAL_MODULES],
        ];

        foreach ($plans as $definition) {
            $plan = Plan::updateOrCreate(
                ['key' => $definition['key']],
                ['name' => $definition['name'], 'sort_order' => $definition['sort_order']],
            );

            $plan->modules()->sync(
                Module::whereIn('key', $definition['modules'])->pluck('id'),
            );
        }
    }
}
