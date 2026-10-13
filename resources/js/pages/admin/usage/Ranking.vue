<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import UsageCoverageNotice from '@/components/admin/usage/UsageCoverageNotice.vue';
import UsageDefinitions from '@/components/admin/usage/UsageDefinitions.vue';
import UsageFilters from '@/components/admin/usage/UsageFilters.vue';
import UsageHeader from '@/components/admin/usage/UsageHeader.vue';
import UsagePagination from '@/components/admin/usage/UsagePagination.vue';
import UsageSegmented from '@/components/admin/usage/UsageSegmented.vue';
import TableShell from '@/components/TableShell.vue';
import { commonQuery, formatDate, formatNumber, listQuery, withQuery } from '@/lib/usageFormat';
import type { UsageRankingProps, UsageRankingRow } from '@/types/usage';

/**
 * Ranking de regularidade. Sem medalhas, sem cores de pódio, sem pontuação
 * composta: cada critério é um número que se lê e se explica, e o empate
 * mostra a mesma posição.
 */
const props = defineProps<UsageRankingProps>();

const form = reactive({
    sort: props.filters.sort ?? props.sort_options[0]?.key ?? '',
    age: props.filters.age,
});

const extra = computed(() => ({ sort: form.sort, age: form.age === 'all' ? '' : form.age }));

function apply(): void {
    router.get(
        '/admin/usage/ranking',
        { ...commonQuery(props.filters), ...Object.fromEntries(Object.entries(extra.value).filter(([, value]) => value)) },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function setSort(key: string): void {
    form.sort = key;
    apply();
}

function positionText(row: UsageRankingRow): string {
    return row.tied ? `=${row.position}` : String(row.position);
}

function detailHref(row: UsageRankingRow): string {
    return withQuery(`/admin/usage/users/${row.ulid}`, listQuery(props.filters));
}

function planText(row: UsageRankingRow): string {
    return row.plans.length > 0 ? row.plans.join(', ') : '—';
}

function ageText(row: UsageRankingRow): string {
    return `Criada em ${formatDate(row.joined_on)} (${formatNumber(row.account_age_days)} ${row.account_age_days === 1 ? 'dia' : 'dias'})`;
}

function partialText(row: UsageRankingRow): string {
    return `observação parcial: ${formatNumber(row.observation_days)} de ${formatNumber(row.period_days)} dias`;
}

const fieldClass =
    'w-full rounded-md border border-input bg-background px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 h-9 max-md:h-11';
const headCell = 'px-3 py-2 font-medium';
const partialBadge = 'mt-1 inline-block rounded-full border border-border bg-muted px-2 py-0.5 text-xs text-muted-foreground';
</script>

<template>
    <Head title="Utilização — Ranking" />

    <div class="space-y-6 p-4 md:p-6">
        <UsageHeader :filters="filters" active="ranking" />

        <p class="rounded-xl border border-border bg-muted/40 px-4 py-3 text-base font-medium">{{ support_text }}</p>

        <UsageFilters path="/admin/usage/ranking" :filters="filters" :options="options" :extra="extra" />

        <div class="grid gap-4 rounded-xl border border-border bg-card p-4 md:grid-cols-[auto_minmax(0,16rem)] md:items-start">
            <div class="space-y-2">
                <p class="text-sm font-medium">Ordenar por</p>
                <UsageSegmented :model-value="form.sort" :options="sort_options" label="Ordenar por" @update:model-value="setSort" />
                <p v-if="form.sort === 'features'" class="text-xs text-muted-foreground">
                    Indicador complementar: usar poucas funcionalidades não é pior.
                </p>
            </div>
            <label class="space-y-1 text-sm">
                <span class="font-medium">Antiguidade da conta</span>
                <select v-model="form.age" :class="fieldClass" @change="apply">
                    <option v-for="age in age_options" :key="age.key" :value="age.key">{{ age.label }}</option>
                </select>
            </label>
        </div>

        <UsageCoverageNotice :coverage="coverage" />
        <UsageDefinitions :definitions="definitions" />

        <section aria-labelledby="ranking-title" class="space-y-3">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="ranking-title" class="text-lg font-semibold">Regularidade de utilização</h2>
                <p class="text-sm text-muted-foreground">{{ formatNumber(total_ranked) }} {{ total_ranked === 1 ? 'pessoa ordenada' : 'pessoas ordenadas' }}</p>
            </div>

            <!-- Telemóvel -->
            <ol class="space-y-3 md:hidden">
                <li v-for="row in ranking.data" :key="row.ulid" class="space-y-2 rounded-xl border border-border bg-card p-4">
                    <div class="flex items-baseline gap-3">
                        <span class="text-lg font-semibold tabular-nums" :aria-label="`Posição ${positionText(row)}`">{{ positionText(row) }}</span>
                        <div class="min-w-0">
                            <Link :href="detailHref(row)" class="block min-h-11 font-medium text-primary hover:underline">{{ row.name }}</Link>
                            <p class="break-all text-sm text-muted-foreground">{{ row.email }}</p>
                        </div>
                    </div>
                    <dl class="grid grid-cols-2 gap-x-3 gap-y-2 text-sm">
                        <div>
                            <dt class="text-xs text-muted-foreground">Plano</dt>
                            <dd>{{ planText(row) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">Conta</dt>
                            <dd>
                                {{ ageText(row) }}
                                <span v-if="row.partial_observation" :class="partialBadge">{{ partialText(row) }}</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">Dias ativos</dt>
                            <dd class="tabular-nums">{{ formatNumber(row.active_days) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">Ações</dt>
                            <dd class="tabular-nums">
                                {{ formatNumber(row.actions) }}
                                <span class="block text-xs text-muted-foreground">{{ formatNumber(row.items) }} elementos afetados</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">Semanas com atividade</dt>
                            <dd class="tabular-nums">{{ formatNumber(row.active_weeks) }} de {{ formatNumber(row.observed_weeks) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">Funcionalidades</dt>
                            <dd class="tabular-nums">{{ formatNumber(row.features) }}</dd>
                        </div>
                    </dl>
                </li>
            </ol>

            <!-- Ecrãs largos -->
            <div class="hidden md:block">
                <TableShell>
                    <template #head>
                        <tr>
                            <th scope="col" :class="headCell">Posição</th>
                            <th scope="col" :class="headCell">Utilizador</th>
                            <th scope="col" :class="headCell">Plano</th>
                            <th scope="col" :class="headCell">Conta</th>
                            <th scope="col" :class="[headCell, 'text-right']">Dias ativos</th>
                            <th scope="col" :class="[headCell, 'text-right']">Ações</th>
                            <th scope="col" :class="[headCell, 'text-right']">Semanas com atividade</th>
                            <th scope="col" :class="[headCell, 'text-right']">Funcionalidades</th>
                        </tr>
                    </template>
                    <template #body>
                        <tr v-for="row in ranking.data" :key="row.ulid" class="align-top hover:bg-muted/20">
                            <td class="px-3 py-2 font-semibold tabular-nums">{{ positionText(row) }}</td>
                            <td class="px-3 py-2">
                                <Link :href="detailHref(row)" class="font-medium text-primary hover:underline">{{ row.name }}</Link>
                                <div class="text-xs text-muted-foreground">{{ row.email }}</div>
                            </td>
                            <td class="px-3 py-2">{{ planText(row) }}</td>
                            <td class="px-3 py-2">
                                {{ ageText(row) }}
                                <div v-if="row.partial_observation"><span :class="partialBadge">{{ partialText(row) }}</span></div>
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ formatNumber(row.active_days) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ formatNumber(row.actions) }}
                                <div class="text-xs text-muted-foreground">{{ formatNumber(row.items) }} elementos afetados</div>
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ formatNumber(row.active_weeks) }} de {{ formatNumber(row.observed_weeks) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ formatNumber(row.features) }}</td>
                        </tr>
                    </template>
                </TableShell>
            </div>

            <p v-if="ranking.data.length === 0" class="rounded-xl border border-border px-3 py-8 text-center text-sm text-muted-foreground">
                Nenhuma pessoa com atividade observada corresponde aos filtros.
            </p>

            <UsagePagination :links="ranking.links" />

            <p v-if="unranked !== null" class="text-sm text-muted-foreground">
                {{ formatNumber(unranked) }} {{ unranked === 1 ? 'pessoa sem atividade observada no período não é ordenada' : 'pessoas sem atividade observada no período não são ordenadas' }}.
            </p>
            <p v-else class="text-sm text-muted-foreground">Não há recolha neste período: ninguém é ordenado.</p>
        </section>
    </div>
</template>
