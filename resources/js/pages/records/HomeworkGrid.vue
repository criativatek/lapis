<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { MessageSquarePlus, RotateCcw } from '@lucide/vue';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import UnsavedChangesDialog from '@/components/lessons/UnsavedChangesDialog.vue';
import { useUnsavedChangesGuard } from '@/composables/useUnsavedChangesGuard';
import { formatDay } from '@/lib/recordFollowup';

/**
 * A grelha de TPC (0.165.0) — TODOS os alunos que estavam na turma nesse dia,
 * com ou sem marcação anterior, por número. Uma linha, três escolhas sempre à
 * vista; quem ainda não foi marcado fica «Não verificado» — nunca se presume
 * realização nem incumprimento.
 *
 * O modelo não tem um «TPC» como entidade: turma + dia é a identidade da
 * grelha (uma marcação por aluno e dia). Um aluno com dois registos de TPC no
 * mesmo dia não é fundido: a linha fica só de leitura e diz porquê.
 *
 * Só se envia o que mudou. O que está por guardar distingue-se do que já está
 * gravado, sobrevive a uma gravação em curso e é protegido ao sair, ao mudar
 * de data ou de turma (`useUnsavedChangesGuard`, o mesmo das Aulas).
 *
 * DUAS SESSÕES NA MESMA GRELHA: cada linha leva a versão da marcação que foi
 * lida. Se outra pessoa (ou outro separador) a mudou entretanto, essa linha
 * não é gravada — volta como conflito, com o estado atual à vista e a escolha
 * do professor intacta, à espera de «Manter a minha escolha» ou «Usar o estado
 * atual». As outras linhas gravam-se normalmente, e o que ficou por gravar
 * diz-se — nunca um «guardado» total.
 */
type HomeworkStatus = 'done' | 'partially_done' | 'not_done';
type LockedReason = 'duplicate' | 'undetermined';

type ServerRow = {
    id: number;
    ulid: string;
    number: number | null;
    name: string;
    homework_status: HomeworkStatus | null;
    description: string;
    record_count: number;
    version: string;
    editable: boolean;
    locked_reason: LockedReason | null;
    eligibility?: 'eligible' | 'undetermined' | 'not_enrolled';
    enrollment_status_label?: string;
    left_on: string | null;
};

type Conflict = {
    status: HomeworkStatus | null;
    description: string;
    change: 'created' | 'changed' | 'removed';
    at: string | null;
};

type SaveOutcome = {
    saved: number[];
    unchanged: number[];
    conflicts: { enrollment_id: number; homework_status: HomeworkStatus | null; description: string; version: string; change: Conflict['change']; at: string | null }[];
};

type GridRow = {
    enrollment_id: number;
    ulid: string;
    number: number | null;
    name: string;
    status: HomeworkStatus | null;
    savedStatus: HomeworkStatus | null;
    description: string;
    savedDescription: string;
    editable: boolean;
    lockedReason: LockedReason | null;
    recordCount: number;
    leftOn: string | null;
    statusLabel: string | null;
    /** The version of the marking as last read — sent back as `expected`. */
    version: string;
    /** Changed in another session since it was read: awaiting the teacher's review. */
    conflict: Conflict | null;
    observationOpen: boolean;
    descriptionCustomized: boolean;
};

const props = withDefaults(
    defineProps<{
        classUlid: string;
        occurredAt: string;
        /** Archived class, or no right to write: the grid can be read, not changed. */
        readOnly?: boolean;
    }>(),
    { readOnly: false },
);

const emit = defineEmits<{
    saved: [];
}>();

const STATUSES: { value: HomeworkStatus; label: string; symbol: string; shortcut: string; selected: string; idle: string; row: string }[] = [
    {
        value: 'done',
        label: 'Realizou',
        symbol: '✓',
        shortcut: '1',
        selected: 'border-emerald-700 bg-emerald-700 text-white dark:border-emerald-500 dark:bg-emerald-600',
        idle: 'text-emerald-800 dark:text-emerald-300',
        row: 'bg-emerald-50/80 dark:bg-emerald-950/30',
    },
    {
        value: 'partially_done',
        label: 'Realizou parcialmente',
        symbol: '◐',
        shortcut: '2',
        selected: 'border-blue-700 bg-blue-700 text-white dark:border-blue-500 dark:bg-blue-600',
        idle: 'text-blue-800 dark:text-blue-300',
        row: 'bg-blue-50/80 dark:bg-blue-950/30',
    },
    {
        value: 'not_done',
        label: 'Não realizou',
        symbol: '✕',
        shortcut: '3',
        selected: 'border-orange-700 bg-orange-700 text-white dark:border-orange-500 dark:bg-orange-600',
        idle: 'text-orange-800 dark:text-orange-300',
        row: 'bg-orange-50/80 dark:bg-orange-950/30',
    },
];

const UNVERIFIED_LABEL = 'Não verificado';

const root = ref<HTMLElement | null>(null);
const rows = ref<GridRow[]>([]);
const sharedDescription = ref('');
const loading = ref(false);
const loadError = ref('');
const saveError = ref('');
const savedAt = ref<string | null>(null);
/** After a partial save: how many rows were saved, and how many came back in conflict. */
const partial = ref<{ saved: number; conflicts: number } | null>(null);
// Set BEFORE the save's visit starts: the guard's `before` hook runs ahead of
// `form.processing`, and the page's own save must never be questioned.
const submitting = ref(false);
let loadSequence = 0;

const form = useForm<{
    occurred_at: string;
    rows: { enrollment_id: number; homework_status: HomeworkStatus | null; description: string }[];
}>({ occurred_at: props.occurredAt, rows: [] });

function isRowDirty(row: GridRow): boolean {
    if (!row.editable) {
        return false;
    }

    return row.status !== row.savedStatus || (row.status !== null && row.description !== row.savedDescription);
}

const dirtyRows = computed(() => rows.value.filter(isRowDirty));
const conflictRows = computed(() => rows.value.filter((row) => row.conflict !== null));
/** A row in conflict is only sent again after the teacher has reviewed it. */
const savableRows = computed(() => dirtyRows.value.filter((row) => row.conflict === null));
const editableRows = computed(() => rows.value.filter((row) => row.editable && !props.readOnly));
const unverifiedEditable = computed(() => editableRows.value.filter((row) => row.status === null));
const hasSavedMarkings = computed(() => rows.value.some((row) => row.savedStatus !== null || row.recordCount > 1));

const counts = computed(() => {
    // A row that can never be verified (two records that day, or an enrollment
    // the data cannot place on it) is not «por verificar».
    const countable = rows.value.filter((row) => row.recordCount <= 1 && (row.lockedReason === null || row.status !== null));

    return {
        done: countable.filter((row) => row.status === 'done').length,
        partially_done: countable.filter((row) => row.status === 'partially_done').length,
        not_done: countable.filter((row) => row.status === 'not_done').length,
        unverified: countable.filter((row) => row.status === null).length,
    };
});

function fromServer(row: ServerRow): GridRow {
    return {
        enrollment_id: row.id,
        ulid: row.ulid,
        number: row.number,
        name: row.name,
        status: row.homework_status,
        savedStatus: row.homework_status,
        description: row.description,
        savedDescription: row.description,
        editable: row.editable,
        lockedReason: row.locked_reason,
        recordCount: row.record_count,
        leftOn: row.left_on,
        statusLabel: row.enrollment_status_label ?? null,
        version: row.version,
        conflict: null,
        observationOpen: row.description !== '',
        descriptionCustomized: row.description !== '',
    };
}

type Submitted = Map<number, { status: HomeworkStatus | null; description: string }>;

/**
 * Loads the grid of the day. After a save, `submitted` names what was sent:
 * every row the teacher touched WHILE the save was running keeps that newer
 * choice (still «por guardar»), the rest takes what the server now holds. A
 * row in `conflicts` keeps the teacher's choice and its conflict, over the
 * state and version the server holds now.
 */
async function loadBatch(submitted: Submitted | null = null, conflicts: Map<number, Conflict> = new Map()): Promise<void> {
    const sequence = ++loadSequence;
    loadError.value = '';

    if (props.occurredAt === '') {
        rows.value = [];
        loading.value = false;

        return;
    }

    if (submitted === null) {
        loading.value = true;
        rows.value = [];
    }

    try {
        const response = await fetch(
            `/classes/${props.classUlid}/records/homework-batch?occurred_at=${encodeURIComponent(props.occurredAt)}`,
            { headers: { Accept: 'application/json' } },
        );

        if (!response.ok) {
            throw new Error('load failed');
        }

        const payload = (await response.json()) as { enrollments: ServerRow[] };

        // A newer day, class or reload already started — this answer is old.
        if (sequence !== loadSequence) {
            return;
        }

        const current = new Map(rows.value.map((row) => [row.enrollment_id, row]));

        rows.value = payload.enrollments.map((serverRow) => {
            const fresh = fromServer(serverRow);
            const local = current.get(serverRow.id);

            if (submitted === null || local === undefined || !fresh.editable) {
                return fresh;
            }

            const conflict = conflicts.get(serverRow.id) ?? local.conflict;

            if (conflict !== null) {
                fresh.status = local.status;
                fresh.description = local.description;
                fresh.descriptionCustomized = local.descriptionCustomized;
                fresh.observationOpen = local.observationOpen || fresh.observationOpen;
                fresh.conflict = conflict;

                return fresh;
            }

            const sent = submitted.get(serverRow.id);
            const changedSince = sent !== undefined
                ? local.status !== sent.status || (local.status !== null && local.description !== sent.description)
                : isRowDirty(local);

            if (changedSince) {
                fresh.status = local.status;
                fresh.description = local.description;
                fresh.descriptionCustomized = local.descriptionCustomized;
            }

            fresh.observationOpen = local.observationOpen || fresh.observationOpen;

            return fresh;
        });
    } catch {
        if (sequence === loadSequence) {
            if (submitted === null) {
                rows.value = [];
            }

            loadError.value = 'Não foi possível carregar a grelha. Tente novamente.';
        }
    } finally {
        if (sequence === loadSequence) {
            loading.value = false;
        }
    }
}

// Loads once the grid is actually in the browser — `onMounted` never runs
// during SSR (unlike `setup()`), and this fetches over the network with a
// relative URL that has no origin to resolve against on the server.
onMounted(() => {
    void loadBatch();
});

watch(
    () => [props.classUlid, props.occurredAt],
    () => {
        sharedDescription.value = '';
        savedAt.value = null;
        partial.value = null;
        saveError.value = '';
        void loadBatch();
    },
);

function setStatus(row: GridRow, status: HomeworkStatus): void {
    if (!row.editable || props.readOnly) {
        return;
    }

    row.status = status;

    if (!row.descriptionCustomized && sharedDescription.value !== '') {
        row.description = sharedDescription.value;
    }
}

/** Back to «Não verificado». A saved observation is only lost after saying so (see save()). */
function resetRow(row: GridRow): void {
    if (!row.editable || props.readOnly) {
        return;
    }

    row.status = null;
}

/** Only the students still «Não verificado» — a choice already made is never overwritten. */
function markAllDone(): void {
    for (const row of unverifiedEditable.value) {
        setStatus(row, 'done');
    }
}

function discardChanges(): void {
    for (const row of rows.value) {
        row.status = row.savedStatus;
        row.description = row.savedDescription;
        row.descriptionCustomized = row.savedDescription !== '';
        row.conflict = null;
    }

    saveError.value = '';
    partial.value = null;
}

/** Conflict reviewed: the teacher's choice is sent next time, over the state now seen. */
function keepMyChoice(row: GridRow): void {
    row.conflict = null;
}

/** Conflict reviewed: the choice is dropped, the row shows what is saved now. */
function useCurrentState(row: GridRow): void {
    row.status = row.savedStatus;
    row.description = row.savedDescription;
    row.descriptionCustomized = row.savedDescription !== '';
    row.conflict = null;
}

function applySharedDescription(): void {
    for (const row of rows.value) {
        if (row.editable && row.status !== null && !row.descriptionCustomized) {
            row.description = sharedDescription.value;
        }
    }
}

function customizeDescription(row: GridRow): void {
    row.descriptionCustomized = true;
}

function timeNow(): string {
    return new Date().toLocaleTimeString('pt-PT', { hour: '2-digit', minute: '2-digit' });
}

/** Saves the rows that changed. Resolves `true` only when the server accepted them. */
function save(): Promise<boolean> {
    const changed = savableRows.value;

    if (changed.length === 0 || props.readOnly) {
        return Promise.resolve(dirtyRows.value.length === 0);
    }

    const losingObservation = changed.filter((row) => row.status === null && row.savedDescription.trim() !== '');

    if (
        losingObservation.length > 0 &&
        !window.confirm(
            losingObservation.length === 1
                ? `Repor ${losingObservation[0].name} em «Não verificado» elimina a observação guardada nessa marcação. Continuar?`
                : `Repor ${losingObservation.length} alunos em «Não verificado» elimina as observações guardadas nessas marcações. Continuar?`,
        )
    ) {
        return Promise.resolve(false);
    }

    const submitted: Submitted = new Map(
        changed.map((row) => [row.enrollment_id, { status: row.status, description: row.status === null ? '' : row.description }]),
    );
    const versions = new Map(changed.map((row) => [row.enrollment_id, row.version]));

    form.occurred_at = props.occurredAt;
    form.rows = [...submitted.entries()].map(([enrollmentId, value]) => ({
        enrollment_id: enrollmentId,
        homework_status: value.status,
        description: value.description,
        expected: versions.get(enrollmentId) ?? null,
    }));
    saveError.value = '';
    partial.value = null;
    submitting.value = true;

    return new Promise((resolve) => {
        let succeeded = false;
        let outcome: SaveOutcome | null = null;

        form.put(`/classes/${props.classUlid}/records/homework-batch`, {
            preserveScroll: true,
            preserveState: true,
            onFlash: (flash: Record<string, unknown>) => {
                outcome = (flash.homework_save as SaveOutcome | undefined) ?? null;
            },
            onSuccess: () => {
                const result = outcome as SaveOutcome | null;
                const conflicts = new Map<number, Conflict>(
                    (result?.conflicts ?? []).map((conflict) => [
                        conflict.enrollment_id,
                        { status: conflict.homework_status, description: conflict.description, change: conflict.change, at: conflict.at },
                    ]),
                );

                for (const row of rows.value) {
                    const sent = submitted.get(row.enrollment_id);
                    const conflict = (result?.conflicts ?? []).find((candidate) => candidate.enrollment_id === row.enrollment_id);

                    if (conflict !== undefined) {
                        // Not written: what is there now becomes the baseline,
                        // the teacher's choice stays on screen to be reviewed.
                        row.savedStatus = conflict.homework_status;
                        row.savedDescription = conflict.description;
                        row.version = conflict.version;
                        row.conflict = conflicts.get(row.enrollment_id) ?? null;
                    } else if (sent !== undefined) {
                        row.savedStatus = sent.status;
                        row.savedDescription = sent.description;
                    }
                }

                // Only a save without conflicts is a complete one.
                succeeded = conflicts.size === 0;

                if (succeeded) {
                    savedAt.value = timeNow();
                } else {
                    savedAt.value = null;
                    partial.value = { saved: submitted.size - conflicts.size, conflicts: conflicts.size };
                }

                emit('saved');
                void loadBatch(submitted, conflicts);
            },
            onError: (errors) => {
                saveError.value = errors.rows ?? Object.values(errors)[0] ?? 'Não foi possível guardar.';
            },
            onFinish: () => {
                submitting.value = false;

                if (!succeeded && saveError.value === '' && partial.value === null) {
                    saveError.value = 'Não foi possível guardar. As escolhas mantêm-se — tente novamente.';
                }

                resolve(succeeded);
            },
        });
    });
}

function submit(): void {
    void save();
}

function destroyBatch(): void {
    if (!window.confirm('Eliminar todas as marcações de TPC desta turma nesta data?')) {
        return;
    }

    submitting.value = true;
    router.delete(`/classes/${props.classUlid}/records/homework-batch`, {
        data: { occurred_at: props.occurredAt },
        preserveScroll: true,
        preserveState: true,
        onFinish: () => {
            submitting.value = false;
        },
        onSuccess: () => {
            savedAt.value = null;
            emit('saved');
            void loadBatch();
        },
    });
}

const guard = useUnsavedChangesGuard({
    isDirty: () => dirtyRows.value.length > 0,
    isSubmitting: () => submitting.value || form.processing,
    save,
    discard: discardChanges,
});

defineExpose({
    /** Runs a change of day, class or tab now, or after the teacher decides about what is unsaved. */
    request: guard.request,
    isDirty: () => dirtyRows.value.length > 0,
});

// --- Teclado: uma linha = um grupo de escolhas --------------------------------

function optionIndex(row: GridRow): number {
    const index = STATUSES.findIndex((status) => status.value === row.status);

    return index === -1 ? 0 : index;
}

function focusOption(rowIndex: number, option: number): void {
    void nextTick(() => {
        root.value?.querySelector<HTMLElement>(`[data-row="${rowIndex}"][data-option="${option}"]`)?.focus();
    });
}

function onRowKeydown(event: KeyboardEvent, rowIndex: number): void {
    const row = rows.value[rowIndex];

    if (row === undefined) {
        return;
    }

    const shortcut = STATUSES.findIndex((status) => status.shortcut === event.key);

    if (shortcut !== -1) {
        event.preventDefault();
        setStatus(row, STATUSES[shortcut].value);
        focusOption(rowIndex, shortcut);

        return;
    }

    switch (event.key) {
        case '0':
        case 'Delete':
        case 'Backspace':
            event.preventDefault();
            resetRow(row);
            break;
        case 'ArrowRight':
        case 'ArrowLeft': {
            event.preventDefault();
            const step = event.key === 'ArrowRight' ? 1 : -1;
            const next = row.status === null ? (step === 1 ? 0 : STATUSES.length - 1) : (optionIndex(row) + step + STATUSES.length) % STATUSES.length;
            setStatus(row, STATUSES[next].value);
            focusOption(rowIndex, next);
            break;
        }
        case 'ArrowDown':
        case 'ArrowUp': {
            event.preventDefault();
            const target = rowIndex + (event.key === 'ArrowDown' ? 1 : -1);

            if (target >= 0 && target < rows.value.length) {
                focusOption(target, optionIndex(rows.value[target]));
            }

            break;
        }
    }
}

function tabIndexFor(row: GridRow, option: number): number {
    return option === optionIndex(row) ? 0 : -1;
}

function rowTone(row: GridRow): string {
    return STATUSES.find((status) => status.value === row.status)?.row ?? '';
}

function lockedText(row: GridRow): string {
    if (row.lockedReason === 'duplicate') {
        return `Tem ${row.recordCount} registos de TPC nesta data — não são fundidos nem se escolhe um. Corrija-os um a um na pesquisa.`;
    }

    return `Inscrição «${row.statusLabel ?? 'não ativa'}» sem data de saída: não se sabe se pertencia à turma nesta data, por isso não pode ser marcada. Registe a data de saída na turma.`;
}

function conflictText(row: GridRow): string {
    const conflict = row.conflict;

    if (conflict === null) {
        return '';
    }

    const when = conflict.at ? ` às ${new Date(conflict.at).toLocaleTimeString('pt-PT', { hour: '2-digit', minute: '2-digit' })}` : '';
    const now = STATUSES.find((status) => status.value === conflict.status)?.label ?? UNVERIFIED_LABEL;
    const what = conflict.change === 'removed'
        ? `foi reposta em «${UNVERIFIED_LABEL}»`
        : conflict.change === 'created'
            ? `foi marcada «${now}»`
            : `passou a «${now}»`;

    return `Noutra sessão, esta marcação ${what}${when}, depois de abrir a grelha. A sua escolha («${statusLabelOf(row)}») não foi gravada.`;
}

function duplicateSearchHref(row: GridRow): string {
    const query = new URLSearchParams({
        tab: 'search',
        class: props.classUlid,
        student: row.ulid,
        kind: 'homework',
        mode: 'range',
        from: props.occurredAt,
        to: props.occurredAt,
        searched: '1',
    });

    return `/records?${query.toString()}`;
}

function statusLabelOf(row: GridRow): string {
    return STATUSES.find((status) => status.value === row.status)?.label ?? UNVERIFIED_LABEL;
}
</script>

<template>
    <section ref="root" class="space-y-3" aria-label="Grelha de trabalho de casa" data-test="homework-grid">
        <p v-if="readOnly" class="rounded-md border border-border bg-muted/30 px-3 py-2 text-sm text-muted-foreground" role="note">
            Esta turma está só para consulta — as marcações não podem ser alteradas.
        </p>

        <label v-if="!readOnly" class="block text-sm">
            <span class="mb-1 block text-xs text-muted-foreground">Descrição comum (opcional)</span>
            <input
                v-model="sharedDescription"
                maxlength="1000"
                class="min-h-11 w-full rounded-md border border-border bg-background px-3 py-2"
                placeholder="Ex.: Exercícios 1 a 5 da página 42"
                :disabled="loading"
                data-test="shared-description"
                @input="applySharedDescription"
            />
            <span class="mt-1 block text-xs text-muted-foreground">Preenche a observação de quem for marcado; pode personalizá-la em cada aluno.</span>
        </label>

        <div class="flex flex-wrap items-center gap-2">
            <button
                v-if="!readOnly"
                type="button"
                class="inline-flex min-h-11 items-center gap-1.5 rounded-md border border-emerald-700/40 px-3 text-sm font-medium text-emerald-800 hover:bg-emerald-50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50 dark:text-emerald-300 dark:hover:bg-emerald-950/30"
                :disabled="loading || unverifiedEditable.length === 0"
                data-test="mark-all-done"
                @click="markAllDone"
            >
                <span aria-hidden="true">✓</span> Marcar todos como realizou
                <span class="text-xs font-normal opacity-80">({{ unverifiedEditable.length }} por verificar)</span>
            </button>
            <p class="text-xs text-muted-foreground">
                Aplica-se só a quem está «Não verificado» nesta grelha; depois ajuste as exceções antes de guardar.
            </p>
        </div>

        <div class="flex flex-wrap gap-1.5 text-xs tabular-nums" aria-live="polite" data-test="homework-summary">
            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-1 text-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200">
                <span aria-hidden="true">✓</span> Realizou {{ counts.done }}
            </span>
            <span class="inline-flex items-center gap-1 rounded-full bg-blue-50 px-2.5 py-1 text-blue-900 dark:bg-blue-950/40 dark:text-blue-200">
                <span aria-hidden="true">◐</span> Realizou parcialmente {{ counts.partially_done }}
            </span>
            <span class="inline-flex items-center gap-1 rounded-full bg-orange-50 px-2.5 py-1 text-orange-900 dark:bg-orange-950/40 dark:text-orange-200">
                <span aria-hidden="true">✕</span> Não realizou {{ counts.not_done }}
            </span>
            <span class="inline-flex items-center gap-1 rounded-full bg-muted/60 px-2.5 py-1 text-muted-foreground">
                <span aria-hidden="true">—</span> Por verificar {{ counts.unverified }}
            </span>
        </div>

        <p v-if="!readOnly" class="hidden text-xs text-muted-foreground lg:block">
            Teclado: Tab muda de aluno, ← → ou 1, 2, 3 escolhem, 0 repõe em «Não verificado», ↑ ↓ sobem e descem na lista.
        </p>

        <p v-if="loading" class="py-8 text-center text-sm text-muted-foreground">A carregar alunos…</p>
        <div v-else-if="loadError" class="flex flex-wrap items-center gap-2 rounded-md bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950/40 dark:text-red-200" role="alert">
            {{ loadError }}
            <button type="button" class="min-h-11 rounded-md px-3 font-medium underline" @click="loadBatch()">Tentar novamente</button>
        </div>
        <p v-else-if="rows.length === 0" class="py-8 text-center text-sm text-muted-foreground">Nenhum aluno integrava esta turma nesta data.</p>

        <div v-else class="space-y-2 lg:space-y-0 lg:overflow-hidden lg:rounded-md lg:border lg:border-border">
            <div class="hidden grid-cols-[3rem_minmax(10rem,1fr)_repeat(3,9.5rem)_2.75rem_7rem] items-center gap-1 bg-muted/30 px-3 py-2 text-xs text-muted-foreground lg:grid" aria-hidden="true">
                <span>N.º</span><span>Aluno</span><span class="text-center">Realizou</span><span class="text-center">Realizou parcialmente</span><span class="text-center">Não realizou</span><span></span><span></span>
            </div>
            <div
                v-for="(row, rowIndex) in rows"
                :key="row.enrollment_id"
                class="rounded-md border p-3 lg:rounded-none lg:border-x-0 lg:border-b-0 lg:p-0"
                :class="[rowTone(row), isRowDirty(row) ? 'border-l-4 border-l-amber-500 border-border' : 'border-border']"
                :data-test="`homework-row-${row.enrollment_id}`"
                :data-status="row.status ?? 'unverified'"
            >
                <div class="grid gap-2 lg:grid-cols-[3rem_minmax(10rem,1fr)_repeat(3,9.5rem)_2.75rem_7rem] lg:items-center lg:px-3 lg:py-1.5">
                    <span class="hidden text-sm tabular-nums text-muted-foreground lg:block">{{ row.number ?? '—' }}</span>
                    <span class="min-w-0 text-sm">
                        <span class="font-medium"><span class="tabular-nums text-muted-foreground lg:hidden">{{ row.number === null ? '' : `n.º ${row.number} · ` }}</span>{{ row.name }}</span>
                        <span class="block text-xs text-muted-foreground">
                            {{ statusLabelOf(row) }}<template v-if="isRowDirty(row)"> · <span class="font-medium text-amber-800 dark:text-amber-300" data-test="row-unsaved">por guardar</span></template>
                            <template v-if="row.leftOn && row.editable"> · saiu a {{ formatDay(row.leftOn) }}</template>
                        </span>
                    </span>

                    <template v-if="row.editable && !readOnly">
                        <div class="grid grid-cols-3 gap-1.5 lg:col-span-3 lg:gap-1" role="radiogroup" :aria-label="`Trabalho de casa — ${row.name}`" @keydown="onRowKeydown($event, rowIndex)">
                            <button
                                v-for="(status, option) in STATUSES"
                                :key="status.value"
                                type="button"
                                role="radio"
                                class="flex min-h-11 items-center justify-center gap-1 rounded-md border px-2 py-1.5 text-xs font-medium focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:outline-none"
                                :class="row.status === status.value ? status.selected : `border-border bg-background hover:bg-muted/40 ${status.idle}`"
                                :aria-checked="row.status === status.value"
                                :aria-label="`${status.label} — ${row.name}`"
                                :tabindex="tabIndexFor(row, option)"
                                :data-row="rowIndex"
                                :data-option="option"
                                @click="setStatus(row, status.value)"
                            >
                                <span aria-hidden="true">{{ status.symbol }}</span>
                                <span>{{ status.label }}</span>
                            </button>
                        </div>
                        <button
                            type="button"
                            class="inline-flex min-h-11 items-center justify-center gap-1 rounded-md px-2 text-xs text-muted-foreground hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:invisible"
                            :disabled="row.status === null"
                            :aria-label="`Repor ${row.name} em «Não verificado»`"
                            :title="'Repor em «Não verificado»'"
                            data-test="reset-row"
                            @click="resetRow(row)"
                        >
                            <RotateCcw class="size-4" aria-hidden="true" /><span class="lg:sr-only">Repor</span>
                        </button>
                        <button
                            type="button"
                            class="inline-flex min-h-11 items-center gap-1 text-left text-xs font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:justify-center"
                            :aria-expanded="row.observationOpen"
                            @click="row.observationOpen = !row.observationOpen"
                        >
                            <MessageSquarePlus class="size-3.5 shrink-0" aria-hidden="true" />
                            {{ row.observationOpen ? 'Fechar observação' : 'Observação' }}
                        </button>
                    </template>

                    <p v-else-if="row.editable" class="text-xs text-muted-foreground lg:col-span-5">{{ statusLabelOf(row) }}</p>
                    <p v-else class="text-xs text-muted-foreground lg:col-span-5" data-test="row-locked">
                        {{ lockedText(row) }}
                        <Link v-if="row.lockedReason === 'duplicate'" :href="duplicateSearchHref(row)" class="font-medium text-primary underline">Ver os registos</Link>
                        <Link v-else :href="`/classes/${classUlid}`" class="font-medium text-primary underline">Abrir a turma</Link>
                    </p>
                </div>
                <div
                    v-if="row.conflict"
                    class="flex flex-wrap items-center gap-2 border-t border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100"
                    role="alert"
                    data-test="row-conflict"
                >
                    <p class="min-w-0 flex-1">{{ conflictText(row) }}</p>
                    <button type="button" class="min-h-11 rounded-md border border-amber-400 px-3 text-xs font-medium hover:bg-amber-100 dark:hover:bg-amber-900/40" data-test="keep-mine" @click="keepMyChoice(row)">
                        Manter a minha escolha
                    </button>
                    <button type="button" class="min-h-11 rounded-md border border-amber-400 px-3 text-xs font-medium hover:bg-amber-100 dark:hover:bg-amber-900/40" data-test="use-current" @click="useCurrentState(row)">
                        Usar o estado atual
                    </button>
                </div>
                <div v-if="row.observationOpen && row.editable && !readOnly" class="border-t border-border bg-background/60 p-3">
                    <label class="block text-xs text-muted-foreground">
                        Observação — {{ row.name }}
                        <textarea
                            v-model="row.description"
                            rows="2"
                            maxlength="1000"
                            class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm text-foreground disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="row.status === null"
                            :placeholder="row.status === null ? 'Escolha primeiro uma das três opções.' : 'Observação opcional'"
                            @input="customizeDescription(row)"
                        ></textarea>
                    </label>
                    <p v-if="row.status === null && row.savedDescription !== ''" class="mt-1 text-xs text-amber-800 dark:text-amber-300">
                        Ao guardar em «Não verificado», a observação gravada nesta marcação é eliminada.
                    </p>
                </div>
            </div>
        </div>

        <div
            v-if="!readOnly && rows.length > 0"
            class="sticky bottom-0 z-10 flex flex-wrap items-center justify-between gap-2 rounded-md border border-border bg-card/95 px-3 py-2 backdrop-blur"
            data-test="save-bar"
        >
            <p class="text-sm" aria-live="polite">
                <span v-if="saveError" class="text-red-700 dark:text-red-300" role="alert" data-test="save-error">{{ saveError }}</span>
                <span v-else-if="partial && conflictRows.length > 0" class="font-medium text-amber-800 dark:text-amber-300" role="alert" data-test="partial-save">
                    <template v-if="partial.saved === 0">Nada foi gravado:</template>
                    <template v-else>Gravação parcial: {{ partial.saved === 1 ? '1 linha gravada' : `${partial.saved} linhas gravadas` }};</template>
                    {{ conflictRows.length === 1 ? '1 aluno alterado noutra sessão ficou por gravar — reveja-o.' : `${conflictRows.length} alunos alterados noutra sessão ficaram por gravar — reveja-os.` }}
                </span>
                <span v-else-if="conflictRows.length > 0" class="font-medium text-amber-800 dark:text-amber-300" data-test="conflict-count">
                    {{ conflictRows.length === 1 ? '1 aluno com conflito por rever' : `${conflictRows.length} alunos com conflito por rever` }}
                </span>
                <span v-else-if="dirtyRows.length > 0" class="font-medium text-amber-800 dark:text-amber-300" data-test="dirty-count">
                    {{ dirtyRows.length === 1 ? '1 alteração por guardar' : `${dirtyRows.length} alterações por guardar` }}
                </span>
                <span v-else-if="savedAt" class="text-emerald-800 dark:text-emerald-300" data-test="saved-at">Guardado às {{ savedAt }}.</span>
                <span v-else class="text-muted-foreground">Sem alterações por guardar.</span>
            </p>
            <div class="flex flex-wrap items-center gap-2">
                <button
                    v-if="hasSavedMarkings && dirtyRows.length === 0"
                    type="button"
                    class="min-h-11 rounded-md px-3 text-sm text-red-700 hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-950/30"
                    :disabled="loading || form.processing"
                    @click="destroyBatch"
                >
                    Eliminar o TPC desta data
                </button>
                <button
                    v-if="dirtyRows.length > 0"
                    type="button"
                    class="min-h-11 rounded-md px-3 text-sm text-muted-foreground hover:bg-muted/40"
                    :disabled="form.processing"
                    data-test="discard"
                    @click="discardChanges"
                >
                    Desfazer alterações
                </button>
                <button
                    type="button"
                    class="min-h-11 rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50"
                    :disabled="loading || form.processing || savableRows.length === 0"
                    data-test="save-homework"
                    @click="submit"
                >
                    {{ form.processing ? 'A guardar…' : 'Guardar trabalho de casa' }}
                </button>
            </div>
        </div>

        <UnsavedChangesDialog
            :open="guard.pending.value !== null"
            :context="`${dirtyRows.length === 1 ? '1 marcação' : `${dirtyRows.length} marcações`} de TPC de ${formatDay(occurredAt)} por guardar`"
            :lead="null"
            consequence="Se continuares sem guardar, perdes estas marcações."
            saving-text="A guardar o trabalho de casa…"
            :saving="guard.saving.value"
            @stay="guard.stay"
            @leave="guard.leaveWithoutSaving"
            @save="guard.saveAndContinue"
        />
    </section>
</template>
