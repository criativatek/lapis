<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Check, Copy, Eraser, Info, Save, Trash2 } from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import AlertError from '@/components/AlertError.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import LessonAttendanceList from '@/components/lessons/LessonAttendanceList.vue';
import type { LessonAttendance } from '@/components/lessons/LessonAttendanceList.vue';
import LessonDayEvents from '@/components/lessons/LessonDayEvents.vue';
import type { DayEvent } from '@/components/lessons/LessonDayEvents.vue';
import LessonOutcomePanel from '@/components/lessons/LessonOutcomePanel.vue';
import type { LessonOutcomeValue } from '@/components/lessons/LessonOutcomePanel.vue';
import LessonPreparationContextPanel from '@/components/lessons/LessonPreparationContextPanel.vue';
import UnsavedChangesDialog from '@/components/lessons/UnsavedChangesDialog.vue';
import LessonSummaryConflict from '@/components/lessons/week/LessonSummaryConflict.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useUnsavedChangesGuard } from '@/composables/useUnsavedChangesGuard';
import { markLessonsStale, recordConfirmedSummary } from '@/lib/confirmedSummaries';
import { baseOnLabel } from '@/lib/lessonContext';
import type { LessonBaseSource } from '@/lib/lessonContext';
import { lessonDisplayState } from '@/lib/lessons';
import { statusToneClasses } from '@/lib/statusTone';
import { combineSummaries } from '@/lib/summaryMerge';
import { capitalizeFirst } from '@/lib/text';

type Lesson = {
    ulid: string;
    starts_at: string;
    ends_at: string | null;
    status: 'preparation' | 'prepared' | 'taught';
    status_label: string;
    lesson_number: number | null;
    /**
     * A versão do sumário que esta página leu (`lessons.summary_version`). Vai
     * com cada gravação e com «Limpar sumário»: uma página aberta há horas não
     * apaga em silêncio o que entretanto se gravou noutra janela ou no cartão
     * da semana (0.158.0).
     */
    summary_version: number;
    /** Como a ocorrência fechou (0.146.0) — NULL enquanto aberta. */
    outcome: LessonOutcomeValue | null;
    outcome_label: string | null;
    outcome_reason_label: string | null;
    outcome_note: string | null;
    can_record_outcome: boolean;
    absence_reasons: { value: string; label: string }[];
    pending_plan: string | null;
    /** Decididos no servidor — ver LessonController::show(). */
    can_delete: boolean;
    can_clear_summary: boolean;
    /** «8.º F», ou «8.º F · T1» numa aula de um grupo. Composto no servidor. */
    context_label: string;
    class_group_label: string | null;
    school_class: {
        ulid: string;
        label: string;
        subject: string;
    };
    summary: {
        content: string;
        private_notes: string | null;
        resources: string | null;
        homework: string | null;
        reviewed_at: string | null;
    } | null;
};

const props = defineProps<{ lesson: Lesson; attendance: LessonAttendance; day_events: DayEvent[] }>();

// Rascunho de faltas antes da consolidação: começa com o que o servidor já
// sabia (linhas 'absent' gravadas) e viaja com o sumário e com o "marcar
// lecionada" — nunca é o próprio pedido de registo.
const initialAbsent = props.attendance.students
    .filter((student) => student.status === 'absent')
    .map((student) => student.student_ulid);

const summaryForm = useForm({
    content: props.lesson.summary?.content ?? '',
    private_notes: props.lesson.summary?.private_notes ?? '',
    resources: props.lesson.summary?.resources ?? '',
    homework: props.lesson.summary?.homework ?? '',
    absent: initialAbsent,
    summary_version: props.lesson.summary_version ?? 0,
});
const taughtForm = useForm({ absent: initialAbsent });
const recordForm = useForm({ absent: initialAbsent });
const clearForm = useForm({ summary_version: props.lesson.summary_version ?? 0 });
const deleteForm = useForm({});

function updateAbsent(absent: string[]): void {
    summaryForm.absent = absent;
    taughtForm.absent = absent;
    recordForm.absent = absent;
}

function recordAttendance(): void {
    submittingFromThisPage.value = true;
    recordForm.post(`/lessons/${props.lesson.ulid}/attendance`, {
        preserveScroll: true,
        onSuccess: markLessonsStale,
        onFinish: releaseSubmission,
    });
}

// As duas ações destrutivas desta página são deliberadamente DUAS, com dois
// diálogos e duas frases diferentes: confundir «limpar o sumário» com «eliminar
// a aula» é exatamente o engano que esta funcionalidade existe para desfazer.
const clearDialogOpen = ref(false);
const deleteDialogOpen = ref(false);

function clearSummary(): void {
    submittingFromThisPage.value = true;
    // A versão que a página mostra: limpar só apaga o que se está a ver.
    clearForm.summary_version = props.lesson.summary_version ?? 0;
    clearForm.delete(`/lessons/${props.lesson.ulid}/summary`, {
        preserveScroll: true,
        onSuccess: () => {
            // O sumário limpo também sobe a versão: a semana não pode voltar,
            // pelo histórico, a mostrar o texto que acabou de ser apagado.
            recordConfirmedSummary(props.lesson.ulid, '', props.lesson.summary_version ?? 0);
            markLessonsStale();
            clearDialogOpen.value = false;
            summaryForm.content = '';
            summaryForm.summary_version = props.lesson.summary_version ?? 0;
            summaryForm.defaults({ ...summaryForm.data(), content: '' });
        },
        onFinish: releaseSubmission,
    });
}

// O resultado da aula é registado pelo diálogo de «Não houve aula», que não
// passa por aqui: quando o resultado ou o estado mudam nas props, as aulas da
// semana também ficaram para trás.
watch(
    () => [props.lesson.outcome, props.lesson.status],
    () => markLessonsStale(),
);

function deleteLesson(): void {
    submittingFromThisPage.value = true;
    deleteForm.delete(`/lessons/${props.lesson.ulid}`, {
        onSuccess: markLessonsStale,
        onFinish: releaseSubmission,
    });
}

// "Basear no sumário anterior" — a read-only convenience (never a write) that
// offers the most recent earlier lesson WITH TEXT (taught or only prepared; the button names which) as an editable
// starting point. Fetched once, up front, only when there is nothing typed
// yet to lose — never overwrites anything the teacher already wrote.
const previousSummary = ref<LessonBaseSource | null>(null);

onMounted(async () => {
    if (props.lesson.summary?.content) {
        return;
    }

    try {
        const response = await fetch(`/lessons/${props.lesson.ulid}/previous-summary`, {
            headers: { Accept: 'application/json' },
        });

        if (response.status !== 204) {
            previousSummary.value = (await response.json()) as LessonBaseSource;
        }
    } catch {
        // Best-effort only — no previous summary is offered on failure.
    }
});

const canBasePrevious = computed(() => previousSummary.value !== null && summaryForm.content.trim() === '');

function basePreviousSummary(): void {
    if (previousSummary.value === null) {
        return;
    }

    summaryForm.content = previousSummary.value.content;
    summaryForm.private_notes = previousSummary.value.private_notes ?? '';
    summaryForm.resources = previousSummary.value.resources ?? '';
    summaryForm.homework = previousSummary.value.homework ?? '';
}

const summaryTextarea = ref<HTMLTextAreaElement | null>(null);

function applyDayEventToSummary(content: string, focusSummary: boolean): void {
    summaryForm.content = content;

    if (focusSummary) {
        summaryTextarea.value?.focus();
    }
}

const dateFormatter = new Intl.DateTimeFormat('pt-PT', {
    weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'Europe/Lisbon',
});
const timeFormatter = new Intl.DateTimeFormat('pt-PT', {
    hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Lisbon',
});

const lessonDate = computed(() =>
    capitalizeFirst(dateFormatter.format(new Date(props.lesson.starts_at))),
);
const lessonTime = computed(() => {
    const start = timeFormatter.format(new Date(props.lesson.starts_at));

    return props.lesson.ends_at ? `${start}–${timeFormatter.format(new Date(props.lesson.ends_at))}` : start;
});
/**
 * GRAVAÇÃO RECUSADA POR CONFLITO DE VERSÃO — outra janela (ou o cartão da
 * semana) gravou este sumário depois de esta página o ler. O que se escreveu
 * aqui fica no formulário; mostra-se o texto gravado ao lado para comparar e
 * combinar. Nenhuma escolha grava sozinha: a gravação seguinte volta a ser
 * verificada no servidor, já com a versão que se acabou de ver.
 */
const conflict = computed(() =>
    summaryForm.errors.summary_version
        ? {
              stored: props.lesson.summary?.content ?? '',
              version: props.lesson.summary_version ?? 0,
          }
        : null,
);
const conflictNotice = ref<string | null>(null);
/**
 * Falha que NÃO é de validação (500, ligação perdida): o servidor não deu uma
 * resposta que o formulário saiba ler. Fica à vista, com o texto intacto.
 */
const saveFailure = ref<string | null>(null);

function resolveConflict(choice: 'combine' | 'keep-mine' | 'use-stored'): void {
    const current = conflict.value;

    if (current === null) {
        return;
    }

    if (choice === 'combine') {
        summaryForm.content = combineSummaries(summaryForm.content, current.stored);
        conflictNotice.value = 'Os dois textos foram combinados. Revê e guarda; a versão volta a ser verificada.';
    } else if (choice === 'keep-mine') {
        conflictNotice.value = 'Ficas com o teu texto. Ao guardar, ele substitui o que está gravado.';
    } else {
        summaryForm.content = current.stored;
        conflictNotice.value = null;
    }

    summaryForm.summary_version = current.version;
    summaryForm.clearErrors('summary_version');
    summaryTextarea.value?.focus();
}

const summaryErrors = computed(() =>
    Object.entries(summaryForm.errors)
        .filter(([field]) => field !== 'summary_version')
        .map(([, message]) => message),
);
const attendanceErrors = computed(() => [...Object.values(taughtForm.errors), ...Object.values(recordForm.errors)]);

// The week to return to is derived from the lesson itself, never threaded in
// from wherever the teacher happened to arrive from — a direct link, browser
// history and the weekly view all land on the same, correct week. Anchored at
// noon UTC on the Lisbon calendar date, the same way Index.vue builds its own
// `?week=` values, so a DST shift can never move the date by a day.
const originWeekHref = computed(() => {
    const lisbonDate = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Europe/Lisbon',
    }).format(new Date(props.lesson.starts_at));
    const date = new Date(`${lisbonDate}T12:00:00Z`);
    const isoWeekday = date.getUTCDay() === 0 ? 7 : date.getUTCDay();
    date.setUTCDate(date.getUTCDate() - (isoWeekday - 1));

    return `/lessons?week=${date.toISOString().slice(0, 10)}`;
});

// A submission started by this page's own buttons ("Guardar", "Marcar como
// lecionada") is exactly how the work gets saved — never a way of losing it.
// It is therefore exempt from the unsaved-changes guard below, which would
// otherwise interrogate the teacher about the very request that saves.
const submittingFromThisPage = ref(false);

function releaseSubmission(): void {
    submittingFromThisPage.value = false;
}

/** Grava o formulário inteiro; resolve `true` só quando a gravação foi aceite. */
function submitSummary(): Promise<boolean> {
    submittingFromThisPage.value = true;
    conflictNotice.value = null;
    saveFailure.value = null;

    return new Promise((resolve) => {
        let saved = false;

        summaryForm.put(`/lessons/${props.lesson.ulid}/summary`, {
            preserveScroll: true,
            onSuccess: () => {
                saved = true;
                // Antes de o formulário se dar por limpo, para a versão nova
                // ficar também no ponto de partida.
                summaryForm.summary_version = props.lesson.summary_version ?? 0;
                // Gravação CONFIRMADA: a semana mostra este texto mesmo que o
                // histórico a reponha com as props de antes.
                recordConfirmedSummary(
                    props.lesson.ulid,
                    props.lesson.summary?.content ?? '',
                    props.lesson.summary_version ?? 0,
                );
                markLessonsStale();
            },
            onHttpException: () => {
                saveFailure.value =
                    'Não foi possível guardar: o servidor não respondeu como devia. O teu texto continua aqui — tenta outra vez.';

                return false;
            },
            onNetworkError: () => {
                saveFailure.value =
                    'Não foi possível guardar: a ligação falhou. O teu texto continua aqui, por gravar — tenta outra vez.';

                return false;
            },
            onFinish: () => {
                releaseSubmission();
                resolve(saved);
            },
        });
    });
}

function markTaught(): void {
    submittingFromThisPage.value = true;
    taughtForm.post(`/lessons/${props.lesson.ulid}/mark-taught`, {
        preserveScroll: true,
        onSuccess: markLessonsStale,
        onFinish: releaseSubmission,
    });
}

// Leaving with a sumário half-written loses it silently: nothing on this page
// persists on its own. `summaryForm.isDirty` is Inertia's own comparison
// against the values the form was created with, and it returns to false by
// itself once a save succeeds (useForm re-baselines its defaults in
// onSuccess), so a saved sumário never triggers the warning.
//
// Navigating inside the app opens «Tens alterações por guardar» (with
// «Guardar e continuar», which only continues once the save is accepted);
// closing the tab or reloading gets the browser's own native protection.
const guard = useUnsavedChangesGuard({
    isDirty: () => summaryForm.isDirty,
    isSubmitting: () => submittingFromThisPage.value,
    save: submitSummary,
    discard: () => {},
});

async function saveAndContinue(): Promise<void> {
    const saved = await guard.saveAndContinue();

    if (!saved) {
        summaryTextarea.value?.focus();
    }
}

// «Voltar às aulas da semana» regressa ao sítio exato de onde se veio — a
// mesma vista, os mesmos filtros e a mesma posição — quando se veio de lá
// (Aulas e Sumários guardou a origem nesta sessão). Sem essa origem, vai para
// a semana da aula, como sempre.
const RETURN_KEY = 'lapis.lessons.return';
const returnTarget = ref<{ url: string; viaHistory: boolean }>({
    url: originWeekHref.value,
    viaHistory: false,
});

onMounted(() => {
    try {
        const entry = JSON.parse(window.sessionStorage.getItem(RETURN_KEY) ?? 'null') as {
            url?: string;
            lesson?: string;
        } | null;

        if (entry?.lesson === props.lesson.ulid && typeof entry.url === 'string' && entry.url.startsWith('/lessons')) {
            returnTarget.value = { url: entry.url, viaHistory: true };
        }
    } catch {
        // Sem sessionStorage, o regresso é à semana da aula.
    }
});

function goBack(event: MouseEvent): void {
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
        return;
    }

    event.preventDefault();
    guard.request(() => {
        if (returnTarget.value.viaHistory && window.history.length > 1) {
            window.history.back();
        } else {
            router.visit(returnTarget.value.url);
        }
    });
}
</script>

<template>
    <Head :title="`${lesson.context_label} — Sumário`" />

    <main class="mx-auto w-full max-w-3xl space-y-6 p-4 pb-28 sm:p-6 sm:pb-8">
        <Button as-child variant="ghost" class="-ml-3 min-h-11">
            <a :href="returnTarget.url" data-testid="lesson-back" @click="goBack">
                <ArrowLeft class="size-4" />
                Voltar às aulas da semana
            </a>
        </Button>

        <div class="space-y-3">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <Heading :title="lesson.context_label" :description="lesson.school_class.subject" />
                <div class="flex items-center gap-2">
                    <!-- O mesmo número que a lista e o horário mostram: é o
                         mesmo campo da mesma aula, e não um contador de ecrã. -->
                    <Badge v-if="lesson.lesson_number !== null" variant="outline" class="tabular-nums"
                        >Lição {{ lesson.lesson_number }}</Badge
                    >
                    <Badge variant="secondary" data-testid="lesson-state" :class="statusToneClasses(lessonDisplayState(lesson).value)">{{
                        lessonDisplayState(lesson).label
                    }}</Badge>
                </div>
            </div>

            <dl class="grid gap-3 rounded-xl border bg-card p-4 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Data</dt>
                    <dd class="mt-1">{{ lessonDate }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Hora</dt>
                    <dd class="mt-1">{{ lessonTime }}</dd>
                </div>
            </dl>
        </div>

        <LessonOutcomePanel
            :lesson-ulid="lesson.ulid"
            :outcome="lesson.outcome"
            :outcome-label="lesson.outcome_label"
            :outcome-reason-label="lesson.outcome_reason_label"
            :outcome-note="lesson.outcome_note"
            :can-record="lesson.can_record_outcome"
            :reasons="lesson.absence_reasons"
            :pending-plan="lesson.pending_plan"
            @submitting="(value) => (submittingFromThisPage = value)"
        />

        <!-- O contexto de quem prepara: aberto numa aula por dar, recolhido numa já
             fechada (aí já não se prepara nada). -->
        <LessonPreparationContextPanel :lesson-ulid="lesson.ulid" :default-open="lesson.outcome === null && lesson.status !== 'taught'" />

        <form class="space-y-4" @submit.prevent="submitSummary">
            <AlertError v-if="summaryErrors.length > 0" :errors="summaryErrors" title="Não foi possível guardar o sumário." />
            <AlertError v-if="attendanceErrors.length > 0" :errors="attendanceErrors" title="Não foi possível registar a assiduidade." />

            <div v-if="saveFailure" role="alert" class="flex items-start gap-2 rounded-lg border border-destructive/40 bg-destructive/5 p-3 text-sm text-destructive" data-testid="summary-save-failure">
                <Info class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                {{ saveFailure }}
            </div>

            <div v-if="summaryForm.recentlySuccessful && !saveFailure" role="status" class="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200">
                <Check class="size-4" />
                Sumário guardado.
            </div>

            <LessonDayEvents :events="day_events" :summary-content="summaryForm.content" @append="applyDayEventToSummary" />

            <div class="grid gap-2">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <Label for="lesson-summary" class="text-base font-semibold">Sumário</Label>
                    <Button v-if="canBasePrevious" type="button" variant="outline" size="sm" @click="basePreviousSummary">
                        <Copy class="size-4" /> {{ baseOnLabel(previousSummary) }}
                    </Button>
                </div>
                <textarea id="lesson-summary" ref="summaryTextarea" v-model="summaryForm.content" name="content" rows="10" maxlength="16000" required class="min-h-56 w-full resize-y rounded-xl border border-input bg-background px-4 py-3 text-base leading-relaxed shadow-xs outline-none transition-colors placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50" placeholder="Escreve o sumário desta aula…" :disabled="summaryForm.processing" aria-describedby="lesson-summary-error" />
                <InputError id="lesson-summary-error" :message="summaryForm.errors.content" />
                <p v-if="conflictNotice && !conflict" role="status" class="flex items-start gap-1.5 text-sm">
                    <Info class="mt-0.5 size-4 shrink-0 text-primary" aria-hidden="true" />{{ conflictNotice }}
                </p>
                <LessonSummaryConflict
                    v-if="conflict"
                    class="@container"
                    :draft="summaryForm.content"
                    :stored="conflict.stored"
                    @combine="resolveConflict('combine')"
                    @keep-mine="resolveConflict('keep-mine')"
                    @use-stored="resolveConflict('use-stored')"
                />
            </div>

            <LessonAttendanceList
                v-if="lesson.outcome === null || lesson.outcome === 'taught'"
                id="assiduidade"
                class="scroll-mt-20"
                :lesson-ulid="lesson.ulid"
                :attendance="attendance"
                :class-group-label="lesson.class_group_label"
                :lesson-taught="lesson.status === 'taught'"
                :absent="summaryForm.absent"
                @update:absent="updateAbsent"
                @record="recordAttendance"
            />

            <details :open="Boolean(lesson.summary?.private_notes)" class="group rounded-xl border bg-card">
                <summary class="cursor-pointer px-4 py-3 font-semibold focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-ring/50 sm:px-5">Notas do professor</summary>
                <div class="grid gap-2 border-t px-4 py-4 sm:px-5">
                    <p id="private-notes-help" class="text-sm text-muted-foreground">Notas privadas, visíveis apenas para o professor e nunca mostradas aos alunos.</p>
                    <textarea id="lesson-private-notes" v-model="summaryForm.private_notes" name="private_notes" rows="5" maxlength="16000" class="min-h-32 w-full resize-y rounded-xl border border-input bg-background px-4 py-3 leading-relaxed" :disabled="summaryForm.processing" aria-describedby="private-notes-help private-notes-error" />
                    <InputError id="private-notes-error" :message="summaryForm.errors.private_notes" />
                </div>
            </details>

            <details :open="Boolean(lesson.summary?.resources)" class="group rounded-xl border bg-card">
                <summary class="cursor-pointer px-4 py-3 font-semibold focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-ring/50 sm:px-5">Recursos</summary>
                <div class="grid gap-2 border-t px-4 py-4 sm:px-5">
                    <Label for="lesson-resources" class="sr-only">Recursos</Label>
                    <textarea id="lesson-resources" v-model="summaryForm.resources" name="resources" rows="4" maxlength="16000" class="min-h-28 w-full resize-y rounded-xl border border-input bg-background px-4 py-3 leading-relaxed" placeholder="Referência ou URL" :disabled="summaryForm.processing" aria-describedby="resources-error" />
                    <InputError id="resources-error" :message="summaryForm.errors.resources" />
                </div>
            </details>

            <details :open="Boolean(lesson.summary?.homework)" class="group rounded-xl border bg-card">
                <summary class="cursor-pointer px-4 py-3 font-semibold focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-ring/50 sm:px-5">TPC</summary>
                <div class="grid gap-2 border-t px-4 py-4 sm:px-5">
                    <Label for="lesson-homework" class="sr-only">TPC</Label>
                    <textarea id="lesson-homework" v-model="summaryForm.homework" name="homework" rows="4" maxlength="16000" class="min-h-28 w-full resize-y rounded-xl border border-input bg-background px-4 py-3 leading-relaxed" :disabled="summaryForm.processing" aria-describedby="homework-error" />
                    <InputError id="homework-error" :message="summaryForm.errors.homework" />
                </div>
            </details>

            <div class="flex flex-col gap-3 sm:flex-row">
                <Button type="submit" size="lg" class="min-h-12 flex-1 text-base" :disabled="summaryForm.processing">
                    <Spinner v-if="summaryForm.processing" />
                    <Save v-else class="size-5" />
                    {{ summaryForm.processing ? 'A guardar…' : 'Guardar' }}
                </Button>
                <Button v-if="lesson.status !== 'taught' && lesson.outcome === null" type="button" size="lg" variant="secondary" class="min-h-12" :disabled="taughtForm.processing" @click="markTaught">
                    <Spinner v-if="taughtForm.processing" />
                    <Check v-else class="size-5" />
                    Marcar como lecionada
                </Button>
            </div>
            <p v-if="lesson.status !== 'taught' && lesson.outcome === null" class="text-xs text-muted-foreground">
                Os alunos sem falta assinalada ficam presentes.
            </p>
        </form>

        <!-- As ações destrutivas vivem FORA do formulário do sumário e num bloco
             próprio, separadas do «Guardar» por uma fronteira visível: são as
             únicas desta página que não se desfazem. -->
        <section
            v-if="lesson.can_clear_summary || lesson.can_delete"
            class="space-y-3 rounded-xl border border-destructive/30 p-4"
        >
            <h2 class="text-sm font-semibold">Corrigir esta aula</h2>
            <div class="flex flex-col gap-3 sm:flex-row">
                <Dialog v-if="lesson.can_clear_summary" v-model:open="clearDialogOpen">
                    <Button
                        type="button"
                        variant="outline"
                        class="min-h-11 flex-1"
                        @click="clearDialogOpen = true"
                    >
                        <Eraser class="size-4" /> Limpar sumário
                    </Button>
                    <DialogContent class="sm:max-w-md">
                        <DialogHeader class="space-y-2">
                            <DialogTitle>Limpar este sumário?</DialogTitle>
                            <DialogDescription>
                                A aula será mantida, mas o texto do sumário será removido.
                                As notas do professor, os recursos e o TPC não são
                                apagados.
                            </DialogDescription>
                        </DialogHeader>
                        <DialogFooter class="gap-2">
                            <DialogClose as-child>
                                <Button type="button" variant="outline" class="min-h-11"
                                    >Cancelar</Button
                                >
                            </DialogClose>
                            <Button
                                type="button"
                                variant="destructive"
                                class="min-h-11"
                                :disabled="clearForm.processing"
                                @click="clearSummary"
                            >
                                Limpar sumário
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                <Dialog v-if="lesson.can_delete" v-model:open="deleteDialogOpen">
                    <Button
                        type="button"
                        variant="outline"
                        class="min-h-11 flex-1 text-destructive"
                        @click="deleteDialogOpen = true"
                    >
                        <Trash2 class="size-4" /> Eliminar aula
                    </Button>
                    <DialogContent class="sm:max-w-md">
                        <DialogHeader class="space-y-2">
                            <DialogTitle>Eliminar esta aula?</DialogTitle>
                            <DialogDescription>
                                Os dados associados a esta ocorrência serão eliminados. O
                                horário recorrente da turma não será alterado, e as
                                restantes aulas mantêm-se.
                            </DialogDescription>
                        </DialogHeader>
                        <DialogFooter class="gap-2">
                            <DialogClose as-child>
                                <Button type="button" variant="outline" class="min-h-11"
                                    >Cancelar</Button
                                >
                            </DialogClose>
                            <Button
                                type="button"
                                variant="destructive"
                                class="min-h-11"
                                :disabled="deleteForm.processing"
                                @click="deleteLesson"
                            >
                                Eliminar aula
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </div>
        </section>

        <!-- A mesma saída do topo, no fundo da página: um sumário longo tira a
             de cima do ecrã. Um `Link` e não um botão do formulário — não
             guarda, não marca como lecionada, e passa pela mesma guarda de
             alterações por guardar que a de cima. -->
        <Button as-child variant="outline" class="min-h-11 w-full sm:w-auto">
            <a :href="returnTarget.url" data-testid="lesson-back-bottom" @click="goBack">
                <ArrowLeft class="size-4" />
                Voltar às aulas da semana
            </a>
        </Button>

        <UnsavedChangesDialog
            :open="guard.pending.value !== null"
            :context="`${lesson.context_label} (${lessonDate}, ${lessonTime})`"
            :saving="guard.saving.value"
            @stay="guard.stay"
            @leave="guard.leaveWithoutSaving"
            @save="saveAndContinue"
        />
    </main>
</template>
