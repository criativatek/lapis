/**
 * O contrato das props do painel de utilização (`/admin/usage`).
 *
 * Vem de `App\Services\Usage\Insights\*` e de docs/superpowers/specs/
 * 2026-10-11-usage-insights-design.md, secção 7. Períodos sem recolha são
 * `null`, nunca 0; o servidor devolve contagens e denominadores, e quem
 * mostra uma percentagem mostra o denominador ao lado.
 */

export type UsageSegmentKey = 'real' | 'test' | 'internal' | 'unclassified';

export type UsageClassificationKey = 'unclassified' | 'real' | 'test' | 'internal';

export type UsagePeriodKey = '7' | '30' | '90' | 'custom';

export type UsageAgeKey = 'all' | 'full' | 'lt30' | '30to90' | 'gt90';

/** Estado de cobertura de uma semana ou de um mês. */
export type WeekStatus = 'no_coverage' | 'in_progress' | 'partial_period' | 'partial_coverage' | 'complete';

export type UsageFilters = {
    period: UsagePeriodKey;
    from: string;
    to: string;
    days: number;
    plan: string | null;
    organization: string | null;
    segment: UsageSegmentKey;
    /** O início foi encostado ao prazo de conservação. */
    adjusted_to_retention: boolean;
    /** Pesquisa (só na lista de utilizadores). */
    q: string;
    /** Filtro por sinal (só na lista de utilizadores). */
    signal: string | null;
    sort: string | null;
    /** Antiguidade da conta (só no ranking). */
    age: UsageAgeKey;
};

export type UsageFilterOptions = {
    plans: { key: string; label: string }[];
    organizations: { ulid: string; name: string; classification: UsageClassificationKey }[];
    segments: { key: UsageSegmentKey; label: string; description: string }[];
    signal_options: { key: string; label: string }[];
};

export type UsageCoverage = {
    collection_started_at: string | null;
    start_day: string | null;
    retention_cutoff: string;
    aggregated_at: string | null;
    observed_from: string | null;
    observed_to: string | null;
    period_status: 'complete' | 'partial' | 'none';
    /** Frases em pt-PT, prontas a mostrar. */
    notes: string[];
};

export type UsageDefinition = { term: string; text: string };

/** Props comuns a todas as páginas. */
export type UsageCommonProps = {
    filters: UsageFilters;
    options: UsageFilterOptions;
    coverage: UsageCoverage;
    definitions: UsageDefinition[];
};

export type UsageSignal = {
    key: string;
    label: string;
    reason: string;
    window_from: string;
    window_to: string;
};

export type UsageFeatureRef = { key: string; label: string };

export type UsagePaginated<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
};

// --- Visão geral -----------------------------------------------------------

export type UsageHeadline = {
    active_7: { value: number | null; observed_days: number; window_days: 7 };
    active_30: { value: number | null; observed_days: number; window_days: 30 };
    active_period: number | null;
    new_accounts: number;
    first_useful_record: { done: number; of: number; before_collection: number };
};

export type UsageWeek = {
    start: string;
    end: string;
    status: WeekStatus;
    active_users: number | null;
    active_user_days: number | null;
    actions: number | null;
    items: number | null;
};

export type UsageMonth = {
    month: string;
    label: string;
    status: WeekStatus;
    active_users: number | null;
};

export type UsageFeatureRow = {
    key: string;
    label: string;
    users: number;
    users_with_actions: number;
    actions: number;
    items: number;
    /** Discriminação dos elementos afetados, quando as ações a trazem. */
    items_created: number;
    items_changed: number;
    items_unchanged: number;
    failures: number;
};

export type UsageSignalSummary = {
    key: string;
    label: string;
    description: string;
    count: number;
    not_evaluated: number;
};

export type UsageCohort = {
    week_start: string;
    week_end: string;
    size: number;
    weeks: { offset: number; status: 'complete' | 'partial_coverage' | 'in_progress' | 'no_coverage' | 'not_started'; returned: number | null }[];
};

export type UsageOverviewProps = UsageCommonProps & {
    headline: UsageHeadline;
    weeks: UsageWeek[];
    months: UsageMonth[];
    features: UsageFeatureRow[];
    signals: UsageSignalSummary[];
    cohorts: UsageCohort[];
};

// --- Acompanhamento --------------------------------------------------------

export type UsageUserRow = {
    ulid: string;
    name: string;
    email: string;
    joined_on: string;
    plans: string[];
    organizations: number;
    flags: { test: boolean; internal: boolean; unclassified: boolean };
    last_activity_on: string | null;
    active_days: number;
    actions: number;
    items: number;
    features: UsageFeatureRef[];
    signals: UsageSignal[];
    /** Descritivo, nunca um juízo. */
    status: string | null;
    observation_days: number;
    period_days: number;
};

export type UsageUsersProps = UsageCommonProps & {
    users: UsagePaginated<UsageUserRow>;
    sort_options: { key: string; label: string }[];
};

export type UsageUserDetailProps = UsageCommonProps & {
    user: {
        ulid: string;
        name: string;
        email: string;
        joined_on: string;
        status: string | null;
        organizations: { name: string; plan: string | null; classification: UsageClassificationKey }[];
        flags: { test: boolean; internal: boolean; unclassified: boolean };
    };
    first_steps: { key: string; label: string; observed_on: string | null; note: string | null }[];
    class_available_since: { date: string | null; label: string };
    signals: UsageSignal[];
    /** Sinais que ficaram por avaliar por falta de cobertura. */
    not_evaluated: { key: string; label: string }[];
    summary: {
        active_days: number;
        active_weeks: number;
        actions: number;
        items: number;
        failures: number;
        last_activity_on: string | null;
        observation_days: number;
        period_days: number;
    };
    weeks: {
        start: string;
        end: string;
        status: WeekStatus;
        active_days: number | null;
        actions: number | null;
        items: number | null;
        failures: number | null;
        features: UsageFeatureRef[];
    }[];
    features: {
        key: string;
        label: string;
        active_days: number;
        view_days: number;
        actions: number;
        items: number;
        items_created: number;
        items_changed: number;
        items_unchanged: number;
        failures: number;
    }[];
};

// --- Ranking ---------------------------------------------------------------

export type UsageRankingRow = {
    position: number;
    /** Partilha a posição com outra pessoa. */
    tied: boolean;
    ulid: string;
    name: string;
    email: string;
    plans: string[];
    joined_on: string;
    account_age_days: number;
    observation_days: number;
    period_days: number;
    partial_observation: boolean;
    active_days: number;
    actions: number;
    items: number;
    active_weeks: number;
    observed_weeks: number;
    features: number;
};

export type UsageRankingProps = UsageCommonProps & {
    support_text: string;
    ranking: UsagePaginated<UsageRankingRow>;
    total_ranked: number;
    /** Pessoas sem atividade no período, que não são ordenadas. */
    unranked: number | null;
    sort_options: { key: string; label: string }[];
    age_options: { key: UsageAgeKey; label: string }[];
};
