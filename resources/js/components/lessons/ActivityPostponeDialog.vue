<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Info } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { eventTimeLabel, summaryLineFor } from '@/lib/lessonDayEvents';
import type { DayEventTiming } from '@/lib/lessonDayEvents';
import { SURFACE } from '@/lib/surfaces';

export type PostponeEvent = DayEventTiming & { ulid: string; title: string; notes?: string | null };

type PostponeMove = {
    from: { ulid: string | null; starts_at: string };
    to: {
        ulid: string | null;
        starts_at: string;
        ends_at: string | null;
        lesson_number: number | null;
        lesson_number_end: number | null;
        lesson_number_label: string | null;
    };
    created_from_timetable: boolean;
    excerpt: string | null;
    fields: string[];
};

export type PostponePreview = {
    lesson: { ulid: string; starts_at: string };
    event: { ulid: string; title: string };
    outcome_label: string;
    note: string | null;
    stripped_activity_line: boolean;
    moves: PostponeMove[];
    pending: {
        on: { ulid: string; starts_at: string };
        on_this_lesson: boolean;
        excerpt: string | null;
        fields: string[];
    } | null;
    nothing_to_move: boolean;
    discarded_attendance_drafts: number;
    renumbered: { ulid: string; starts_at: string; from: number | null; to: number | null }[];
    /** Quantas preparações mudam de lugar — as que avançam e a que fica pendente. */
    displaced_preparations: number;
    requires_cascade_confirmation: boolean;
    plan_token: string;
};

/** Os campos que viajam com a preparação, além do texto do sumário. */
const FIELD_LABELS: Record<string, string> = {
    resources: 'recursos',
    homework: 'TPC',
    private_notes: 'notas do professor',
};

/**
 * «Registar e adiar a preparação» (0.171.0): a pré-visualização do que
 * acontece quando uma atividade do calendário ocupa a aula. NADA muda até o
 * professor confirmar — o servidor calcula o mesmo plano que depois executa,
 * numa transação revertida, e devolve o `plan_token` que a confirmação exige.
 *
 * A preparação que passa para a aula seguinte é a que está GUARDADA: com
 * alterações por guardar o diálogo não pede pré-visualização nenhuma — pede
 * primeiro para guardar (`saveFirst`, a gravação da própria página), e só
 * depois mostra o efeito. Se a gravação não for aceite (conflito de versão,
 * número por confirmar, erro), o diálogo fecha-se: a razão está na página, por
 * trás dele, e o professor tem de a ver.
 */
const props = defineProps<{
    lessonUlid: string;
    event: PostponeEvent | null;
    hasUnsavedChanges: boolean;
    /** Grava o que a página tem por guardar; resolve `true` só se a gravação foi aceite. */
    saveFirst?: () => Promise<boolean>;
}>();

const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ submitting: [boolean]; done: [] }>();

const savingFirst = ref(false);

async function saveBeforePreview(): Promise<void> {
    if (props.saveFirst === undefined || savingFirst.value) {
        return;
    }

    savingFirst.value = true;

    try {
        if (!(await props.saveFirst())) {
            open.value = false;
        }
    } finally {
        savingFirst.value = false;
    }
}

/**
 * A linha que «Incluir no sumário» gera para este acontecimento — a mesma regra
 * do servidor (`LessonDayEvents::summaryLine`), que só retira linhas inteiras
 * iguais a esta.
 */
const activityLine = computed(() => (props.event === null ? '' : summaryLineFor({ title: props.event.title, notes: props.event.notes ?? null })));
const hasEventNotes = computed(() => (props.event?.notes ?? '').trim() !== '');

function fromLabel(move: PostponeMove, index: number): string {
    return index === 0 ? 'A preparação desta aula' : `A preparação de ${dayLabel(move.from.starts_at)}`;
}

function extraFields(fields: string[]): string | null {
    const extra = fields.filter((field) => field !== 'content').map((field) => FIELD_LABELS[field] ?? field);

    return extra.length === 0 ? null : `Leva também: ${extra.join(', ')}.`;
}

const preview = ref<PostponePreview | null>(null);
const loading = ref(false);
/** A pré-visualização foi recusada (422) ou falhou: não há nada para confirmar. */
const previewError = ref<string | null>(null);
/** O registo foi recusado depois da pré-visualização; o plano volta a calcular-se. */
const refusal = ref<string | null>(null);
const confirmCascade = ref(false);
const submitting = ref(false);
/** Guarda de pedidos concorrentes: só a resposta do último pedido conta. */
let requestCounter = 0;

const dateFormatter = new Intl.DateTimeFormat('pt-PT', {
    weekday: 'short',
    day: 'numeric',
    month: 'numeric',
    timeZone: 'Europe/Lisbon',
});
const timeFormatter = new Intl.DateTimeFormat('pt-PT', { hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Lisbon' });

function dayLabel(iso: string): string {
    return dateFormatter.format(new Date(iso));
}

function timeRange(startIso: string, endIso: string | null): string {
    const start = timeFormatter.format(new Date(startIso));

    return endIso ? `${start}–${timeFormatter.format(new Date(endIso))}` : start;
}

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

function firstMessage(errors: Record<string, string | string[]> | undefined | null): string | null {
    if (!errors) {
        return null;
    }

    for (const value of Object.values(errors)) {
        const message = Array.isArray(value) ? value[0] : value;

        if (message) {
            return message;
        }
    }

    return null;
}

async function requestPreview(): Promise<void> {
    if (props.event === null) {
        return;
    }

    const current = ++requestCounter;
    loading.value = true;
    previewError.value = null;
    preview.value = null;
    confirmCascade.value = false;

    try {
        const response = await fetch(`/lessons/${props.lessonUlid}/activity-postponement/preview`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify({ calendar_event: props.event.ulid }),
        });

        if (current !== requestCounter) {
            return;
        }

        const body = await response.json().catch(() => null);

        if (current !== requestCounter) {
            return;
        }

        if (response.status === 422) {
            previewError.value = firstMessage(body?.errors) ?? 'Não foi possível pré-visualizar. Nada foi alterado.';

            return;
        }

        if (!response.ok || body === null) {
            previewError.value = 'Não foi possível pré-visualizar. Nada foi alterado — tenta outra vez.';

            return;
        }

        preview.value = body as PostponePreview;
    } catch {
        if (current === requestCounter) {
            previewError.value = 'Não foi possível pré-visualizar: a ligação falhou. Nada foi alterado.';
        }
    } finally {
        if (current === requestCounter) {
            loading.value = false;
        }
    }
}

function reset(): void {
    requestCounter++;
    loading.value = false;
    preview.value = null;
    previewError.value = null;
    confirmCascade.value = false;
}

// Pede a pré-visualização quando o diálogo abre COM tudo guardado, e volta a
// pedi-la quando as alterações por guardar deixam de existir. Fechar invalida
// qualquer pedido em curso (a resposta tardia não reabre nada).
watch(
    () => [open.value, props.hasUnsavedChanges, props.event?.ulid] as const,
    ([isOpen, unsaved]) => {
        if (!isOpen) {
            reset();
            refusal.value = null;

            return;
        }

        if (unsaved) {
            reset();

            return;
        }

        void requestPreview();
    },
    { immediate: true },
);

const moveCount = computed(() => preview.value?.moves.length ?? 0);

const canConfirm = computed(
    () =>
        !props.hasUnsavedChanges &&
        preview.value !== null &&
        previewError.value === null &&
        !loading.value &&
        !submitting.value &&
        (!preview.value.requires_cascade_confirmation || confirmCascade.value),
);

function confirm(): void {
    if (!canConfirm.value || preview.value === null || props.event === null) {
        return;
    }

    submitting.value = true;
    refusal.value = null;
    emit('submitting', true);

    router.post(
        `/lessons/${props.lessonUlid}/activity-postponement`,
        {
            calendar_event: props.event.ulid,
            plan_token: preview.value.plan_token,
            confirm_cascade: confirmCascade.value,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                open.value = false;
                emit('done');
            },
            onError: (errors) => {
                // A razão fica à vista e o plano volta a calcular-se: o token
                // que o professor viu já não vale.
                refusal.value = firstMessage(errors) ?? 'Não foi possível registar. Nada foi alterado.';
                void requestPreview();
            },
            onFinish: () => {
                submitting.value = false;
                emit('submitting', false);
            },
        },
    );
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg">
            <DialogHeader class="space-y-2">
                <DialogTitle>Registar e adiar a preparação</DialogTitle>
                <DialogDescription>
                    <span v-if="event" class="block font-medium text-foreground">
                        {{ event.title }} · {{ eventTimeLabel(event) }}
                    </span>
                    A aula fica registada como atividade do calendário e a preparação avança uma aula. Nada
                    muda até confirmares.
                </DialogDescription>
            </DialogHeader>

            <div v-if="hasUnsavedChanges" :class="['space-y-3 rounded-xl border p-4 text-sm', SURFACE.sky]" data-testid="postpone-unsaved">
                <p class="flex items-start gap-2">
                    <Info class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    Tens alterações por guardar no sumário desta aula. Guarda-as primeiro: a preparação que passa
                    para a aula seguinte é a que está guardada.
                </p>
                <Button
                    v-if="saveFirst"
                    type="button"
                    class="min-h-11"
                    data-testid="postpone-save-first"
                    :disabled="savingFirst"
                    @click="saveBeforePreview"
                >
                    <Spinner v-if="savingFirst" />
                    {{ savingFirst ? 'A guardar…' : 'Guardar alterações' }}
                </Button>
            </div>

            <div v-else-if="loading" class="flex items-center gap-2 text-sm text-muted-foreground" role="status">
                <Spinner /> A calcular o que acontece…
            </div>

            <InputError v-if="previewError && !hasUnsavedChanges" :message="previewError" data-testid="postpone-error" />
            <InputError v-if="refusal" :message="refusal" data-testid="postpone-refusal" />

            <div v-if="preview && !hasUnsavedChanges" class="space-y-4 text-sm" data-testid="postpone-preview">
                <section :class="['space-y-1 rounded-xl border p-3', SURFACE.sky]">
                    <h3 class="font-semibold">Fica registado nesta aula</h3>
                    <p data-testid="postpone-stays">
                        {{ preview.outcome_label }}<template v-if="preview.note"> — {{ preview.note }}</template>
                    </p>
                    <p class="text-muted-foreground">
                        É o que vês ao reabrir esta aula e o que aparece no registo da turma. A preparação é adiada,
                        como se mostra abaixo.
                    </p>
                    <p v-if="hasEventNotes" class="text-muted-foreground" data-testid="postpone-notes-stay">
                        As notas da atividade não são copiadas para o registo da aula: continuam completas no teu
                        calendário.
                    </p>
                    <p v-if="preview.stripped_activity_line" class="text-muted-foreground" data-testid="postpone-stripped">
                        A linha «{{ activityLine }}», acrescentada por «Incluir no sumário», não segue com a
                        preparação: a atividade fica identificada nesta aula pelo título acima. O resto do texto,
                        incluindo o que escreveste ou alteraste, segue com a preparação.
                    </p>
                </section>

                <section class="space-y-2">
                    <h3 class="font-semibold">Para onde vai a preparação</h3>

                    <p v-if="preview.nothing_to_move" data-testid="postpone-nothing">
                        Esta aula não tinha preparação: nada passa para outra aula.
                    </p>

                    <ol v-else-if="moveCount > 0" class="space-y-2">
                        <li
                            v-for="(move, index) in preview.moves"
                            :key="`${move.to.starts_at}-${index}`"
                            class="rounded-lg border p-3"
                            data-testid="postpone-move"
                        >
                            <p class="text-muted-foreground">{{ fromLabel(move, index) }} passa para</p>
                            <p class="font-medium">
                                {{ dayLabel(move.to.starts_at) }}, {{ timeRange(move.to.starts_at, move.to.ends_at) }}
                                <template v-if="move.to.lesson_number_label"> · Lição {{ move.to.lesson_number_label }}</template>
                            </p>
                            <p v-if="move.created_from_timetable" class="text-muted-foreground">
                                aula criada a partir do horário
                            </p>
                            <p v-if="move.excerpt" class="text-muted-foreground">«{{ move.excerpt }}»</p>
                            <p v-if="extraFields(move.fields)" class="text-muted-foreground">{{ extraFields(move.fields) }}</p>
                        </li>
                    </ol>

                    <div v-if="preview.pending" class="rounded-lg border p-3" data-testid="postpone-pending">
                        <p class="font-medium">
                            {{ moveCount > 0 ? 'A última preparação' : 'Esta preparação' }} não tem aula onde caiba
                        </p>
                        <p class="text-muted-foreground">
                            Não há mais aulas no horário até ao fim do ano letivo. Fica guardada como «Planeamento
                            pendente»
                            {{ preview.pending.on_this_lesson ? 'nesta aula' : `na aula de ${dayLabel(preview.pending.on.starts_at)}` }},
                            recuperável, sem apagar texto.
                        </p>
                        <p v-if="preview.pending.excerpt" class="text-muted-foreground">«{{ preview.pending.excerpt }}»</p>
                        <p v-if="extraFields(preview.pending.fields)" class="text-muted-foreground">{{ extraFields(preview.pending.fields) }}</p>
                    </div>
                </section>

                <p v-if="preview.discarded_attendance_drafts > 0" data-testid="postpone-drafts">
                    {{ preview.discarded_attendance_drafts === 1 ? '1 falta em rascunho é descartada' : `${preview.discarded_attendance_drafts} faltas em rascunho são descartadas` }}:
                    a assiduidade não se aplica a esta aula.
                </p>

                <section v-if="preview.renumbered.length > 0" class="space-y-1" data-testid="postpone-renumbered">
                    <h3 class="font-semibold">Números que mudam</h3>
                    <ul class="space-y-0.5">
                        <li v-for="item in preview.renumbered" :key="item.ulid" class="tabular-nums">
                            {{ dayLabel(item.starts_at) }}: Lição {{ item.from ?? '—' }} → {{ item.to ?? 'sem número' }}
                        </li>
                    </ul>
                </section>

                <p class="text-muted-foreground">
                    Nenhuma preparação, sumário ou registo é substituído: cada preparação avança uma aula, pela mesma
                    ordem.
                </p>

                <label
                    v-if="preview.requires_cascade_confirmation"
                    :class="['flex min-h-11 items-start gap-2 rounded-xl border p-3', SURFACE.sky]"
                    data-testid="postpone-cascade"
                >
                    <input v-model="confirmCascade" type="checkbox" class="mt-1" />
                    <span>Confirmo que estas {{ preview.displaced_preparations }} preparações mudam de aula, como mostrado acima.</span>
                </label>
            </div>

            <DialogFooter class="gap-2">
                <Button type="button" variant="outline" class="min-h-11" data-testid="postpone-cancel" @click="open = false">
                    Cancelar
                </Button>
                <Button type="button" class="min-h-11" :disabled="!canConfirm" data-testid="postpone-confirm" @click="confirm">
                    <Spinner v-if="submitting" />
                    Registar e adiar
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
