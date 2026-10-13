<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, ArrowUpDown, Search } from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import ClassPicker from '@/components/records/ClassPicker.vue';
import type { CaseStatus, ConsultationFilters, FollowupView, Panorama, PanoramaColumn, PanoramaRow } from '@/lib/recordFollowup';
import { daysLabel, formatDay, PANORAMA_COLUMNS, studentLabel, tabQuery } from '@/lib/recordFollowup';

/**
 * «Panorama da turma» — every student of the class, the four indicators side
 * by side, as soon as the class is known (no «Pesquisar»). One table, never
 * four lists. Sorting, «Só alunos com ocorrências» and the quick search work on
 * the rows already loaded: the server sends one class's aggregated table,
 * never the history.
 *
 * Sorting is for reading only — there is no score, no ranking, no total per
 * student.
 */
type ClassOption = { ulid: string; label: string; subject: string; subject_ulid: string; archived: boolean };
type PeriodOption = { ulid: string; label: string; starts_on: string; ends_on: string; is_current: boolean };
type SortKey = 'number' | 'name' | PanoramaColumn;

const props = defineProps<{
    panorama: Panorama | null;
    filters: ConsultationFilters;
    years: { ulid: string; label: string }[];
    options: { classes: ClassOption[]; periods: PeriodOption[] };
    context: {
        window_label: string | null;
        period_is_current: boolean;
        needs_window: boolean;
        window_problem: string | null;
        periods_notice: string | null;
        overlaps: string[];
    };
}>();

const emit = defineEmits<{
    'open-case': [classUlid: string, studentUlid: string, category: FollowupView];
}>();

// --- O âmbito (ano, turma, período ou datas) ------------------------------------

const when = reactive({ mode: props.filters.mode, period: props.filters.period, from: props.filters.from, to: props.filters.to });

watch(
    () => props.filters,
    (filters) => {
        Object.assign(when, { mode: filters.mode, period: filters.period, from: filters.from, to: filters.to });
    },
);

const loading = ref(false);

function visit(query: Record<string, string>, full = false): void {
    loading.value = true;
    // A synchronous visit: a newer choice cancels the one still loading, so an
    // old answer never overwrites the latest class, period or dates.
    router.get('/records', query, {
        preserveScroll: true,
        preserveState: !full,
        ...(full ? {} : { only: ['panorama', 'context', 'filters', 'openCase'] }),
        onFinish: () => {
            loading.value = false;
        },
    });
}

function chooseClass(ulid: string): void {
    visit(tabQuery({ ...props.filters, class: ulid }, 'panorama'));
}

function chooseYear(event: Event): void {
    const year = (event.target as HTMLSelectElement).value;
    visit({ tab: 'panorama', year }, true);
}

function applyWindow(): void {
    visit(tabQuery({ ...props.filters, mode: when.mode, period: when.period, from: when.from, to: when.to }, 'panorama'));
}

function onPeriodChange(): void {
    if (when.mode === 'period') {
        applyWindow();
    }
}

const currentClass = computed(() => props.options.classes.find((option) => option.ulid === props.filters.class) ?? null);

const periodDates = (period: PeriodOption): string => `${formatDay(period.starts_on)} a ${formatDay(period.ends_on)}`;

// --- A tabela ----------------------------------------------------------------

const sort = reactive<{ key: SortKey; direction: 'asc' | 'desc' }>({ key: 'number', direction: 'asc' });
const onlyWithOccurrences = ref(false);
const query = ref('');

function normalise(text: string): string {
    return text.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase().trim();
}

function setSort(key: SortKey): void {
    if (sort.key === key) {
        sort.direction = sort.direction === 'asc' ? 'desc' : 'asc';

        return;
    }

    sort.key = key;
    // An indicator is read from the most to the fewest; a name or number in order.
    sort.direction = key === 'number' || key === 'name' ? 'asc' : 'desc';
}

function compareBase(left: PanoramaRow, right: PanoramaRow): number {
    return (left.number ?? Number.MAX_SAFE_INTEGER) - (right.number ?? Number.MAX_SAFE_INTEGER) || left.name.localeCompare(right.name, 'pt') || left.ulid.localeCompare(right.ulid);
}

const visibleRows = computed<PanoramaRow[]>(() => {
    const rows = props.panorama?.rows ?? [];
    const needle = normalise(query.value);
    const filtered = rows.filter(
        (row) => (!onlyWithOccurrences.value || row.has_occurrences) && (needle === '' || normalise(row.name).includes(needle) || String(row.number ?? '') === needle),
    );
    const direction = sort.direction === 'asc' ? 1 : -1;

    return [...filtered].sort((left, right) => {
        if (sort.key === 'number') {
            return direction * compareBase(left, right);
        }

        if (sort.key === 'name') {
            return direction * (left.name.localeCompare(right.name, 'pt') || compareBase(left, right));
        }

        const a = left.counts[sort.key];
        const b = right.counts[sort.key];

        // «—» and «n.a.» never compete with numbers: always at the end.
        if (a === null || b === null) {
            return a === b ? compareBase(left, right) : a === null ? 1 : -1;
        }

        return direction * (a - b) || compareBase(left, right);
    });
});

function ariaSort(key: SortKey): 'ascending' | 'descending' | 'none' {
    return sort.key === key ? (sort.direction === 'asc' ? 'ascending' : 'descending') : 'none';
}

function cellText(row: PanoramaRow, column: PanoramaColumn): string {
    const value = row.counts[column];

    if (value !== null) {
        return String(value);
    }

    return row.missing[column] === 'not_applicable' ? 'n.a.' : '—';
}

function cellTitle(row: PanoramaRow, column: PanoramaColumn): string {
    const value = row.counts[column];

    if (value === null) {
        if (row.missing[column] === 'not_applicable') {
            return 'Não aplicável: a assiduidade das aulas não está disponível nesta conta.';
        }

        return column === 'homework_not_done' ? 'Sem verificações de TPC neste período.' : 'Sem assiduidade lançada para este aluno neste período.';
    }

    if (column === 'absences') {
        return value === 0 ? 'Nenhuma falta registada nas aulas lançadas.' : `${value} ${value === 1 ? 'falta' : 'faltas'} em ${daysLabel(row.absence_days)}.`;
    }

    if (column === 'homework_not_done') {
        return `${row.homework.verified} ${row.homework.verified === 1 ? 'verificação' : 'verificações'}: ${row.homework.done} realizou, ${row.homework.partially_done} parcialmente, ${row.homework.not_done} não realizou.`;
    }

    return value === 0 ? 'Nenhuma registada.' : '';
}

function statusOf(row: PanoramaRow, column: PanoramaColumn): CaseStatus | null {
    return column === 'absences' ? null : (row.statuses[column] ?? null);
}

function enrolmentNote(row: PanoramaRow): string | null {
    if (!row.partial) {
        return null;
    }

    if (row.left_on) {
        return `saiu a ${formatDay(row.left_on)}`;
    }

    return `entrou a ${formatDay(row.enrolled_on)}`;
}

function open(row: PanoramaRow, column: PanoramaColumn): void {
    if (props.filters.class === null) {
        return;
    }

    emit('open-case', props.filters.class, row.ulid, column);
}

const totals = computed(() => props.panorama?.totals ?? null);
const meta = computed(() => props.panorama?.meta ?? null);
const withOccurrences = computed(() => (props.panorama?.rows ?? []).filter((row) => row.has_occurrences).length);
</script>

<template>
    <div class="space-y-4" data-test="panorama-tab">
        <!-- O âmbito -->
        <div class="card-soft space-y-3 rounded-2xl border border-border/70 bg-card p-4">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <label class="text-sm">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Ano letivo</span>
                    <select :value="filters.year" class="min-h-11 w-full rounded-md border border-input bg-background px-3 text-sm" data-test="panorama-year" @change="chooseYear">
                        <option v-for="year in years" :key="year.ulid" :value="year.ulid">{{ year.label }}</option>
                    </select>
                </label>
                <label v-if="filters.class !== null" class="text-sm">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Turma e disciplina</span>
                    <select :value="filters.class" class="min-h-11 w-full rounded-md border border-input bg-background px-3 text-sm" data-test="panorama-class" @change="chooseClass(($event.target as HTMLSelectElement).value)">
                        <option v-for="option in options.classes" :key="option.ulid" :value="option.ulid">
                            {{ option.label }} · {{ option.subject }}{{ option.archived ? ' (arquivada)' : '' }}
                        </option>
                    </select>
                </label>
                <!-- min-w-0: a fieldset never shrinks below its content by default,
                and a long period label would widen the whole grid on a phone. -->
                <fieldset class="min-w-0 text-sm sm:col-span-2" :disabled="filters.class === null">
                    <legend class="mb-1 block text-xs font-medium text-muted-foreground">Período</legend>
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="inline-flex rounded-lg border border-border p-0.5" role="radiogroup" aria-label="Período ou intervalo de datas">
                            <button
                                type="button"
                                role="radio"
                                :aria-checked="when.mode === 'period'"
                                class="min-h-11 rounded-md px-3 text-sm"
                                :class="when.mode === 'period' ? 'bg-accent font-medium text-accent-foreground' : 'text-muted-foreground hover:bg-muted/40'"
                                data-test="panorama-mode-period"
                                @click="when.mode = 'period'"
                            >
                                Período
                            </button>
                            <button
                                type="button"
                                role="radio"
                                :aria-checked="when.mode === 'range'"
                                class="min-h-11 rounded-md px-3 text-sm"
                                :class="when.mode === 'range' ? 'bg-accent font-medium text-accent-foreground' : 'text-muted-foreground hover:bg-muted/40'"
                                data-test="panorama-mode-range"
                                @click="when.mode = 'range'"
                            >
                                Datas
                            </button>
                        </div>
                        <select
                            v-if="when.mode === 'period'"
                            v-model="when.period"
                            class="min-h-11 w-full min-w-0 flex-1 rounded-md border border-input bg-background px-3 text-sm disabled:opacity-60 sm:w-auto"
                            :disabled="options.periods.length === 0"
                            aria-label="Período de avaliação"
                            data-test="panorama-period"
                            @change="onPeriodChange"
                        >
                            <option :value="null">{{ options.periods.length === 0 ? 'Sem períodos configurados' : 'Escolha um período' }}</option>
                            <option v-for="period in options.periods" :key="period.ulid" :value="period.ulid">
                                {{ period.label }} ({{ periodDates(period) }}){{ period.is_current ? ' — em curso' : '' }}
                            </option>
                        </select>
                        <template v-else>
                            <input v-model="when.from" type="date" aria-label="De" class="min-h-11 rounded-md border border-input bg-background px-3 text-sm" data-test="panorama-from" />
                            <input v-model="when.to" type="date" aria-label="Até" class="min-h-11 rounded-md border border-input bg-background px-3 text-sm" data-test="panorama-to" />
                            <button type="button" class="min-h-11 rounded-md bg-primary px-3 text-sm font-medium text-primary-foreground" data-test="panorama-apply" @click="applyWindow">Aplicar</button>
                        </template>
                    </div>
                </fieldset>
            </div>
        </div>

        <ClassPicker v-if="filters.class === null" :classes="options.classes" :include-archived="true" title="Escolha a turma para ver o panorama" @select="chooseClass" />

        <template v-else>
            <div class="space-y-2">
                <p class="text-sm" data-test="panorama-context">
                    <span class="font-medium">{{ currentClass?.label }} · {{ currentClass?.subject }}</span>
                    <template v-if="context.window_label"> · {{ context.window_label }}</template>
                    <span v-if="context.period_is_current" class="text-muted-foreground"> — período em curso</span>
                </p>
                <p v-if="context.periods_notice" class="rounded-lg border border-border bg-muted/30 px-3 py-2 text-sm" role="note" data-test="panorama-periods-notice">{{ context.periods_notice }}</p>
                <p v-for="overlap in context.overlaps" :key="overlap" class="rounded-lg border border-border bg-muted/30 px-3 py-2 text-sm" role="note">
                    Os períodos {{ overlap }} sobrepõem-se: uma ocorrência nesses dias conta só no primeiro.
                </p>
                <p v-if="context.window_problem" class="text-sm text-destructive" role="alert">{{ context.window_problem }}</p>
                <p v-else-if="context.needs_window" class="rounded-lg border border-dashed border-border px-3 py-2 text-sm" data-test="panorama-needs-window">
                    Hoje não cai em nenhum período de avaliação configurado. Escolha um período, ou um intervalo de datas e «Aplicar». O ano inteiro nunca é assumido como período.
                </p>
            </div>

            <template v-if="panorama">
                <div class="flex flex-wrap items-center gap-3">
                    <label class="relative w-full min-w-0 sm:w-auto sm:max-w-xs sm:flex-1">
                        <span class="sr-only">Procurar aluno no panorama</span>
                        <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
                        <input
                            v-model="query"
                            type="search"
                            class="min-h-11 w-full rounded-md border border-input bg-background pr-3 pl-9 text-sm"
                            placeholder="Procurar aluno (nome ou n.º)"
                            data-test="panorama-search"
                        />
                    </label>
                    <label class="inline-flex min-h-11 items-center gap-2 text-sm">
                        <input v-model="onlyWithOccurrences" type="checkbox" class="size-4" data-test="panorama-only-occurrences" />
                        Só alunos com ocorrências ({{ withOccurrences }})
                    </label>
                    <span v-if="loading" class="text-xs text-muted-foreground" aria-live="polite">A atualizar…</span>
                </div>

                <p class="text-xs text-muted-foreground md:hidden" data-test="panorama-scroll-hint">Deslize a tabela para o lado para ver os quatro indicadores →</p>

                <div class="relative overflow-x-auto rounded-xl border border-border bg-card" data-test="panorama-table-wrapper">
                    <table class="w-full min-w-[34rem] border-collapse text-sm" data-test="panorama-table">
                        <caption class="sr-only">Panorama da turma: TPC não realizados, faltas de material, atrasos e faltas de presença por aluno</caption>
                        <thead class="bg-muted text-xs text-muted-foreground">
                            <tr>
                                <th scope="col" class="w-12 px-2 py-2 text-left font-medium" :aria-sort="ariaSort('number')">
                                    <button type="button" class="inline-flex min-h-11 items-center gap-1" data-test="sort-number" @click="setSort('number')">
                                        N.º <component :is="sort.key === 'number' ? (sort.direction === 'asc' ? ArrowUp : ArrowDown) : ArrowUpDown" class="size-3" aria-hidden="true" />
                                    </button>
                                </th>
                                <th scope="col" class="sticky left-0 z-10 bg-muted px-2 py-2 text-left font-medium" :aria-sort="ariaSort('name')">
                                    <button type="button" class="inline-flex min-h-11 items-center gap-1" data-test="sort-name" @click="setSort('name')">
                                        Nome <component :is="sort.key === 'name' ? (sort.direction === 'asc' ? ArrowUp : ArrowDown) : ArrowUpDown" class="size-3" aria-hidden="true" />
                                    </button>
                                </th>
                                <th v-for="column in PANORAMA_COLUMNS" :key="column.key" scope="col" class="px-2 py-2 text-right font-medium" :aria-sort="ariaSort(column.key)">
                                    <button type="button" class="ml-auto inline-flex min-h-11 items-center gap-1 text-right" :data-test="`sort-${column.key}`" @click="setSort(column.key)">
                                        {{ column.label }}
                                        <component :is="sort.key === column.key ? (sort.direction === 'asc' ? ArrowUp : ArrowDown) : ArrowUpDown" class="size-3 shrink-0" aria-hidden="true" />
                                    </button>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="visibleRows.length === 0">
                                <td colspan="6" class="px-3 py-6 text-center text-sm text-muted-foreground" data-test="panorama-empty">
                                    {{ panorama.rows.length === 0 ? 'Nenhum aluno integrou esta turma neste período.' : 'Nenhum aluno corresponde a esta pesquisa.' }}
                                </td>
                            </tr>
                            <tr v-for="row in visibleRows" :key="row.ulid" class="border-t border-border" :data-test="`panorama-row-${row.ulid}`">
                                <td class="px-2 py-1.5 tabular-nums text-muted-foreground">{{ row.number ?? '—' }}</td>
                                <th scope="row" class="sticky left-0 z-10 max-w-[11rem] bg-card px-2 py-1.5 text-left font-medium">
                                    <span class="block truncate" :title="row.name">{{ row.name }}</span>
                                    <span v-if="enrolmentNote(row)" class="block text-xs font-normal text-muted-foreground">{{ enrolmentNote(row) }}</span>
                                </th>
                                <td v-for="column in PANORAMA_COLUMNS" :key="column.key" class="px-2 py-1 text-right tabular-nums" :data-test="`cell-${column.key}`">
                                    <button
                                        v-if="(row.counts[column.key] ?? 0) > 0"
                                        type="button"
                                        class="inline-flex min-h-11 min-w-11 items-center justify-end gap-1.5 rounded-md px-2 font-semibold text-primary hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                        :title="cellTitle(row, column.key)"
                                        :aria-label="`${column.label} de ${studentLabel(row.number, row.name)}: ${row.counts[column.key]}. Ver as datas`"
                                        @click="open(row, column.key)"
                                    >
                                        <span
                                            v-if="statusOf(row, column.key) === 'pending'"
                                            class="size-2 rounded-full bg-amber-500"
                                            aria-hidden="true"
                                            data-test="status-pending"
                                        ></span>
                                        <span v-else-if="statusOf(row, column.key) === 'registered'" class="text-xs text-emerald-700 dark:text-emerald-300" aria-hidden="true" data-test="status-registered">✓</span>
                                        <span class="sr-only" v-if="statusOf(row, column.key) === 'pending'">alerta por tratar,</span>
                                        <span class="sr-only" v-else-if="statusOf(row, column.key) === 'registered'">registado no Inovar,</span>
                                        {{ row.counts[column.key] }}
                                        <span v-if="column.key === 'absences'" class="hidden text-xs font-normal text-muted-foreground sm:inline">· {{ daysLabel(row.absence_days) }}</span>
                                    </button>
                                    <span v-else class="inline-flex min-h-11 items-center px-2 text-muted-foreground" :title="cellTitle(row, column.key)">{{ cellText(row, column.key) }}</span>
                                </td>
                            </tr>
                        </tbody>
                        <tfoot v-if="totals && visibleRows.length > 0" class="border-t-2 border-border bg-muted text-sm font-medium">
                            <tr>
                                <td class="px-2 py-2"></td>
                                <th scope="row" class="sticky left-0 z-10 bg-muted px-2 py-2 text-left">Total da turma</th>
                                <td v-for="column in PANORAMA_COLUMNS" :key="column.key" class="px-2 py-2 text-right tabular-nums" :data-test="`total-${column.key}`">
                                    {{ column.key === 'absences' && meta && !meta.attendance_applicable ? 'n.a.' : totals[column.key] }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="space-y-1 text-xs text-muted-foreground" data-test="panorama-notes">
                    <p>
                        <strong class="font-medium text-foreground">0</strong> = nenhuma ocorrência registada (não prova cumprimento) ·
                        <strong class="font-medium text-foreground">—</strong> = sem dados lançados ·
                        <strong class="font-medium text-foreground">n.a.</strong> = não aplicável ·
                        <span class="inline-block size-2 rounded-full bg-amber-500 align-middle" aria-hidden="true"></span> alerta por tratar ·
                        <span class="text-emerald-700 dark:text-emerald-300" aria-hidden="true">✓</span> registado no Inovar. Toque num número para ver as datas.
                    </p>
                    <p v-if="meta" data-test="panorama-homework-note">
                        TPC: {{ meta.homework_days === 0 ? 'nenhum dia com TPC marcado neste período — «—» é «sem verificações».' : `TPC marcado em ${daysLabel(meta.homework_days)} neste período; só «Não realizou» entra na contagem.` }}
                    </p>
                    <p v-if="meta && meta.attendance_applicable" data-test="panorama-attendance-note">
                        Faltas de presença: assiduidade lançada em {{ meta.lessons_counted === 1 ? '1 aula' : `${meta.lessons_counted} aulas` }}<template v-if="meta.lessons_not_recorded > 0">; {{ meta.lessons_not_recorded === 1 ? '1 aula dada ainda sem assiduidade lançada' : `${meta.lessons_not_recorded} aulas dadas ainda sem assiduidade lançada` }} (não conta como falta)</template>.
                        Uma falta é uma aula; a aplicação não distingue faltas justificadas de injustificadas.
                    </p>
                    <p v-else-if="meta" data-test="panorama-attendance-note">Faltas de presença: a assiduidade das aulas não está disponível nesta conta.</p>
                </div>
            </template>
        </template>
    </div>
</template>
