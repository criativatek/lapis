<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Check, Copy, Eraser, Info, Save, Trash2 } from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import AlertError from '@/components/AlertError.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import ActivityPostponeDialog from '@/components/lessons/ActivityPostponeDialog.vue';
import LessonAttendanceList from '@/components/lessons/LessonAttendanceList.vue';
import type { LessonAttendance } from '@/components/lessons/LessonAttendanceList.vue';
import LessonDayEvents from '@/components/lessons/LessonDayEvents.vue';
import type { DayEvent } from '@/components/lessons/LessonDayEvents.vue';
import LessonNumberConfirmation from '@/components/lessons/LessonNumberConfirmation.vue';
import LessonNumberField from '@/components/lessons/LessonNumberField.vue';
import LessonOutcomePanel from '@/components/lessons/LessonOutcomePanel.vue';
import type { LessonOutcomeValue } from '@/components/lessons/LessonOutcomePanel.vue';
import LessonPreparationContextPanel from '@/components/lessons/LessonPreparationContextPanel.vue';
import UnsavedChangesDialog from '@/components/lessons/UnsavedChangesDialog.vue';
import LessonSummaryConflict from '@/components/lessons/week/LessonSummaryConflict.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
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
import {
    NO_NUMBER_ERRORS,
    numberDraftDirty,
    numberDraftFromLesson,
    numberSavePayload,
    previewNeedsConfirmation,
    requestNumberPreview,
    validateNumberDraft,
} from '@/lib/lessonNumber';
import type { LessonNumberDraft, LessonNumberErrors, LessonNumberPreview } from '@/lib/lessonNumber';
import { lessonDisplayState, lessonNumberLabel } from '@/lib/lessons';
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
    /** Fim de uma aula que corresponde a várias lições («11–12»); NULL = uma só lição. */
    lesson_number_end: number | null;
    /** O número foi indicado pelo professor e não é recalculado (0.169.0). */
    lesson_number_manual: boolean;
    numbering_frozen?: boolean;
    /** Falso numa aula de professor ausente: não há lição a numerar. */
    can_set_number: boolean;
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

/**
 * A interrupção do calendário que cobre o dia desta aula — derivada em leitura,
 * ou null — e o que «Ver e corrigir» lhe faria, com a MESMA classificação do
 * aviso: `retirable` só quando a aula sai mesmo; senão, `reasons` diz porquê.
 */
type CalendarConflict = {
    title: string;
    type: string;
    type_label: string;
    starts_on: string;
    ends_on: string;
    kind?: 'A' | 'B' | 'C' | null;
    retirable?: boolean;
    /** A aula é de uma turma que quem vê leciona (só essas entram em «Ver e corrigir»). */
    in_scope?: boolean;
    year_editable?: boolean;
    reasons?: { code: string; label: string }[];
} | null;

const props = withDefaults(
    defineProps<{ lesson: Lesson; attendance: LessonAttendance; day_events: DayEvent[]; calendar_conflict?: CalendarConflict }>(),
    { calendar_conflict: null },
);

const conflictDateFormatter = new Intl.DateTimeFormat('pt-PT', { day: '2-digit', month: '2-digit', year: 'numeric', timeZone: 'UTC' });

function conflictDate(date: string): string {
    return conflictDateFormatter.format(new Date(`${date}T00:00:00Z`));
}

/** «Esta aula cai num dia sem aulas (Interrupção de Natal, 21/12/2026 – 02/01/2027).» */
const conflictSentence = computed(() => {
    const conflict = props.calendar_conflict;

    if (conflict === null) {
        return null;
    }

    const range =
        conflict.starts_on === conflict.ends_on
            ? conflictDate(conflict.starts_on)
            : `${conflictDate(conflict.starts_on)} – ${conflictDate(conflict.ends_on)}`;

    return `Esta aula cai num dia sem aulas (${conflict.title}, ${range}).`;
});

/**
 * O que «Ver e corrigir» faz a ESTA aula, dito para o caso dela — e nunca
 * «tem registos» de uma aula vazia num ano fechado, nem «pode ser retirada» de
 * uma aula com planeamento sem dizer para onde ele vai.
 */
const conflictOutcome = computed<string | null>(() => {
    const conflict = props.calendar_conflict;

    if (conflict === null) {
        return null;
    }

    if (conflict.in_scope === false) {
        return 'Não é de uma turma tua: só os professores da turma a podem corrigir, em Aulas e Sumários.';
    }

    if (conflict.retirable === true && conflict.kind === 'A') {
        return 'Está vazia: pode ser retirada em Aulas e Sumários › Ver e corrigir.';
    }

    if (conflict.retirable === true && conflict.kind === 'B') {
        return 'Tem planeamento: em Aulas e Sumários › Ver e corrigir, é retirada e o planeamento passa para a aula seguinte da turma (ou do grupo), sem se perder nada.';
    }

    if ((conflict.kind === 'A' || conflict.kind === 'B') && conflict.year_editable === false) {
        return 'O ano letivo está fechado, por isso não é alterada.';
    }

    if (conflict.kind === 'C') {
        return conflict.reasons?.length === 1 && conflict.reasons[0].code === 'batch_limit'
            ? 'Fica para a correção seguinte: em Aulas e Sumários › Ver e corrigir, há demasiadas aulas com planeamento para uma só vez.'
            : 'Não é alterada automaticamente:';
    }

    return 'Não é alterada automaticamente.';
});
const conflictReasons = computed(() => {
    const reasons = props.calendar_conflict?.reasons ?? [];

    return reasons.length === 1 && reasons[0].code === 'batch_limit' ? [] : reasons;
});

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

// ------------------------------------------------- o número da lição (0.169.0)
//
// O rascunho do número NÃO vive no `summaryForm`: o Inertia enviaria sempre as
// suas chaves, e um pedido com `lesson_number*` é uma ordem para (re)fixar o
// número. Só quando o professor o muda é que as chaves entram no pedido
// (`transform`, abaixo); sem elas o servidor não toca na numeração.
const numberOriginal = ref<LessonNumberDraft>(numberDraftFromLesson(props.lesson));
const numberDraft = ref<LessonNumberDraft>(numberDraftFromLesson(props.lesson));
const numberLocalErrors = ref<LessonNumberErrors>(NO_NUMBER_ERRORS);
const numberPreview = ref<LessonNumberPreview | null>(null);
const numberChecking = ref(false);
/** As chaves a juntar ao PUT em curso; NULL = a numeração não se toca. */
const pendingNumberKeys = ref<Record<string, unknown> | null>(null);

const numberDirty = computed(() => props.lesson.can_set_number && numberDraftDirty(numberDraft.value, numberOriginal.value));
// Erros do servidor (`lesson_number*`, na gravação) e locais (validação, conflitos
// da pré-visualização) aparecem no mesmo sítio: junto do campo.
const serverErrors = computed(() => summaryForm.errors as Record<string, string | undefined>);
const numberErrors = computed<LessonNumberErrors>(() => ({
    number: numberLocalErrors.value.number ?? serverErrors.value.lesson_number ?? null,
}));

// Os erros do número não são campos do formulário (as chaves só viajam quando o
// número muda), por isso o tipo do `useForm` não os conhece.
function clearNumberErrors(): void {
    (summaryForm as unknown as { clearErrors: (...fields: string[]) => void }).clearErrors('lesson_number');
}

function setNumberDraft(value: LessonNumberDraft): void {
    numberDraft.value = value;
    // Um número diferente invalida o que se viu e o que se recusou.
    numberLocalErrors.value = NO_NUMBER_ERRORS;
    numberPreview.value = null;
    clearNumberErrors();
}
const taughtForm = useForm({ absent: initialAbsent });

/** Texto (ou número) por guardar nesta aula — o que o servidor ainda não viu. */
const hasUnsavedChanges = computed(() => summaryForm.isDirty || numberDirty.value);
summaryForm.transform((data) => ({ ...data, ...(pendingNumberKeys.value ?? {}) }));
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

// QUANDO A AULA FECHA, O FORMULÁRIO REFAZ-SE A PARTIR DO SERVIDOR (0.171.0).
//
// Registar um resultado (pelo diálogo de «Não houve aula» ou por «Registar e
// adiar a preparação») desloca o sumário GRAVADO para a aula seguinte. Como o
// Inertia preserva o estado do componente nos `post`, o formulário continuava a
// mostrar o texto que acabou de sair — numa aula já fechada, a parecer que o
// plano ficou ali — e gravá-lo de novo escrevia o mesmo plano duas vezes. As
// props frescas têm o que realmente ficou nesta aula (nada, se o sumário
// seguiu); é daí que o formulário e o rascunho do número recomeçam.
watch(
    () => props.lesson.outcome,
    (outcome, previous) => {
        if (previous !== null || outcome === null) {
            return;
        }

        const fresh = {
            content: props.lesson.summary?.content ?? '',
            private_notes: props.lesson.summary?.private_notes ?? '',
            resources: props.lesson.summary?.resources ?? '',
            homework: props.lesson.summary?.homework ?? '',
            absent: [] as string[],
            summary_version: props.lesson.summary_version ?? 0,
        };

        summaryForm.defaults(fresh);
        summaryForm.reset();
        summaryForm.clearErrors();
        taughtForm.absent = [];
        recordForm.absent = [];
        clearForm.summary_version = fresh.summary_version;
        conflictNotice.value = null;
        saveFailure.value = null;
        numberOriginal.value = numberDraftFromLesson(props.lesson);
        numberDraft.value = numberDraftFromLesson(props.lesson);
        numberLocalErrors.value = NO_NUMBER_ERRORS;
        numberPreview.value = null;
    },
);

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

// ------------------------------------- «Registar e adiar a preparação» (0.171.0)

const postponeOpen = ref(false);
const postponeEvent = ref<DayEvent | null>(null);

function openPostpone(event: DayEvent): void {
    postponeEvent.value = event;
    postponeOpen.value = true;
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
        // O número tem os erros junto do campo, não no resumo do formulário.
        .filter(([field]) => !['summary_version', 'lesson_number'].includes(field))
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

/**
 * Grava o formulário inteiro; resolve `true` só quando a gravação foi aceite.
 *
 * Número da lição NÃO mudou: grava como sempre, sem chaves `lesson_number*`.
 * Mudou: primeiro a pré-visualização — um conflito não grava, e efeitos em
 * aulas já registadas (ou avisos) pedem confirmação no painel antes de gravar.
 */
async function submitSummary(): Promise<boolean> {
    conflictNotice.value = null;
    saveFailure.value = null;

    if (!numberDirty.value) {
        return sendSummary(null);
    }

    const local = validateNumberDraft(numberDraft.value);

    if (local.number !== null) {
        numberLocalErrors.value = local;
        numberPreview.value = null;

        return false;
    }

    numberLocalErrors.value = NO_NUMBER_ERRORS;
    numberPreview.value = null;
    clearNumberErrors();
    // A verificação também é "guardar": a guarda de navegação não a interroga.
    submittingFromThisPage.value = true;
    numberChecking.value = true;

    const result = await requestNumberPreview(props.lesson.ulid, numberDraft.value);

    numberChecking.value = false;

    if (result.kind === 'invalid') {
        releaseSubmission();
        numberLocalErrors.value = result.errors;

        return false;
    }

    if (result.kind === 'failed') {
        releaseSubmission();
        saveFailure.value = result.message;

        return false;
    }

    const preview = result.preview;

    if (preview.conflicts.length > 0) {
        releaseSubmission();
        numberLocalErrors.value = { number: preview.conflicts.join(' ') };

        return false;
    }

    if (!preview.changed) {
        return sendSummary(null);
    }

    if (previewNeedsConfirmation(preview)) {
        releaseSubmission();
        numberPreview.value = preview;

        return false;
    }

    return sendSummary(preview);
}

/** «Guardar» no painel de confirmação: envia a confirmação que o servidor deu. */
function confirmNumber(): Promise<boolean> {
    return sendSummary(numberPreview.value);
}

function sendSummary(preview: LessonNumberPreview | null): Promise<boolean> {
    submittingFromThisPage.value = true;
    conflictNotice.value = null;
    saveFailure.value = null;
    pendingNumberKeys.value = preview === null ? null : numberSavePayload(numberDraft.value, preview.confirmation);

    return new Promise((resolve) => {
        let saved = false;

        summaryForm.put(`/lessons/${props.lesson.ulid}/summary`, {
            preserveScroll: true,
            onError: () => {
                // O servidor recusou o número (conflito, confirmação em falta
                // ou desatualizada): o erro fica junto do campo e o «Guardar»
                // seguinte volta a pedir a pré-visualização.
                numberPreview.value = null;
            },
            onSuccess: () => {
                saved = true;
                // O número que o servidor guardou é o novo ponto de partida.
                numberOriginal.value = numberDraftFromLesson(props.lesson);
                numberDraft.value = numberDraftFromLesson(props.lesson);
                numberPreview.value = null;
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
                pendingNumberKeys.value = null;
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
    isDirty: () => hasUnsavedChanges.value,
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

        // Vinda de Aulas e Sumários, ou das «Faltas de presença» da consulta
        // de Registos (0.164.0) — sempre um caminho da própria aplicação.
        if (entry?.lesson === props.lesson.ulid && typeof entry.url === 'string' && (entry.url.startsWith('/lessons') || entry.url.startsWith('/records'))) {
            returnTarget.value = { url: entry.url, viaHistory: true };
        }
    } catch {
        // Sem sessionStorage, o regresso é à semana da aula.
    }
});

/**
 * Vinda dos resultados de uma pesquisa nos sumários (0.162.0), a saída diz
 * para onde volta: o URL guardado leva a pesquisa (`q`), e regressar repõe a
 * expressão, os filtros e a página de resultados.
 */
const returnLabel = computed(() => {
    try {
        const target = new URL(returnTarget.value.url, 'http://lapis.local');

        if (returnTarget.value.viaHistory && target.pathname.startsWith('/records')) {
            return 'Voltar à consulta de registos';
        }

        const query = target.searchParams;

        return returnTarget.value.viaHistory && (query.get('q') ?? '').trim() !== ''
            ? 'Voltar aos resultados da pesquisa'
            : 'Voltar às aulas da semana';
    } catch {
        return 'Voltar às aulas da semana';
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
                {{ returnLabel }}
            </a>
        </Button>

        <Alert v-if="conflictSentence" data-testid="lesson-calendar-conflict">
            <Info aria-hidden="true" />
            <AlertDescription>
                <p>{{ conflictSentence }}</p>
                <p v-if="conflictOutcome" data-testid="lesson-calendar-conflict-outcome">{{ conflictOutcome }}</p>
                <ul v-if="conflictReasons.length > 0" class="list-disc pl-5" data-testid="lesson-calendar-conflict-reasons">
                    <li v-for="reason in conflictReasons" :key="reason.code">{{ reason.label }}</li>
                </ul>
            </AlertDescription>
        </Alert>

        <div class="space-y-3">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <Heading :title="lesson.context_label" :description="lesson.school_class.subject" />
                <div class="flex items-center gap-2">
                    <!-- O mesmo número que a lista e o horário mostram: é o
                         mesmo campo da mesma aula, e não um contador de ecrã. -->
                    <Badge v-if="lessonNumberLabel(lesson.lesson_number, lesson.lesson_number_end) !== null" variant="outline" class="tabular-nums"
                        >{{ lessonNumberLabel(lesson.lesson_number, lesson.lesson_number_end) }}</Badge
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
            :unsaved-changes="hasUnsavedChanges"
            @submitting="(value) => (submittingFromThisPage = value)"
            :save-first="submitSummary"
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

            <LessonDayEvents
                :events="day_events"
                :summary-content="summaryForm.content"
                :can-postpone="lesson.can_record_outcome"
                @append="applyDayEventToSummary"
                @postpone="openPostpone"
            />

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

            <div class="grid gap-3 rounded-xl border bg-card p-4">
                <LessonNumberField
                    :model-value="numberDraft"
                    :original="numberOriginal"
                    :manual="lesson.lesson_number_manual"
                    :can-set="lesson.can_set_number"
                    :frozen="lesson.numbering_frozen === true"
                    :errors="numberErrors"
                    id-prefix="lesson-number"
                    :disabled="summaryForm.processing || numberChecking"
                    @update:model-value="setNumberDraft"
                />
                <LessonNumberConfirmation
                    v-if="numberPreview"
                    :preview="numberPreview"
                    :saving="summaryForm.processing"
                    id-prefix="lesson-number-confirmation"
                    @confirm="confirmNumber"
                    @cancel="numberPreview = null"
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
                <Button type="submit" size="lg" class="min-h-12 flex-1 text-base" :disabled="summaryForm.processing || numberChecking">
                    <Spinner v-if="summaryForm.processing || numberChecking" />
                    <Save v-else class="size-5" />
                    {{ summaryForm.processing || numberChecking ? 'A guardar…' : 'Guardar' }}
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
                {{ returnLabel }}
            </a>
        </Button>

        <!-- «Registar e adiar a preparação»: o diálogo vive FORA do formulário do
             sumário — é uma pré-visualização, e só a confirmação escreve. -->
        <ActivityPostponeDialog
            v-model:open="postponeOpen"
            :lesson-ulid="lesson.ulid"
            :event="postponeEvent"
            :has-unsaved-changes="hasUnsavedChanges"
            :save-first="submitSummary"
            @submitting="(value) => (submittingFromThisPage = value)"
        />

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
