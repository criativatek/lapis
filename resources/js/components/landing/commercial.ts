/**
 * THE COMMERCIAL OFFER, IN ONE FILE.
 *
 * Prices, the Fundador condition and the comparison rows are written once
 * here and read by LandingPricing, LandingFounder and LandingCompare. Three
 * components that each carried their own «44,90 €» is exactly how a page ends
 * up quoting two different prices after one of them is updated.
 *
 * WHAT IS NOT HERE: which plan carries which capability. That is not copy —
 * it is `plan_module`, and the comparison table derives every ✓ from the
 * `moduleKeys` HomeController sends. A row states which module keys it needs;
 * the plan answers whether it has them.
 *
 * ONLY ANNUAL BILLING EXISTS. The monthly figures below are equivalences for
 * reading a yearly price, never a product: there is no monthly plan, no
 * monthly/annual toggle, and nothing anywhere charges by the month.
 */

/** The Pro plan, list price. */
export const PRO_PRICE = '44,90 €';
export const PRO_PRICE_PER_YEAR = '44,90 € / ano';
export const PRO_MONTHLY_EQUIVALENT = 'equivalente a menos de 3,75 €/mês';

/** The launch condition on that same Pro plan — not a fourth plan. */
export const FOUNDER_PRICE = '29,90 €';
export const FOUNDER_PRICE_PER_YEAR = '29,90 € / ano';
export const FOUNDER_MONTHLY_EQUIVALENT = 'equivalente a menos de 2,50 €/mês';
export const FOUNDER_SEATS = 250;
export const FOUNDER_DEADLINE = '31 de dezembro de 2026';

/**
 * The quantitative caps the Base plan really carries (`plans.limits`, seeded
 * by EntitlementsSeeder). Stated on the page because they are true: a page
 * that says «sem limites» while the server refuses the ninth class is a page
 * that creates a support ticket on the day it converts somebody.
 */
export const BASE_ACTIVE_CLASSES = 8;
export const BASE_ACTIVE_STUDENTS = 300;

export type PlanCommercial = {
    /** The one line that has to land, even if nothing else is read. */
    headline: string;
    /** Who it is for, in one sentence. */
    body: string;
    /** A line above the figure, when the figure alone would not be honest. */
    priceLead?: string;
    price: string;
    /** «/ ano» — absent when the price is not a period price. */
    priceUnit?: string;
    /** The monthly equivalence, or the «sob consulta» complement. */
    priceNote: string;
    /** A second, smaller line under the note. */
    priceFootnote?: string;
    cta: string;
    features: readonly string[];
    /** The conceptual boundary, repeated verbatim under the comparison. */
    boundary: string;
};

/**
 * Keyed by `plans.key`, so a plan renamed in the seeder still finds its copy.
 * A plan with no entry here falls back to FALLBACK_PLAN and shows no price —
 * inventing one would be worse than showing none.
 */
export const PLAN_COPY: Record<string, PlanCommercial> = {
    base: {
        headline: 'Tudo o que precisa para organizar, avaliar e acompanhar.',
        body: 'Para o professor que procura uma ferramenta completa para a gestão de turmas, a avaliação e o acompanhamento de alunos.',
        priceLead: 'Gratuito no ano letivo 2026/27',
        price: '0 €',
        priceNote:
            'Sem cartão. A conta fica ativa no plano Base assim que a criar.',
        priceFootnote: `Até ${BASE_ACTIVE_CLASSES} turmas e ${BASE_ACTIVE_STUDENTS} alunos ativos. Turmas e alunos arquivados não contam.`,
        cta: 'Começar gratuitamente',
        features: [
            'Turmas e alunos',
            'Perfis e critérios de avaliação, com os seus pesos',
            'Instrumentos e classificações',
            'Acompanhamento factual do aluno, com registos positivos',
            'Registos',
            'Autoavaliação',
            'Estratégias e medidas manuais',
            'Relatórios essenciais',
            'Calendário do ano letivo, com acontecimentos manuais',
            'Exportação dos próprios dados',
        ],
        boundary: 'Base regista e mostra.',
    },
    pro: {
        headline: 'Menos trabalho. Mais compreensão. Mais tempo para ensinar.',
        body: 'Tudo o que existe no Base, acrescentando ferramentas que organizam o dia a dia, interpretam informação e ajudam o professor a agir.',
        price: PRO_PRICE,
        priceUnit: '/ ano',
        priceNote: PRO_MONTHLY_EQUIVALENT,
        priceFootnote: 'Subscrição anual. Não existe pagamento mensal.',
        cta: 'Escolher Pro',
        features: [
            'Horário do professor',
            'Aulas e sumários',
            'Planeamento e sequências de aulas',
            'Análises avançadas, tendências e regularidade',
            'Alertas inteligentes',
            'Estado 360º do aluno',
            'Identificação automática de pontos fortes, potencialidades e margem de progressão',
            'Apoio à definição do próximo passo pedagógico',
            'Acompanhamento da eficácia das estratégias',
            'Sínteses avançadas',
            'IA pedagógica: sugere estratégias e ajuda a aperfeiçoar relatórios',
            'Importações avançadas: o calendário publicado pela escola e as grelhas de correção',
            'Cópia de segurança completa, restauro e histórico de backups',
        ],
        boundary: 'Pro cruza, interpreta e ajuda a agir.',
    },
    institutional: {
        headline:
            'Uma linguagem comum para toda a escola, sem retirar autonomia ao professor.',
        body: 'Tudo o que existe no Pro, acrescentando a gestão pedagógica à escala da instituição — uma plataforma para escolas e agrupamentos que coordenam vários professores.',
        price: 'Sob consulta',
        priceNote:
            'Ainda não disponível para adesão. Fale connosco e avisamos quando abrir.',
        cta: 'Falar connosco',
        features: [
            'Múltiplos professores, membros e licenças',
            'Administradores e coordenadores',
            'Configurações partilhadas',
            'Perfis de avaliação institucionais',
            'Escalas e modelos comuns',
            'Calendário institucional',
            'Recursos partilhados e templates institucionais',
            'Visão agregada e analytics institucionais',
            'Governação da IA e políticas de retenção',
            'Auditoria e gestão institucional',
        ],
        boundary: 'Institucional coordena, partilha e agrega.',
    },
};

export const FALLBACK_PLAN: PlanCommercial = {
    headline: 'Um plano do Lapispro.',
    body: 'Veja a comparação detalhada mais abaixo.',
    price: 'Sob consulta',
    priceNote: 'Fale connosco para saber o que inclui.',
    cta: 'Comparar os planos',
    features: [],
    boundary: '',
};

export const FOUNDER = {
    badge: 'Membro Fundador · 2026',
    title: 'Faça parte dos primeiros 250.',
    body: 'Os primeiros professores a aderirem ao plano Pro poderão beneficiar de uma condição exclusiva de lançamento.',
    /** Said plainly, because §9 asks the visitor to understand it immediately. */
    clarification:
        'Não é um plano diferente. São exatamente as mesmas funcionalidades do plano Pro, numa condição de adesão distinta.',
    eligibility: `Disponível até ${FOUNDER_DEADLINE} ou até serem atingidos os primeiros ${FOUNDER_SEATS} Membros Fundadores, consoante o que ocorrer primeiro.`,
    benefits: [
        'Condição comercial exclusiva',
        'Prioridade na análise de problemas reportados',
        'Consideração preferencial das propostas de novas funcionalidades',
        'Participação privilegiada na evolução do Lapispro',
    ],
    closing:
        'O mesmo plano Pro. Uma condição especial para quem acreditou desde o início.',
    cta: 'Quero ser Membro Fundador',
} as const;

/**
 * One line of the comparison table.
 *
 * `modules` are the entitlement keys a plan must carry — ALL of them — for
 * the row to read as included. A row with an empty `modules` is one the
 * commercial matrix defines and the product does not implement yet: it is
 * listed, because leaving it out would misrepresent the Institucional offer,
 * and it is marked «Em preparação» rather than ✓, because presenting it as
 * available would misrepresent the product. Neither half is negotiable.
 */
export type CompareRow = {
    label: string;
    modules: readonly string[];
    /** Plan keys where the offer places it but no capability exists yet. */
    planned?: readonly string[];
};

export const COMPARE_ROWS: readonly CompareRow[] = [
    { label: 'Turmas e alunos', modules: ['classes', 'students'] },
    { label: 'Perfis, critérios e pesos', modules: ['assessment_profiles'] },
    {
        label: 'Instrumentos e classificações',
        modules: ['instruments', 'assessments'],
    },
    { label: 'Acompanhamento factual do aluno', modules: ['student_progress'] },
    // The FACTS are Base and stay Base: the registos the teacher wrote, the
    // domain figures, the results behind them. What Pro adds is naming them
    // without being asked — «Pontos fortes identificados automaticamente»
    // (BuildStudentStrengths), which the Base/Pro realignment moved behind
    // `advanced_analytics`, and which this table states two rows below rather
    // than here.
    {
        label: 'Registos positivos e evidência factual',
        modules: ['records', 'student_progress'],
    },
    { label: 'Autoavaliação manual', modules: ['self_assessments'] },
    { label: 'Estratégias e medidas manuais', modules: ['interventions'] },
    { label: 'Relatórios essenciais', modules: ['reports'] },
    // TWO ROWS, BECAUSE THE MATRIZ HAS TWO LINES AND THE SEEDER NOW HAS TWO
    // KEYS. «Agenda integrada» was one row on `calendar` while the whole
    // calendar was Pro; with `calendar` in Base and only the import left in
    // Pro, that single row would have read ✓ Base and told a visitor the
    // school's .xlsx comes with the free plan. They are kept adjacent so the
    // boundary is read in one glance instead of inferred.
    {
        label: 'Calendário do ano letivo e acontecimentos',
        modules: ['calendar'],
    },
    {
        label: 'Importação avançada de calendário',
        modules: ['calendar_import'],
    },
    { label: 'Horário do professor', modules: ['lessons'] },
    { label: 'Aulas e sumários', modules: ['lessons'] },
    { label: 'Planeamento e sequências de aulas', modules: ['lessons'] },
    {
        label: 'Tendências e análises automáticas',
        modules: ['advanced_analytics'],
    },
    { label: 'Alertas e atenção automática', modules: ['advanced_analytics'] },
    { label: 'Estado 360º', modules: ['advanced_analytics'] },
    // The Pro half of the same subject: not «has strengths», but «names them
    // without being asked». `BuildStudentInsights` is what reads the figures
    // the Base row above already shows.
    {
        label: 'Identificação automática de pontos fortes e potencialidades',
        modules: ['advanced_analytics'],
    },
    { label: 'Margem de progressão', modules: ['advanced_analytics'] },
    { label: 'Próximo passo pedagógico', modules: ['advanced_analytics'] },
    // Two AI rows, because the product has two AI features: «Sugestões
    // pedagógicas (IA)» over strategies, and «Aperfeiçoar redação» over a
    // report section. There is deliberately no third row for AI «applied to
    // assessment» — nothing in the code lets a model touch a classification,
    // and naming a capability the product does not have would be inventing
    // one.
    { label: 'IA pedagógica', modules: ['ai_assistance'] },
    {
        label: 'IA aplicada a relatórios',
        modules: ['ai_assistance', 'report_pedagogical_analysis'],
    },
    // The half of §7 that IS paid. Exporting your own data has no row at all
    // and no key behind it — §20 makes portability a property of the platform
    // — so the label names restoring and the history, never «exportação», and
    // LandingCompare's footnote says the other half is in every plan.
    {
        label: 'Restauro de cópia de segurança e histórico de backups',
        modules: ['data_backup_restore'],
    },
    { label: 'Gestão de vários professores', modules: ['institution_admin'] },
    { label: 'Gestão de licenças', modules: [], planned: ['institutional'] },
    { label: 'Modelos institucionais', modules: ['institution_library'] },
    {
        label: 'Configuração partilhada',
        modules: [],
        planned: ['institutional'],
    },
    {
        label: 'Calendário institucional',
        modules: [],
        planned: ['institutional'],
    },
    { label: 'Analytics agregados', modules: ['institution_reports'] },
    { label: 'Governação institucional', modules: ['audit_log'] },
];

export type RowAvailability = 'included' | 'planned' | 'absent';

export function availability(
    row: CompareRow,
    planKey: string,
    planModuleKeys: readonly string[],
): RowAvailability {
    if (
        row.modules.length > 0 &&
        row.modules.every((module) => planModuleKeys.includes(module))
    ) {
        return 'included';
    }

    return row.planned?.includes(planKey) ? 'planned' : 'absent';
}

/** The three sentences, in plan order. Repeated verbatim from the plan cards. */
export const BOUNDARIES = [
    { plan: 'Base', sentence: 'Base regista e mostra.' },
    { plan: 'Pro', sentence: 'Pro cruza, interpreta e ajuda a agir.' },
    {
        plan: 'Institucional',
        sentence: 'Institucional coordena, partilha e agrega.',
    },
] as const;
