<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';

/**
 * O fio de um pedido — a fonte canónica da conversa.
 *
 * RESPONDER A UM PEDIDO RESOLVIDO REABRE-O, e o botão di-lo em vez de o
 * esconder: não há acção separada de reabertura, porque quem tem mais a dizer
 * já está a dizê-lo (ADR-0011 §12).
 *
 * O AVISO DE RECEÇÃO APARECE UMA VEZ, vindo da flash de quem acabou de abrir o
 * pedido, e diz a VERDADE sobre o email: se a confirmação não saiu, não a dá
 * como enviada. Não é um toast porque não é uma nota de rodapé — é a resposta à
 * pergunta com que a pessoa carregou no botão, e desaparecer em quatro segundos
 * deixá-la-ia sem ela.
 */

type Message = {
    ulid: string;
    role: string;
    roleLabel: string;
    body: string;
    createdAt: string | null;
};

type SupportRequest = {
    ulid: string;
    reference: string;
    subject: string | null;
    categoryLabel: string;
    status: string;
    statusLabel: string;
    createdAt: string | null;
    autoResolved: boolean;
    description: string | null;
    messages: Message[];
};

const props = defineProps<{ request: SupportRequest }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Suporte', href: '/support' },
            { title: 'Pedido', href: '#' },
        ],
    },
});

type Acknowledgement = { reference: string; emailDelivered: boolean };

const page = usePage();

/**
 * Só existe na navegação que vem de abrir o pedido; nas seguintes, não.
 *
 * `page.flash` e não `page.props.flash`: a flash do Inertia viaja ao lado dos
 * props no objeto da página, não dentro deles.
 */
const acknowledgement = computed<Acknowledgement | null>(
    () =>
        (page.flash as { supportAcknowledgement?: Acknowledgement } | undefined)
            ?.supportAcknowledgement ?? null,
);

const form = useForm({ body: '' });

function submit(): void {
    form.post(`/support/${props.request.ulid}/mensagens`, {
        preserveScroll: true,
        onSuccess: () => form.reset('body'),
    });
}

function formatDateTime(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString('pt-PT') : '—';
}
</script>

<template>
    <Head :title="`Pedido ${request.reference}`" />

    <div class="max-w-3xl space-y-6">
        <Heading
            variant="small"
            :title="request.subject ?? request.reference"
            :description="`${request.reference} · ${request.categoryLabel} · ${request.statusLabel}`"
        />

        <div
            v-if="acknowledgement"
            class="rounded-lg border p-4 text-sm"
            :class="
                acknowledgement.emailDelivered
                    ? 'border-emerald-300 bg-emerald-50 text-emerald-950 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100'
                    : 'border-amber-300 bg-amber-50 text-amber-950 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100'
            "
        >
            <p class="font-medium">Pedido enviado com sucesso</p>
            <p class="mt-1">
                O seu pedido
                <strong class="font-mono">{{
                    acknowledgement.reference
                }}</strong>
                foi recebido pela equipa de suporte. Lamentamos o incómodo e
                iremos analisá-lo com a maior brevidade possível.
                <template v-if="acknowledgement.emailDelivered">
                    Enviámos também uma confirmação para o seu email.
                </template>
                <template v-else>
                    A confirmação por email não pôde ser enviada neste momento,
                    mas o pedido ficou registado normalmente.
                </template>
            </p>
        </div>

        <p
            v-if="request.autoResolved"
            class="rounded-lg border border-border bg-muted/40 p-3 text-sm text-muted-foreground"
        >
            Este pedido foi fechado automaticamente por falta de resposta. Se
            ainda precisar de ajuda, responda abaixo e ele reabre.
        </p>

        <ul class="space-y-3">
            <li
                v-for="message in request.messages"
                :key="message.ulid"
                class="rounded-lg border border-border p-4"
                :class="
                    message.role === 'operator'
                        ? 'bg-muted/40'
                        : message.role === 'system'
                          ? 'border-dashed'
                          : ''
                "
            >
                <div
                    class="mb-2 flex items-baseline justify-between gap-2 text-xs text-muted-foreground"
                >
                    <span class="font-medium">{{ message.roleLabel }}</span>
                    <span>{{ formatDateTime(message.createdAt) }}</span>
                </div>
                <p class="text-sm whitespace-pre-line">{{ message.body }}</p>
            </li>
        </ul>

        <form class="space-y-3" @submit.prevent="submit">
            <label class="block text-sm">
                <span class="mb-1 block font-medium">Responder</span>
                <textarea
                    v-model="form.body"
                    rows="5"
                    maxlength="5000"
                    class="w-full rounded-md border border-border bg-background px-3 py-2"
                ></textarea>
                <InputError :message="form.errors.body" />
            </label>
            <Button type="submit" :disabled="form.processing">
                {{
                    request.status === 'resolved'
                        ? 'Responder e reabrir'
                        : 'Enviar resposta'
                }}
            </Button>
        </form>
    </div>
</template>
