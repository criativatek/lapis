<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import { Button } from '@/components/ui/button';

/**
 * Admin > Suporte — a fila.
 *
 * A ORDEM É A DO TRABALHO, não a da data: abertos primeiro, depois em curso,
 * depois à espera do utilizador, e os resolvidos no fim. Quem abre este ecrã
 * quer saber o que falta fazer.
 *
 * AS ENTREGAS FALHADAS ESTÃO NO TOPO E FORA DOS FILTROS, como as transferências
 * por confirmar no ecrã comercial: sem worker, um aviso que não saiu só volta a
 * sair se uma pessoa carregar no botão — e para isso tem de o ver sem o
 * procurar.
 */

type Option = { value: string; label: string };

type Row = {
    ulid: string;
    reference: string;
    subject: string | null;
    categoryLabel: string;
    status: string;
    statusLabel: string;
    technicalCodeLabel: string | null;
    requesterEmail: string | null;
    waitingDays: number | null;
    createdAt: string | null;
    holdActive: boolean;
    isAnonymised: boolean;
};

type Paginator = {
    data: Row[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
};

type PendingDelivery = {
    ulid: string;
    requestUlid: string | null;
    reference: string | null;
    typeLabel: string;
    recipientLabel: string;
    attempts: number;
    failureLabel: string | null;
    lastFailedAt: string | null;
};

const props = defineProps<{
    requests: Paginator;
    filters: Record<string, string>;
    options: {
        statuses: Option[];
        categories: Option[];
        technicalCodes: Option[];
    };
    pendingDeliveries: PendingDelivery[];
}>();

const form = reactive({
    status: props.filters.status ?? '',
    category: props.filters.category ?? '',
    technical_code: props.filters.technical_code ?? '',
    hold: props.filters.hold ?? '',
    search: props.filters.search ?? '',
});

function apply(): void {
    router.get(
        '/admin/support',
        { ...form },
        { preserveState: true, replace: true },
    );
}

function clear(): void {
    Object.keys(form).forEach((key) => {
        form[key as keyof typeof form] = '';
    });
    apply();
}

const hasFilters = computed(() =>
    Object.values(form).some((value) => value !== ''),
);

/** Os rótulos de paginação do Laravel vêm com entidades HTML. */
function decodeLabel(label: string): string {
    return label.replace(/&laquo;/g, '«').replace(/&raquo;/g, '»');
}

function formatDate(iso: string | null): string {
    return iso ? new Date(iso).toLocaleDateString('pt-PT') : '—';
}

const statusClasses: Record<string, string> = {
    open: 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-300',
    in_progress:
        'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    waiting_for_user:
        'bg-violet-100 text-violet-800 dark:bg-violet-950 dark:text-violet-300',
    resolved: 'bg-muted text-muted-foreground',
};
</script>

<template>
    <Head title="Suporte — Backoffice" />

    <div class="space-y-6 p-6">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Suporte</h1>
            <p class="text-sm text-muted-foreground">
                Pedidos de professores, com e sem conta.
                {{ requests.total }} no total.
            </p>
        </div>

        <!-- Avisos que não chegaram. Sem worker, só saem outra vez por acção
             de uma pessoa — por isso aparecem antes de tudo o resto. -->
        <section
            v-if="pendingDeliveries.length"
            class="space-y-3 rounded-lg border border-amber-400/60 bg-amber-50 p-4 dark:border-amber-700/60 dark:bg-amber-950/30"
        >
            <h2 class="text-sm font-medium">
                {{ pendingDeliveries.length }} notificação(ões) por entregar
            </h2>
            <ul class="space-y-2">
                <li
                    v-for="entrega in pendingDeliveries"
                    :key="entrega.ulid"
                    class="flex flex-wrap items-center gap-x-4 gap-y-1 rounded-md border border-border bg-background px-3 py-2 text-sm"
                >
                    <Link
                        :href="`/admin/support/${entrega.requestUlid}`"
                        class="font-mono text-xs underline"
                        >{{ entrega.reference }}</Link
                    >
                    <span>{{ entrega.typeLabel }}</span>
                    <span class="text-xs text-muted-foreground">{{
                        entrega.recipientLabel
                    }}</span>
                    <span class="text-xs text-destructive">{{
                        entrega.failureLabel
                    }}</span>
                    <span class="text-xs text-muted-foreground"
                        >{{ entrega.attempts }} tentativa(s)</span
                    >
                </li>
            </ul>
        </section>

        <!-- Filtros -->
        <section class="rounded-lg border border-border p-4">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <label class="space-y-1 text-sm">
                    <span class="text-xs text-muted-foreground">Estado</span>
                    <select
                        v-model="form.status"
                        class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                    >
                        <option value="">Todos</option>
                        <option
                            v-for="option in options.statuses"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                </label>

                <label class="space-y-1 text-sm">
                    <span class="text-xs text-muted-foreground">Assunto</span>
                    <select
                        v-model="form.category"
                        class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                    >
                        <option value="">Todos</option>
                        <option
                            v-for="option in options.categories"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                </label>

                <label class="space-y-1 text-sm">
                    <span class="text-xs text-muted-foreground"
                        >Classificação</span
                    >
                    <select
                        v-model="form.technical_code"
                        class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                    >
                        <option value="">Todas</option>
                        <option value="none">Sem classificação</option>
                        <option
                            v-for="option in options.technicalCodes"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                </label>

                <label class="space-y-1 text-sm">
                    <span class="text-xs text-muted-foreground">Retenção</span>
                    <select
                        v-model="form.hold"
                        class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                    >
                        <option value="">Todos</option>
                        <option value="active">Com suspensão em vigor</option>
                    </select>
                </label>

                <label class="space-y-1 text-sm">
                    <span class="text-xs text-muted-foreground"
                        >Referência ou email</span
                    >
                    <input
                        v-model="form.search"
                        type="search"
                        class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                        @keyup.enter="apply"
                    />
                </label>
            </div>

            <div class="mt-3 flex gap-2">
                <Button size="sm" @click="apply">Filtrar</Button>
                <Button
                    v-if="hasFilters"
                    size="sm"
                    variant="outline"
                    @click="clear"
                    >Limpar</Button
                >
            </div>
        </section>

        <!-- Fila -->
        <p
            v-if="requests.data.length === 0"
            class="text-sm text-muted-foreground"
        >
            Nenhum pedido corresponde a estes filtros.
        </p>

        <div v-else class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-sm">
                <thead>
                    <tr
                        class="border-b border-border text-left text-xs text-muted-foreground"
                    >
                        <th class="px-3 py-2 font-medium">Referência</th>
                        <th class="px-3 py-2 font-medium">Resumo</th>
                        <th class="px-3 py-2 font-medium">Assunto</th>
                        <th class="px-3 py-2 font-medium">Estado</th>
                        <th class="px-3 py-2 font-medium">Classificação</th>
                        <th class="px-3 py-2 font-medium">Aberto em</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="pedido in requests.data"
                        :key="pedido.ulid"
                        class="border-b border-border/60 last:border-0"
                    >
                        <td class="px-3 py-2">
                            <Link
                                :href="`/admin/support/${pedido.ulid}`"
                                class="font-mono text-xs underline"
                                >{{ pedido.reference }}</Link
                            >
                            <span
                                v-if="pedido.holdActive"
                                class="mt-0.5 block text-xs text-amber-700 dark:text-amber-400"
                                >retenção suspensa</span
                            >
                            <span
                                v-if="pedido.isAnonymised"
                                class="mt-0.5 block text-xs text-muted-foreground"
                                >anonimizado</span
                            >
                        </td>
                        <td class="px-3 py-2">{{ pedido.subject ?? '—' }}</td>
                        <td class="px-3 py-2 text-muted-foreground">
                            {{ pedido.categoryLabel }}
                        </td>
                        <td class="px-3 py-2">
                            <span
                                class="rounded-full px-2 py-0.5 text-xs"
                                :class="statusClasses[pedido.status]"
                                >{{ pedido.statusLabel }}</span
                            >
                            <span
                                v-if="pedido.waitingDays !== null"
                                class="mt-0.5 block text-xs text-muted-foreground"
                                >há {{ pedido.waitingDays }} dia(s)</span
                            >
                        </td>
                        <td class="px-3 py-2 text-xs text-muted-foreground">
                            {{ pedido.technicalCodeLabel ?? '—' }}
                        </td>
                        <td class="px-3 py-2 text-xs text-muted-foreground">
                            {{ formatDate(pedido.createdAt) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav v-if="requests.links.length > 3" class="flex flex-wrap gap-1">
            <Link
                v-for="link in requests.links"
                :key="link.label"
                :href="link.url ?? '#'"
                class="rounded border border-border px-2 py-1 text-xs"
                :class="link.active ? 'bg-muted font-medium' : ''"
                >{{ decodeLabel(link.label) }}</Link
            >
        </nav>
    </div>
</template>
