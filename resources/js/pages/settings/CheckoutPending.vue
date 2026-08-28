<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';

/**
 * Os dados para transferir, e o que acontece a seguir.
 *
 * A REFERÊNCIA VEM PRIMEIRO E EM DESTAQUE. É o único campo que, se for
 * esquecido, faz a transferência chegar sem se saber de quem é — e a seguir
 * alguém tem de ligar ao banco para descobrir. Tudo o resto está no ecrã
 * porque é preciso; ela está no topo porque é a que se esquece.
 *
 * DIZ EXPLICITAMENTE QUE O PLANO AINDA NÃO MUDOU. Um ecrã de sucesso a seguir
 * a um formulário lê-se como «está feito», e não está: falta o dinheiro chegar
 * e alguém confirmá-lo.
 */

const props = defineProps<{
    payment: {
        reference: string | null;
        amount: string;
        status: string;
        statusLabel: string;
        expiresAt: string | null;
        requestedAt: string | null;
    };
    planName: string;
    bank: {
        beneficiary: string | null;
        iban: string | null;
        bic: string | null;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Plano', href: '/settings/plan' },
            { title: 'Dados para pagamento', href: '/settings/plan' },
        ],
    },
});

const isPending = computed(() => props.payment.status === 'pending');

function formatDate(value: string | null): string | null {
    return value === null ? null : new Date(value).toLocaleDateString('pt-PT');
}
</script>

<template>
    <Head title="Dados para pagamento" />

    <h1 class="sr-only">Dados para pagamento</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Dados para pagamento"
            description="Transfira o valor abaixo e escreva a referência na descrição."
        />

        <div class="rounded-lg border border-border p-4">
            <dl class="space-y-4">
                <div>
                    <dt
                        class="text-xs tracking-wide text-muted-foreground uppercase"
                    >
                        Referência — escreva-a na descrição
                    </dt>
                    <dd
                        class="mt-1 text-2xl font-semibold tracking-[0.08em] tabular-nums"
                    >
                        {{ payment.reference }}
                    </dd>
                </div>

                <div>
                    <dt
                        class="text-xs tracking-wide text-muted-foreground uppercase"
                    >
                        Valor
                    </dt>
                    <dd class="mt-1 text-2xl font-semibold">
                        {{ payment.amount }}
                    </dd>
                </div>

                <div v-if="bank.beneficiary">
                    <dt
                        class="text-xs tracking-wide text-muted-foreground uppercase"
                    >
                        Beneficiário
                    </dt>
                    <dd class="mt-1">{{ bank.beneficiary }}</dd>
                </div>

                <div>
                    <dt
                        class="text-xs tracking-wide text-muted-foreground uppercase"
                    >
                        IBAN
                    </dt>
                    <dd class="mt-1 tracking-[0.04em] tabular-nums">
                        {{ bank.iban }}
                    </dd>
                </div>

                <div v-if="bank.bic">
                    <dt
                        class="text-xs tracking-wide text-muted-foreground uppercase"
                    >
                        BIC/SWIFT
                    </dt>
                    <dd class="mt-1">{{ bank.bic }}</dd>
                </div>
            </dl>
        </div>

        <div
            v-if="isPending"
            class="rounded-lg border border-border p-4 text-sm"
        >
            <p class="font-medium">O plano ainda não mudou.</p>
            <p class="mt-1 text-muted-foreground">
                A sua conta mantém o plano atual até confirmarmos a entrada do
                pagamento. Nada se perde entretanto, e nada lhe é cobrado
                automaticamente — a transferência é sua, quando quiser.
            </p>
            <p v-if="payment.expiresAt" class="mt-2 text-muted-foreground">
                Estes dados são válidos até {{ formatDate(payment.expiresAt) }}.
            </p>
        </div>

        <div v-else class="rounded-lg border border-border p-4 text-sm">
            <p class="font-medium">
                Este pedido está {{ payment.statusLabel.toLowerCase() }}.
            </p>
            <p class="mt-1 text-muted-foreground">
                Se transferiu e o plano continua por mudar, fale connosco com
                esta referência à mão.
            </p>
        </div>

        <p class="text-sm text-muted-foreground">
            Enviámos estes dados também por email, para os ter à mão quando
            abrir o homebanking.
        </p>

        <Button as-child variant="outline">
            <a href="/settings/plan">Voltar ao plano</a>
        </Button>
    </div>
</template>
