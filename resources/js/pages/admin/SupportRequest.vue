<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import InputError from '@/components/InputError.vue';

/**
 * Admin > Suporte > um pedido: o fio, as decisões e as entregas.
 *
 * TUDO O QUE UM OPERADOR PRECISA ESTÁ AQUI — responder, mudar de estado,
 * classificar, suspender e retomar a eliminação, e reenviar um aviso que não
 * chegou. Uma Central que só recolhe pedidos sem os operar seria uma caixa de
 * correio com mais passos.
 *
 * A NOTA DA SUSPENSÃO É VISÍVEL AQUI E EM MAIS LADO NENHUM: não vai para a
 * auditoria, não vai para email, e é o único campo do hold que a anonimização
 * apaga.
 */

type Message = {
    ulid: string;
    role: string;
    roleLabel: string;
    authorName: string | null;
    body: string;
    createdAt: string | null;
};

type Delivery = {
    ulid: string;
    type: string;
    typeLabel: string;
    recipientLabel: string;
    attempts: number;
    deliveredAt: string | null;
    failureLabel: string | null;
    lastFailedAt: string | null;
};

type SupportRequestDetail = {
    ulid: string;
    reference: string;
    subject: string | null;
    categoryLabel: string;
    status: string;
    statusLabel: string;
    technicalCode: string | null;
    requesterName: string | null;
    requesterEmail: string | null;
    organizationName: string | null;
    source: string;
    appVersion: string;
    technicalReference: string | null;
    technicalRoute: string | null;
    description: string | null;
    createdAt: string | null;
    resolvedAt: string | null;
    autoResolved: boolean;
    holdActive: boolean;
    holdReasonLabel: string | null;
    holdAt: string | null;
    holdNote: string | null;
    holdReleasedAt: string | null;
    isAnonymised: boolean;
    messages: Message[];
    deliveries: Delivery[];
};

const props = defineProps<{
    request: SupportRequestDetail;
    options: {
        statuses: { value: string; label: string }[];
        technicalCodes: { value: string; label: string }[];
        holdReasons: { value: string; label: string }[];
    };
}>();

const base = `/admin/support/${props.request.ulid}`;

const replyForm = useForm({ body: '' });
const statusForm = useForm({ status: props.request.status });
const classifyForm = useForm({ technical_code: props.request.technicalCode });
const holdForm = useForm({ reason_code: '', note: '' });
const releaseForm = useForm({});
const resendForm = useForm({ notification_type: '' });

function reply(): void {
    replyForm.post(`${base}/reply`, {
        preserveScroll: true,
        onSuccess: () => replyForm.reset('body'),
    });
}

function changeStatus(): void {
    statusForm.post(`${base}/status`, { preserveScroll: true });
}

function classify(): void {
    classifyForm.post(`${base}/classify`, { preserveScroll: true });
}

function applyHold(): void {
    holdForm.post(`${base}/hold`, {
        preserveScroll: true,
        onSuccess: () => holdForm.reset(),
    });
}

function releaseHold(): void {
    if (!window.confirm('Retomar a eliminação deste pedido?')) {
        return;
    }
    releaseForm.delete(`${base}/hold`, { preserveScroll: true });
}

function resend(type: string): void {
    resendForm.notification_type = type;
    resendForm.post(`${base}/resend`, { preserveScroll: true });
}

function formatDateTime(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString('pt-PT') : '—';
}
</script>

<template>
    <Head :title="`Suporte ${request.reference}`" />

    <div class="space-y-6 p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">
                    {{ request.subject ?? request.reference }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    <span class="font-mono">{{ request.reference }}</span> ·
                    {{ request.categoryLabel }} · {{ request.statusLabel }}
                    <span v-if="request.autoResolved">
                        · fechado por inatividade</span
                    >
                </p>
            </div>
            <Button as-child variant="outline" size="sm">
                <Link href="/admin/support">← Fila</Link>
            </Button>
        </div>

        <p
            v-if="request.isAnonymised"
            class="rounded-lg border border-border bg-muted/40 p-3 text-sm text-muted-foreground"
        >
            Este pedido foi anonimizado por retenção. O conteúdo e as mensagens
            já não existem; ficam a referência, o assunto e as datas.
        </p>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
            <!-- O fio -->
            <section class="space-y-4">
                <div
                    v-if="request.description"
                    class="rounded-lg border border-border p-4"
                >
                    <p class="mb-2 text-xs text-muted-foreground">
                        Pedido inicial ·
                        {{ formatDateTime(request.createdAt) }}
                    </p>
                    <p class="text-sm whitespace-pre-line">
                        {{ request.description }}
                    </p>
                </div>

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
                            <span class="font-medium"
                                >{{ message.roleLabel
                                }}<template v-if="message.authorName">
                                    · {{ message.authorName }}</template
                                ></span
                            >
                            <span>{{ formatDateTime(message.createdAt) }}</span>
                        </div>
                        <p class="text-sm whitespace-pre-line">
                            {{ message.body }}
                        </p>
                    </li>
                </ul>

                <form
                    v-if="!request.isAnonymised"
                    class="space-y-3 rounded-lg border border-border p-4"
                    @submit.prevent="reply"
                >
                    <label class="block text-sm">
                        <span class="mb-1 block font-medium">Responder</span>
                        <textarea
                            v-model="replyForm.body"
                            rows="5"
                            maxlength="5000"
                            class="w-full rounded-md border border-border bg-background px-3 py-2"
                        ></textarea>
                        <InputError :message="replyForm.errors.body" />
                    </label>
                    <p class="text-xs text-muted-foreground">
                        Responder coloca o pedido «à espera de resposta» e
                        começa a contar os 23 e os 30 dias.
                    </p>
                    <Button type="submit" :disabled="replyForm.processing">
                        Enviar resposta
                    </Button>
                </form>
            </section>

            <!-- As decisões -->
            <aside class="space-y-4">
                <section class="space-y-2 rounded-lg border border-border p-4">
                    <h2 class="text-sm font-medium">Quem pediu</h2>
                    <dl class="space-y-1 text-sm text-muted-foreground">
                        <div>{{ request.requesterName ?? '—' }}</div>
                        <div>{{ request.requesterEmail ?? '—' }}</div>
                        <div v-if="request.organizationName">
                            {{ request.organizationName }}
                            <span class="text-xs">(contexto)</span>
                        </div>
                        <div class="text-xs">
                            {{
                                request.source === 'guest'
                                    ? 'Sem conta'
                                    : 'Com conta'
                            }}
                            · versão {{ request.appVersion }}
                        </div>
                        <div v-if="request.technicalRoute" class="text-xs">
                            rota: {{ request.technicalRoute }}
                        </div>
                    </dl>
                </section>

                <section class="space-y-2 rounded-lg border border-border p-4">
                    <h2 class="text-sm font-medium">Estado</h2>
                    <select
                        v-model="statusForm.status"
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
                    <Button size="sm" variant="outline" @click="changeStatus"
                        >Mudar estado</Button
                    >
                </section>

                <section class="space-y-2 rounded-lg border border-border p-4">
                    <h2 class="text-sm font-medium">Classificação técnica</h2>
                    <p class="text-xs text-muted-foreground">
                        Interna. Nada a infere — é quem olha que a atribui.
                    </p>
                    <select
                        v-model="classifyForm.technical_code"
                        class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                    >
                        <option :value="null">Por classificar</option>
                        <option
                            v-for="option in options.technicalCodes"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                    <Button size="sm" variant="outline" @click="classify"
                        >Classificar</Button
                    >
                </section>

                <section class="space-y-2 rounded-lg border border-border p-4">
                    <h2 class="text-sm font-medium">Retenção</h2>

                    <template v-if="request.holdActive">
                        <p class="text-sm">
                            Eliminação suspensa desde
                            {{ formatDateTime(request.holdAt) }} —
                            {{ request.holdReasonLabel }}.
                        </p>
                        <p
                            v-if="request.holdNote"
                            class="rounded bg-muted p-2 text-xs whitespace-pre-line"
                        >
                            {{ request.holdNote }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Suspender trava apenas a anonimização. Libertar não
                            reinicia os 24 meses.
                        </p>
                        <Button size="sm" variant="outline" @click="releaseHold"
                            >Retomar eliminação</Button
                        >
                    </template>

                    <form v-else class="space-y-2" @submit.prevent="applyHold">
                        <select
                            v-model="holdForm.reason_code"
                            class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                        >
                            <option value="" disabled>Motivo</option>
                            <option
                                v-for="option in options.holdReasons"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select>
                        <InputError :message="holdForm.errors.reason_code" />
                        <textarea
                            v-model="holdForm.note"
                            rows="2"
                            maxlength="1000"
                            placeholder="Nota interna (opcional)"
                            class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                        ></textarea>
                        <Button
                            type="submit"
                            size="sm"
                            variant="outline"
                            :disabled="holdForm.processing"
                            >Suspender eliminação</Button
                        >
                    </form>
                </section>

                <section
                    v-if="request.deliveries.length"
                    class="space-y-2 rounded-lg border border-border p-4"
                >
                    <h2 class="text-sm font-medium">Notificações</h2>
                    <ul class="space-y-2 text-xs">
                        <li
                            v-for="entrega in request.deliveries"
                            :key="entrega.ulid"
                            class="flex flex-wrap items-center gap-2"
                        >
                            <span class="flex-1">{{ entrega.typeLabel }}</span>
                            <span
                                v-if="entrega.deliveredAt"
                                class="text-muted-foreground"
                                >entregue</span
                            >
                            <span v-else class="text-destructive">{{
                                entrega.failureLabel ?? 'por entregar'
                            }}</span>
                            <Button
                                size="sm"
                                variant="outline"
                                :disabled="resendForm.processing"
                                @click="resend(entrega.type)"
                                >Reenviar</Button
                            >
                        </li>
                    </ul>
                </section>
            </aside>
        </div>
    </div>
</template>
