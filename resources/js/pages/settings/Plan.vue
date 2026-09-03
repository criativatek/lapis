<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';

/**
 * The organization's own plan, and the one self-service upgrade Lapispro offers
 * without any operator involved: a voluntary, time-boxed Pro trial (§Trial).
 *
 * `state` arrives already computed server-side (App\Http\Controllers\Settings\PlanController::edit)
 * — this page never re-derives it from `currentPlanName`/`trial`/`usedTrialBefore`,
 * it only decides what to SHOW for each of the six states. `proDays` always
 * comes from the prop, never a literal, so the trial length stays whatever
 * TRIAL_PRO_DAYS/config('trial.pro_days') says.
 */

type PlanState =
    | 'eligible'
    | 'trial_active'
    | 'trial_expired'
    | 'pro_active'
    | 'institutional'
    | 'unavailable';

type Trial = {
    starts_at: string;
    ends_at: string;
    days_remaining: number;
};

type SubscribeOffer = {
    price: string;
    standardPrice: string;
    isFounder: boolean;
    seatsRemaining: number;
    pendingUlid: string | null;
    pendingReference: string | null;
};

defineProps<{
    state: PlanState;
    subscribe: SubscribeOffer | null;
    proDays: number;
    currentPlanName: string | null;
    trial: Trial | null;
    usedTrialBefore: boolean | null;
    canRedeemCapabilityCode: boolean;
}>();

const form = useForm({});

// `trial` (App\Support\Trial\TrialException) is never a field of this empty
// form — read through a string index the same way classes/Create.vue's
// `limitError` does for its own dynamic, backend-only error key.
const trialError = computed(
    () => (form.errors as Record<string, string>).trial,
);

function activateTrial(): void {
    form.post('/settings/plan/trial', { preserveScroll: true });
}

// Resgatar um voucher `free_until`. O plano-alvo segue EXPLÍCITO no pedido —
// 'pro' é o alvo que esta página oferece, não uma decisão do código: o
// servidor valida o alvo e confronta-o com a restrição do voucher.
const voucherForm = useForm({
    voucher_code: '',
    plan_key: 'pro',
});

function redeemVoucher(): void {
    voucherForm.post('/settings/plan/voucher', {
        preserveScroll: true,
        onSuccess: () => voucherForm.reset('voucher_code'),
    });
}

const capabilityForm=useForm({capability_code:''});
function redeemCapabilityCode():void{
capabilityForm.post('/settings/plan/capability-code',{preserveScroll:true,onSuccess:()=>capabilityForm.reset()});
}

/** Consistent with how the rest of the app shows a date to a teacher (§AccountClosure.vue). */
function formatDate(iso: string): string {
    return new Date(iso).toLocaleDateString('pt-PT');
}
</script>

<template>
    <Head title="Plano" />

    <h1 class="sr-only">Plano</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Plano"
            description="Consulte e faça a gestão do plano da sua organização."
        />

        <!-- eligible: the offer. -->
        <div
            v-if="state === 'eligible'"
            class="space-y-4 rounded-lg border border-border p-4"
        >
            <div class="space-y-1">
                <h3 class="text-sm font-medium">Experimente o plano Pro</h3>
                <p class="text-sm text-muted-foreground">
                    Experimente gratuitamente todas as funcionalidades do plano
                    Pro durante {{ proDays }} dias. Não é necessário cartão e o
                    período não é renovado automaticamente. No final, regressa
                    ao plano Base sem perder os seus dados.
                </p>
            </div>
            <div class="space-y-2">
                <Button :disabled="form.processing" @click="activateTrial">
                    Experimentar Pro por {{ proDays }} dias
                </Button>
                <InputError :message="trialError" />
            </div>
        </div>

        <!-- trial_active: no activation control — there is nothing left to offer. -->
        <div
            v-else-if="state === 'trial_active' && trial"
            class="space-y-2 rounded-lg border border-border p-4"
        >
            <h3 class="text-sm font-medium">Período experimental Pro ativo</h3>
            <p class="text-sm text-muted-foreground">
                Iniciado em {{ formatDate(trial.starts_at) }}. Termina em
                {{ formatDate(trial.ends_at) }} —
                {{ trial.days_remaining }} dia(s) restante(s).
            </p>
            <p class="text-sm text-muted-foreground">
                O período experimental não é renovado automaticamente. No final,
                a sua organização regressa ao plano Base sem perder os dados
                criados entretanto.
            </p>
        </div>

        <!-- trial_expired: already used, no activation control. -->
        <div
            v-else-if="state === 'trial_expired'"
            class="space-y-2 rounded-lg border border-border p-4"
        >
            <h3 class="text-sm font-medium">
                Plano atual: {{ currentPlanName }}
            </h3>
            <p class="text-sm text-muted-foreground">
                O período experimental Pro já foi utilizado.
            </p>
            <p class="text-sm text-muted-foreground">
                Os dados criados durante o período experimental foram mantidos.
            </p>
        </div>

        <!-- pro_active: no activation control, ever — regardless of usedTrialBefore. -->
        <div
            v-else-if="state === 'pro_active'"
            class="space-y-2 rounded-lg border border-border p-4"
        >
            <h3 class="text-sm font-medium">
                Plano atual: {{ currentPlanName }}
            </h3>
            <p v-if="usedTrialBefore" class="text-xs text-muted-foreground">
                Já utilizou o período experimental Pro anteriormente.
            </p>
        </div>

        <!-- institutional: plan name only — no trial copy of any kind. -->
        <div
            v-else-if="state === 'institutional'"
            class="space-y-2 rounded-lg border border-border p-4"
        >
            <h3 class="text-sm font-medium">
                Plano atual: {{ currentPlanName }}
            </h3>
        </div>

        <!-- unavailable: nothing in force to offer a trial against (e.g. a
             suspended organization, or one with no subscription at all) —
             no trial copy, no button, current plan name only if there is one. -->
        <div
            v-else-if="state === 'unavailable'"
            class="space-y-2 rounded-lg border border-border p-4"
        >
            <h3 v-if="currentPlanName" class="text-sm font-medium">
                Plano atual: {{ currentPlanName }}
            </h3>
            <p class="text-sm text-muted-foreground">
                De momento não há nenhuma ação disponível sobre o plano desta
                organização.
            </p>
        </div>

        <!-- Subscrever, em bloco próprio e não dentro de um dos estados acima:
             a oferta é a mesma quer se venha de um período experimental a
             terminar, de um já usado, ou de nada. Quando não há oferta, o
             servidor manda `null` e isto não existe. -->
        <div
            v-if="subscribe"
            class="space-y-3 rounded-lg border border-border p-4"
        >
            <div class="space-y-1">
                <h3 class="text-sm font-medium">Subscrever o plano Pro</h3>
                <p class="text-sm text-muted-foreground">
                    <strong>{{ subscribe.price }}</strong> por ano, por
                    transferência bancária.
                    <template v-if="subscribe.isFounder">
                        Condição Membro Fundador, em vez de
                        <span class="line-through">{{
                            subscribe.standardPrice
                        }}</span>
                        — restam {{ subscribe.seatsRemaining }} lugares.
                    </template>
                </p>
                <p class="text-sm text-muted-foreground">
                    Preenche os dados de faturação, recebe o IBAN e uma
                    referência. O plano é ativado depois de confirmarmos a
                    entrada do pagamento.
                </p>
            </div>

            <p
                v-if="subscribe.pendingReference"
                class="text-sm text-muted-foreground"
            >
                Já tem um pedido em curso com a referência
                <strong>{{ subscribe.pendingReference }}</strong
                >.
            </p>

            <Button as-child>
                <a
                    :href="
                        subscribe.pendingUlid
                            ? `/settings/plan/checkout/${subscribe.pendingUlid}`
                            : '/settings/plan/checkout'
                    "
                >
                    {{
                        subscribe.pendingReference
                            ? 'Ver os dados para pagamento'
                            : 'Subscrever o Pro'
                    }}
                </a>
            </Button>
        </div>

        <!-- Voucher de acesso gratuito até uma data. Só o tipo `free_until` se
             resgata aqui — os códigos com preço aplicam-se no checkout, onde há
             uma quantia para eles decidirem. O servidor valida, decide e diz o
             caso concreto; esta página não adivinha nada. -->
        <div class="space-y-3 rounded-lg border border-border p-4">
            <div class="space-y-1">
                <h3 class="text-sm font-medium">Tem um voucher?</h3>
                <p class="text-sm text-muted-foreground">
                    Se recebeu um código de acesso do Lapispro, resgate-o aqui.
                    Um código de desconto aplica-se no passo de subscrição.
                </p>
            </div>

            <form
                class="flex flex-col gap-2 sm:flex-row sm:items-start"
                @submit.prevent="redeemVoucher"
            >
                <div class="min-w-0 flex-1">
                    <input
                        v-model="voucherForm.voucher_code"
                        type="text"
                        autocomplete="off"
                        autocapitalize="characters"
                        spellcheck="false"
                        placeholder="Escreva o código como o recebeu"
                        class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                        aria-label="Código do voucher"
                    />
                    <InputError
                        :message="voucherForm.errors.voucher_code"
                        class="mt-1"
                    />
                </div>
                <Button
                    type="submit"
                    variant="outline"
                    :disabled="voucherForm.processing"
                >
                    Resgatar
                </Button>
            </form>
        </div>
        <section v-if="canRedeemCapabilityCode" class="space-y-3 rounded-lg border border-border p-4">
            <div><h3 class="text-sm font-medium">Código de capacidades temporárias</h3><p class="text-sm text-muted-foreground">Introduza um código fornecido pela equipa Lapispro. O plano da organização não será alterado.</p></div>
            <form class="flex flex-col gap-2 sm:flex-row" @submit.prevent="redeemCapabilityCode"><input v-model="capabilityForm.capability_code" required maxlength="64" autocomplete="off" placeholder="XXXX-XXXX-XXXX-XXXX" class="min-w-0 flex-1 rounded-md border border-border bg-background px-3 py-2 font-mono text-sm" /><Button :disabled="capabilityForm.processing">Resgatar código</Button></form>
            <InputError :message="capabilityForm.errors.capability_code" />
        </section>
    </div>
</template>
