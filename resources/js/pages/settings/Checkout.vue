<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';

/**
 * Os dados de faturação, antes de haver IBAN nenhum.
 *
 * NÃO É UM PAGAMENTO. Este ecrã não cobra nada e não muda plano nenhum: recolhe
 * a quem se passa a fatura e devolve uma referência de transferência. É por
 * isso que o botão diz «Obter dados para pagamento» e não «Pagar» — prometer
 * pagamento a quem vai abrir o homebanking noutro separador seria mentir sobre
 * o que acabou de acontecer.
 *
 * O PREÇO VEM DO SERVIDOR, incluindo a condição Fundador. A página nunca decide
 * se ainda há lugares: se decidisse, o 251.º comprador veria 29,90 € num
 * browser que não tem como contar.
 */

type Billing = {
    name: string | null;
    tax_number: string | null;
    address_line1: string | null;
    address_line2: string | null;
    postal_code: string | null;
    city: string | null;
    country: string | null;
    email: string | null;
};

const props = defineProps<{
    plan: { key: string; name: string };
    price: string;
    standardPrice: string;
    isFounderPrice: boolean;
    founderSeatsRemaining: number;
    billing: Billing | null;
    pendingReference: string | null;
    pendingUlid: string | null;
    bankTransferReady: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Plano', href: '/settings/plan' },
            { title: 'Subscrever', href: '/settings/plan/checkout' },
        ],
    },
});

const form = useForm({
    name: props.billing?.name ?? '',
    tax_number: props.billing?.tax_number ?? '',
    address_line1: props.billing?.address_line1 ?? '',
    address_line2: props.billing?.address_line2 ?? '',
    postal_code: props.billing?.postal_code ?? '',
    city: props.billing?.city ?? '',
    country: props.billing?.country ?? 'PT',
    email: props.billing?.email ?? '',
});

// Erro que vem do domínio (App\Support\Commercial\CheckoutUnavailable) e não de
// um campo — lido pelo índice, como o Plan.vue faz com `trial`.
const checkoutError = computed(
    () => (form.errors as Record<string, string>).checkout,
);

function submit(): void {
    form.post('/settings/plan/checkout', { preserveScroll: true });
}
</script>

<template>
    <Head title="Subscrever" />

    <h1 class="sr-only">Subscrever o {{ plan.name }}</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Subscrever"
            :description="`Preencha os dados de faturação. A seguir mostramos-lhe o IBAN e a referência para transferir.`"
        />

        <!-- Um pedido já aberto: mostrar antes do formulário, senão a pessoa
             preenche tudo outra vez sem saber que já tem uma referência. -->
        <div
            v-if="pendingReference"
            class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm dark:border-amber-900 dark:bg-amber-950/40"
        >
            <p class="font-medium">Já tem um pedido em curso.</p>
            <p class="mt-1 text-muted-foreground">
                A referência <strong>{{ pendingReference }}</strong> continua
                válida. Se transferir com ela, não precisa de fazer mais nada
                aqui.
            </p>
            <Button as-child variant="outline" size="sm" class="mt-3">
                <a :href="`/settings/plan/checkout/${pendingUlid}`"
                    >Ver os dados para pagamento</a
                >
            </Button>
        </div>

        <div
            v-if="!bankTransferReady"
            class="rounded-lg border border-destructive/40 bg-destructive/5 p-4 text-sm"
        >
            <p class="font-medium">Pagamentos indisponíveis de momento.</p>
            <p class="mt-1 text-muted-foreground">
                Os dados bancários ainda não estão configurados. Contacte-nos e
                tratamos da subscrição consigo.
            </p>
        </div>

        <div class="rounded-lg border border-border p-4">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-sm font-medium">{{ plan.name }}</h2>
                <p class="text-2xl font-semibold tracking-tight">
                    {{ price }}
                    <span class="text-sm font-normal text-muted-foreground"
                        >/ ano</span
                    >
                </p>
            </div>

            <p v-if="isFounderPrice" class="mt-2 text-sm text-muted-foreground">
                Condição Membro Fundador aplicada — em vez de
                <span class="line-through">{{ standardPrice }}</span
                >. Restam {{ founderSeatsRemaining }} lugares.
            </p>

            <p class="mt-2 text-xs text-muted-foreground">
                Subscrição anual. O plano é ativado depois de confirmarmos a
                entrada do pagamento; até lá a sua conta mantém o plano atual.
            </p>
        </div>

        <form class="space-y-4" @submit.prevent="submit">
            <p class="text-sm font-medium">Dados de faturação</p>

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="text-sm sm:col-span-2">
                    <span class="mb-1 block font-medium">Nome ou entidade</span>
                    <input
                        v-model="form.name"
                        type="text"
                        autocomplete="organization"
                        class="w-full rounded-md border border-border bg-background px-3 py-2"
                    />
                    <InputError :message="form.errors.name" />
                </label>

                <label class="text-sm">
                    <span class="mb-1 block font-medium"
                        >NIF
                        <span class="font-normal text-muted-foreground"
                            >(opcional)</span
                        ></span
                    >
                    <input
                        v-model="form.tax_number"
                        type="text"
                        inputmode="numeric"
                        class="w-full rounded-md border border-border bg-background px-3 py-2"
                    />
                    <InputError :message="form.errors.tax_number" />
                </label>

                <label class="text-sm">
                    <span class="mb-1 block font-medium">País</span>
                    <input
                        v-model="form.country"
                        type="text"
                        maxlength="2"
                        class="w-full rounded-md border border-border bg-background px-3 py-2 uppercase"
                    />
                    <InputError :message="form.errors.country" />
                </label>

                <label class="text-sm sm:col-span-2">
                    <span class="mb-1 block font-medium">Morada</span>
                    <input
                        v-model="form.address_line1"
                        type="text"
                        autocomplete="address-line1"
                        class="w-full rounded-md border border-border bg-background px-3 py-2"
                    />
                    <InputError :message="form.errors.address_line1" />
                </label>

                <label class="text-sm sm:col-span-2">
                    <span class="mb-1 block font-medium"
                        >Complemento
                        <span class="font-normal text-muted-foreground"
                            >(opcional)</span
                        ></span
                    >
                    <input
                        v-model="form.address_line2"
                        type="text"
                        autocomplete="address-line2"
                        class="w-full rounded-md border border-border bg-background px-3 py-2"
                    />
                    <InputError :message="form.errors.address_line2" />
                </label>

                <label class="text-sm">
                    <span class="mb-1 block font-medium">Código postal</span>
                    <input
                        v-model="form.postal_code"
                        type="text"
                        autocomplete="postal-code"
                        class="w-full rounded-md border border-border bg-background px-3 py-2"
                    />
                    <InputError :message="form.errors.postal_code" />
                </label>

                <label class="text-sm">
                    <span class="mb-1 block font-medium">Localidade</span>
                    <input
                        v-model="form.city"
                        type="text"
                        autocomplete="address-level2"
                        class="w-full rounded-md border border-border bg-background px-3 py-2"
                    />
                    <InputError :message="form.errors.city" />
                </label>

                <label class="text-sm sm:col-span-2">
                    <span class="mb-1 block font-medium"
                        >Email de faturação</span
                    >
                    <input
                        v-model="form.email"
                        type="email"
                        autocomplete="email"
                        class="w-full rounded-md border border-border bg-background px-3 py-2"
                    />
                    <InputError :message="form.errors.email" />
                    <span class="mt-1 block text-xs text-muted-foreground">
                        É para aqui que enviamos os dados de pagamento e, mais
                        tarde, a fatura.
                    </span>
                </label>
            </div>

            <InputError :message="checkoutError" />

            <Button
                type="submit"
                :disabled="form.processing || !bankTransferReady"
            >
                Obter dados para pagamento
            </Button>
        </form>
    </div>
</template>
