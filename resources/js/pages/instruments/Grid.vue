<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { CircleAlert, Save } from '@lucide/vue';
import { computed, nextTick, reactive, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { percentFor as computePercentFor, qualitativeLabelFor as computeQualitativeLabelFor } from '@/lib/instrumentQualitativeRating';

type Item = {
    id: number;
    code: string;
    label: string | null;
    points_possible: number;
    is_bonus: boolean;
    domains: { name: string; percent: number }[];
};

type Student = {
    enrollment_id: number;
    name: string;
    class_number: number | null;
    enrolled_on: string;
    is_late_entry: boolean;
    joined_after_instrument: boolean;
};

type Score = {
    enrollment_id: number;
    instrument_item_id: number;
    result_state: string;
    points_earned: number | null;
    state_reason: string | null;
};

type StateOption = { value: string; label: string; carries_value: boolean };

const props = defineProps<{
    instrument: {
        ulid: string;
        title: string;
        applied_on: string;
        status_label: string;
        status: string;
        cancellation_reason: string | null;
        total_points: number | null;
        class_label: string;
        class_ulid: string;
        period: string;
    };
    items: Item[];
    students: Student[];
    scores: Score[];
    states: StateOption[];
    scaleBands: { label: string; band_min: string; band_max: string }[];
}>();

type Cell = { state: string; points: number | null; reason: string | null };

const cellKey = (enrollmentId: number, itemId: number) => `${enrollmentId}:${itemId}`;

// Cells the teacher has touched, keyed so only these get sent on save. Untouched
// cells are never transmitted, so two teachers marking different columns of the
// same class do not overwrite one another.
const dirty = reactive(new Set<string>());

const cells = reactive<Record<string, Cell>>({});

for (const score of props.scores) {
    cells[cellKey(score.enrollment_id, score.instrument_item_id)] = {
        state: score.result_state,
        points: score.points_earned,
        reason: score.state_reason,
    };
}

function cell(enrollmentId: number, itemId: number): Cell {
    const key = cellKey(enrollmentId, itemId);
    // A missing cell is "por avaliar" — the grid does not pre-create rows, and an
    // empty box is never read as a zero.
    cells[key] ??= { state: 'pending', points: null, reason: null };

    return cells[key];
}

function markDirty(enrollmentId: number, itemId: number): void {
    dirty.add(cellKey(enrollmentId, itemId));
}

function onPointsInput(student: Student, item: Item, value: string): void {
    const current = cell(student.enrollment_id, item.id);

    if (value === '') {
        // Cleared back to empty: this is "no data", not a zero.
        current.state = 'pending';
        current.points = null;
    } else {
        current.state = 'assessed';
        current.points = Number(value);
    }

    markDirty(student.enrollment_id, item.id);
}

/**
 * A question's own cotação is a hard ceiling — mirrors the server-side check
 * in RecordScores::guardAgainstScoresAboveMaximum(), so the teacher sees the
 * problem while typing instead of only after a rejected save.
 */
function isOverMax(student: Student, item: Item): boolean {
    const current = cell(student.enrollment_id, item.id);

    return current.state === 'assessed' && current.points !== null && current.points > item.points_possible;
}

const hasOverMaxCell = computed(() =>
    props.students.some((student) => props.items.some((item) => isOverMax(student, item))),
);

function onStateChange(student: Student, item: Item, state: string): void {
    const current = cell(student.enrollment_id, item.id);
    current.state = state;

    // Only an assessed cell keeps a number — switching to absent clears it here
    // as well as on the server.
    if (state !== 'assessed') {
        current.points = null;
    }

    markDirty(student.enrollment_id, item.id);
}

/** Keyboard navigation: Enter/arrows move down a column, Tab moves across. */
function onKeydown(event: KeyboardEvent, rowIndex: number, columnIndex: number): void {
    const move = (row: number, column: number) => {
        const next = document.querySelector<HTMLInputElement>(
            `[data-cell="${row}-${column}"]`,
        );

        if (next) {
            event.preventDefault();
            nextTick(() => {
                next.focus();
                next.select();
            });
        }
    };

    if (event.key === 'Enter' || event.key === 'ArrowDown') {
        move(rowIndex + 1, columnIndex);
    } else if (event.key === 'ArrowUp') {
        move(rowIndex - 1, columnIndex);
    }
}

/**
 * The total, or null when nothing has been marked.
 *
 * Returning 0 for a student with no marks would put the app's own rule on screen
 * backwards: an absent student, or one who joined after the test, would appear
 * to have scored nothing. No marks means no total — shown as "—".
 */
function totalFor(student: Student): number | null {
    let sum = 0;
    let assessed = 0;

    for (const item of props.items) {
        const current = cells[cellKey(student.enrollment_id, item.id)];

        if (current?.state === 'assessed' && current.points !== null) {
            sum += current.points;
            assessed += 1;
        }
    }

    return assessed === 0 ? null : sum;
}

/**
 * The percentage, over only the items already graded — never the
 * instrument's full total_points as a fixed denominator, so an ungraded
 * item never drags the percentage down (CLAUDE.md §13.3, "vazio não é
 * zero"). Bonus items add to the numerator but not the denominator,
 * mirroring CalculationEngine::calculateDomain()'s treatment of bonus
 * items. Returns null under the same condition totalFor() does (nothing
 * graded yet), or if every graded item happened to be bonus (denominator
 * would be zero).
 *
 * This is a per-instrument indicator only — it does not mirror domain
 * weighting, eligibility/late-entry rules, or absence_mode from
 * CalculationEngine. See instrumentQualitativeRating.ts for the extracted,
 * independently testable implementation.
 */
function percentFor(student: Student): number | null {
    return computePercentFor(props.items, (itemId) => cells[cellKey(student.enrollment_id, itemId)]);
}

/**
 * The qualitative label for the student's current percentage, from the
 * class's assessment-profile scale bands (scaleBands prop). A band match is
 * inclusive on both ends, mirroring only the inclusive-boundary convention
 * of CalculationEngine::combine()'s band-matching loop — not its domain
 * weighting, eligibility, or absence handling (see percentFor() above).
 * Returns null when nothing is graded yet, or when scaleBands is empty (no
 * profile assigned to the class, or its scale has no bands configured) —
 * the caller renders "—" in that case, never a guessed label.
 */
function qualitativeLabelFor(student: Student): string | null {
    return computeQualitativeLabelFor(percentFor(student), props.scaleBands);
}

/** True while some cells are marked and others are still pending. */
function isPartial(student: Student): boolean {
    const marked = props.items.filter((item) => {
        const current = cells[cellKey(student.enrollment_id, item.id)];

        return current !== undefined && current.state !== 'pending';
    }).length;

    return marked > 0 && marked < props.items.length;
}

/** Compact labels so the state selector stays narrow in a wide grid. */
const shortLabels: Record<string, string> = {
    pending: '—',
    assessed: '✓',
    absent: 'Aus',
    absent_justified: 'AusJ',
    exempt: 'Disp',
    not_applicable: 'N/A',
    annulled: 'Anul',
    under_review: 'Rev',
};

const dirtyCount = computed(() => dirty.size);
const saving = ref(false);

function save(): void {
    if (dirty.size === 0) {
        return;
    }

    const payload = [...dirty].map((key) => {
        const [enrollmentId, itemId] = key.split(':').map(Number);
        const current = cells[key];

        return {
            enrollment_id: enrollmentId,
            instrument_item_id: itemId,
            result_state: current.state,
            points_earned: current.state === 'assessed' ? current.points : null,
            state_reason: current.reason,
        };
    });

    saving.value = true;
    router.post(`/instruments/${props.instrument.ulid}/scores`, { cells: payload }, {
        preserveScroll: true,
        onSuccess: () => dirty.clear(),
        onFinish: () => {
            saving.value = false;
        },
    });
}

const nonAssessedStates = computed(() => props.states.filter((state) => !state.carries_value));

const isCancelled = computed(() => props.instrument.status === 'cancelled');

const cancelDialogOpen = ref(false);
const cancelForm = useForm<{ reason: string }>({ reason: '' });

function openCancelDialog(): void {
    cancelForm.reset();
    cancelForm.clearErrors();
    cancelDialogOpen.value = true;
}

function submitCancel(): void {
    cancelForm.post(`/instruments/${props.instrument.ulid}/cancel`, {
        preserveScroll: true,
        onSuccess: () => {
            cancelDialogOpen.value = false;
        },
    });
}

function revertCancellation(): void {
    router.post(`/instruments/${props.instrument.ulid}/revert-cancellation`, {}, { preserveScroll: true });
}
</script>

<template>
    <Head :title="instrument.title" />

    <div class="space-y-4 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <Heading :title="instrument.title" :description="`${instrument.class_label} · ${instrument.period} · ${instrument.applied_on}`" />
                <Link :href="`/classes/${instrument.class_ulid}`" class="text-sm text-muted-foreground hover:underline">
                    ← Voltar à turma
                </Link>
            </div>
            <div class="flex items-center gap-3">
                <Badge variant="secondary">{{ instrument.status_label }}</Badge>
                <template v-if="!isCancelled">
                    <Link :href="`/instruments/${instrument.ulid}/edit`" class="text-sm text-muted-foreground hover:underline">
                        Editar instrumento
                    </Link>
                    <Button type="button" variant="outline" size="sm" @click="openCancelDialog">
                        Anular instrumento
                    </Button>
                    <span v-if="dirtyCount" class="text-sm text-amber-700">
                        {{ dirtyCount }} alteraç{{ dirtyCount === 1 ? 'ão' : 'ões' }} por guardar
                    </span>
                    <span v-if="hasOverMaxCell" class="text-sm text-destructive">
                        Há notas acima da cotação máxima
                    </span>
                    <Button :disabled="dirtyCount === 0 || saving || hasOverMaxCell" @click="save">
                        <Save class="size-4" /> Guardar
                    </Button>
                </template>
            </div>
        </div>

        <div v-if="isCancelled" class="flex items-center justify-between rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <span>Instrumento anulado — motivo: {{ instrument.cancellation_reason }}</span>
            <Button type="button" variant="outline" size="sm" @click="revertCancellation">
                Reverter anulação
            </Button>
        </div>

        <div v-if="students.length === 0" class="rounded-lg border border-dashed border-border p-10 text-center">
            <p class="text-sm text-muted-foreground">Esta turma ainda não tem alunos inscritos.</p>
        </div>

        <div v-else class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full border-collapse text-sm">
                <thead class="bg-muted/50">
                    <tr>
                        <th class="sticky left-0 z-10 bg-muted/50 px-3 py-2 text-left font-medium">Aluno</th>
                        <th v-for="item in items" :key="item.id" class="min-w-24 px-2 py-2 text-center font-medium">
                            <div>{{ item.code }}</div>
                            <div class="text-xs font-normal text-muted-foreground">
                                {{ item.points_possible }} pts<span v-if="item.is_bonus"> · bónus</span>
                            </div>
                            <div v-if="item.domains.length" class="text-[10px] font-normal text-muted-foreground">
                                {{ item.domains.map((d) => `${d.name} ${d.percent}%`).join(' · ') }}
                            </div>
                        </th>
                        <th class="px-3 py-2 text-right font-medium">Total</th>
                        <th
                            class="px-3 py-2 text-left font-medium"
                            title="Indicador só deste instrumento — não é a classificação oficial da turma/período, que pondera domínios e outras regras."
                        >Apreciação Qualitativa</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="(student, rowIndex) in students" :key="student.enrollment_id" class="hover:bg-muted/20">
                        <td class="sticky left-0 z-10 bg-background px-3 py-1.5 whitespace-nowrap">
                            <span class="text-muted-foreground">{{ student.class_number ?? '—' }}</span>
                            <span class="ml-2 font-medium">{{ student.name }}</span>
                            <span
                                v-if="student.joined_after_instrument"
                                class="ml-1.5 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] text-amber-900"
                                title="Entrou depois desta avaliação — não é penalizado por ela."
                            >entrou depois</span>
                        </td>

                        <td v-for="(item, columnIndex) in items" :key="item.id" class="px-1 py-1 text-center">
                            <div class="flex items-center justify-center gap-1">
                                <input
                                    :data-cell="`${rowIndex}-${columnIndex}`"
                                    type="number"
                                    step="0.25"
                                    min="0"
                                    :max="item.points_possible"
                                    :value="cell(student.enrollment_id, item.id).state === 'assessed' ? cell(student.enrollment_id, item.id).points : ''"
                                    :disabled="isCancelled || (cell(student.enrollment_id, item.id).state !== 'assessed' && cell(student.enrollment_id, item.id).state !== 'pending')"
                                    :class="[
                                        'h-8 w-16 rounded border bg-transparent px-1.5 text-center tabular-nums disabled:opacity-40',
                                        isOverMax(student, item) ? 'border-destructive text-destructive' : 'border-input',
                                    ]"
                                    :title="isOverMax(student, item) ? `Excede a cotação máxima (${item.points_possible} pts).` : undefined"
                                    @input="onPointsInput(student, item, ($event.target as HTMLInputElement).value)"
                                    @keydown="onKeydown($event, rowIndex, columnIndex)"
                                />
                                <select
                                    :value="cell(student.enrollment_id, item.id).state"
                                    :disabled="isCancelled"
                                    class="h-8 w-14 cursor-pointer rounded border border-input bg-transparent text-xs"
                                    :title="states.find((s) => s.value === cell(student.enrollment_id, item.id).state)?.label"
                                    @change="onStateChange(student, item, ($event.target as HTMLSelectElement).value)"
                                >
                                    <option value="pending">—</option>
                                    <option value="assessed">✓</option>
                                    <option v-for="state in nonAssessedStates.filter((s) => s.value !== 'pending')" :key="state.value" :value="state.value">
                                        {{ shortLabels[state.value] ?? state.label }}
                                    </option>
                                </select>
                            </div>
                        </td>

                        <td class="px-3 py-1.5 text-right font-semibold tabular-nums">
                            <template v-if="totalFor(student) === null">
                                <span class="text-muted-foreground" title="Sem classificações registadas — não é zero.">—</span>
                            </template>
                            <template v-else>
                                {{ totalFor(student) }}<span v-if="instrument.total_points" class="font-normal text-muted-foreground">/{{ instrument.total_points }}</span>
                                <span v-if="percentFor(student) !== null" class="font-normal text-muted-foreground"> · {{ percentFor(student) }}%</span>
                                <span v-if="isPartial(student)" class="ml-1 text-xs font-normal text-amber-600" title="Ainda há questões por avaliar.">parcial</span>
                            </template>
                        </td>
                        <td class="px-3 py-1.5 text-left text-muted-foreground">
                            {{ qualitativeLabelFor(student) ?? '—' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="flex items-start gap-2 text-xs text-muted-foreground">
            <CircleAlert class="mt-0.5 size-3.5 shrink-0" />
            Uma célula vazia significa "por avaliar" — nunca zero. Para registar uma ausência ou
            dispensa, use o seletor de estado ao lado da caixa. Um zero só é guardado se o
            introduzir como classificação.
        </p>

        <Dialog v-model:open="cancelDialogOpen">
            <DialogContent>
                <form @submit.prevent="submitCancel">
                    <DialogHeader>
                        <DialogTitle>Anular instrumento</DialogTitle>
                        <DialogDescription>
                            O instrumento deixa de contar para o cálculo e fica só-leitura até reverteres a anulação.
                        </DialogDescription>
                    </DialogHeader>
                    <div class="grid gap-4 py-4">
                        <div class="grid gap-2">
                            <label for="cancel-reason" class="text-sm font-medium">Motivo</label>
                            <textarea
                                id="cancel-reason"
                                v-model="cancelForm.reason"
                                rows="3"
                                class="rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                            ></textarea>
                            <InputError :message="cancelForm.errors.reason" />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button type="submit" :disabled="cancelForm.processing">Anular</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
