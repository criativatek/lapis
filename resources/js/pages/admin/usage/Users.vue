<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import UsageCoverageNotice from '@/components/admin/usage/UsageCoverageNotice.vue';
import UsageDefinitions from '@/components/admin/usage/UsageDefinitions.vue';
import UsageFilters from '@/components/admin/usage/UsageFilters.vue';
import UsageHeader from '@/components/admin/usage/UsageHeader.vue';
import UsagePagination from '@/components/admin/usage/UsagePagination.vue';
import UsageSignalBadge from '@/components/admin/usage/UsageSignalBadge.vue';
import TableShell from '@/components/TableShell.vue';
import { Button } from '@/components/ui/button';
import { commonQuery, formatDate, formatNumber, listQuery, NO_ACTIVITY, withQuery } from '@/lib/usageFormat';
import type { UsageUserRow, UsageUsersProps } from '@/types/usage';

/**
 * Acompanhamento: quem usou o quê, com a razão de cada sinal à vista.
 * Em ecrãs largos uma tabela; no telemóvel, cartões empilhados (sem scroll lateral da página).
 */
const props = defineProps<UsageUsersProps>();

const form = reactive({
    q: props.filters.q ?? '',
    signal: props.filters.signal ?? '',
    sort: props.filters.sort ?? props.sort_options[0]?.key ?? '',
});

const extra = computed(() => ({ q: form.q.trim(), signal: form.signal, sort: form.sort }));

function apply(): void {
    router.get(
        '/admin/usage/users',
        { ...commonQuery(props.filters), ...Object.fromEntries(Object.entries(extra.value).filter(([, value]) => value)) },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function detailHref(user: UsageUserRow): string {
    return withQuery(`/admin/usage/users/${user.ulid}`, listQuery(props.filters));
}

function planText(user: UsageUserRow): string {
    return user.plans.length > 0 ? user.plans.join(', ') : '—';
}

function activityText(user: UsageUserRow): string {
    return user.last_activity_on ? formatDate(user.last_activity_on) : NO_ACTIVITY;
}

function activeDaysText(user: UsageUserRow): string {
    return `${formatNumber(user.active_days)} de ${formatNumber(user.observation_days)} dias observados`;
}

const fieldClass =
    'w-full rounded-md border border-input bg-background px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 h-9 max-md:h-11';
const headCell = 'px-3 py-2 font-medium';
</script>

<template>
    <Head title="Utilização — Acompanhamento" />

    <div class="space-y-6 p-4 md:p-6">
        <UsageHeader :filters="filters" active="users" />
        <UsageFilters path="/admin/usage/users" :filters="filters" :options="options" :extra="extra" />

        <form class="grid gap-3 rounded-xl border border-border bg-card p-4 md:grid-cols-[2fr_1fr_1fr_auto] md:items-end" aria-label="Pesquisa e ordenação" @submit.prevent="apply">
            <label class="space-y-1 text-sm">
                <span class="font-medium">Pesquisar por nome ou email</span>
                <input v-model="form.q" type="search" maxlength="100" :class="fieldClass" />
            </label>
            <label class="space-y-1 text-sm">
                <span class="font-medium">Sinal</span>
                <select v-model="form.signal" :class="fieldClass" @change="apply">
                    <option value="">Todos</option>
                    <option v-for="signal in options.signal_options" :key="signal.key" :value="signal.key">{{ signal.label }}</option>
                </select>
            </label>
            <label class="space-y-1 text-sm">
                <span class="font-medium">Ordenar por</span>
                <select v-model="form.sort" :class="fieldClass" @change="apply">
                    <option v-for="sort in sort_options" :key="sort.key" :value="sort.key">{{ sort.label }}</option>
                </select>
            </label>
            <Button type="submit" class="max-md:min-h-11">Pesquisar</Button>
        </form>

        <UsageCoverageNotice :coverage="coverage" />
        <UsageDefinitions :definitions="definitions" />

        <section aria-labelledby="users-title" class="space-y-3">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="users-title" class="text-lg font-semibold">Pessoas</h2>
                <p class="text-sm text-muted-foreground">{{ formatNumber(users.total) }} {{ users.total === 1 ? 'pessoa' : 'pessoas' }}</p>
            </div>
            <p class="text-sm text-muted-foreground">Os sinais são sugestões de verificação, não diagnósticos. Nada é enviado automaticamente.</p>

            <!-- Telemóvel: um cartão por pessoa. -->
            <ul class="space-y-3 md:hidden">
                <li v-for="user in users.data" :key="user.ulid" class="space-y-3 rounded-xl border border-border bg-card p-4">
                    <div>
                        <Link :href="detailHref(user)" class="block min-h-11 font-medium text-primary hover:underline">{{ user.name }}</Link>
                        <p class="break-all text-sm text-muted-foreground">{{ user.email }}</p>
                        <p v-if="user.status" class="mt-1 text-sm">{{ user.status }}</p>
                    </div>
                    <dl class="grid grid-cols-2 gap-x-3 gap-y-2 text-sm">
                        <div>
                            <dt class="text-xs text-muted-foreground">Adesão</dt>
                            <dd>{{ formatDate(user.joined_on) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">Plano</dt>
                            <dd>{{ planText(user) }}</dd>
                        </div>
                        <div class="col-span-2">
                            <dt class="text-xs text-muted-foreground">Última atividade observada</dt>
                            <dd>{{ activityText(user) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">Dias ativos</dt>
                            <dd>{{ activeDaysText(user) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">Ações</dt>
                            <dd class="tabular-nums">
                                {{ formatNumber(user.actions) }}
                                <span class="block text-xs text-muted-foreground">{{ formatNumber(user.items) }} elementos afetados</span>
                            </dd>
                        </div>
                    </dl>
                    <div v-if="user.features.length" class="flex flex-wrap gap-1">
                        <span v-for="feature in user.features" :key="feature.key" class="rounded-full border border-border px-2 py-0.5 text-xs">{{ feature.label }}</span>
                    </div>
                    <div v-if="user.signals.length" class="space-y-2">
                        <UsageSignalBadge v-for="signal in user.signals" :key="signal.key" :signal="signal" />
                    </div>
                </li>
            </ul>

            <!-- Ecrãs largos: tabela. -->
            <div class="hidden md:block">
                <TableShell>
                    <template #head>
                        <tr>
                            <th scope="col" :class="headCell">Pessoa</th>
                            <th scope="col" :class="headCell">Adesão</th>
                            <th scope="col" :class="headCell">Plano</th>
                            <th scope="col" :class="headCell">Última atividade observada</th>
                            <th scope="col" :class="headCell">Dias ativos</th>
                            <th scope="col" :class="[headCell, 'text-right']">Ações</th>
                            <th scope="col" :class="headCell">Funcionalidades</th>
                            <th scope="col" :class="headCell">Sinais</th>
                            <th scope="col" :class="headCell">Estado</th>
                        </tr>
                    </template>
                    <template #body>
                        <tr v-for="user in users.data" :key="user.ulid" class="align-top hover:bg-muted/20">
                            <td class="px-3 py-2">
                                <Link :href="detailHref(user)" class="font-medium text-primary hover:underline">{{ user.name }}</Link>
                                <div class="text-xs text-muted-foreground">{{ user.email }}</div>
                            </td>
                            <td class="px-3 py-2 tabular-nums">{{ formatDate(user.joined_on) }}</td>
                            <td class="px-3 py-2">{{ planText(user) }}</td>
                            <td class="max-w-48 px-3 py-2">{{ activityText(user) }}</td>
                            <td class="px-3 py-2">{{ activeDaysText(user) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ formatNumber(user.actions) }}
                                <div class="text-xs text-muted-foreground">{{ formatNumber(user.items) }} elementos afetados</div>
                            </td>
                            <td class="px-3 py-2">
                                <div class="flex flex-wrap gap-1">
                                    <span v-for="feature in user.features" :key="feature.key" class="rounded-full border border-border px-2 py-0.5 text-xs">{{ feature.label }}</span>
                                    <span v-if="user.features.length === 0" class="text-muted-foreground">—</span>
                                </div>
                            </td>
                            <td class="min-w-56 px-3 py-2">
                                <div class="space-y-2">
                                    <UsageSignalBadge v-for="signal in user.signals" :key="signal.key" :signal="signal" />
                                    <span v-if="user.signals.length === 0" class="text-muted-foreground">—</span>
                                </div>
                            </td>
                            <td class="max-w-44 px-3 py-2">{{ user.status ?? '—' }}</td>
                        </tr>
                    </template>
                </TableShell>
            </div>

            <p v-if="users.data.length === 0" class="rounded-xl border border-border px-3 py-8 text-center text-sm text-muted-foreground">
                Nenhuma pessoa corresponde aos filtros.
            </p>

            <UsagePagination :links="users.links" />
        </section>
    </div>
</template>
