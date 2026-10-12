<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { computed } from 'vue';
import UsageCoverageNotice from '@/components/admin/usage/UsageCoverageNotice.vue';
import UsageDefinitions from '@/components/admin/usage/UsageDefinitions.vue';
import UsageHeader from '@/components/admin/usage/UsageHeader.vue';
import UsageSignalBadge from '@/components/admin/usage/UsageSignalBadge.vue';
import UsageTile from '@/components/admin/usage/UsageTile.vue';
import TableShell from '@/components/TableShell.vue';
import { dash, formatDate, formatNumber, formatRange, itemsBreakdown, listQuery, NO_ACTIVITY, statusLabel, withQuery } from '@/lib/usageFormat';
import type { UsageClassificationKey, UsageUserDetailProps } from '@/types/usage';

/**
 * Detalhe de uma pessoa: contagens e datas observadas, nunca conteúdos
 * pedagógicos nem o histórico de cliques.
 */
const props = defineProps<UsageUserDetailProps>();

const CLASSIFICATION_SUFFIX: Record<UsageClassificationKey, string> = {
    real: 'utilização real',
    test: 'teste',
    internal: 'interna',
    unclassified: 'por classificar',
};

const backHref = computed(() => withQuery('/admin/usage/users', listQuery(props.filters)));

const lastActivity = computed(() => (props.summary.last_activity_on ? formatDate(props.summary.last_activity_on) : NO_ACTIVITY));

const numberCell = 'px-3 py-2 text-right tabular-nums';
const headCell = 'px-3 py-2 font-medium';
</script>

<template>
    <Head title="Utilização — Pessoa" />

    <div class="space-y-6 p-4 md:p-6">
        <UsageHeader :filters="filters" active="users" />

        <Link :href="backHref" class="inline-flex min-h-11 items-center gap-1.5 text-sm font-medium text-primary underline-offset-4 hover:underline md:min-h-9">
            <ArrowLeft class="size-4" aria-hidden="true" /> Voltar ao acompanhamento
        </Link>

        <!-- Cabeçalho da pessoa -->
        <section aria-labelledby="person-title" class="space-y-3 rounded-xl border border-border bg-card p-4">
            <div>
                <h2 id="person-title" class="text-lg font-semibold">{{ user.name }}</h2>
                <p class="break-all text-sm text-muted-foreground">{{ user.email }}</p>
            </div>

            <dl class="grid gap-3 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-xs text-muted-foreground">Adesão</dt>
                    <dd>{{ formatDate(user.joined_on) }}</dd>
                </div>
                <div v-if="user.status">
                    <dt class="text-xs text-muted-foreground">Estado</dt>
                    <dd>{{ user.status }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-xs text-muted-foreground">Organizações</dt>
                    <dd>
                        <ul v-if="user.organizations.length" class="space-y-0.5">
                            <li v-for="(organization, index) in user.organizations" :key="index">
                                {{ organization.name }} — {{ organization.plan ?? 'sem plano' }}<span v-if="organization.classification !== 'real'" class="text-muted-foreground"> ({{ CLASSIFICATION_SUFFIX[organization.classification] }})</span>
                            </li>
                        </ul>
                        <span v-else>—</span>
                    </dd>
                </div>
            </dl>

            <div v-if="user.flags.test || user.flags.internal || user.flags.unclassified" class="flex flex-wrap gap-1">
                <span v-if="user.flags.test" class="rounded-full border border-border px-2 py-0.5 text-xs">teste e demonstração</span>
                <span v-if="user.flags.unclassified" class="rounded-full border border-border px-2 py-0.5 text-xs">por classificar</span>
                <span v-if="user.flags.internal" class="rounded-full border border-border px-2 py-0.5 text-xs">equipa interna</span>
            </div>
        </section>

        <UsageCoverageNotice :coverage="coverage" />
        <UsageDefinitions :definitions="definitions" />

        <!-- Primeiros passos -->
        <section aria-labelledby="steps-title" class="space-y-3">
            <h2 id="steps-title" class="text-lg font-semibold">Primeiros passos</h2>
            <ul class="divide-y divide-border rounded-xl border border-border bg-card">
                <li v-for="step in first_steps" :key="step.key" class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 px-4 py-3 text-sm">
                    <span class="font-medium">{{ step.label }}</span>
                    <!-- Com data; atingido sem data (saiu pelo prazo — nunca uma data aproximada); não observado. -->
                    <span v-if="step.observed_on" class="tabular-nums">{{ formatDate(step.observed_on) }}</span>
                    <span v-else-if="step.reached" data-test="step-reached-without-date">Atingido — data já não conservada</span>
                    <span v-else class="text-muted-foreground">Ainda não observado</span>
                    <span v-if="step.note" class="basis-full text-xs text-muted-foreground">{{ step.note }}</span>
                </li>
            </ul>
            <p class="text-sm text-muted-foreground">
                <template v-if="class_available_since.date">
                    Turma disponível desde {{ formatDate(class_available_since.date) }} — configuração, não prova de utilização.
                </template>
                <template v-else>Ainda sem turma disponível — e uma turma é configuração, não prova de utilização.</template>
            </p>
        </section>

        <!-- Sinais -->
        <section aria-labelledby="signals-title" class="space-y-3">
            <h2 id="signals-title" class="text-lg font-semibold">Sinais de acompanhamento</h2>
            <p class="text-sm text-muted-foreground">Sugestões de verificação, não diagnósticos. Nada é enviado automaticamente.</p>
            <div v-if="signals.length" class="grid gap-2 md:grid-cols-2">
                <UsageSignalBadge v-for="signal in signals" :key="signal.key" :signal="signal" />
            </div>
            <p v-else class="text-sm text-muted-foreground">Nenhum sinal neste período.</p>
            <div v-if="not_evaluated.length" class="rounded-md border border-border bg-muted/40 px-3 py-2 text-sm">
                <p class="font-medium">Por avaliar (recolha insuficiente)</p>
                <ul class="list-inside list-disc text-muted-foreground">
                    <li v-for="item in not_evaluated" :key="item.key">{{ item.label }}</li>
                </ul>
            </div>
        </section>

        <!-- Resumo -->
        <section aria-labelledby="summary-title" class="space-y-3">
            <h2 id="summary-title" class="text-lg font-semibold">Resumo do período</h2>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <UsageTile label="Dias ativos" :value="`${formatNumber(summary.active_days)} de ${formatNumber(summary.observation_days)}`" hint="dias observados" />
                <UsageTile label="Semanas com atividade" :value="formatNumber(summary.active_weeks)" />
                <UsageTile label="Ações concluídas" :value="formatNumber(summary.actions)" />
                <UsageTile label="Elementos afetados" :value="formatNumber(summary.items)" hint="contados à parte das ações" />
                <UsageTile label="Falhas técnicas" :value="formatNumber(summary.failures)" />
                <UsageTile label="Última atividade observada (todas as organizações)" :value="lastActivity" />
            </div>
        </section>

        <!-- Semanas -->
        <section aria-labelledby="weeks-title" class="space-y-3">
            <h2 id="weeks-title" class="text-lg font-semibold">Semana a semana</h2>
            <TableShell>
                <template #head>
                    <tr>
                        <th scope="col" :class="headCell">Semana</th>
                        <th scope="col" :class="headCell">Estado</th>
                        <th scope="col" :class="[headCell, 'text-right']">Dias ativos</th>
                        <th scope="col" :class="[headCell, 'text-right']">Ações</th>
                        <th scope="col" :class="[headCell, 'text-right']">Elementos afetados</th>
                        <th scope="col" :class="[headCell, 'text-right']">Falhas técnicas</th>
                        <th scope="col" :class="headCell">Funcionalidades</th>
                    </tr>
                </template>
                <template #body>
                    <tr v-for="week in weeks" :key="week.start">
                        <th scope="row" class="px-3 py-2 text-left font-normal tabular-nums">{{ formatRange(week.start, week.end) }}</th>
                        <td class="px-3 py-2" :class="week.status === 'complete' ? '' : 'text-muted-foreground'">{{ statusLabel(week.status) }}</td>
                        <td :class="numberCell">{{ dash(week.active_days) }}</td>
                        <td :class="numberCell">{{ dash(week.actions) }}</td>
                        <td :class="numberCell">{{ dash(week.items) }}</td>
                        <td :class="numberCell">{{ dash(week.failures) }}</td>
                        <td class="px-3 py-2">{{ week.features.length ? week.features.map((feature) => feature.label).join(', ') : '—' }}</td>
                    </tr>
                    <tr v-if="weeks.length === 0">
                        <td colspan="7" class="px-3 py-8 text-center text-muted-foreground">Sem semanas neste período.</td>
                    </tr>
                </template>
            </TableShell>
        </section>

        <!-- Funcionalidades -->
        <section aria-labelledby="features-title" class="space-y-3">
            <h2 id="features-title" class="text-lg font-semibold">Por funcionalidade</h2>
            <TableShell>
                <template #head>
                    <tr>
                        <th scope="col" :class="headCell">Funcionalidade</th>
                        <th scope="col" :class="[headCell, 'text-right']">Dias ativos</th>
                        <th scope="col" :class="[headCell, 'text-right']">Dias de consulta</th>
                        <th scope="col" :class="[headCell, 'text-right']">Ações</th>
                        <th scope="col" :class="[headCell, 'text-right']">Elementos afetados</th>
                        <th scope="col" :class="[headCell, 'text-right']">Falhas técnicas</th>
                    </tr>
                </template>
                <template #body>
                    <tr v-for="feature in features" :key="feature.key">
                        <th scope="row" class="px-3 py-2 text-left font-medium">{{ feature.label }}</th>
                        <td :class="numberCell">{{ formatNumber(feature.active_days) }}</td>
                        <td :class="numberCell">{{ formatNumber(feature.view_days) }}</td>
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

        <p class="rounded-xl border border-border bg-muted/40 px-4 py-3 text-sm text-muted-foreground">
            Mostra apenas contagens. Não inclui conteúdos pedagógicos nem o histórico de cliques.
        </p>
    </div>
</template>
