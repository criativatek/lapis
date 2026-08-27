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
        'reports' => 'Relatórios',

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
        'inovar_export' => 'Grelhas preparadas para o INOVAR',

        // Institucional.
        'institution_admin' => 'Administração Institucional',
        'institution_library' => 'Biblioteca Institucional',
        'institution_reports' => 'Relatórios Agregados',
        'audit_log' => 'Registo de Auditoria',
    ];

    protected const BASE_MODULES = [
        'assessment_profiles', 'classes', 'students', 'instruments', 'assessments',
        'results', 'self_assessments', 'records', 'interventions', 'student_progress',
        'reports',
    ];

    protected const PRO_MODULES = [
        ...self::BASE_MODULES,
        'calendar', 'lessons', 'ai_assistance', 'advanced_analytics', 'template_sharing',
        'self_assessment_links', 'correction_grid_import', 'inovar_export',
        'report_pedagogical_analysis',
    ];

    protected const INSTITUTIONAL_MODULES = [
        ...self::PRO_MODULES,
        'institution_admin', 'institution_library', 'institution_reports', 'audit_log',
    ];

    /**
     * Quantitative caps (§Lote 3, distinct from the module catalogue above:
     * "may use" vs "how much"). `"unlimited"` is the literal string, never
     * `null` — see `App\Support\Limits\Limits::parse()`. Institucional's
     * `unlimited` here is a temporary default: the real institutional
     * contractual limit is out of scope for this Lote and is left for a
     * future one to define.
     */
    protected const BASE_LIMITS = ['active_classes' => 8, 'active_students' => 300];

    protected const UNLIMITED = ['active_classes' => 'unlimited', 'active_students' => 'unlimited'];

    /**
     * The full catalogue's keys, for callers outside this class that need to
     * verify every capability is real (CatalogCoherenceTest) without exposing
     * the MODULES const — and its human names — wholesale.
     *
     * @return list<string>
     */
    public static function moduleKeys(): array
    {
        return array_keys(self::MODULES);
    }

    public function run(): void
    {
        foreach (self::MODULES as $key => $name) {
            Module::updateOrCreate(['key' => $key], ['name' => $name]);
        }

        $plans = [
            ['key' => 'base', 'name' => 'Lapispro Base', 'sort_order' => 1, 'modules' => self::BASE_MODULES, 'limits' => self::BASE_LIMITS],
            ['key' => 'pro', 'name' => 'Lapispro Pro', 'sort_order' => 2, 'modules' => self::PRO_MODULES, 'limits' => self::UNLIMITED],
            ['key' => 'institutional', 'name' => 'Lapispro Institucional', 'sort_order' => 3, 'modules' => self::INSTITUTIONAL_MODULES, 'limits' => self::UNLIMITED],
        ];

        foreach ($plans as $definition) {
            $plan = Plan::updateOrCreate(
                ['key' => $definition['key']],
                ['name' => $definition['name'], 'sort_order' => $definition['sort_order'], 'limits' => $definition['limits']],
            );

            $plan->modules()->sync(
                Module::whereIn('key', $definition['modules'])->pluck('id'),
            );
        }
    }
}
