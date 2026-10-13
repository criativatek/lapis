<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { BookX, ChevronDown, ChevronRight, Clock, NotebookPen, PackageX, Search, UserX, X } from '@lucide/vue';
import type { Component } from 'vue';
import { computed, reactive, ref, watch } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import AbsenceCaseDialog from '@/components/records/AbsenceCaseDialog.vue';
import FollowupCaseDialog from '@/components/records/FollowupCaseDialog.vue';
import RecordPagination from '@/components/records/RecordPagination.vue';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import type { AbsenceDetail, AlertRow, CaseDetail, ConsultationFilters, FollowupRow, FollowupView, Paginated, ResultRow, Tile } from '@/lib/recordFollowup';
import { absencesLabel, CASE_KEYS, daysLabel, filtersToQuery, formatDay, occurrencesLabel, statusLabel, studentLabel, tileFigures, withQuery } from '@/lib/recordFollowup';
import { statusToneClasses } from '@/lib/statusTone';

/**
 * «Registos» — a consulta (spec 2026-10-12). À entrada: filtros, o resumo dos
 * três acompanhamentos e os alertas por tratar — nunca a lista de registos,
 * que só aparece depois de «Pesquisar» e vem paginada do servidor.
 *
 * Os filtros escolhidos ficam por aplicar até «Pesquisar»: o resumo e o
 * contexto escrito por cima descrevem sempre o que ESTÁ aplicado (o URL),
 * para que um número nunca seja lido no âmbito errado.
 */
type ClassOption = { ulid: string; label: string; subject: string; subject_ulid: string; archived: boolean };
type StudentOption = { ulid: string; number: number | null; name: string };
type KindOption = { value: string; label: string; group_label: string };
type PeriodOption = { ulid: string; label: string; starts_on: string; ends_on: string; is_current: boolean };

const props = defineProps<{
    years: { ulid: string; label: string }[];
    filters: ConsultationFilters;
    options: {
        subjects: { ulid: string; name: string }[];
        classes: ClassOption[];
        students: StudentOption[];
        kinds: KindOption[];
        periods: PeriodOption[];
    };
    context: {
        has_classes: boolean;
        year_label: string | null;
        scope_label: string;
        student_label: string | null;
        window_label: string | null;
        period_is_current: boolean;
        needs_window: boolean;
        window_problem: string | null;
        periods_notice: string | null;
        overlaps: string[];
    };
    tiles: Tile[];
    alerts: AlertRow[];
    results: Paginated<ResultRow> | null;
    followup: { category: FollowupView; label: string; unit: 'occurrence' | 'absence'; rows: Paginated<FollowupRow> } | null;
    openCase?: CaseDetail | AbsenceDetail | null;
    can: { confirm: boolean };
}>();

const page = usePage();

const CATEGORY_ICONS: Record<FollowupView, Component> = {
    homework_not_done: BookX,
    lateness: Clock,
    missing_material: PackageX,
    absences: UserX,
};

const absenceCase = computed<AbsenceDetail | null>(() => (props.openCase && 'kind' in props.openCase && props.openCase.kind === 'absences' ? props.openCase : null));
const recordCase = computed<CaseDetail | null>(() => (props.openCase && !('kind' in props.openCase) ? props.openCase : null));

// --- Filtros por aplicar --------------------------------------------------

const form = reactive<ConsultationFilters>({ ...props.filters });

watch(
    () => props.filters,
    (filters) => {
        Object.assign(form, filters);
    },
);

const loadingOptions = ref(false);

// No telemóvel os filtros ficam recolhidos atrás do âmbito escrito, para que o
// resumo apareça sem scroll; a partir de `sm` estão sempre abertos (só CSS —
// nada muda depois da hidratação).
const filtersOpen = ref(false);

const visibleClasses = computed(() => props.options.classes.filter((option) => form.subject === null || option.subject_ulid === form.subject));

const kindGroups = computed(() => {
    const groups: { label: string; kinds: KindOption[] }[] = [];

    for (const kind of props.options.kinds) {
        let group = groups.find((candidate) => candidate.label === kind.group_label);

        if (!group) {
            group = { label: kind.group_label, kinds: [] };
            groups.push(group);
        }

        group.kinds.push(kind);
    }

    return groups;
});

/**
 * As opções que dependem de outro filtro (as turmas e os períodos de um ano,
 * os alunos de uma turma) vêm do servidor sem aplicar nada: só `options` é
 * recarregado e o URL não muda.
 */
function reloadOptions(onLoaded?: () => void): void {
    loadingOptions.value = true;

    router.get('/records', filtersToQuery(form), {
        only: ['options'],
        preserveState: true,
        preserveScroll: true,
        preserveUrl: true,
        onSuccess: () => onLoaded?.(),
        onFinish: () => {
            loadingOptions.value = false;
        },
    });
}

function onYearChange(): void {
    form.subject = null;
    form.class = null;
    form.student = null;
    form.period = null;
    reloadOptions(() => {
        form.period = props.options.periods.find((period) => period.is_current)?.ulid ?? null;
    });
}

function onSubjectChange(): void {
    if (form.class !== null && !visibleClasses.value.some((option) => option.ulid === form.class)) {
        form.class = null;
        form.student = null;
    }
}

function onClassChange(): void {
    form.student = null;
    reloadOptions();
}

function setMode(mode: 'period' | 'range'): void {
    form.mode = mode;
}

function search(): void {
    router.get('/records', { ...filtersToQuery(form), searched: '1' }, { preserveScroll: true });
}

function clearFilters(): void {
    router.get('/records');
}

// --- O que está aplicado --------------------------------------------------

function appliedQuery(extra: Record<string, string> = {}): Record<string, string> {
    return { ...filtersToQuery(props.filters), ...(props.filters.searched ? { searched: '1' } : {}), ...extra };
}

function openFollowup(category: FollowupView): void {
    if (props.filters.view === category) {
        closeFollowup();

        return;
    }

    router.get('/records', appliedQuery({ view: category }), { preserveScroll: true });
}

function closeFollowup(): void {
    router.get('/records', appliedQuery(), { preserveScroll: true });
}

function openCaseFor(classUlid: string, studentUlid: string, category: FollowupView): void {
    router.get(
        withQuery(page.url, { case_class: classUlid, case_student: studentUlid, case_category: category }),
        {},
        { only: ['openCase'], preserveState: true, preserveScroll: true },
    );
}

function closeCase(): void {
    const changes = Object.fromEntries(CASE_KEYS.map((key) => [key, null]));

    router.get(withQuery(page.url, changes), {}, { only: ['openCase'], preserveState: true, preserveScroll: true, replace: true });
}

/** Um alerta abre no seu período, com a lista da sua categoria por trás. */
function openAlert(alert: AlertRow): void {
    router.get(
        '/records',
        {
            ...filtersToQuery({ ...props.filters, mode: 'period', period: alert.period_ulid, from: null, to: null }),
            view: alert.category,
            case_class: alert.class_ulid,
            case_student: alert.student_ulid,
            case_category: alert.category,
        },
        { preserveScroll: true },
    );
}

function recordHref(row: ResultRow): string {
    const query = new URLSearchParams({ registo: row.ulid, voltar: page.url });

    return `/classes/${row.class_ulid}/records?${query.toString()}`;
}

const registerClasses = computed(() => props.options.classes.filter((option) => !option.archived));

// Shown only when it matters: some student is followed in more than one class.
const showsCases = computed(() => props.tiles.some((tile) => tile.cases !== tile.students));

const showSubjectFilter = computed(() => props.options.subjects.length > 1);

const periodDates = (period: PeriodOption): string => `${formatDay(period.starts_on)} a ${formatDay(period.ends_on)}`;
</script>

<template>
    <Head title="Registos" />

    <div class="mx-auto w-full max-w-5xl space-y-6 p-4">
        <PageHeader title="Registos" description="Consultar os registos, acompanhar TPC, atrasos, faltas de material e faltas de presença, e preparar a comunicação ao Inovar.">
            <template v-if="registerClasses.length > 0" #actions>
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button class="min-h-11">
                            <NotebookPen class="size-4" /> Registar
                            <ChevronDown class="size-4 opacity-70" aria-hidden="true" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="min-w-56">
                        <DropdownMenuItem v-for="option in registerClasses" :key="option.ulid" as-child class="min-h-11 cursor-pointer">
                            <Link :href="`/classes/${option.ulid}/records`">{{ option.label }} · {{ option.subject }}</Link>
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </template>
        </PageHeader>

        <EmptyState v-if="years.length === 0" title="Ainda não tem turmas com registos para consultar." :icon="NotebookPen">
            <template #action>
                <Button as-child>
                    <Link href="/classes">Ver turmas</Link>
                </Button>
            </template>
        </EmptyState>

        <template v-else>
            <button
                type="button"
                class="inline-flex min-h-11 w-full items-center justify-between gap-2 rounded-xl border border-border bg-card px-4 text-sm font-medium sm:hidden"
                :aria-expanded="filtersOpen"
                aria-controls="records-filters"
                data-test="toggle-filters"
                @click="filtersOpen = !filtersOpen"
            >
                {{ filtersOpen ? 'Esconder filtros' : 'Filtros e pesquisa' }}
                <ChevronDown class="size-4 transition-transform" :class="filtersOpen ? 'rotate-180' : ''" aria-hidden="true" />
            </button>

            <!-- Filtros -->
            <form
                id="records-filters"
                class="card-soft space-y-4 rounded-2xl border border-border/70 bg-card p-4 sm:block sm:p-5"
                :class="filtersOpen ? 'block' : 'hidden'"
                aria-label="Filtros da consulta"
                @submit.prevent="search"
            >
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <label class="text-sm">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Ano letivo</span>
                        <select v-model="form.year" class="min-h-11 w-full rounded-md border border-input bg-background px-3 text-sm" data-test="filter-year" @change="onYearChange">
                            <option v-for="year in years" :key="year.ulid" :value="year.ulid">{{ year.label }}</option>
                        </select>
                    </label>

                    <label v-if="showSubjectFilter" class="text-sm">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Disciplina</span>
                        <select v-model="form.subject" class="min-h-11 w-full rounded-md border border-input bg-background px-3 text-sm" data-test="filter-subject" @change="onSubjectChange">
                            <option :value="null">Todas as disciplinas</option>
                            <option v-for="subject in options.subjects" :key="subject.ulid" :value="subject.ulid">{{ subject.name }}</option>
                        </select>
                    </label>

                    <label class="text-sm">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Turma</span>
                        <select v-model="form.class" class="min-h-11 w-full rounded-md border border-input bg-background px-3 text-sm" data-test="filter-class" @change="onClassChange">
                            <option :value="null">Todas as turmas</option>
                            <option v-for="option in visibleClasses" :key="option.ulid" :value="option.ulid">
                                {{ option.label }} · {{ option.subject }}{{ option.archived ? ' (arquivada)' : '' }}
                            </option>
                        </select>
                    </label>

                    <label class="text-sm">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Aluno</span>
                        <select
                            v-model="form.student"
                            class="min-h-11 w-full rounded-md border border-input bg-background px-3 text-sm disabled:opacity-60"
                            :disabled="form.class === null || loadingOptions"
                            data-test="filter-student"
                        >
                            <option :value="null">{{ form.class === null ? 'Escolha primeiro uma turma' : 'Todos os alunos' }}</option>
                            <option v-for="student in options.students" :key="student.ulid" :value="student.ulid">{{ studentLabel(student.number, student.name) }}</option>
                        </select>
                    </label>

                    <label class="text-sm">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Tipo de registo</span>
                        <select v-model="form.kind" class="min-h-11 w-full rounded-md border border-input bg-background px-3 text-sm" data-test="filter-kind">
                            <option :value="null">Todos os tipos</option>
                            <optgroup v-for="group in kindGroups" :key="group.label" :label="group.label">
                                <option v-for="kind in group.kinds" :key="kind.value" :value="kind.value">{{ kind.label }}</option>
                            </optgroup>
                        </select>
                    </label>
                </div>

                <fieldset class="space-y-2">
                    <legend class="text-xs font-medium text-muted-foreground">Quando</legend>
                    <div class="inline-flex rounded-lg border border-border p-0.5" role="radiogroup" aria-label="Período ou intervalo de datas">
                        <button
                            type="button"
                            role="radio"
                            :aria-checked="form.mode === 'period'"
                            class="min-h-11 rounded-md px-3 text-sm"
                            :class="form.mode === 'period' ? 'bg-accent font-medium text-accent-foreground' : 'text-muted-foreground hover:bg-muted/40'"
                            data-test="mode-period"
                            @click="setMode('period')"
                        >
                            Período de avaliação
                        </button>
                        <button
                            type="button"
                            role="radio"
                            :aria-checked="form.mode === 'range'"
                            class="min-h-11 rounded-md px-3 text-sm"
                            :class="form.mode === 'range' ? 'bg-accent font-medium text-accent-foreground' : 'text-muted-foreground hover:bg-muted/40'"
                            data-test="mode-range"
                            @click="setMode('range')"
                        >
                            Intervalo de datas
                        </button>
                    </div>

                    <div v-if="form.mode === 'period'" class="grid gap-3 lg:grid-cols-3">
                        <label class="text-sm lg:col-span-2">
                            <span class="sr-only">Período de avaliação</span>
                            <select
                                v-model="form.period"
                                class="min-h-11 w-full rounded-md border border-input bg-background px-3 text-sm disabled:opacity-60"
                                :disabled="options.periods.length === 0 || loadingOptions"
                                data-test="filter-period"
                            >
                                <option :value="null">{{ options.periods.length === 0 ? 'Sem períodos configurados' : 'Escolha um período' }}</option>
                                <option v-for="period in options.periods" :key="period.ulid" :value="period.ulid">
                                    {{ period.label }} ({{ periodDates(period) }}){{ period.is_current ? ' — em curso' : '' }}
                                </option>
                            </select>
                        </label>
                    </div>

                    <div v-else class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <label class="text-sm">
                            <span class="mb-1 block text-xs text-muted-foreground">De</span>
                            <input v-model="form.from" type="date" class="min-h-11 w-full rounded-md border border-input bg-background px-3 text-sm" data-test="filter-from" />
                        </label>
                        <label class="text-sm">
                            <span class="mb-1 block text-xs text-muted-foreground">Até</span>
                            <input v-model="form.to" type="date" class="min-h-11 w-full rounded-md border border-input bg-background px-3 text-sm" data-test="filter-to" />
                        </label>
                    </div>
                </fieldset>

                <div class="flex flex-wrap items-center gap-2">
                    <Button type="submit" class="min-h-11" data-test="search">
                        <Search class="size-4" /> Pesquisar
                    </Button>
                    <Button type="button" variant="ghost" class="min-h-11" data-test="clear" @click="clearFilters">
                        <X class="size-4" /> Limpar filtros
                    </Button>
                </div>
            </form>

            <!-- O âmbito aplicado, sempre por extenso -->
            <div class="space-y-2">
                <p class="text-sm" data-test="context">
                    <span class="font-medium">{{ context.year_label }}</span>
                    · {{ context.scope_label }}
                    <template v-if="context.student_label"> · {{ context.student_label }}</template>
                    <template v-if="context.window_label"> · {{ context.window_label }}</template>
                    <span v-if="context.period_is_current" class="text-muted-foreground"> — período em curso</span>
                </p>

                <p v-if="context.periods_notice" class="rounded-lg border border-border bg-muted/30 px-3 py-2 text-sm" role="note" data-test="periods-notice">
                    {{ context.periods_notice }}
                </p>
                <p v-for="overlap in context.overlaps" :key="overlap" class="rounded-lg border border-border bg-muted/30 px-3 py-2 text-sm" role="note">
                    Os períodos {{ overlap }} sobrepõem-se: um registo nesses dias conta só no primeiro.
                </p>
                <p v-if="context.window_problem" class="text-sm text-destructive" role="alert">{{ context.window_problem }}</p>
                <p v-else-if="context.needs_window" class="rounded-lg border border-dashed border-border px-3 py-2 text-sm" data-test="needs-window">
                    Hoje não cai em nenhum período de avaliação configurado. Escolha um período ou um intervalo de datas e carregue em «Pesquisar».
                </p>
            </div>

            <!-- Os três acessos diretos -->
            <section v-if="tiles.length > 0" aria-labelledby="followup-heading" class="space-y-3">
                <h2 id="followup-heading" class="text-base font-semibold">Acompanhamento</h2>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <button
                        v-for="tile in tiles"
                        :key="tile.category"
                        type="button"
                        class="card-soft flex min-h-11 items-start gap-3 rounded-2xl border p-4 text-left transition-colors hover:bg-muted/30"
                        :class="filters.view === tile.category ? 'border-primary bg-primary/5' : 'border-border/70 bg-card'"
                        :aria-pressed="filters.view === tile.category"
                        :data-test="`tile-${tile.category}`"
                        @click="openFollowup(tile.category)"
                    >
                        <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-muted/60 text-foreground/70" aria-hidden="true">
                            <component :is="CATEGORY_ICONS[tile.category]" class="size-5" />
                        </span>
                        <span class="min-w-0">
                            <span class="block font-semibold">{{ tile.label }}</span>
                            <span class="block text-sm tabular-nums text-muted-foreground">{{ tileFigures(tile) }}</span>
                            <span v-if="tile.pending" class="mt-1 inline-block rounded-full px-2 py-0.5 text-xs font-medium" :class="statusToneClasses('pending')">
                                {{ tile.pending }} por tratar
                            </span>
                        </span>
                    </button>
                </div>
                <p v-if="showsCases" class="text-xs text-muted-foreground" data-test="cases-hint">
                    Um caso é um aluno numa turma: um aluno com ocorrências em duas turmas conta uma vez como aluno e duas como casos, e os alertas de cada turma ficam separados.
                </p>
            </section>

            <!-- Alertas por tratar -->
            <section v-if="alerts.length > 0" aria-labelledby="alerts-heading" class="space-y-3" data-test="alerts">
                <h2 id="alerts-heading" class="text-base font-semibold">Alertas por tratar ({{ alerts.length }})</h2>
                <ul class="divide-y divide-border overflow-hidden rounded-xl border border-border bg-card">
                    <li v-for="alert in alerts" :key="alert.case_ulid">
                        <button type="button" class="flex min-h-11 w-full items-center gap-3 px-4 py-3 text-left hover:bg-muted/30" @click="openAlert(alert)">
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-medium">{{ studentLabel(alert.number, alert.name) }}</span>
                                <span class="block text-xs text-muted-foreground">
                                    {{ alert.category_label }} · {{ occurrencesLabel(alert.occurrences) }} · {{ alert.class_label }} · {{ alert.subject }} · {{ alert.period_label }}
                                </span>
                            </span>
                            <ChevronRight class="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                        </button>
                    </li>
                </ul>
            </section>

            <!-- Lista de um acompanhamento -->
            <section v-if="followup" aria-labelledby="followup-list-heading" class="space-y-3" data-test="followup-list">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 id="followup-list-heading" class="text-base font-semibold">{{ followup.label }} — por aluno e turma</h2>
                    <Button type="button" variant="ghost" class="min-h-11" @click="closeFollowup">Fechar lista</Button>
                </div>

                <EmptyState v-if="followup.rows.total === 0" :title="`Nenhum aluno com ${followup.label.toLowerCase()} neste âmbito.`" />

                <template v-else>
                    <ul class="divide-y divide-border overflow-hidden rounded-xl border border-border bg-card">
                        <li v-for="row in followup.rows.data" :key="`${row.class_ulid}:${row.student_ulid}`">
                            <button
                                type="button"
                                class="flex min-h-11 w-full items-center gap-3 px-4 py-3 text-left hover:bg-muted/30"
                                :aria-label="`Ver as datas de ${row.name}`"
                                @click="openCaseFor(row.class_ulid, row.student_ulid, followup.category)"
                            >
                                <span class="w-12 shrink-0 text-sm tabular-nums text-muted-foreground">{{ row.number === null ? '—' : `n.º ${row.number}` }}</span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-medium">{{ row.name }}</span>
                                    <span class="block truncate text-xs text-muted-foreground">
                                        <template v-if="row.days !== null">{{ absencesLabel(row.occurrences) }} em {{ daysLabel(row.days) }} · </template>
                                        {{ row.class_label }} · {{ row.subject }}
                                        <template v-if="row.student_cases > 1"> · também noutra turma</template>
                                        <template v-if="row.origin === 'existing'"> · sem alerta</template>
                                    </span>
                                </span>
                                <span v-if="statusLabel(row.status)" class="hidden rounded-full px-2 py-0.5 text-xs font-medium sm:inline" :class="statusToneClasses(row.status ?? 'none')">
                                    {{ statusLabel(row.status) }}
                                </span>
                                <span class="w-10 shrink-0 text-right text-base font-semibold tabular-nums">{{ row.occurrences }}</span>
                                <ChevronRight class="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                            </button>
                        </li>
                    </ul>
                    <RecordPagination
                        :current-page="followup.rows.current_page"
                        :last-page="followup.rows.last_page"
                        :total="followup.rows.total"
                        :prev-url="followup.rows.prev_url"
                        :next-url="followup.rows.next_url"
                        :noun="{ one: 'caso', many: 'casos' }"
                    />
                </template>
            </section>

            <!-- Resultados da pesquisa -->
            <section v-else-if="results" aria-labelledby="results-heading" class="space-y-3" data-test="results">
                <h2 id="results-heading" class="text-base font-semibold">Registos encontrados</h2>

                <EmptyState v-if="results.total === 0" title="Nenhum registo corresponde a estes filtros." description="Experimente outro período, outro tipo ou «Limpar filtros»." />

                <template v-else>
                    <ul class="divide-y divide-border overflow-hidden rounded-xl border border-border bg-card">
                        <li v-for="row in results.data" :key="row.ulid" class="flex items-start gap-3 px-4 py-3">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                    <span class="rounded-full bg-accent px-2 py-0.5 text-xs text-accent-foreground">{{ row.kind_label }}</span>
                                    <span v-if="row.detail_label" class="text-xs text-muted-foreground">— {{ row.detail_label }}</span>
                                    <span v-if="row.severity_label" class="text-xs text-muted-foreground">— {{ row.severity_label }}</span>
                                    <span class="text-sm font-medium">{{ row.student ? studentLabel(row.student.number, row.student.name) : 'Turma inteira' }}</span>
                                </div>
                                <p class="mt-0.5 text-xs text-muted-foreground">
                                    <span class="tabular-nums">{{ formatDay(row.date) }}</span> · {{ row.class_label }} · {{ row.subject }}
                                </p>
                                <p v-if="row.description" class="mt-1 text-sm break-words text-muted-foreground">{{ row.description }}</p>
                            </div>
                            <Link
                                :href="recordHref(row)"
                                class="inline-flex min-h-11 shrink-0 items-center rounded-md px-3 text-sm font-medium text-primary hover:bg-muted/40"
                                :aria-label="`Abrir o registo de ${formatDay(row.date)}`"
                            >
                                Abrir
                            </Link>
                        </li>
                    </ul>
                    <RecordPagination
                        :current-page="results.current_page"
                        :last-page="results.last_page"
                        :total="results.total"
                        :prev-url="results.prev_url"
                        :next-url="results.next_url"
                        :noun="{ one: 'registo', many: 'registos' }"
                    />
                </template>
            </section>

            <p v-else-if="!context.needs_window" class="rounded-xl border border-dashed border-border px-4 py-6 text-center text-sm text-muted-foreground" data-test="initial-state">
                A lista de registos aparece aqui depois de «Pesquisar». Escolha os filtros, ou abra um dos acompanhamentos acima.
            </p>
        </template>

        <FollowupCaseDialog v-if="recordCase" :detail="recordCase" :current-url="page.url" @close="closeCase" />
        <AbsenceCaseDialog v-else-if="absenceCase" :detail="absenceCase" :current-url="page.url" @close="closeCase" />
    </div>
</template>
