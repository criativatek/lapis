<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import { Button } from '@/components/ui/button';

/**
 * Admin > Comercial.
 *
 * THE CARDS NEVER MULTIPLY A COUNT BY A PRICE. Every euro shown here arrives
 * from the server already summed out of recorded payments; this page has no
 * price list and no arithmetic that could invent one. When the payments table is
 * empty — which is its state today — the figures read 0 EUR, and the page says
 * plainly why rather than filling the space with an estimate.
 *
 * No chart. A single revenue figure and a handful of counts do not become more
 * legible as a bar; a decorative one would only make the empty state look like a
 * broken one (§20).
 */

type Option = { value: string; label: string };

type Row = {
    ulid: string;
    organization: string;
    type: string;
    owner: string | null;
    owner_email: string | null;
    plan: string;
    plan_key: string;
    condition: string;
    condition_label: string;
    status: string;
    status_label: string;
    in_force: boolean;
    starts_at: string;
    ends_at: string | null;
    paid_cents: number;
    payment_count: number;
    last_paid_at: string | null;
};

type Paginator = {
    data: Row[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
    from: number | null;
    to: number | null;
};

const props = defineProps<{
    metrics: {
        accounts: {
            total: number;
            by_plan: { base: number; pro: number; institutional: number };
            without_subscription: number;
            trials_active: number;
            pro_by_condition: Record<string, number>;
            paying: number;
        };
        revenue: {
            total_cents: number;
            period_cents: number;
            current_year_cents: number;
            paid_count: number;
            average_cents: number | null;
            currency: string;
            from: string | null;
            to: string | null;
        };
    };
    subscriptions: Paginator;
    awaitingConfirmation: {
        ulid: string;
        reference: string | null;
        amount: string;
        account: string;
        accountUlid: string | null;
        requestedAt: string | null;
        waitingDays: number;
    }[];
    filters: Record<string, string>;
    options: { plans: Option[]; conditions: Option[]; statuses: Option[] };
}>();

const form = reactive({
    search: props.filters.search ?? '',
    plan: props.filters.plan ?? '',
    condition: props.filters.condition ?? '',
    status: props.filters.status ?? '',
    payment: props.filters.payment ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});

const currency = computed(() => props.metrics.revenue.currency);

/** Cents in, a readable figure out. The only formatting of money on this page. */
function money(cents: number): string {
    return new Intl.NumberFormat('pt-PT', {
        style: 'currency',
        currency: currency.value === 'MIXED' ? 'EUR' : currency.value,
    }).format(cents / 100);
}

function apply(): void {
    router.get(
        '/admin/commercial',
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

const exportHref = computed(() => {
    const query = new URLSearchParams(
        Object.entries(form).filter(([, value]) => value !== '') as [
            string,
            string,
        ][],
    ).toString();

    return `/admin/commercial/export${query === '' ? '' : `?${query}`}`;
});

/** The Pro breakdown, in a fixed order, so a missing bucket reads as 0 and not as absent. */
const proBreakdown = computed(() =>
    [
        { key: 'standard', label: 'Standard' },
        { key: 'founder', label: 'Membro Fundador' },
        { key: 'voucher', label: 'Voucher' },
        { key: 'trial', label: 'Experiência' },
        { key: 'admin_grant', label: 'Concessão administrativa' },
        { key: 'institutional', label: 'Institucional' },
        { key: 'legacy', label: 'Anterior ao modelo' },
        { key: 'other', label: 'Outra' },
        { key: 'unknown', label: 'Origem não registada' },
    ].map((bucket) => ({
        ...bucket,
        count: props.metrics.accounts.pro_by_condition[bucket.key] ?? 0,
    })),
);

const statusClasses: Record<string, string> = {
    active: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    trial: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    suspended: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
    expired: 'bg-muted text-muted-foreground',
};

const conditionClasses: Record<string, string> = {
    founder:
        'bg-violet-100 text-violet-800 dark:bg-violet-950 dark:text-violet-300',
    trial: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    unknown: 'bg-muted text-muted-foreground',
};
</script>

<template>
    <Head title="Comercial — Backoffice" />

    <div class="space-y-6 p-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">Comercial</h1>
                <p class="text-sm text-muted-foreground">
                    Contas, subscrições e pagamentos efetivamente recebidos.
                </p>
            </div>
            <Button as-child variant="outline" size="sm">
                <a :href="exportHref">Exportar CSV</a>
            </Button>
        </div>

        <!--
            Pagamentos à espera de alguém.

            NO TOPO E FORA DOS FILTROS, de propósito. A tabela de subscrições
            responde a «em que plano está cada conta»; isto é trabalho por
            fazer, e estava invisível — só se encontrava abrindo a ficha de uma
            conta que já se soubesse ter pago. Quem abre este ecrã tem de ver o
            que está à espera dele sem ter de o adivinhar.
        -->
        <section
            v-if="awaitingConfirmation.length"
            aria-labelledby="awaiting-title"
            class="space-y-3 rounded-lg border border-amber-400/60 bg-amber-50 p-4 dark:border-amber-700/60 dark:bg-amber-950/30"
        >
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="awaiting-title" class="text-sm font-medium">
                    {{ awaitingConfirmation.length }} transferência(s) por
                    confirmar
                </h2>
                <p class="text-xs text-muted-foreground">
                    Compare a referência com a descrição no extrato e confirme
                    na ficha da conta.
                </p>
            </div>

            <ul class="space-y-2">
                <li
                    v-for="pedido in awaitingConfirmation"
                    :key="pedido.ulid"
                    class="flex flex-wrap items-center gap-x-4 gap-y-1 rounded-md border border-border bg-background px-3 py-2 text-sm"
                >
                    <span class="font-mono text-xs tracking-wide">{{
                        pedido.reference
                    }}</span>
                    <span class="font-medium">{{ pedido.account }}</span>
                    <span class="tabular-nums">{{ pedido.amount }}</span>
                    <span class="text-xs text-muted-foreground">
                        pedido a {{ pedido.requestedAt }}
                        <template v-if="pedido.waitingDays > 0">
                            · há {{ pedido.waitingDays }} dia(s)
                        </template>
                    </span>
                    <Button
                        v-if="pedido.accountUlid"
                        as-child
                        size="sm"
                        variant="outline"
                        class="ml-auto"
                    >
                        <Link :href="`/admin/commercial/${pedido.accountUlid}`"
                            >Abrir e confirmar</Link
                        >
                    </Button>
                </li>
            </ul>
        </section>

        <!-- Receita -->
        <section aria-labelledby="revenue-title" class="space-y-3">
            <h2
                id="revenue-title"
                class="text-sm font-medium text-muted-foreground"
            >
                Receita
            </h2>

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-lg border border-border p-4">
                    <div class="text-xs text-muted-foreground">
                        Receita total
                    </div>
                    <div class="mt-1 text-2xl font-semibold tabular-nums">
                        {{ money(metrics.revenue.total_cents) }}
                    </div>
                    <div class="mt-1 text-xs text-muted-foreground">
                        {{ metrics.revenue.paid_count }} pagamento(s)
                        registado(s) como pago
                    </div>
                </div>
                <div class="rounded-lg border border-border p-4">
                    <div class="text-xs text-muted-foreground">
                        {{
                            metrics.revenue.from || metrics.revenue.to
                                ? 'Receita no período'
                                : 'Receita no período (sem datas)'
                        }}
                    </div>
                    <div class="mt-1 text-2xl font-semibold tabular-nums">
                        {{ money(metrics.revenue.period_cents) }}
                    </div>
                    <div class="mt-1 text-xs text-muted-foreground">
                        {{ metrics.revenue.from ?? '—' }} a
                        {{ metrics.revenue.to ?? '—' }}
                    </div>
                </div>
                <div class="rounded-lg border border-border p-4">
                    <div class="text-xs text-muted-foreground">
                        Receita no ano civil
                    </div>
                    <div class="mt-1 text-2xl font-semibold tabular-nums">
                        {{ money(metrics.revenue.current_year_cents) }}
                    </div>
                </div>
                <div class="rounded-lg border border-border p-4">
                    <div class="text-xs text-muted-foreground">
                        Valor médio por pagamento
                    </div>
                    <div class="mt-1 text-2xl font-semibold tabular-nums">
                        {{
                            metrics.revenue.average_cents === null
                                ? '—'
                                : money(metrics.revenue.average_cents)
                        }}
                    </div>
                    <div class="mt-1 text-xs text-muted-foreground">
                        {{
                            metrics.revenue.average_cents === null
                                ? 'Sem pagamentos para calcular'
                                : 'Sobre pagamentos pagos'
                        }}
                    </div>
                </div>
            </div>

            <!-- Said on the page, not only in a docblock: an operator looking at
                 0 EUR has to be able to tell "nobody paid" from "the number is
                 broken", and the difference is worth four lines of prose. -->
            <p
                v-if="metrics.revenue.paid_count === 0"
                class="rounded-lg border border-dashed border-border p-4 text-sm text-muted-foreground"
            >
                <strong class="font-medium text-foreground"
                    >Ainda não há pagamentos registados.</strong
                >
                A receita resulta exclusivamente de pagamentos reais lançados em
                cada conta — nunca do número de contas Pro nem do preço de
                tabela. Enquanto não existir nenhum, o valor correto é 0 €.
            </p>
        </section>

        <!-- Contas -->
        <section aria-labelledby="accounts-title" class="space-y-3">
            <h2
                id="accounts-title"
                class="text-sm font-medium text-muted-foreground"
            >
                Contas
            </h2>

            <div class="grid gap-3 sm:grid-cols-3 xl:grid-cols-6">
                <div class="rounded-lg border border-border p-4">
                    <div class="text-xs text-muted-foreground">Total</div>
                    <div class="mt-1 text-2xl font-semibold tabular-nums">
                        {{ metrics.accounts.total }}
                    </div>
                </div>
                <div class="rounded-lg border border-border p-4">
                    <div class="text-xs text-muted-foreground">Base</div>
                    <div class="mt-1 text-2xl font-semibold tabular-nums">
                        {{ metrics.accounts.by_plan.base }}
                    </div>
                </div>
                <div class="rounded-lg border border-border p-4">
                    <div class="text-xs text-muted-foreground">Pro</div>
                    <div class="mt-1 text-2xl font-semibold tabular-nums">
                        {{ metrics.accounts.by_plan.pro }}
                    </div>
                </div>
                <div class="rounded-lg border border-border p-4">
                    <div class="text-xs text-muted-foreground">
                        Institucional
                    </div>
                    <div class="mt-1 text-2xl font-semibold tabular-nums">
                        {{ metrics.accounts.by_plan.institutional }}
                    </div>
                </div>
                <div class="rounded-lg border border-border p-4">
                    <div class="text-xs text-muted-foreground">
                        Em experiência
                    </div>
                    <div class="mt-1 text-2xl font-semibold tabular-nums">
                        {{ metrics.accounts.trials_active }}
                    </div>
                </div>
                <div class="rounded-lg border border-border p-4">
                    <div class="text-xs text-muted-foreground">
                        Com pagamentos
                    </div>
                    <div class="mt-1 text-2xl font-semibold tabular-nums">
                        {{ metrics.accounts.paying }}
                    </div>
                    <div class="mt-1 text-xs text-muted-foreground">
                        Contas com receita registada
                    </div>
                </div>
            </div>

            <p
                v-if="metrics.accounts.without_subscription > 0"
                class="text-xs text-muted-foreground"
            >
                {{ metrics.accounts.without_subscription }} conta(s) sem
                subscrição em vigor — não contam para nenhum dos planos acima.
            </p>
        </section>

        <!-- Pro por condição comercial: a distinção que justifica esta área. -->
        <section aria-labelledby="condition-title" class="space-y-3">
            <h2
                id="condition-title"
                class="text-sm font-medium text-muted-foreground"
            >
                Pro, por condição comercial
            </h2>
            <div class="grid gap-3 sm:grid-cols-3 xl:grid-cols-5">
                <div
                    v-for="bucket in proBreakdown"
                    :key="bucket.key"
                    class="rounded-lg border border-border px-4 py-3"
                >
                    <div class="text-xs text-muted-foreground">
                        {{ bucket.label }}
                    </div>
                    <div class="mt-1 text-xl font-semibold tabular-nums">
                        {{ bucket.count }}
                    </div>
                </div>
            </div>
            <p class="text-xs text-muted-foreground">
                «Membro Fundador» é uma condição comercial do plano Pro, não um
                plano. Uma conta cuja origem nunca foi registada aparece como
                «Origem não registada» e nunca é inferida pelo valor pago.
                <span class="mt-1 block">
                    «Voucher» é uma marcação administrativa feita por um
                    operador. Não existe motor de vouchers: nenhum código foi
                    validado, resgatado ou convertido em desconto pelo sistema.
                </span>
            </p>
        </section>

        <!-- Filtros -->
        <section
            aria-labelledby="filters-title"
            class="rounded-lg border border-border p-4"
        >
            <h2 id="filters-title" class="sr-only">Filtros</h2>
            <form
                class="grid gap-3 md:grid-cols-2 xl:grid-cols-4"
                @submit.prevent="apply"
            >
                <label class="space-y-1 xl:col-span-2">
                    <span class="text-xs text-muted-foreground">Pesquisar</span>
                    <input
                        v-model="form.search"
                        type="search"
                        placeholder="Organização, nome ou email…"
                        class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                    />
                </label>
                <label class="space-y-1">
                    <span class="text-xs text-muted-foreground">Plano</span>
                    <select
                        v-model="form.plan"
                        class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                    >
                        <option value="">Todos</option>
                        <option
                            v-for="plan in options.plans"
                            :key="plan.value"
                            :value="plan.value"
                        >
                            {{ plan.label }}
                        </option>
                    </select>
                </label>
                <label class="space-y-1">
                    <span class="text-xs text-muted-foreground"
                        >Condição comercial</span
                    >
                    <select
                        v-model="form.condition"
                        class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                    >
                        <option value="">Todas</option>
                        <option
                            v-for="condition in options.conditions"
                            :key="condition.value"
                            :value="condition.value"
                        >
                            {{ condition.label }}
                        </option>
                    </select>
                </label>
                <label class="space-y-1">
                    <span class="text-xs text-muted-foreground">Estado</span>
                    <select
                        v-model="form.status"
                        class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                    >
                        <option value="">Todos</option>
                        <option
                            v-for="status in options.statuses"
                            :key="status.value"
                            :value="status.value"
                        >
                            {{ status.label }}
                        </option>
                    </select>
                </label>
                <label class="space-y-1">
                    <span class="text-xs text-muted-foreground"
                        >Pagamentos</span
                    >
                    <select
                        v-model="form.payment"
                        class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                    >
                        <option value="">Indiferente</option>
                        <option value="paid">Com pagamentos</option>
                        <option value="unpaid">Sem pagamentos</option>
                    </select>
                </label>
                <label class="space-y-1">
                    <span class="text-xs text-muted-foreground"
                        >Início desde</span
                    >
                    <input
                        v-model="form.from"
                        type="date"
                        class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                    />
                </label>
                <label class="space-y-1">
                    <span class="text-xs text-muted-foreground"
                        >Início até</span
                    >
                    <input
                        v-model="form.to"
                        type="date"
                        class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                    />
                </label>

                <div class="flex items-end gap-2 xl:col-span-4">
                    <Button type="submit" size="sm">Filtrar</Button>
                    <Button
                        v-if="hasFilters"
                        type="button"
                        variant="ghost"
                        size="sm"
                        @click="clear"
                        >Limpar</Button
                    >
                    <p
                        class="ml-auto self-center text-xs text-muted-foreground"
                    >
                        As datas filtram o início da subscrição e delimitam a
                        «receita no período».
                    </p>
                </div>
            </form>
        </section>

        <!-- Subscrições -->
        <section aria-labelledby="list-title" class="space-y-3">
            <div class="flex items-baseline justify-between gap-3">
                <h2
                    id="list-title"
                    class="text-sm font-medium text-muted-foreground"
                >
                    Subscrições
                </h2>
                <p class="text-xs text-muted-foreground">
                    {{ subscriptions.total }} conta(s)
                </p>
            </div>

            <div class="overflow-x-auto rounded-lg border border-border">
                <table class="w-full text-sm">
                    <thead
                        class="bg-muted/50 text-left text-xs text-muted-foreground"
                    >
                        <tr>
                            <th class="px-3 py-2 font-medium">Conta</th>
                            <th class="px-3 py-2 font-medium">Plano</th>
                            <th class="px-3 py-2 font-medium">Condição</th>
                            <th class="px-3 py-2 font-medium">Estado</th>
                            <th class="px-3 py-2 font-medium">Início</th>
                            <th class="px-3 py-2 font-medium">Fim</th>
                            <th class="px-3 py-2 text-right font-medium">
                                Pago
                            </th>
                            <th class="px-3 py-2 font-medium">
                                Último pagamento
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr
                            v-for="row in subscriptions.data"
                            :key="row.ulid"
                            class="hover:bg-muted/20"
                        >
                            <td class="px-3 py-2">
                                <Link
                                    :href="`/admin/commercial/${row.ulid}`"
                                    class="font-medium hover:underline"
                                >
                                    {{ row.organization }}
                                </Link>
                                <div class="text-xs text-muted-foreground">
                                    {{ row.owner ?? '—'
                                    }}<span v-if="row.owner_email">
                                        · {{ row.owner_email }}</span
                                    >
                                </div>
                            </td>
                            <td class="px-3 py-2">{{ row.plan }}</td>
                            <td class="px-3 py-2">
                                <span
                                    class="inline-flex rounded-full px-2 py-0.5 text-xs"
                                    :class="
                                        conditionClasses[row.condition] ??
                                        'bg-muted text-muted-foreground'
                                    "
                                >
                                    {{ row.condition_label }}
                                </span>
                            </td>
                            <td class="px-3 py-2">
                                <span
                                    class="inline-flex rounded-full px-2 py-0.5 text-xs"
                                    :class="
                                        statusClasses[row.status] ??
                                        'bg-muted text-muted-foreground'
                                    "
                                >
                                    {{ row.status_label }}
                                </span>
                            </td>
                            <td
                                class="px-3 py-2 whitespace-nowrap text-muted-foreground"
                            >
                                {{ row.starts_at }}
                            </td>
                            <td
                                class="px-3 py-2 whitespace-nowrap text-muted-foreground"
                            >
                                {{ row.ends_at ?? '—' }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                <span
                                    :class="
                                        row.paid_cents === 0
                                            ? 'text-muted-foreground'
                                            : ''
                                    "
                                >
                                    {{ money(row.paid_cents) }}
                                </span>
                                <div
                                    v-if="row.payment_count > 0"
                                    class="text-xs text-muted-foreground"
                                >
                                    {{ row.payment_count }} pagamento(s)
                                </div>
                            </td>
                            <td
                                class="px-3 py-2 whitespace-nowrap text-muted-foreground"
                            >
                                {{ row.last_paid_at ?? '—' }}
                            </td>
                        </tr>
                        <tr v-if="subscriptions.data.length === 0">
                            <td
                                colspan="8"
                                class="px-3 py-8 text-center text-sm text-muted-foreground"
                            >
                                Nenhuma subscrição corresponde a estes filtros.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <nav
                v-if="subscriptions.links.length > 3"
                class="flex flex-wrap gap-1"
            >
                <template
                    v-for="(link, index) in subscriptions.links"
                    :key="index"
                >
                    <!-- The label is the paginator's own («&laquo; Anterior»,
                         «1»), so it carries entities and has to be rendered as
                         HTML. On a native element rather than on the component:
                         v-html on a component replaces whatever that component
                         renders, which for <Link> is the anchor itself. Same
                         shape as the Contas listing, for the same reason. -->
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        class="rounded-md border border-border px-3 py-1.5 text-sm"
                        :class="
                            link.active
                                ? 'bg-primary text-primary-foreground'
                                : 'hover:bg-muted/40'
                        "
                        preserve-scroll
                    >
                        <span v-html="link.label" />
                    </Link>
                    <span
                        v-else
                        class="rounded-md border border-border px-3 py-1.5 text-sm text-muted-foreground opacity-50"
                        v-html="link.label"
                    />
                </template>
            </nav>
        </section>
    </div>
</template>
