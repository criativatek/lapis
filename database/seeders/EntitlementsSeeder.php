<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\Plan;
use App\Models\PlanVersion;
use Illuminate\Database\Seeder;

/**
 * The commercial composition of Base / Pro / Institucional.
 *
 * Taken from the comparison table in Apresentacao_LAPIS.docx §8 and the menu
 * structure doc. This is a starting composition, not a contract: it is seeded
 * data precisely so modules can move between plans without a code change.
 *
 * IT PUBLISHES; IT NO LONGER MUTATES (ADR-0008 §4). This file stays the one
 * place where each plan's composition is written down — that does not change
 * and should not. What changed is what it DOES with it. It used to `sync()`
 * `module_plan` and `update()` `plans.limits` on every run, so moving one
 * capability between plans rewrote — retroactively, and without a record —
 * what every subscriber of that plan had ever been entitled to. It now
 * compares the composition it holds against the last version published, and
 * only if they genuinely differ publishes version N+1. A published version is
 * never written to again.
 *
 * Idempotent in the strong sense: running it twice creates ONE version;
 * running it after a real change creates EXACTLY one more. That is what keeps
 * `db:seed` safe on a live database and turns «has the offer changed?» into a
 * hash comparison instead of a careful read.
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

        // «Calendário do Ano Letivo» — Base since the Base/Pro realignment.
        // Matriz Mestre §2 marks «Calendário mensal/anual» and «Datas
        // relevantes / eventos manuais» with a tick in all three plans: seeing
        // the year one already defined, and writing down the reunião that
        // belongs nowhere else, are part of organizing a year rather than a
        // separate product. Only «Importação avançada de calendário» is Pro
        // there, and it now has its own key below instead of riding on this
        // one.
        'calendar' => 'Calendário do Ano Letivo',

        // Pro.
        // The .xlsx the agrupamento publishes, read into this year's
        // structure. Split out of 'calendar' so the views and the
        // acontecimentos could go to Base without taking the import with
        // them — Matriz §2 «Importação avançada de calendário» and §17
        // 'calendar_import', which the Matriz already lists as a key of its
        // own rather than a mode of 'calendar'.
        'calendar_import' => 'Importação do Calendário da Escola',
        'lessons' => 'Aulas e Sumários',
        // «Cópia de segurança completa e restauro» (Matriz §7, §17
        // 'data_backup_restore'). NOT the export: exporting your own data
        // and the RGPD export are ticked for every plan and stay ungated —
        // that is portability, and §20 makes it a property of the platform
        // rather than a paid feature. What this key gates is the other half,
        // which §7 marks Pro: restoring a complete backup back into an
        // organization, and the history of the backups themselves.
        'data_backup_restore' => 'Cópia de Segurança e Restauro',
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

        // THE AI CAPABILITY CATALOGUE (`App\Services\Ai\Gateway\AiCapability`).
        // Every one of these is a real, enforced key: `AiGateway` refuses any
        // request whose organization does not hold the capability behind it.
        //
        // WHICH PLAN GETS WHICH IS NOW DECIDED, and the decision is the Matriz
        // Mestre's, not this file's. It was open until the AI-complete slice —
        // the previous version of this comment said so, and said the day
        // somebody decided it would be a one-line change in the arrays below.
        // This is that change. The composition below is transcribed from the
        // Matriz, capability by capability:
        //
        //   help_assistant           Base · Pro · Institucional
        //   ai_pedagogical_analysis         Pro · Institucional
        //   ai_assessment                   Pro · Institucional
        //   ai_followup                     Pro · Institucional
        //   ai_strategies                   Pro · Institucional
        //   ai_reports                      Pro · Institucional
        //   ai_governance                          Institucional
        //   ai_institutional_pool                  Institucional
        //
        // BASE HAS THE HELP ASSISTANT, AND THE LIMIT IS A QUOTA RATHER THAN A
        // SECOND KEY. «Base = sim, limitado/configurável» is expressed by the
        // ceilings in `config('lapis.ai.quotas')` and by a plan's own
        // `limits['ai_quota']`, both of which an operator can change without a
        // deploy. A separate `help_assistant_limited` key would have made
        // «how much» a commercial decision frozen in code, which is exactly
        // what §3 of the brief says not to do.
        //
        // `ai_assistance` STAYS EXACTLY WHERE IT IS (Pro and Institucional).
        // It is the historical key that gated «Aperfeiçoar redação» and the
        // strategy suggester before either went through the gateway. Both now
        // check `ai_reports` and `ai_strategies`, and both still accept the old
        // key through `AiCapability::legacyModuleKeys()` — so an organization
        // holding `ai_assistance` through an override keeps working.
        'help_assistant' => 'Assistente do Centro de Ajuda',
        'ai_pedagogical_analysis' => 'Análise Pedagógica da Turma (IA)',
        'ai_assessment' => 'IA na Avaliação',
        'ai_followup' => 'IA no Acompanhamento',
        'ai_strategies' => 'IA em Estratégias e Medidas',
        'ai_reports' => 'IA nos Relatórios',
        'ai_governance' => 'Governação de IA',
        'ai_institutional_pool' => 'Pool de IA da Organização',
    ];

    protected const BASE_MODULES = [
        'assessment_profiles', 'classes', 'students', 'instruments', 'assessments',
        'results', 'self_assessments', 'records', 'interventions', 'student_progress',
        'reports',
        // Matriz Mestre §2: «Calendário mensal/anual» and «Datas relevantes /
        // eventos manuais» are ticked for Base, Pro and Institucional alike.
        // A Base organization that already defines períodos, interrupções and
        // feriados in «Estrutura do Ano Letivo» could not open either view of
        // what it had just defined; that is the misalignment this key's move
        // corrects. The advanced import stayed Pro, under 'calendar_import'.
        'calendar',
        // The Centro de Ajuda's articles need no entitlement at all — the page
        // is outside the `organization` middleware. This key is the ASSISTANT
        // on top of them, which does reach an engine and therefore does.
        'help_assistant',
    ];

    protected const PRO_MODULES = [
        ...self::BASE_MODULES,
        'calendar_import', 'lessons', 'ai_assistance', 'advanced_analytics', 'template_sharing',
        'self_assessment_links', 'correction_grid_import', 'inovar_export',
        'report_pedagogical_analysis', 'data_backup_restore',
        'ai_pedagogical_analysis', 'ai_assessment', 'ai_followup', 'ai_strategies', 'ai_reports',
    ];

    protected const INSTITUTIONAL_MODULES = [
        ...self::PRO_MODULES,
        'institution_admin', 'institution_library', 'institution_reports', 'audit_log',
        // Governação = consumo e configuração, nunca vigilância de conteúdo.
        // Neither key reaches an engine; see `AiCapability::isMetered()`.
        'ai_governance', 'ai_institutional_pool',
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
            ['key' => 'base', 'name' => 'Base', 'sort_order' => 1, 'modules' => self::BASE_MODULES, 'limits' => self::BASE_LIMITS],
            ['key' => 'pro', 'name' => 'Pro', 'sort_order' => 2, 'modules' => self::PRO_MODULES, 'limits' => self::UNLIMITED],
            ['key' => 'institutional', 'name' => 'Institucional', 'sort_order' => 3, 'modules' => self::INSTITUTIONAL_MODULES, 'limits' => self::UNLIMITED],
        ];

        foreach ($plans as $definition) {
            $plan = Plan::updateOrCreate(
                ['key' => $definition['key']],
                ['name' => $definition['name'], 'sort_order' => $definition['sort_order']],
            );

            $this->publish($plan, $definition['modules'], $definition['limits']);
        }
    }

    /**
     * Publishes this composition as the plan's next version — or does nothing
     * at all, if it is the composition already on sale.
     *
     * The comparison is against the LAST version by number, not against the
     * current sellable one: a composition identical to a retired version is
     * still a change relative to what is on sale now, and re-publishing it as
     * version N+1 is the honest record of that. The hash is computed by
     * `PlanVersion` — the same function the backfill migration used — so the
     * two sides of this comparison can never drift apart and make a fresh
     * install publish a spurious v2 on its first `db:seed`.
     *
     * `attach()`, never `sync()`. The modules of a version are written once,
     * here, at the moment it is published, and there is no code path anywhere
     * that writes them a second time.
     *
     * @param  list<string>  $moduleKeys
     * @param  array<string, mixed>  $limits
     */
    protected function publish(Plan $plan, array $moduleKeys, array $limits): void
    {
        $hash = PlanVersion::compositionHash($moduleKeys, $limits);

        // The number and the hash of the last version, read as values rather
        // than as a model: «no version yet» is an ordinary answer here, not an
        // absent object every line below has to guard against.
        $latestNumber = (int) $plan->versions()->max('version');
        $latestHash = $plan->versions()->orderByDesc('version')->value('composition_hash');

        if ($latestHash === $hash) {
            return;
        }

        $version = $plan->versions()->create([
            'version' => $latestNumber + 1,
            'limits' => $limits,
            'composition_hash' => $hash,
            'published_at' => now(),
        ]);

        $version->modules()->attach(
            Module::whereIn('key', $moduleKeys)->pluck('id'),
        );
    }
}
