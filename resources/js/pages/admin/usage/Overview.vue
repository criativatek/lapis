<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import type { ChartConfiguration } from 'chart.js';
import { computed, defineAsyncComponent } from 'vue';
import UsageCoverageNotice from '@/components/admin/usage/UsageCoverageNotice.vue';
import UsageDefinitions from '@/components/admin/usage/UsageDefinitions.vue';
import UsageFilters from '@/components/admin/usage/UsageFilters.vue';
import UsageHeader from '@/components/admin/usage/UsageHeader.vue';
import UsageTile from '@/components/admin/usage/UsageTile.vue';
import TableShell from '@/components/TableShell.vue';
import { categoryAxis, chromeColours } from '@/lib/chartTheme';
import { commonQuery, dash, formatNumber, formatRange, itemsBreakdown, ofTotal, statusLabel, withQuery } from '@/lib/usageFormat';
import type { UsageOverviewProps, WeekStatus } from '@/types/usage';

/**
 * Visão geral do painel de utilização.
 *
 * Um período sem recolha nunca é um zero: aparece como «sem recolha» ou «—», e
 * no gráfico não tem barra. Uma percentagem só aparece com o denominador ao lado.
 */
const props = defineProps<UsageOverviewProps>();

const StatChart = defineAsyncComponent(() => import('@/components/charts/StatChart.vue'));

const query = computed(() => commonQuery(props.filters));

// --- Mosaicos -------------------------------------------------------------

function activeTile(tile: { value: number | null; observed_days: number; window_days: number }): { value: string; hint?: string } {
    if (tile.value === null) {
        return { value: 'Sem recolha' };
    }

    return {
        value: formatNumber(tile.value),
        hint: tile.observed_days < tile.window_days ? `recolha em ${tile.observed_days} de ${tile.window_days} dias` : undefined,
    };
}

const active7 = computed(() => activeTile(props.headline.active_7));
const active30 = computed(() => activeTile(props.headline.active_30));

const firstRecord = computed(() => {
    const { done, of, before_collection: before } = props.headline.first_useful_record;
    const note = before > 0 ? `${formatNumber(before)} ${before === 1 ? 'conta anterior' : 'contas anteriores'} à recolha, fora do denominador.` : undefined;

    if (of <= 0) {
        return { value: '—', hint: ['Sem contas criadas depois do início da recolha.', note].filter(Boolean).join(' ') };
    }

    return { value: ofTotal(done, of), hint: note };
});

// --- Gráfico semanal ------------------------------------------------------

const PARTIAL: WeekStatus[] = ['in_progress', 'partial_period', 'partial_coverage'];

const hasInProgress = computed(() => props.weeks.some((week) => week.status === 'in_progress'));
const hasPartial = computed(() => props.weeks.some((week) => week.status === 'partial_period' || week.status === 'partial_coverage'));

const chartConfig = computed<ChartConfiguration>(() => {
    const chrome = chromeColours();
    const primary =
        typeof getComputedStyle === 'function' ? getComputedStyle(document.documentElement).getPropertyValue('--primary').trim() || '#2563eb' : '#2563eb';

    return {
        type: 'bar',
        data: {
            // Duas linhas no rótulo de uma semana sem recolha: o texto diz o que a ausência de barra significa.
            labels: props.weeks.map((week) =>
                week.status === 'no_coverage' ? [formatRange(week.start, week.end), 'sem recolha'] : formatRange(week.start, week.end),
            ),
            datasets: [
                {
                    label: 'Utilizadores ativos',
                    data: props.weeks.map((week) => (week.status === 'no_coverage' ? null : week.active_users)),
                    backgroundColor: props.weeks.map((week) => (PARTIAL.includes(week.status) ? 'rgba(120, 128, 144, 0.45)' : primary)),
                    borderRadius: 4,
                    maxBarThickness: 36,
                },
            ],
        },
        options: {
            scales: {
                x: categoryAxis(chrome),
                y: { beginAtZero: true, ticks: { precision: 0, color: chrome.muted }, grid: { color: chrome.grid }, border: { display: false } },
            },
        },
    } as unknown as ChartConfiguration;
});

// --- Sinais ---------------------------------------------------------------

function signalHref(key: string): string {
    return withQuery('/admin/usage/users', { ...query.value, signal: key });
}

// --- Coortes --------------------------------------------------------------

const cohortOffsets = computed(() => {
    const offsets = new Set<number>();

    props.cohorts.forEach((cohort) => cohort.weeks.forEach((week) => offsets.add(week.offset)));

    const sorted = [...offsets].sort((a, b) => a - b);

    return sorted.length > 0 ? sorted : [0, 1, 2, 3, 4];
});

function offsetLabel(offset: number): string {
    return offset === 0 ? 'Semana 0' : `Semana +${offset}`;
}

function cohortCell(cohort: UsageOverviewProps['cohorts'][number], offset: number) {
    return cohort.weeks.find((week) => week.offset === offset) ?? null;
}

function percent(returned: number, size: number): string {
    return `${Math.round((returned / size) * 100)}%`;
}

const numberCell = 'px-3 py-2 text-right tabular-nums';
const headCell = 'px-3 py-2 font-medium';
</script>

<template>
    <Head title="Utilização — Visão geral" />

    <div class="space-y-6 p-4 md:p-6">
        <UsageHeader :filters="filters" active="overview" />
        <UsageFilters path="/admin/usage" :filters="filters" :options="options" />
        <UsageCoverageNotice :coverage="coverage" />
        <UsageDefinitions :definitions="definitions" />

        <!-- Mosaicos -->
        <section aria-labelledby="headline-title" class="space-y-3">
            <h2 id="headline-title" class="text-lg font-semibold">Em resumo</h2>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                <UsageTile label="Ativos — últimos 7 dias" :value="active7.value" :hint="active7.hint" />
                <UsageTile label="Ativos — últimos 30 dias" :value="active30.value" :hint="active30.hint" />
                <UsageTile label="Ativos no período" :value="dash(headline.active_period)" :hint="headline.active_period === null ? 'Sem recolha no período' : undefined" />
                <UsageTile label="Novas contas no período" :value="formatNumber(headline.new_accounts)" />
                <UsageTile label="Primeiro registo útil" :value="firstRecord.value" :hint="firstRecord.hint" />
            </div>
        </section>

        <!-- Evolução semanal -->
        <section aria-labelledby="weeks-title" class="space-y-3">
            <h2 id="weeks-title" class="text-lg font-semibold">Evolução semanal</h2>
            <p class="text-sm text-muted-foreground">Utilizadores ativos em cada semana (segunda a domingo).</p>

            <div class="rounded-xl border border-border bg-card p-4">
                <!-- A tabela abaixo é a alternativa acessível: o gráfico não repete os números a quem não o vê. -->
                <div aria-hidden="true">
                    <StatChart
                        :config="chartConfig"
                        summary="Utilizadores ativos por semana. A tabela seguinte traz os mesmos números."
                        :headers="['Semana', 'Utilizadores ativos']"
                        :rows="[]"
                        height-class="h-64"
                    />
                </div>
                <ul class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                    <li class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-primary" aria-hidden="true"></span>Semana completa</li>
                    <li v-if="hasInProgress" class="flex items-center gap-1.5">
                        <span class="size-2.5 rounded-sm bg-muted-foreground/50" aria-hidden="true"></span>Semana em curso
                    </li>
                    <li v-if="hasPartial" class="flex items-center gap-1.5">
                        <span class="size-2.5 rounded-sm bg-muted-foreground/50" aria-hidden="true"></span>Semana parcial
                    </li>
                    <li>Sem barra = sem recolha</li>
                </ul>
            </div>

            <TableShell>
                <template #head>
                    <tr>
                        <th scope="col" :class="headCell">Semana</th>
                        <th scope="col" :class="headCell">Estado</th>
                        <th scope="col" :class="[headCell, 'text-right']">Utilizadores ativos</th>
                        <th scope="col" :class="[headCell, 'text-right']">Dias ativos</th>
                        <th scope="col" :class="[headCell, 'text-right']">Ações</th>
                        <th scope="col" :class="[headCell, 'text-right']">Elementos afetados</th>
                    </tr>
                </template>
                <template #body>
                    <tr v-for="week in weeks" :key="week.start">
                        <th scope="row" class="px-3 py-2 text-left font-normal tabular-nums">{{ formatRange(week.start, week.end) }}</th>
                        <td class="px-3 py-2" :class="week.status === 'complete' ? '' : 'text-muted-foreground'">{{ statusLabel(week.status) }}</td>
                        <td :class="numberCell">{{ dash(week.active_users) }}</td>
                        <td :class="numberCell">{{ dash(week.active_user_days) }}</td>
                        <td :class="numberCell">{{ dash(week.actions) }}</td>
                        <td :class="numberCell">{{ dash(week.items) }}</td>
                    </tr>
                    <tr v-if="weeks.length === 0">
                        <td colspan="6" class="px-3 py-8 text-center text-muted-foreground">Sem semanas neste período.</td>
                    </tr>
                </template>
            </TableShell>
        </section>

        <!-- Meses -->
        <section aria-labelledby="months-title" class="space-y-3">
            <h2 id="months-title" class="text-lg font-semibold">Evolução mensal</h2>
            <TableShell>
                <template #head>
                    <tr>
                        <th scope="col" :class="headCell">Mês</th>
                        <th scope="col" :class="headCell">Estado</th>
                        <th scope="col" :class="[headCell, 'text-right']">Utilizadores ativos</th>
                    </tr>
                </template>
                <template #body>
                    <tr v-for="month in months" :key="month.month">
                        <th scope="row" class="px-3 py-2 text-left font-normal">{{ month.label }}</th>
                        <td class="px-3 py-2" :class="month.status === 'complete' ? '' : 'text-muted-foreground'">{{ statusLabel(month.status, 'month') }}</td>
                        <td :class="numberCell">{{ dash(month.active_users) }}</td>
                    </tr>
                    <tr v-if="months.length === 0">
                        <td colspan="3" class="px-3 py-8 text-center text-muted-foreground">Sem meses neste período.</td>
                    </tr>
                </template>
            </TableShell>
        </section>

        <!-- Funcionalidades -->
        <section aria-labelledby="features-title" class="space-y-3">
            <h2 id="features-title" class="text-lg font-semibold">Utilização por funcionalidade</h2>
            <p class="text-sm text-muted-foreground">Os elementos afetados contam-se à parte: nunca são somados às ações.</p>
            <TableShell>
                <template #head>
                    <tr>
                        <th scope="col" :class="headCell">Funcionalidade</th>
                        <th scope="col" :class="[headCell, 'text-right']">Utilizadores</th>
                        <th scope="col" :class="[headCell, 'text-right']">Com ações concluídas</th>
                        <th scope="col" :class="[headCell, 'text-right']">Ações</th>
                        <th scope="col" :class="[headCell, 'text-right']">Elementos afetados</th>
                        <th scope="col" :class="[headCell, 'text-right']">Falhas técnicas</th>
                    </tr>
                </template>
                <template #body>
                    <tr v-for="feature in features" :key="feature.key">
                        <th scope="row" class="px-3 py-2 text-left font-medium">{{ feature.label }}</th>
                        <td :class="numberCell">{{ formatNumber(feature.users) }}</td>
                        <td :class="numberCell">{{ formatNumber(feature.users_with_actions) }}</td>
                        <td :class="numberCell">{{ formatNumber(feature.actions) }}</td>
                        <td :class="numberCell">
                            {{ formatNumber(feature.items) }}
                            <span v-if="itemsBreakdown(feature)" class="block text-xs font-normal text-muted-foreground">{{ itemsBreakdown(feature) }}</span>
                        </td>
                        <td :class="numberCell">{{ formatNumber(feature.failures) }}</td>
                    </tr>
                    <tr v-if="features.length === 0">
                        <td colspan="6" class="px-3 py-8 text-center text-muted-foreground">Sem utilização observada neste período.</td>
                    </tr>
                </template>
            </TableShell>
        </section>

        <!-- Sinais -->
        <section aria-labelledby="signals-title" class="space-y-3">
            <h2 id="signals-title" class="text-lg font-semibold">Sinais de acompanhamento</h2>
            <p class="text-sm text-muted-foreground">Sugestões de verificação, não diagnósticos. Nada é enviado automaticamente.</p>
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                <article v-for="signal in signals" :key="signal.key" class="flex flex-col gap-2 rounded-xl border border-border bg-card p-4">
                    <h3 class="font-medium">{{ signal.label }}</h3>
                    <p class="text-sm text-muted-foreground">{{ signal.description }}</p>
                    <p class="text-2xl font-semibold tabular-nums">{{ formatNumber(signal.count) }}</p>
                    <p v-if="signal.not_evaluated > 0" class="text-xs text-muted-foreground">
                        {{ formatNumber(signal.not_evaluated) }} por avaliar (recolha insuficiente)
                    </p>
                    <Link
                        :href="signalHref(signal.key)"
                        class="mt-auto flex min-h-11 items-center text-sm font-medium text-primary underline-offset-4 hover:underline md:min-h-9"
                    >
                        Ver lista
                    </Link>
                </article>
            </div>
        </section>

        <!-- Coortes -->
        <section aria-labelledby="cohorts-title" class="space-y-3">
            <h2 id="cohorts-title" class="text-lg font-semibold">Regresso nas semanas seguintes à adesão</h2>
            <p class="text-sm text-muted-foreground">
                Conta só as contas criadas depois do início da recolha. Cada célula diz quantas pessoas da coorte tiveram atividade nessa semana.
            </p>
            <TableShell>
                <template #head>
                    <tr>
                        <th scope="col" :class="headCell">Semana de adesão</th>
                        <th scope="col" :class="[headCell, 'text-right']">Contas</th>
                        <th v-for="offset in cohortOffsets" :key="offset" scope="col" :class="[headCell, 'text-right']">{{ offsetLabel(offset) }}</th>
                    </tr>
                </template>
                <template #body>
                    <tr v-for="cohort in cohorts" :key="cohort.week_start">
                        <th scope="row" class="px-3 py-2 text-left font-normal tabular-nums">{{ formatRange(cohort.week_start, cohort.week_end) }}</th>
                        <td :class="numberCell">{{ formatNumber(cohort.size) }}</td>
                        <td v-for="offset in cohortOffsets" :key="offset" class="px-3 py-2 text-right tabular-nums">
                            <template v-if="cohortCell(cohort, offset)?.status === 'complete'">
                                {{ formatNumber(cohortCell(cohort, offset)?.returned ?? 0) }} de {{ formatNumber(cohort.size) }}
                                <span v-if="cohort.size > 0" class="text-muted-foreground">({{ percent(cohortCell(cohort, offset)?.returned ?? 0, cohort.size) }})</span>
                            </template>
                            <template v-else-if="cohortCell(cohort, offset)?.status === 'partial_coverage'">
                                {{ formatNumber(cohortCell(cohort, offset)?.returned ?? 0) }} de {{ formatNumber(cohort.size) }}
                                <span class="ml-1 rounded-full border border-border px-1.5 py-0.5 text-xs text-muted-foreground">recolha parcial</span>
                            </template>
                            <span v-else-if="cohortCell(cohort, offset)?.status === 'no_coverage'" class="text-muted-foreground">sem recolha</span>
                            <template v-else-if="cohortCell(cohort, offset)?.status === 'in_progress'">
                                {{ formatNumber(cohortCell(cohort, offset)?.returned ?? 0) }} até agora
                                <span class="ml-1 rounded-full border border-border px-1.5 py-0.5 text-xs text-muted-foreground">em curso</span>
                            </template>
                            <span v-else class="text-muted-foreground">ainda não decorreu</span>
                        </td>
                    </tr>
                    <tr v-if="cohorts.length === 0">
                        <td :colspan="2 + cohortOffsets.length" class="px-3 py-8 text-center text-muted-foreground">
                            Ainda não há contas criadas depois do início da recolha.
                        </td>
                    </tr>
                </template>
            </TableShell>
        </section>
    </div>
</template>
