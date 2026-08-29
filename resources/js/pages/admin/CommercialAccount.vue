<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';

/**
 * One account's commercial record.
 *
 * READ-FIRST. What an account may USE is not changed anywhere on this page —
 * there is no «mudar plano», no «dar Pro», no override switch. The two forms it
 * carries record FACTS about the account: the condition it was sold under, and
 * money that was received. Provisioning lives where it always lived, on the
 * account page under Contas.
 *
 * NO PEDAGOGICAL DATA. No student, class, classification or report reaches this
 * screen; the only personal data present is the account holder's name and email,
 * which is what managing a subscription requires and nothing more.
 */

type Option = { value: string; label: string };

type Subscription = {
    id: number;
    plan: string;
    plan_key: string;
    status: string;
    status_label: string;
    condition: string;
    condition_label: string;
    condition_stored: string | null;
    condition_note: string | null;
    is_trial: boolean;
    in_force: boolean;
    starts_at: string;
    ends_at: string | null;
    /**
     * O que foi CONTRATADO — prova imutável, e por isso só de leitura em todo
     * este ecrã. `contracted_price_cents` a `null` é «nunca houve preço
     * acordado»; `0` é «acordado como gratuito». Não são a mesma coisa, e a
     * página não pode deixar que pareçam.
     */
    contracted_price_cents: number | null;
    contracted_currency: string | null;
    billing_period: string | null;
    billing_period_label: string | null;
    commercial_term_ends_at: string | null;
    plan_version: number | null;
    module_count?: number;
    read_only_count?: number;
};

/** Um dos «primeiros 250». Ver `App\Support\Commercial\FounderSeats`. */
type FounderSeat = {
    number: number;
    capacity: number;
    price_cents: number;
    currency: string;
    claimed_at: string;
    confirmed_at: string | null;
    reserved_until: string | null;
    is_confirmed: boolean;
    is_holding: boolean;
};

type Payment = {
    ulid: string;
    amount_cents: number;
    currency: string;
    status: string;
    status_label: string;
    counts_as_revenue: boolean;
    method: string | null;
    provider_reference: string | null;
    condition_label: string | null;
    voucher_code: string | null;
    paid_at: string | null;
    period_starts_at: string | null;
    period_ends_at: string | null;
    recorded_by: string | null;
    recorded_at: string | null;
    status_reason: string | null;
    status_changed_by: string | null;
    status_changed_at: string | null;
    correctable: boolean;
    refundable: boolean;
    confirmable: boolean;
};

const props = defineProps<{
    account: {
        ulid: string;
        name: string;
        type: string;
        created_at: string | null;
        owner: { name: string; email: string } | null;
    };
    current: Subscription | null;
    history: Subscription[];
    overrides: {
        module: string;
        enabled: boolean;
        reason: string | null;
        in_force: boolean;
        starts_at: string | null;
        ends_at: string | null;
    }[];
    payments: Payment[];
    totals: { paid_cents: number; payment_count: number; currency: string };
    audit: {
        event: string;
        summary: string | null;
        causer: string | null;
        created_at: string;
    }[];
    options: { conditions: Option[]; methods: Option[]; statuses: Option[] };
    condition_locked: boolean;
    founderSeat: FounderSeat | null;
}>();

/*
 * O passo que faltava, no sítio onde ele é preciso.
 *
 * Registar dinheiro NÃO muda o plano — é uma separação deliberada do domínio, e
 * continua a ser verdade. O que estava mal era outra coisa: depois de confirmar
 * um pagamento, quem o fez tinha de sair deste ecrã, encontrar a ficha da conta
 * noutro sítio do backoffice, e lembrar-se do que ia lá fazer. O acto continua
 * separado; deixa é de estar escondido.
 *
 * Só aparece quando há dinheiro registado e a conta ainda não está no Pro — que
 * é exactamente o estado em que alguém se esqueceu de um passo.
 */
const needsActivation = computed(
    () => props.totals.payment_count > 0 && props.current?.plan_key !== 'pro',
);

const activateForm = useForm({ plan_key: 'pro' });

function activatePro(): void {
    activateForm.post(`/admin/accounts/${props.account.ulid}/plan`, {
        preserveScroll: true,
    });
}

function money(cents: number, currency = props.totals.currency): string {
    return new Intl.NumberFormat('pt-PT', {
        style: 'currency',
        currency,
    }).format(cents / 100);
}

const conditionForm = useForm({
    condition: props.current?.condition_stored ?? '',
    note: props.current?.condition_note ?? '',
});

function saveCondition(): void {
    conditionForm.post(`/admin/commercial/${props.account.ulid}/condition`, {
        preserveScroll: true,
    });
}

const paymentForm = useForm({
    amount: '',
    currency: 'EUR',
    status: 'paid',
    paid_at: new Date().toISOString().slice(0, 10),
    method: '',
    provider_reference: '',
    commercial_condition: '',
    voucher_code: '',
    period_starts_at: '',
    period_ends_at: '',
});

const paymentFormOpen = ref(false);

function savePayment(): void {
    paymentForm.post(`/admin/commercial/${props.account.ulid}/payments`, {
        preserveScroll: true,
        onSuccess: () => {
            paymentForm.reset();
            paymentForm.currency = 'EUR';
            paymentForm.status = 'paid';
            paymentForm.paid_at = new Date().toISOString().slice(0, 10);
            paymentFormOpen.value = false;
        },
    });
}

/*
 * Confirmar que a transferência de um pedido entrou.
 *
 * O VALOR É EDITÁVEL e vem preenchido com o que foi pedido, não fixo: quem
 * confirma está a olhar para o extrato, e se lá estiver outro número é esse que
 * conta. A data também, porque o dia em que o dinheiro entra raramente é o dia
 * em que alguém abre este ecrã.
 */
const confirming = ref<string | null>(null);
const confirmForm = useForm({ amount: '', paid_at: '' });

function startConfirmation(payment: Payment): void {
    confirmForm.reset();
    confirmForm.amount = (payment.amount_cents / 100).toFixed(2);
    confirmForm.paid_at = new Date().toISOString().slice(0, 10);
    confirming.value = payment.ulid;
}

function submitConfirmation(): void {
    if (confirming.value === null) {
        return;
    }

    confirmForm.post(`/admin/commercial/payments/${confirming.value}/confirm`, {
        preserveScroll: true,
        onSuccess: () => {
            confirming.value = null;
        },
    });
}

/**
 * One form object per payment being corrected, created on demand. A single
 * shared form would carry one payment's reason onto the next one.
 */
const correcting = ref<{ ulid: string; mode: 'refund' | 'void' } | null>(null);
const correctionForm = useForm({ reason: '' });

function startCorrection(payment: Payment, mode: 'refund' | 'void'): void {
    correctionForm.reset();
    correctionForm.clearErrors();
    correcting.value = { ulid: payment.ulid, mode };
}

function submitCorrection(): void {
    if (correcting.value === null) {
        return;
    }

    const { ulid, mode } = correcting.value;

    correctionForm.post(`/admin/commercial/payments/${ulid}/${mode}`, {
        preserveScroll: true,
        onSuccess: () => (correcting.value = null),
    });
}

const statusClasses: Record<string, string> = {
    paid: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    pending:
        'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    failed: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
    refunded: 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300',
    cancelled: 'bg-muted text-muted-foreground',
};

const eventLabels: Record<string, string> = {
    'commercial.condition_set': 'Condição comercial',
    'commercial.payment_requested': 'Pagamento pedido',
    'commercial.payment_recorded': 'Pagamento registado',
    'commercial.payment_refunded': 'Pagamento reembolsado',
    'commercial.payment_voided': 'Pagamento anulado',
    'commercial.founder_seat_claimed': 'Lugar de Fundador reservado',
    'commercial.founder_seat_confirmed': 'Lugar de Fundador confirmado',
    'commercial.founder_seat_released': 'Lugar de Fundador libertado',
    'admin.plan_changed': 'Plano alterado',
    'admin.subscription_suspended': 'Subscrição suspensa',
    'admin.subscription_reactivated': 'Subscrição reativada',
};

const hasUnknownCondition = computed(
    () => props.current?.condition === 'unknown',
);
</script>

<template>
    <Head :title="`${account.name} — Comercial`" />

    <div class="space-y-6 p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <Link
                    href="/admin/commercial"
                    class="text-xs text-muted-foreground hover:underline"
                    >← Comercial</Link
                >
                <h1 class="mt-1 text-xl font-semibold tracking-tight">
                    {{ account.name }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{ account.owner?.name ?? 'Sem responsável' }}
                    <span v-if="account.owner">
                        · {{ account.owner.email }}</span
                    >
                    · conta criada em {{ account.created_at ?? '—' }}
                </p>
            </div>
            <Button as-child variant="outline" size="sm">
                <Link :href="`/admin/accounts/${account.ulid}`"
                    >Gerir conta</Link
                >
            </Button>
        </div>

        <div
            v-if="needsActivation"
            class="rounded-lg border border-amber-400/60 bg-amber-50 p-4 dark:border-amber-700/60 dark:bg-amber-950/30"
        >
            <p class="text-sm font-medium">
                Esta conta tem pagamento registado e ainda não está no plano
                Pro.
            </p>
            <p class="mt-1 text-sm text-muted-foreground">
                Registar dinheiro não muda o plano — são factos independentes.
                Ative-o aqui.
            </p>
            <Button
                type="button"
                size="sm"
                class="mt-3"
                :disabled="activateForm.processing"
                @click="activatePro"
            >
                Ativar o plano Pro
            </Button>
        </div>

        <!-- Situação atual -->
        <section aria-labelledby="current-title" class="space-y-3">
            <h2
                id="current-title"
                class="text-sm font-medium text-muted-foreground"
            >
                Situação atual
            </h2>

            <div
                v-if="current === null"
                class="rounded-lg border border-dashed border-border p-4 text-sm text-muted-foreground"
            >
                Esta conta não tem nenhuma subscrição registada.
            </div>

            <div v-else class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-lg border border-border p-4">
                    <div class="text-xs text-muted-foreground">Plano</div>
                    <div class="mt-1 text-lg font-semibold">
                        {{ current.plan }}
                    </div>
                    <div class="mt-1 text-xs text-muted-foreground">
                        {{ current.status_label }}
                    </div>
                </div>
                <div class="rounded-lg border border-border p-4">
                    <div class="text-xs text-muted-foreground">
                        Condição comercial
                    </div>
                    <div class="mt-1 text-lg font-semibold">
                        {{ current.condition_label }}
                    </div>
                    <div
                        v-if="current.condition_note"
                        class="mt-1 text-xs text-muted-foreground"
                    >
                        {{ current.condition_note }}
                    </div>
                </div>
                <div class="rounded-lg border border-border p-4">
                    <div class="text-xs text-muted-foreground">Período</div>
                    <div class="mt-1 text-sm">{{ current.starts_at }}</div>
                    <div class="text-xs text-muted-foreground">
                        até {{ current.ends_at ?? 'sem fim definido' }}
                    </div>
                </div>
                <div class="rounded-lg border border-border p-4">
                    <div class="text-xs text-muted-foreground">
                        Pago por esta conta
                    </div>
                    <div class="mt-1 text-lg font-semibold tabular-nums">
                        {{ money(totals.paid_cents) }}
                    </div>
                    <div class="mt-1 text-xs text-muted-foreground">
                        {{ totals.payment_count }} pagamento(s)
                    </div>
                </div>
            </div>

            <!--
                O QUE FOI CONTRATADO.

                A pergunta que um administrador tinha de ir à base de dados para
                responder: «que condição é que esta organização contratou?». As
                quatro colunas existem desde a 0.88.0 e este ecrã nunca as
                mostrou. Só de leitura, e é deliberado — são prova imutável, e o
                modelo recusa qualquer alteração; corrigir um erro
                administrativo é criar um contrato novo, não reescrever o antigo.

                «Não registado» e «0 €» são coisas diferentes e aparecem
                diferentes: a primeira é uma conta cuja origem ninguém escreveu,
                a segunda é uma adesão que alguém acordou ser gratuita.
            -->
            <div v-if="current" class="rounded-lg border border-border p-4">
                <div class="flex items-baseline justify-between gap-3">
                    <div class="text-xs text-muted-foreground">
                        Contratado
                    </div>
                    <div class="text-xs text-muted-foreground">
                        só de leitura
                    </div>
                </div>

                <dl class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    <div>
                        <dt class="text-xs text-muted-foreground">Preço</dt>
                        <dd class="mt-1 text-sm tabular-nums">
                            <template
                                v-if="current.contracted_price_cents !== null"
                                >{{
                                    money(
                                        current.contracted_price_cents,
                                        current.contracted_currency ??
                                            totals.currency,
                                    )
                                }}</template
                            >
                            <span v-else class="text-muted-foreground"
                                >Não registado</span
                            >
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">Moeda</dt>
                        <dd class="mt-1 text-sm">
                            {{ current.contracted_currency ?? '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">
                            Periodicidade
                        </dt>
                        <dd class="mt-1 text-sm">
                            {{ current.billing_period_label ?? 'Não registada' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">
                            Termo comercial
                        </dt>
                        <dd class="mt-1 text-sm">
                            {{ current.commercial_term_ends_at ?? '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">
                            Versão do plano
                        </dt>
                        <dd class="mt-1 text-sm">
                            <template v-if="current.plan_version !== null"
                                >v{{ current.plan_version }}</template
                            >
                            <template v-else>—</template>
                        </dd>
                    </div>
                </dl>

                <p
                    v-if="current.commercial_term_ends_at"
                    class="mt-3 text-xs text-muted-foreground"
                >
                    O termo comercial diz até quando vale a
                    <em>condição</em>, não até quando vale o
                    <em>acesso</em>. Passada esta data a conta não perde nada —
                    perde o preço que tinha.
                </p>
            </div>

            <!-- O lugar dos «primeiros 250», quando esta conta tem um. -->
            <div
                v-if="founderSeat"
                class="rounded-lg border border-border p-4"
            >
                <div class="text-xs text-muted-foreground">
                    Membro Fundador
                </div>
                <div class="mt-1 text-lg font-semibold">
                    Lugar n.º {{ founderSeat.number }}
                    <span class="text-sm font-normal text-muted-foreground"
                        >de {{ founderSeat.capacity }}</span
                    >
                </div>
                <p class="mt-1 text-xs text-muted-foreground">
                    {{ money(founderSeat.price_cents, founderSeat.currency) }},
                    congelado a
                    {{ founderSeat.claimed_at }}.
                    <template v-if="founderSeat.is_confirmed"
                        >Confirmado a
                        {{ founderSeat.confirmed_at }}.</template
                    >
                    <template v-else-if="founderSeat.is_holding"
                        >Reservado até
                        {{ founderSeat.reserved_until }}, à espera da
                        confirmação do pagamento.</template
                    >
                    <template v-else
                        >A reserva expirou sem pagamento confirmado.</template
                    >
                </p>
                <p class="mt-2 text-xs text-muted-foreground">
                    Não é um plano diferente: são exatamente os módulos do Pro.
                </p>
            </div>

            <p
                v-if="hasUnknownCondition"
                class="rounded-lg border border-dashed border-border p-3 text-xs text-muted-foreground"
            >
                A origem comercial desta subscrição nunca foi registada. Não é
                inferida a partir do plano nem do valor pago — se souber qual é,
                registe-a abaixo.
            </p>

            <div v-if="current" class="rounded-lg border border-border p-4">
                <div class="text-xs text-muted-foreground">
                    Entitlement efetivo
                </div>
                <p class="mt-2 text-sm">
                    <span class="font-medium">{{
                        current.module_count ?? 0
                    }}</span>
                    módulos ativos<span
                        v-if="(current.read_only_count ?? 0) > 0"
                    >
                        · {{ current.read_only_count }} em leitura</span
                    >. A condição comercial não altera nenhum deles.
                </p>
            </div>
        </section>

        <!-- Marcar condição comercial -->
        <section
            aria-labelledby="condition-title"
            class="rounded-lg border border-border p-4"
        >
            <h2 id="condition-title" class="text-sm font-medium">
                Registar condição comercial
            </h2>
            <p class="mt-1 text-xs text-muted-foreground">
                Diz porque é que esta conta está no plano que tem. Não altera o
                plano, o estado nem as datas.
            </p>

            <p
                v-if="condition_locked"
                class="mt-3 rounded-md border border-dashed border-border p-3 text-sm text-muted-foreground"
            >
                Esta subscrição está em experiência. A condição comercial vem do
                próprio estado e não pode ser escrita à mão.
            </p>

            <form
                v-else-if="current"
                class="mt-3 grid gap-3 md:grid-cols-3"
                @submit.prevent="saveCondition"
            >
                <label class="space-y-1">
                    <span class="text-xs text-muted-foreground">Condição</span>
                    <select
                        v-model="conditionForm.condition"
                        class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                    >
                        <option value="">Origem não registada</option>
                        <option
                            v-for="option in options.conditions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                </label>
                <label class="space-y-1 md:col-span-2">
                    <span class="text-xs text-muted-foreground"
                        >Nota (opcional)</span
                    >
                    <input
                        v-model="conditionForm.note"
                        type="text"
                        maxlength="255"
                        placeholder="Ex.: aderiu na campanha de lançamento, confirmado por email."
                        class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                    />
                </label>
                <p
                    v-if="conditionForm.errors.condition"
                    class="text-sm text-red-600 md:col-span-3"
                >
                    {{ conditionForm.errors.condition }}
                </p>
                <div class="md:col-span-3">
                    <Button
                        type="submit"
                        size="sm"
                        :disabled="conditionForm.processing"
                        >Guardar condição</Button
                    >
                </div>
            </form>
        </section>

        <!-- Pagamentos -->
        <section aria-labelledby="payments-title" class="space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2
                    id="payments-title"
                    class="text-sm font-medium text-muted-foreground"
                >
                    Pagamentos
                </h2>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    @click="paymentFormOpen = !paymentFormOpen"
                >
                    {{
                        paymentFormOpen
                            ? 'Cancelar'
                            : 'Registar pagamento recebido'
                    }}
                </Button>
            </div>

            <form
                v-if="paymentFormOpen"
                class="grid gap-3 rounded-lg border border-border p-4 md:grid-cols-3"
                @submit.prevent="savePayment"
            >
                <p class="text-xs text-muted-foreground md:col-span-3">
                    Regista um pagamento que já recebeu (transferência, MB WAY).
                    Não há gateway: nada é cobrado daqui, e não são guardados
                    dados de cartão nem emitida qualquer fatura.
                </p>

                <label class="space-y-1">
                    <span class="text-xs text-muted-foreground"
                        >Valor recebido</span
                    >
                    <input
                        v-model="paymentForm.amount"
                        type="text"
                        inputmode="decimal"
                        placeholder="44,90"
                        class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                    />
                    <span
                        v-if="paymentForm.errors.amount"
                        class="block text-xs text-red-600"
                        >{{ paymentForm.errors.amount }}</span
                    >
                </label>
                <label class="space-y-1">
                    <span class="text-xs text-muted-foreground">Moeda</span>
                    <input
                        v-model="paymentForm.currency"
                        type="text"
                        maxlength="3"
                        class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm uppercase"
                    />
                </label>
                <label class="space-y-1">
                    <span class="text-xs text-muted-foreground">Estado</span>
                    <select
                        v-model="paymentForm.status"
                        class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                    >
                        <option
                            v-for="option in options.statuses"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                </label>

                <label class="space-y-1">
                    <span class="text-xs text-muted-foreground"
                        >Data em que recebeu</span
                    >
                    <input
                        v-model="paymentForm.paid_at"
                        type="date"
                        class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                    />
                    <span
                        v-if="paymentForm.errors.paid_at"
                        class="block text-xs text-red-600"
                        >{{ paymentForm.errors.paid_at }}</span
                    >
                </label>
                <label class="space-y-1">
                    <span class="text-xs text-muted-foreground">Método</span>
                    <select
                        v-model="paymentForm.method"
                        class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                    >
                        <option value="">Não registado</option>
                        <option
                            v-for="option in options.methods"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                </label>
                <label class="space-y-1">
                    <span class="text-xs text-muted-foreground"
                        >Referência</span
                    >
                    <input
                        v-model="paymentForm.provider_reference"
                        type="text"
                        placeholder="Ex.: NIB terminado em 4821"
                        class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                    />
                </label>

                <label class="space-y-1">
                    <span class="text-xs text-muted-foreground"
                        >Condição comercial</span
                    >
                    <select
                        v-model="paymentForm.commercial_condition"
                        class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                    >
                        <option value="">A da subscrição</option>
                        <option
                            v-for="option in options.conditions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                </label>
                <label class="space-y-1">
                    <span class="text-xs text-muted-foreground"
                        >Código de voucher</span
                    >
                    <input
                        v-model="paymentForm.voucher_code"
                        type="text"
                        maxlength="60"
                        class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                    />
                    <span class="block text-xs text-muted-foreground"
                        >Guardado como texto. Não é validado nem
                        resgatado.</span
                    >
                </label>
                <div class="grid grid-cols-2 gap-2">
                    <label class="space-y-1">
                        <span class="text-xs text-muted-foreground"
                            >Período de</span
                        >
                        <input
                            v-model="paymentForm.period_starts_at"
                            type="date"
                            class="w-full rounded-md border border-border bg-background px-2 py-2 text-sm"
                        />
                    </label>
                    <label class="space-y-1">
                        <span class="text-xs text-muted-foreground">até</span>
                        <input
                            v-model="paymentForm.period_ends_at"
                            type="date"
                            class="w-full rounded-md border border-border bg-background px-2 py-2 text-sm"
                        />
                    </label>
                </div>

                <p
                    v-if="paymentForm.errors.period_ends_at"
                    class="text-sm text-red-600 md:col-span-3"
                >
                    {{ paymentForm.errors.period_ends_at }}
                </p>

                <div class="md:col-span-3">
                    <Button
                        type="submit"
                        size="sm"
                        :disabled="paymentForm.processing"
                        >Registar pagamento</Button
                    >
                </div>
            </form>

            <div class="overflow-x-auto rounded-lg border border-border">
                <table class="w-full text-sm">
                    <thead
                        class="bg-muted/50 text-left text-xs text-muted-foreground"
                    >
                        <tr>
                            <th class="px-3 py-2 font-medium">Data</th>
                            <th class="px-3 py-2 text-right font-medium">
                                Valor
                            </th>
                            <th class="px-3 py-2 font-medium">Estado</th>
                            <th class="px-3 py-2 font-medium">Método</th>
                            <th class="px-3 py-2 font-medium">Condição</th>
                            <th class="px-3 py-2 font-medium">Registado por</th>
                            <th class="px-3 py-2 font-medium"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <template
                            v-for="payment in payments"
                            :key="payment.ulid"
                        >
                            <tr class="hover:bg-muted/20">
                                <td class="px-3 py-2 whitespace-nowrap">
                                    {{ payment.paid_at ?? '—' }}
                                </td>
                                <td
                                    class="px-3 py-2 text-right tabular-nums"
                                    :class="
                                        payment.counts_as_revenue
                                            ? ''
                                            : 'text-muted-foreground line-through'
                                    "
                                >
                                    {{
                                        money(
                                            payment.amount_cents,
                                            payment.currency,
                                        )
                                    }}
                                </td>
                                <td class="px-3 py-2">
                                    <span
                                        class="inline-flex rounded-full px-2 py-0.5 text-xs"
                                        :class="
                                            statusClasses[payment.status] ??
                                            'bg-muted text-muted-foreground'
                                        "
                                    >
                                        {{ payment.status_label }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-muted-foreground">
                                    {{ payment.method ?? '—' }}
                                    <!-- A referência, por baixo do método e em
                                         monoespaçado: é o campo que se compara,
                                         caractere a caractere, com a descrição
                                         de uma linha do extrato bancário. Sem
                                         ela à vista, confirmar um pedido é
                                         adivinhar de quem ele é. -->
                                    <span
                                        v-if="payment.provider_reference"
                                        class="mt-0.5 block font-mono text-xs tracking-wide text-foreground"
                                        >{{ payment.provider_reference }}</span
                                    >
                                </td>
                                <td class="px-3 py-2 text-muted-foreground">
                                    {{ payment.condition_label ?? '—' }}
                                    <!-- The code is a REFERENCE, and the label has to say
                                         so where it is read. There is no voucher backend:
                                         nothing validated this code, nothing redeemed it,
                                         and no discount was computed from it. A bare
                                         «Código: X» sitting next to a euro figure reads as
                                         if the system had checked something — which is
                                         exactly the impression this area must not give. -->
                                    <span
                                        v-if="payment.voucher_code"
                                        class="mt-1 block text-xs"
                                    >
                                        <span
                                            class="rounded bg-muted px-1 py-0.5 font-mono"
                                            >{{ payment.voucher_code }}</span
                                        >
                                        <span class="mt-0.5 block italic">
                                            Referência administrativa — não
                                            validada nem resgatada pelo sistema.
                                        </span>
                                    </span>
                                </td>
                                <td
                                    class="px-3 py-2 text-xs text-muted-foreground"
                                >
                                    {{ payment.recorded_by ?? '—' }}
                                    <span class="block">{{
                                        payment.recorded_at
                                    }}</span>
                                </td>
                                <td
                                    class="px-3 py-2 text-right whitespace-nowrap"
                                >
                                    <Button
                                        v-if="payment.confirmable"
                                        type="button"
                                        size="sm"
                                        @click="startConfirmation(payment)"
                                    >
                                        Confirmar recebimento
                                    </Button>
                                    <Button
                                        v-if="payment.refundable"
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        @click="
                                            startCorrection(payment, 'refund')
                                        "
                                    >
                                        Reembolsar
                                    </Button>
                                    <Button
                                        v-if="payment.correctable"
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        @click="
                                            startCorrection(payment, 'void')
                                        "
                                    >
                                        Anular
                                    </Button>
                                </td>
                            </tr>

                            <!-- Confirmar o recebimento: o valor e a data ficam
                                 à vista e editáveis, porque quem confirma está
                                 a ler o extrato e não a repetir o pedido. -->
                            <tr
                                v-if="confirming === payment.ulid"
                                class="bg-muted/20"
                            >
                                <td colspan="99" class="px-3 py-3">
                                    <form
                                        class="flex flex-wrap items-end gap-3"
                                        @submit.prevent="submitConfirmation"
                                    >
                                        <label class="text-xs">
                                            <span class="mb-1 block font-medium"
                                                >Valor recebido</span
                                            >
                                            <input
                                                v-model="confirmForm.amount"
                                                type="number"
                                                step="0.01"
                                                min="0.01"
                                                class="w-32 rounded-md border border-border bg-background px-2 py-1 text-sm"
                                            />
                                            <span
                                                v-if="confirmForm.errors.amount"
                                                class="block text-xs text-red-600"
                                                >{{
                                                    confirmForm.errors.amount
                                                }}</span
                                            >
                                        </label>
                                        <label class="text-xs">
                                            <span class="mb-1 block font-medium"
                                                >Data em que entrou</span
                                            >
                                            <input
                                                v-model="confirmForm.paid_at"
                                                type="date"
                                                class="rounded-md border border-border bg-background px-2 py-1 text-sm"
                                            />
                                            <span
                                                v-if="
                                                    confirmForm.errors.paid_at
                                                "
                                                class="block text-xs text-red-600"
                                                >{{
                                                    confirmForm.errors.paid_at
                                                }}</span
                                            >
                                        </label>
                                        <Button
                                            type="submit"
                                            size="sm"
                                            :disabled="confirmForm.processing"
                                        >
                                            Registar pagamento
                                        </Button>
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="ghost"
                                            @click="confirming = null"
                                        >
                                            Cancelar
                                        </Button>
                                        <p
                                            class="w-full text-xs text-muted-foreground"
                                        >
                                            Isto regista o dinheiro e anula o
                                            pedido.
                                            <strong>Não ativa o plano</strong> —
                                            isso faz-se na ficha da conta, e é
                                            um ato à parte de propósito.
                                        </p>
                                    </form>
                                </td>
                            </tr>

                            <!-- The reason a corrected payment left the revenue,
                                 kept next to it rather than only in the audit
                                 trail: the accounts have to explain themselves
                                 on the page where they are read. -->
                            <tr
                                v-if="payment.status_reason"
                                class="bg-muted/20 text-xs text-muted-foreground"
                            >
                                <td colspan="7" class="px-3 py-2">
                                    {{ payment.status_label }} por
                                    {{ payment.status_changed_by ?? '—' }} em
                                    {{ payment.status_changed_at }} —
                                    {{ payment.status_reason }}
                                </td>
                            </tr>

                            <tr
                                v-if="correcting?.ulid === payment.ulid"
                                class="bg-muted/30"
                            >
                                <td colspan="7" class="px-3 py-3">
                                    <form
                                        class="flex flex-wrap items-end gap-2"
                                        @submit.prevent="submitCorrection"
                                    >
                                        <label
                                            class="min-w-64 flex-1 space-y-1"
                                        >
                                            <span
                                                class="text-xs text-muted-foreground"
                                            >
                                                Motivo
                                                {{
                                                    correcting?.mode ===
                                                    'refund'
                                                        ? 'do reembolso'
                                                        : 'da anulação'
                                                }}
                                            </span>
                                            <input
                                                v-model="correctionForm.reason"
                                                type="text"
                                                maxlength="255"
                                                class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                                            />
                                        </label>
                                        <Button
                                            type="submit"
                                            size="sm"
                                            :disabled="
                                                correctionForm.processing
                                            "
                                            >Confirmar</Button
                                        >
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="ghost"
                                            @click="correcting = null"
                                            >Cancelar</Button
                                        >
                                        <p
                                            v-if="correctionForm.errors.reason"
                                            class="w-full text-sm text-red-600"
                                        >
                                            {{ correctionForm.errors.reason }}
                                        </p>
                                        <p
                                            class="w-full text-xs text-muted-foreground"
                                        >
                                            O valor e a data originais
                                            mantêm-se. Só o estado muda, com
                                            motivo e autoria.
                                        </p>
                                    </form>
                                </td>
                            </tr>
                        </template>

                        <tr v-if="payments.length === 0">
                            <td
                                colspan="7"
                                class="px-3 py-8 text-center text-sm text-muted-foreground"
                            >
                                Nenhum pagamento registado para esta conta.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Histórico de subscrições -->
        <section aria-labelledby="history-title" class="space-y-3">
            <h2
                id="history-title"
                class="text-sm font-medium text-muted-foreground"
            >
                Histórico de subscrições
            </h2>
            <div class="overflow-x-auto rounded-lg border border-border">
                <table class="w-full text-sm">
                    <thead
                        class="bg-muted/50 text-left text-xs text-muted-foreground"
                    >
                        <tr>
                            <th class="px-3 py-2 font-medium">Plano</th>
                            <th class="px-3 py-2 font-medium">Estado</th>
                            <th class="px-3 py-2 font-medium">Condição</th>
                            <th class="px-3 py-2 font-medium">Início</th>
                            <th class="px-3 py-2 font-medium">Fim</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr
                            v-for="entry in history"
                            :key="entry.id"
                            :class="entry.in_force ? 'bg-muted/20' : ''"
                        >
                            <td class="px-3 py-2">
                                {{ entry.plan }}
                                <span
                                    v-if="entry.in_force"
                                    class="ml-1 text-xs text-muted-foreground"
                                    >(em vigor)</span
                                >
                            </td>
                            <td class="px-3 py-2 text-muted-foreground">
                                {{ entry.status_label }}
                            </td>
                            <td class="px-3 py-2 text-muted-foreground">
                                {{ entry.condition_label }}
                            </td>
                            <td
                                class="px-3 py-2 whitespace-nowrap text-muted-foreground"
                            >
                                {{ entry.starts_at }}
                            </td>
                            <td
                                class="px-3 py-2 whitespace-nowrap text-muted-foreground"
                            >
                                {{ entry.ends_at ?? '—' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Overrides -->
        <section
            v-if="overrides.length > 0"
            aria-labelledby="overrides-title"
            class="space-y-3"
        >
            <h2
                id="overrides-title"
                class="text-sm font-medium text-muted-foreground"
            >
                Exceções de módulo
            </h2>
            <div class="overflow-x-auto rounded-lg border border-border">
                <table class="w-full text-sm">
                    <thead
                        class="bg-muted/50 text-left text-xs text-muted-foreground"
                    >
                        <tr>
                            <th class="px-3 py-2 font-medium">Módulo</th>
                            <th class="px-3 py-2 font-medium">Efeito</th>
                            <th class="px-3 py-2 font-medium">Motivo</th>
                            <th class="px-3 py-2 font-medium">Janela</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr
                            v-for="override in overrides"
                            :key="override.module"
                        >
                            <td class="px-3 py-2">{{ override.module }}</td>
                            <td class="px-3 py-2">
                                {{
                                    override.enabled ? 'Concedido' : 'Retirado'
                                }}
                            </td>
                            <td class="px-3 py-2 text-muted-foreground">
                                {{ override.reason ?? '—' }}
                            </td>
                            <td class="px-3 py-2 text-xs text-muted-foreground">
                                {{ override.starts_at ?? 'sempre' }} →
                                {{ override.ends_at ?? 'sem fim' }}
                                <span v-if="!override.in_force">
                                    (fora de vigor)</span
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Alterações administrativas -->
        <section aria-labelledby="audit-title" class="space-y-3">
            <h2
                id="audit-title"
                class="text-sm font-medium text-muted-foreground"
            >
                Alterações comerciais registadas
            </h2>
            <ol
                v-if="audit.length > 0"
                class="divide-y divide-border rounded-lg border border-border"
            >
                <li
                    v-for="(entry, index) in audit"
                    :key="index"
                    class="px-3 py-2 text-sm"
                >
                    <div
                        class="flex flex-wrap items-baseline justify-between gap-2"
                    >
                        <span class="font-medium">{{
                            eventLabels[entry.event] ?? entry.event
                        }}</span>
                        <span class="text-xs text-muted-foreground"
                            >{{ entry.created_at }} ·
                            {{ entry.causer ?? 'sistema' }}</span
                        >
                    </div>
                    <p v-if="entry.summary" class="text-muted-foreground">
                        {{ entry.summary }}
                    </p>
                </li>
            </ol>
            <p
                v-else
                class="rounded-lg border border-dashed border-border p-4 text-sm text-muted-foreground"
            >
                Ainda não há alterações comerciais registadas para esta conta.
            </p>
        </section>
    </div>
</template>
