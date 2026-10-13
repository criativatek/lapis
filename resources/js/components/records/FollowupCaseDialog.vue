<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Check, ClipboardCopy, ExternalLink, RotateCcw } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import { Dialog, DialogDescription, DialogHeader, DialogScrollContent, DialogTitle } from '@/components/ui/dialog';
import type { CaseDetail } from '@/lib/recordFollowup';
import { copyText, declarationAuthor, formatDay, formatInstant, occurrencesLabel, statusLabel, studentLabel, weekday } from '@/lib/recordFollowup';
import { statusToneClasses } from '@/lib/statusTone';

/**
 * One student's follow-up case: the occurrences, the Inovar message prepared
 * from exactly those occurrences, and the teacher's «Registado no Inovar».
 *
 * The message is edited here and never saved — editing it changes nothing in
 * the logbook. Copying never marks anything. Marking asks for an explicit
 * confirmation, because it is the teacher's statement: the application never
 * sees the Inovar.
 */
const props = defineProps<{
    detail: CaseDetail;
    /** The consultation URL as it stands (filters, page, open case) — for «Abrir registo» and back. */
    currentUrl: string;
}>();

const emit = defineEmits<{ close: [] }>();

const page = usePage();

const caseKey = computed(() => `${props.detail.class.ulid}|${props.detail.student.ulid}|${props.detail.category}|${props.detail.window.label}`);

const message = ref(props.detail.message.text);
const copyState = ref<'idle' | 'copied' | 'failed'>('idle');
const confirming = ref(false);
const submitting = ref(false);
const messageElement = ref<HTMLTextAreaElement | null>(null);

// A different case — or the same one whose occurrences changed after a
// correction — starts from the text the server prepared for it.
watch(
    () => [caseKey.value, props.detail.message.text] as const,
    ([key, text], [previousKey, previousText]) => {
        if (key !== previousKey || text !== previousText) {
            message.value = text;
            copyState.value = 'idle';
            confirming.value = false;
        }
    },
);

const occurrencesError = computed(() => (page.props.errors as Record<string, string> | undefined)?.occurrences ?? null);

const missingNotice = computed(() => {
    const missing = props.detail.message.missing;

    if (missing.length === 0) {
        return null;
    }

    const pieces = missing.map((piece) => (piece === 'number' ? 'o número do aluno na turma' : 'o nome do aluno'));

    return `Falta ${pieces.join(' e ')} — reveja a identificação antes de copiar.`;
});

const isEdited = computed(() => message.value !== props.detail.message.text);

/** The threshold the server enforces too — never a declaration below it. */
const ALERT_THRESHOLD = 3;

const blockedReason = computed(() => {
    if (!props.detail.window.is_period) {
        return 'Para marcar como registado no Inovar, consulte por período de avaliação.';
    }

    if (!props.detail.can_confirm) {
        return 'Não é possível marcar como registado no Inovar durante uma sessão de apoio técnico.';
    }

    if (props.detail.count < ALERT_THRESHOLD) {
        return '«Marcar como registado no Inovar» fica disponível a partir da 3.ª ocorrência deste tipo no período.';
    }

    return null;
});

const statusText = computed(() => statusLabel(props.detail.status));

function recordHref(recordUlid: string): string {
    const query = new URLSearchParams({ registo: recordUlid, voltar: props.currentUrl });

    return `/classes/${props.detail.class.ulid}/records?${query.toString()}`;
}

async function copyMessage(): Promise<void> {
    if (await copyText(message.value)) {
        copyState.value = 'copied';

        return;
    }

    // The browser refused: leave the text selected so it can be copied by hand.
    copyState.value = 'failed';
    await nextTick();
    messageElement.value?.focus();
    messageElement.value?.select();
}

function resetMessage(): void {
    message.value = props.detail.message.text;
    copyState.value = 'idle';
}

function confirmRegistration(): void {
    if (!props.detail.window.period_ulid) {
        return;
    }

    submitting.value = true;

    router.post(
        '/records/acompanhamento/confirmacoes',
        {
            class: props.detail.class.ulid,
            student: props.detail.student.ulid,
            category: props.detail.category,
            period: props.detail.window.period_ulid,
            occurrences: props.detail.occurrences.map((occurrence) => ({ ulid: occurrence.ulid, date: occurrence.date })),
        },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                confirming.value = false;
            },
            onFinish: () => {
                submitting.value = false;
            },
        },
    );
}

function undoRegistration(): void {
    const confirmation = props.detail.confirmation;

    if (!confirmation || !confirm('Desfazer a marcação de registado no Inovar? O histórico fica guardado.')) {
        return;
    }

    submitting.value = true;

    router.delete(`/records/acompanhamento/confirmacoes/${confirmation.ulid}`, {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => {
            submitting.value = false;
        },
    });
}

function onOpenChange(open: boolean): void {
    if (!open) {
        emit('close');
    }
}
</script>

<template>
    <Dialog :open="true" @update:open="onOpenChange">
        <DialogScrollContent class="max-w-2xl gap-5 p-4 sm:p-6" data-test="followup-case">
            <DialogHeader class="pr-8 text-left">
                <DialogTitle class="text-lg">{{ studentLabel(detail.student.number, detail.student.name) }}</DialogTitle>
                <DialogDescription>
                    {{ detail.class.label }} · {{ detail.class.subject }} — {{ detail.category_label }} — {{ detail.window.label }}
                </DialogDescription>
            </DialogHeader>

            <div class="flex flex-wrap items-center gap-2 text-sm">
                <span class="font-semibold tabular-nums">{{ occurrencesLabel(detail.count) }}</span>
                <span
                    v-if="statusText"
                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                    :class="statusToneClasses(detail.status ?? 'none')"
                    data-test="case-status"
                >
                    {{ statusText }}
                </span>
                <span v-if="detail.origin === 'alert' && detail.alerted_at" class="text-xs text-muted-foreground">
                    Alerta em {{ formatInstant(detail.alerted_at) }}
                </span>
                <span v-else-if="detail.origin === 'existing'" class="text-xs text-muted-foreground">
                    Chegou a três ou mais sem alerta (registos anteriores aos alertas, importados ou com datas de períodos alteradas).
                </span>
            </div>

            <p v-if="detail.count === 0" class="rounded-lg border border-dashed border-border px-3 py-2 text-sm text-muted-foreground" data-test="no-occurrences">
                Já não há ocorrências deste tipo neste período (foram corrigidas ou eliminadas).
            </p>

            <p v-if="detail.homework" class="rounded-lg bg-muted/30 px-3 py-2 text-sm" data-test="homework-breakdown">
                <span class="font-medium">{{ detail.homework.verified === 1 ? '1 verificação de TPC' : `${detail.homework.verified} verificações de TPC` }} neste período:</span>
                <span class="tabular-nums">
                    <span aria-hidden="true">✓</span> {{ detail.homework.done }} realizou ·
                    <span aria-hidden="true">◐</span> {{ detail.homework.partially_done }} parcialmente ·
                    <span aria-hidden="true">✕</span> {{ detail.homework.not_done }} não realizou.
                </span>
                <span v-if="detail.homework.unmarked_days > 0" class="block text-xs text-muted-foreground">
                    Sem marcação em {{ detail.homework.unmarked_days === 1 ? '1 dia' : `${detail.homework.unmarked_days} dias` }} em que a turma teve TPC marcado — por verificar, não conta como não realizado.
                </span>
                <span class="block text-xs text-muted-foreground">Só «Não realizou» entra na contagem e no alerta.</span>
            </p>

            <section v-if="detail.count > 0" aria-labelledby="case-occurrences">
                <h3 id="case-occurrences" class="mb-2 text-sm font-semibold">Ocorrências abrangidas</h3>
                <ol class="divide-y divide-border rounded-lg border border-border">
                    <li v-for="occurrence in detail.occurrences" :key="occurrence.ulid" class="flex items-start gap-3 px-3 py-2">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm">
                                <span class="font-medium tabular-nums">{{ formatDay(occurrence.date) }}</span>
                                <span class="text-muted-foreground"> · {{ weekday(occurrence.date) }}</span>
                                <span v-if="occurrence.detail_label" class="text-muted-foreground"> · {{ occurrence.detail_label }}</span>
                            </p>
                            <p v-if="occurrence.description" class="mt-0.5 text-sm break-words text-muted-foreground">{{ occurrence.description }}</p>
                        </div>
                        <Link
                            :href="recordHref(occurrence.ulid)"
                            class="inline-flex min-h-11 shrink-0 items-center gap-1 rounded-md px-2 text-xs font-medium text-primary hover:bg-muted/40"
                            :aria-label="`Abrir o registo de ${formatDay(occurrence.date)}`"
                        >
                            <ExternalLink class="size-3.5" aria-hidden="true" /> Abrir registo
                        </Link>
                    </li>
                </ol>
            </section>

            <section v-if="detail.count > 0" aria-labelledby="case-message" class="space-y-2">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h3 id="case-message" class="text-sm font-semibold">Mensagem para o Inovar</h3>
                    <span class="text-xs text-muted-foreground">Editar o texto não altera os registos.</span>
                </div>
                <p class="text-xs text-muted-foreground">Registar uma ocorrência de nível 2 no Inovar é uma decisão sua; esta mensagem só a prepara.</p>
                <p v-if="missingNotice" class="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-950 dark:bg-amber-950/40 dark:text-amber-100" role="note">
                    {{ missingNotice }}
                </p>
                <label class="sr-only" for="case-message-text">Texto da mensagem</label>
                <textarea
                    id="case-message-text"
                    ref="messageElement"
                    v-model="message"
                    rows="6"
                    class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm leading-relaxed"
                    @input="copyState = 'idle'"
                />
                <div class="flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        class="inline-flex min-h-11 items-center gap-2 rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:opacity-90"
                        data-test="copy-message"
                        @click="copyMessage"
                    >
                        <ClipboardCopy class="size-4" aria-hidden="true" /> Copiar mensagem para o Inovar
                    </button>
                    <button
                        v-if="isEdited"
                        type="button"
                        class="inline-flex min-h-11 items-center gap-1 rounded-md px-3 text-sm text-muted-foreground hover:bg-muted/40"
                        @click="resetMessage"
                    >
                        <RotateCcw class="size-4" aria-hidden="true" /> Repor texto
                    </button>
                </div>
                <p class="min-h-5 text-sm" aria-live="polite" data-test="copy-feedback">
                    <span v-if="copyState === 'copied'" class="inline-flex items-center gap-1 text-emerald-700 dark:text-emerald-300">
                        <Check class="size-4" aria-hidden="true" /> Mensagem copiada. Copiar não marca o caso como registado.
                    </span>
                    <span v-else-if="copyState === 'failed'" class="text-foreground">
                        Este browser não permitiu copiar automaticamente. O texto ficou selecionado: use Ctrl+C (ou, no telemóvel, toque longo › Copiar).
                    </span>
                </p>
            </section>

            <section aria-labelledby="case-inovar" class="space-y-3 border-t border-border pt-4">
                <h3 id="case-inovar" class="text-sm font-semibold">Registo no Inovar</h3>

                <div v-if="detail.confirmation" class="space-y-2 text-sm">
                    <p>
                        Marcado como registado no Inovar por {{ declarationAuthor(detail.confirmation.confirmed_by, detail.confirmation.restored) }}
                        em {{ formatInstant(detail.confirmation.confirmed_at) }}, com {{ occurrencesLabel(detail.confirmation.count) }}
                        ({{ detail.confirmation.dates.map(formatDay).join(', ') }}).
                        <template v-if="detail.confirmation.restored">Marcação reposta a partir de um backup.</template>
                    </p>
                    <p
                        v-if="detail.confirmation.missing.length > 0"
                        class="rounded-md bg-amber-50 px-3 py-2 text-amber-950 dark:bg-amber-950/40 dark:text-amber-100"
                        role="note"
                        data-test="confirmation-missing"
                    >
                        {{ detail.confirmation.missing.length }} das {{ detail.confirmation.count }} ocorrências declaradas não {{ detail.confirmation.missing.length === 1 ? 'foi recuperada' : 'foram recuperadas' }}
                        no restauro ({{ detail.confirmation.missing.map(formatDay).join(', ') }}): o registo já não existe nesta conta ou não pôde ser associado.
                        A marcação fica como foi feita; confirme no Inovar, se for preciso.
                    </p>
                    <p
                        v-if="detail.confirmation.changes.added.length > 0 || detail.confirmation.changes.removed.length > 0"
                        class="rounded-md bg-muted/40 px-3 py-2 text-muted-foreground"
                        data-test="confirmation-changes"
                    >
                        Os registos de origem mudaram desde a marcação:
                        <template v-if="detail.confirmation.changes.removed.length > 0">
                            {{ detail.confirmation.changes.removed.length === 1 ? 'deixou de contar 1 registo' : `deixaram de contar ${detail.confirmation.changes.removed.length} registos` }}
                            ({{ detail.confirmation.changes.removed.map(formatDay).join(', ') }})</template><template v-if="detail.confirmation.changes.removed.length > 0 && detail.confirmation.changes.added.length > 0">; </template>
                        <template v-if="detail.confirmation.changes.added.length > 0">
                            {{ detail.confirmation.changes.added.length === 1 ? 'há 1 registo novo' : `há ${detail.confirmation.changes.added.length} registos novos` }}
                            ({{ detail.confirmation.changes.added.map(formatDay).join(', ') }})</template>.
                        A aplicação não altera nem verifica o registo feito no Inovar.
                    </p>
                    <button
                        v-if="detail.can_confirm"
                        type="button"
                        class="inline-flex min-h-11 items-center rounded-md border border-border px-3 text-sm hover:bg-muted/40 disabled:opacity-50"
                        :disabled="submitting"
                        @click="undoRegistration"
                    >
                        Desfazer marcação
                    </button>
                </div>

                <template v-else>
                    <p v-if="blockedReason" class="text-sm text-muted-foreground">{{ blockedReason }}</p>

                    <template v-else>
                        <button
                            v-if="!confirming"
                            type="button"
                            class="inline-flex min-h-11 items-center rounded-md border border-border px-4 text-sm font-medium hover:bg-muted/40"
                            data-test="mark-registered"
                            @click="confirming = true"
                        >
                            Marcar como registado no Inovar
                        </button>

                        <div v-else class="space-y-3 rounded-lg border border-border bg-muted/20 p-3 text-sm" role="group" aria-labelledby="confirm-title">
                            <p id="confirm-title" class="font-medium">Confirma que registou esta ocorrência no Inovar?</p>
                            <p class="text-muted-foreground">
                                É uma declaração sua: a aplicação não verifica o Inovar. Ficam guardados o seu nome, a hora e as
                                {{ occurrencesLabel(detail.count) }} abrangidas ({{ detail.occurrences.map((occurrence) => formatDay(occurrence.date)).join(', ') }}).
                                Pode desfazer depois.
                            </p>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    class="inline-flex min-h-11 items-center rounded-md bg-primary px-4 font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50"
                                    :disabled="submitting"
                                    data-test="confirm-registered"
                                    @click="confirmRegistration"
                                >
                                    Sim, registei no Inovar
                                </button>
                                <button type="button" class="inline-flex min-h-11 items-center rounded-md px-3 hover:bg-muted/40" @click="confirming = false">
                                    Cancelar
                                </button>
                            </div>
                        </div>
                    </template>
                </template>

                <p v-if="occurrencesError" class="text-sm text-destructive" role="alert">{{ occurrencesError }}</p>

                <details v-if="detail.history.length > 0" class="text-sm">
                    <summary class="min-h-11 cursor-pointer py-2 text-muted-foreground">Histórico das marcações ({{ detail.history.length }})</summary>
                    <ul class="space-y-1 pl-1">
                        <li v-for="entry in detail.history" :key="entry.ulid" class="text-muted-foreground">
                            {{ formatInstant(entry.confirmed_at) }} — marcado por {{ declarationAuthor(entry.confirmed_by, entry.restored) }} ({{ occurrencesLabel(entry.count) }})<template v-if="entry.undone_at">; desfeito por {{ declarationAuthor(entry.undone_by, entry.restored) }} em {{ formatInstant(entry.undone_at) }}</template><template v-if="entry.restored"> (reposta de um backup)</template>.
                        </li>
                    </ul>
                </details>
            </section>
        </DialogScrollContent>
    </Dialog>
</template>
