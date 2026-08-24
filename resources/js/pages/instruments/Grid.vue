<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { CheckCircle2, CircleAlert, Save, WifiOff } from '@lucide/vue';
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import StudentAvatar from '@/components/StudentAvatar.vue';
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
import { computeStructuralFingerprint, useGridDraft } from '@/composables/useGridDraft';
import {
    domainResultsFor as computeDomainResultsFor,
    percentFor as computePercentFor,
    qualitativeLabelFor as computeQualitativeLabelFor,
    scaleBandFor,
} from '@/lib/instrumentQualitativeRating';
import { qualitativeToneClasses, qualitativeToneFor } from '@/lib/qualitativeTone';

// Preserves where the teacher came from without a general-purpose breadcrumb:
// arriving from assessments/Show.vue (via ?from=assessments) returns there;
// every other entry point (Elementos de Avaliação, the class page) keeps today's
// unchanged "voltar à turma".
const cameFromAssessments = new URLSearchParams(window.location.search).get('from') === 'assessments';

type Item = {
    id: number;
    code: string;
    label: string | null;
    points_possible: number;
    is_bonus: boolean;
    domains: { domain_id: number; name: string; percent: number }[];
};

type Student = {
    enrollment_id: number;
    name: string;
    photo_url: string | null;
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
    lock_version: number;
};

type ScoreVersion = { enrollment_id: number; instrument_item_id: number; lock_version: number };
type StaleScore = Score;
type ScoreSaveResult = { written: number; versions: ScoreVersion[]; stale: StaleScore[] };

type StateOption = { value: string; label: string; carries_value: boolean; resolves: boolean };

const props = defineProps<{
    instrument: {
        ulid: string;
        title: string;
        applied_on: string;
        status_label: string;
        // Correction workflow: whether it is closed, whether it may be closed,
        // and how many students still lack a decision if it may not.
        is_completed: boolean;
        can_complete: boolean;
        pending_count: number;
        applicable_count: number;
        completed_count: number;
        completed_at: string | null;
        pending_students: string[];
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
    scaleBands: { label: string; band_min: string; band_max: string; sequence: number; is_negative: boolean }[];
}>();

type Cell = { state: string; points: number | null; reason: string | null; lock_version: number };

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
        lock_version: score.lock_version,
    };
}

const page = usePage();
const organizationUlid = page.props.auth.organization?.ulid ?? '';
const currentFingerprint = computeStructuralFingerprint(props.items);
const gridDraft = useGridDraft(
    {
        organizationUlid,
        userId: page.props.auth.user.id,
        instrumentUlid: props.instrument.ulid,
    },
    currentFingerprint,
);

type AvailableDraft = NonNullable<ReturnType<typeof gridDraft.readDraft>>;

const recoverableDraft = ref<AvailableDraft | null>(null);
const incompatibleDraftExists = ref(false);
const isOnline = ref(navigator.onLine);
const otherEditingTabs = reactive(new Set<string>());

function dirtyDraftCells(): Record<string, { state: string; points: number | null; reason: string | null }> {
    return Object.fromEntries(
        [...dirty]
            .filter((key) => cells[key] !== undefined)
            .map((key) => {
                const current = cells[key];

                return [key, { state: current.state, points: current.points, reason: current.reason }];
            }),
    );
}

function writeCurrentDraft(): void {
    gridDraft.writeDraft(dirtyDraftCells());
}

function recoverDraft(): void {
    const draft = recoverableDraft.value;

    if (draft === null) {
        return;
    }

    for (const [key, draftCell] of Object.entries(draft.cells)) {
        const [enrollmentId, itemId] = key.split(':').map(Number);
        const belongsToCurrentGrid = props.students.some((student) => student.enrollment_id === enrollmentId)
            && props.items.some((item) => item.id === itemId);

        if (!belongsToCurrentGrid) {
            continue;
        }

        const current = cell(enrollmentId, itemId);

        cells[key] = { ...draftCell, lock_version: current.lock_version };
        dirty.add(key);
    }

    recoverableDraft.value = null;
    writeCurrentDraft();
}

function discardDraft(): void {
    gridDraft.clearDraft();
    recoverableDraft.value = null;
    incompatibleDraftExists.value = false;
}

const recoverableDraftCount = computed(() => Object.keys(recoverableDraft.value?.cells ?? {}).length);
const recoverableDraftDate = computed(() => {
    if (recoverableDraft.value === null) {
        return '';
    }

    return new Intl.DateTimeFormat('pt-PT', {
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(new Date(recoverableDraft.value.savedAt));
});

type EditingChannelMessage = { type: 'editing' | 'closing'; tabId: string };

let editingChannel: BroadcastChannel | null = null;
let tabId = '';

function announceEditing(): void {
    editingChannel?.postMessage({ type: 'editing', tabId } satisfies EditingChannelMessage);
}

function handleChannelMessage(event: MessageEvent<EditingChannelMessage>): void {
    const message = event.data;

    if (!message || message.tabId === tabId) {
        return;
    }

    if (message.type === 'closing') {
        otherEditingTabs.delete(message.tabId);

        return;
    }

    if (message.type === 'editing' && !otherEditingTabs.has(message.tabId)) {
        otherEditingTabs.add(message.tabId);
        announceEditing();
    }
}

onMounted(() => {
    const draft = gridDraft.readDraft();

    if (draft !== null && Object.keys(draft.cells).length > 0) {
        if (gridDraft.isCompatible()) {
            recoverableDraft.value = draft;
        } else {
            incompatibleDraftExists.value = true;
        }
    }

    window.addEventListener('online', handleOnline);
    window.addEventListener('offline', handleOffline);

    if ('BroadcastChannel' in window) {
        tabId = globalThis.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random()}`;
        editingChannel = new BroadcastChannel(`lapis:grid-editing:${organizationUlid}:${props.instrument.ulid}`);
        editingChannel.addEventListener('message', handleChannelMessage);
        announceEditing();
    }
});

function handleOnline(): void {
    isOnline.value = true;
}

function handleOffline(): void {
    isOnline.value = false;
}

onBeforeUnmount(() => {
    window.removeEventListener('online', handleOnline);
    window.removeEventListener('offline', handleOffline);

    if (editingChannel !== null) {
        editingChannel.postMessage({ type: 'closing', tabId } satisfies EditingChannelMessage);
        editingChannel.removeEventListener('message', handleChannelMessage);
        editingChannel.close();
    }
});

function cell(enrollmentId: number, itemId: number): Cell {
    const key = cellKey(enrollmentId, itemId);
    // A missing cell is "por avaliar" — the grid does not pre-create rows, and an
    // empty box is never read as a zero.
    cells[key] ??= { state: 'pending', points: null, reason: null, lock_version: 0 };

    return cells[key];
}

function markDirty(enrollmentId: number, itemId: number): void {
    dirty.add(cellKey(enrollmentId, itemId));
    writeCurrentDraft();
}

/**
 * A cell holds a number, or it holds nothing. There is no third thing.
 *
 * `Number('')` is 0 and `Number('-')` is NaN, and both used to become an
 * «assessed» cell: the first would have invented a zero, the second sent
 * `assessed` with a value of NaN, which JSON writes as null — the one pair the
 * model forbids, and the 500 this fixes. A half-typed «-» in the deduction
 * column is exactly how that happened.
 *
 * Zero survives, deliberately and by test: `Number('0')` is finite, so a
 * written zero stays an assessed zero. Only «no number at all» is nothing.
 */
function onPointsInput(student: Student, item: Item, value: string): void {
    const current = cell(student.enrollment_id, item.id);
    const parsed = value.trim() === '' ? null : Number(value);

    if (parsed === null || ! Number.isFinite(parsed)) {
        // Empty, or not yet a number. Either way this is «no data», never a
        // zero and never an assessment.
        current.state = 'pending';
        current.points = null;
    } else {
        current.state = 'assessed';
        current.points = parsed;
    }

    markDirty(student.enrollment_id, item.id);
}

/**
 * A question's own cotação is a hard ceiling — mirrors the server-side check
 * in RecordScores::guardAgainstScoresAboveMaximum(), so the teacher sees the
 * problem while typing instead of only after a rejected save.
 */
/**
 * Whether this question is a deduction: outside the denominator, worth nothing
 * on its own, and carrying a NEGATIVE mark that lowers the domain it belongs to.
 *
 * Derived from the item's own canonical properties, never from its name.
 */
function isDeduction(item: Item): boolean {
    return item.is_bonus && item.points_possible === 0;
}

/**
 * The lowest value the box will accept.
 *
 * Zero for an ordinary question. Unbounded below for a deduction, because
 * `min="0"` on the deduction column made the one value it exists to hold
 * impossible to type — and a teacher starting to write «-2» produced a
 * half-typed «-» that used to travel as an assessed cell with no number.
 */
function minimumFor(item: Item): number | undefined {
    return isDeduction(item) ? undefined : 0;
}

function isOverMax(student: Student, item: Item): boolean {
    const current = cell(student.enrollment_id, item.id);

    return current.state === 'assessed' && current.points !== null && current.points > item.points_possible;
}

const hasOverMaxCell = computed(() =>
    props.students.some((student) => props.items.some((item) => isOverMax(student, item))),
);

function onStateChange(student: Student, item: Item, state: string): void {
    const current = cell(student.enrollment_id, item.id);

    // «Avaliado» is a claim about a number, so it cannot be chosen where there
    // is no number: an empty cell marked ✓ used to travel as `assessed` with a
    // null value, which is the pair the model refuses. It stays «por avaliar»
    // until somebody writes a mark in it.
    if (state === 'assessed' && ! Number.isFinite(current.points)) {
        current.state = 'pending';
        current.points = null;
        markDirty(student.enrollment_id, item.id);

        return;
    }

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

/**
 * The Tailwind classes for the scale band a percentage falls into —
 * structural (sequence/is_negative), never a match on the band's own label
 * text, so a custom or translated scale keeps its colour coding. Neutral
 * when nothing is graded yet or no band matches, matching qualitativeLabelFor()'s
 * own "—" fallback.
 */
function toneClassFor(percent: number | null): string {
    const band = scaleBandFor(percent, props.scaleBands);

    return qualitativeToneClasses[band ? qualitativeToneFor(band, props.scaleBands) : 'neutral'];
}

/** domain_id -> its display name, from whichever item first mentions it. */
const domainNames = computed(() => {
    const names = new Map<number, string>();

    for (const item of props.items) {
        for (const allocation of item.domains) {
            if (!names.has(allocation.domain_id)) {
                names.set(allocation.domain_id, allocation.name);
            }
        }
    }

    return names;
});

type DomainSummary = {
    domain_id: number;
    name: string;
    earned: number;
    possible: number;
    percent: number | null;
    isPartial: boolean;
    label: string | null;
    toneClass: string;
};

/**
 * One summary row per domain this instrument's items are allocated to, for
 * the student's current cells — aggregated across every item that touches
 * the domain (item_domain_allocations), never a single item's own score
 * read as the domain's result. See domainResultsFor() for the aggregation
 * itself; this only attaches the domain's name and its qualitative
 * label/tone, reusing the exact same scale bands as the global figure.
 *
 * A partial domain (some items still pending/under_review/etc.) still shows
 * its percentage, label and tone — never invented, always the real
 * aggregate of what IS graded — with isPartial exposed so the template can
 * mark it "parcial", the same convention the existing Total column already
 * uses for the instrument-wide figure. Nothing here is ever hidden just for
 * being provisional; it is only ever labelled as such.
 */
function domainSummariesFor(student: Student): DomainSummary[] {
    const results = computeDomainResultsFor(props.items, (itemId) => cells[cellKey(student.enrollment_id, itemId)]);

    return results.map((result) => ({
        domain_id: result.domain_id,
        name: domainNames.value.get(result.domain_id) ?? '—',
        earned: result.earned,
        possible: result.possible,
        percent: result.percent,
        isPartial: result.isPartial,
        label: computeQualitativeLabelFor(result.percent, props.scaleBands),
        toneClass: toneClassFor(result.percent),
    }));
}

/** True while some cells are marked and others are still pending. */
function isPartial(student: Student): boolean {
    const marked = props.items.filter((item) => {
        const current = cells[cellKey(student.enrollment_id, item.id)];

        return current !== undefined && current.state !== 'pending';
    }).length;

    return marked > 0 && marked < props.items.length;
}

/**
 * Compact labels so the state selector stays narrow in a wide grid.
 *
 * `pending` is the exception and stays spelled out. A dash reads as «nothing
 * here», which is precisely the reading that let one unresolved student hide
 * among five corrected ones — and «por avaliar» is a decision still owed, not
 * an absence of one. It is also what ResultState::Pending already calls itself.
 */
const shortLabels: Record<string, string> = {
    pending: 'Por avaliar',
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

/**
 * Which states count as «dealt with», straight from the server.
 *
 * Not a list written again here: InstrumentCompleteness owns it, and a cell the
 * grid calls resolved while the completeness rule calls it pending is exactly
 * the disagreement that produces a green row and a refusal to close.
 */
const resolvingStates = computed(
    () => new Set(props.states.filter((state) => state.resolves).map((state) => state.value)),
);

/**
 * A student with at least one cell nobody has decided about.
 *
 * Someone who joined after the instrument was applied is left out: the
 * instrument never applied to them, they do not block the correction, and
 * marking their row «por avaliar» would be asking for a decision that is not
 * owed (§11.4).
 */
function isUnresolved(student: Student): boolean {
    if (student.joined_after_instrument) {
        return false;
    }

    return props.items.some((item) => !resolvingStates.value.has(cell(student.enrollment_id, item.id).state));
}

function isPendingCell(student: Student, item: Item): boolean {
    return cell(student.enrollment_id, item.id).state === 'pending';
}

function save(): void {
    if (dirty.size === 0) {
        return;
    }

    const payload = [...dirty].map((key) => {
        const [enrollmentId, itemId] = key.split(':').map(Number);
        const current = cells[key];

        // The last guard before the wire, and the reason it exists: an
        // «assessed» cell without a finite number is the one pair the model
        // refuses, and every path that could produce it is a 500 rather than a
        // wrong mark. Whatever the cell believes about itself, a cell with no
        // number leaves here as «por avaliar».
        //
        // Number.isFinite and not a truthiness check, because 0 is a mark: a
        // written zero must survive as an assessed zero.
        const assessed = current.state === 'assessed' && Number.isFinite(current.points);

        return {
            enrollment_id: enrollmentId,
            instrument_item_id: itemId,
            result_state: assessed ? 'assessed' : current.state === 'assessed' ? 'pending' : current.state,
            points_earned: assessed ? current.points : null,
            state_reason: current.reason,
            lock_version: current.lock_version,
        };
    });

    saving.value = true;
    router.post(`/instruments/${props.instrument.ulid}/scores`, { cells: payload }, {
        preserveScroll: true,
        onSuccess: (page) => {
            const result = page.flash?.scoreSaveResult as ScoreSaveResult | undefined;

            if (!result) {
                return;
            }

            for (const version of result.versions) {
                const key = cellKey(version.enrollment_id, version.instrument_item_id);

                cell(version.enrollment_id, version.instrument_item_id).lock_version = version.lock_version;
                dirty.delete(key);
            }

            // Only conflicted cells are replaced with the authoritative server
            // value. Every other local cell is left untouched.
            for (const stale of result.stale) {
                const key = cellKey(stale.enrollment_id, stale.instrument_item_id);

                cells[key] = {
                    state: stale.result_state,
                    points: stale.points_earned,
                    reason: stale.state_reason,
                    lock_version: stale.lock_version,
                };
                dirty.delete(key);
            }

            gridDraft.reconcileSaved([
                ...result.versions.map((version) => cellKey(version.enrollment_id, version.instrument_item_id)),
                ...result.stale.map((stale) => cellKey(stale.enrollment_id, stale.instrument_item_id)),
            ]);
        },
        onFinish: () => {
            saving.value = false;
        },
    });
}

const nonAssessedStates = computed(() => props.states.filter((state) => !state.carries_value));

/**
 * ===================== O ESTADO DE UM ALUNO NO INSTRUMENTO =====================
 *
 * A student who missed the test missed all seventeen questions of it, and
 * choosing «AusJ» seventeen times is not a thing anybody does twice. This is a
 * SHORTCUT and nothing more: it writes the same per-item states the teacher
 * would have chosen by hand, through the same save, into the same cells. The
 * item remains the only source of truth, and an exception on one question is
 * still made on that question (§5).
 *
 * Which states may be applied to a whole instrument is not a list kept here —
 * it is «every state that does not carry a value». A state that carries one
 * needs a number per question, and a bulk action has no number to give (§3).
 */
const MIXED = '__mixed__';

const rowStates = computed(() => nonAssessedStates.value);

/**
 * What the whole row is, in one word.
 *
 * `MIXED` when the questions disagree, and it is shown as «Vários» rather than
 * resolved to whichever state happens to be commonest: a row with eight marks
 * and nine absences is not an absent row, and saying so would invite the
 * teacher to flatten it by accident (§6).
 */
function rowStateOf(student: Student): string {
    if (props.items.length === 0) {
        return 'pending';
    }

    const first = cell(student.enrollment_id, props.items[0].id).state;

    return props.items.every(
        (item) => cell(student.enrollment_id, item.id).state === first,
    )
        ? first
        : MIXED;
}

/** How many of this student's questions currently hold a mark. */
function markedCount(student: Student): number {
    return props.items.filter((item) => {
        const current = cell(student.enrollment_id, item.id);

        return current.state === 'assessed' && current.points !== null;
    }).length;
}

const pendingBulk = ref<{ student: Student; state: string; marked: number } | null>(null);

function onRowStateChange(student: Student, state: string): void {
    if (state === MIXED) {
        return;
    }

    const marked = markedCount(student);

    // Applying a state to a question that holds a mark REMOVES the mark: only an
    // assessed cell may carry a number, here and in RecordScores alike. Doing
    // that to eight questions at once, silently, is not a shortcut — it is a
    // loss. So it is asked about, and only when there is something to lose (§8).
    if (marked > 0 && state !== 'pending') {
        pendingBulk.value = { student, state, marked };

        return;
    }

    applyRowState(student, state);
}

function applyRowState(student: Student, state: string): void {
    for (const item of props.items) {
        const current = cell(student.enrollment_id, item.id);

        // «Sem estado» clears what was never a classification and leaves alone
        // what was. A question the teacher marked 14 stays marked 14 — clearing
        // a row is undoing an absence, not undoing the correction (§7).
        if (state === 'pending' && current.state === 'assessed') {
            continue;
        }

        if (current.state === state) {
            continue;
        }

        current.state = state;

        if (state !== 'assessed') {
            current.points = null;
        }

        markDirty(student.enrollment_id, item.id);
    }
}

function confirmBulk(): void {
    if (pendingBulk.value !== null) {
        applyRowState(pendingBulk.value.student, pendingBulk.value.state);
        pendingBulk.value = null;
    }
}

const isCancelled = computed(() => props.instrument.status === 'cancelled');

// A closed correction is consulted, not edited. Enforced in RecordScores as
// well — disabling inputs is presentation, never access control.
const isReadOnly = computed(() => isCancelled.value || props.instrument.is_completed);

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

// ------------------------------------------------- correction workflow

const completeDialogOpen = ref(false);
const reopenDialogOpen = ref(false);
const workflowBusy = ref(false);

/**
 * Why «Concluir correção» is refused, in a sentence.
 *
 * This used to live only in the button's `title`. A `title` on a DISABLED
 * button is very nearly invisible: browsers suppress pointer events on disabled
 * form controls, so the native tooltip usually never appears — which left a grey
 * button, no explanation, and a teacher with no way to find out what was wrong.
 * It is rendered on the page now, and the `title` is kept only as a bonus.
 */
const completeBlockedReason = computed<string | null>(() => {
    if (props.instrument.is_completed || isCancelled.value) {
        return null;
    }

    // Saving first, because completeness is about what is persisted — a cell
    // resolved on screen but not yet sent genuinely does not count yet.
    if (dirtyCount.value > 0) {
        return 'Guarde as alterações antes de concluir a correção.';
    }

    if (props.instrument.can_complete) {
        return null;
    }

    // Nobody the instrument applies to, which is a different refusal entirely
    // and the one that used to read «Faltam resolver 0 resultados».
    //
    // An instrument is applicable to a student who was enrolled by the day it
    // was applied. Date it before the class existed — an August date on a year
    // that starts in September — and it applies to nobody, so there is nothing
    // to resolve and nothing to conclude. The count was telling the truth; it
    // was answering a question nobody had asked (§2).
    if (props.instrument.applicable_count === 0) {
        return `Esta avaliação está datada de ${props.instrument.applied_on} e nenhum aluno da turma estava inscrito nessa data, por isso não se aplica a ninguém. Corrija a data da avaliação em «Editar elemento de avaliação».`;
    }

    const count = props.instrument.pending_count;

    // Never «faltam 0»: if the count is zero the refusal is not about counting,
    // and saying so would send the teacher looking for a row that is not there.
    if (count <= 0) {
        return 'Esta avaliação ainda não pode ser concluída. Verifique a data da avaliação e os alunos a que se aplica.';
    }

    return count === 1
        ? 'Falta resolver 1 resultado antes de concluir a correção.'
        : `Faltam resolver ${count} resultados antes de concluir a correção.`;
});

/** «Marta Tomás» · «Marta e Rui» · «A, B, C e mais 2». */
const pendingNamesText = computed<string | null>(() => {
    const names = props.instrument.pending_students;

    if (names.length === 0) {
        return null;
    }

    if (names.length === 1) {
        return names[0];
    }

    if (names.length <= 3) {
        return `${names.slice(0, -1).join(', ')} e ${names[names.length - 1]}`;
    }

    return `${names.slice(0, 3).join(', ')} e mais ${names.length - 3}`;
});

const completeHint = computed(() => completeBlockedReason.value ?? 'Declarar a correção concluída.');

/** The other half of the same problem: a disabled Guardar that explains nothing. */
const saveHint = computed(() => {
    if (hasOverMaxCell.value) {
        return 'Há notas acima da cotação máxima.';
    }

    if (dirtyCount.value === 0) {
        return 'Sem alterações por guardar.';
    }

    return dirtyCount.value === 1 ? 'Guardar 1 alteração.' : `Guardar ${dirtyCount.value} alterações.`;
});

function completeCorrection(): void {
    router.post(`/instruments/${props.instrument.ulid}/complete`, {}, {
        preserveScroll: true,
        onStart: () => (workflowBusy.value = true),
        onFinish: () => {
            workflowBusy.value = false;
            completeDialogOpen.value = false;
        },
    });
}

function reopenCorrection(): void {
    router.post(`/instruments/${props.instrument.ulid}/reopen`, {}, {
        preserveScroll: true,
        onStart: () => (workflowBusy.value = true),
        onFinish: () => {
            workflowBusy.value = false;
            reopenDialogOpen.value = false;
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
                <Link
                    v-if="cameFromAssessments"
                    :href="`/assessments/${instrument.ulid}`"
                    class="text-sm text-muted-foreground hover:underline"
                >
                    ← Voltar a Avaliações
                </Link>
                <Link v-else :href="`/classes/${instrument.class_ulid}`" class="text-sm text-muted-foreground hover:underline">
                    ← Voltar à turma
                </Link>
            </div>
            <div class="flex items-center gap-3">
                <Badge variant="secondary">{{ instrument.status_label }}</Badge>
                <template v-if="!isCancelled">
                    <!--
                      A real anchor, not an Inertia Link: this returns a file,
                      and a client-side navigation would try to render it.
                    -->
                    <a
                        v-if="instrument.status !== 'draft'"
                        :href="`/instruments/${instrument.ulid}/grelha`"
                        class="text-sm text-muted-foreground hover:underline"
                    >
                        Descarregar grelha
                    </a>
                    <Link :href="`/instruments/${instrument.ulid}/edit`" class="text-sm text-muted-foreground hover:underline">
                        Editar elemento de avaliação
                    </Link>
                    <Button v-if="!instrument.is_completed" type="button" variant="outline" size="sm" @click="openCancelDialog">
                        Anular elemento de avaliação
                    </Button>

                    <!-- A closed correction is consulted, not edited. -->
                    <template v-if="instrument.is_completed">
                        <span class="text-sm text-muted-foreground">
                            Correção concluída — em modo de consulta.
                        </span>
                        <Button type="button" variant="outline" size="sm" @click="reopenDialogOpen = true">
                            Reabrir correção
                        </Button>
                    </template>

                    <template v-else>
                        <span v-if="dirtyCount" class="text-sm text-amber-700">
                            {{ dirtyCount }} alteraç{{ dirtyCount === 1 ? 'ão' : 'ões' }} por guardar
                        </span>
                        <!-- Said out loud rather than left to a grey button. The
                             smoke read a disabled Guardar as a broken one. -->
                        <span v-else-if="!hasOverMaxCell" class="text-sm text-muted-foreground">
                            Sem alterações por guardar.
                        </span>
                        <span v-if="hasOverMaxCell" class="text-sm text-destructive">
                            Há notas acima da cotação máxima
                        </span>
                        <Button :disabled="dirtyCount === 0 || saving || hasOverMaxCell" :title="saveHint" @click="save">
                            <Save class="size-4" /> Guardar
                        </Button>
                        <!-- Deliberately separate from Guardar: saving persists
                             work, completing declares it over. -->
                        <Button
                            type="button"
                            variant="secondary"
                            :disabled="!instrument.can_complete || dirtyCount > 0"
                            :title="completeHint"
                            @click="completeDialogOpen = true"
                        >
                            <CheckCircle2 class="size-4" /> Concluir correção
                        </Button>
                    </template>
                </template>
            </div>
        </div>

        <section class="space-y-2" aria-label="Proteção das alterações locais">
            <div
                v-if="recoverableDraft"
                class="flex flex-wrap items-center justify-between gap-3 rounded-md border border-sky-300 bg-sky-50 px-4 py-3 text-sm text-sky-950"
                role="status"
            >
                <p>
                    <strong>Encontrámos alterações não guardadas desta grelha.</strong>
                    {{ recoverableDraftCount }}
                    {{ recoverableDraftCount === 1 ? 'célula' : 'células' }}, de {{ recoverableDraftDate }}.
                </p>
                <div class="flex gap-2">
                    <Button type="button" size="sm" @click="recoverDraft">Recuperar alterações</Button>
                    <Button type="button" variant="outline" size="sm" @click="discardDraft">Ignorar rascunho</Button>
                </div>
            </div>

            <div
                v-if="incompatibleDraftExists"
                class="flex flex-wrap items-center justify-between gap-3 rounded-md border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900"
                role="status"
            >
                <p>Existe um rascunho de uma versão anterior desta grelha e não pode ser aplicado automaticamente.</p>
                <Button type="button" variant="outline" size="sm" @click="discardDraft">Descartar rascunho</Button>
            </div>

            <div
                v-if="!isOnline"
                class="flex items-center gap-2 rounded-md border border-rose-300 bg-rose-50 px-4 py-2 text-sm text-rose-900"
                role="status"
            >
                <WifiOff class="size-4 shrink-0" />
                <span>
                    Sem ligação<span v-if="dirtyCount"> · {{ dirtyCount }} {{ dirtyCount === 1 ? 'alteração protegida' : 'alterações protegidas' }} neste dispositivo</span>
                </span>
            </div>

            <div
                v-if="otherEditingTabs.size > 0"
                class="rounded-md border border-violet-300 bg-violet-50 px-4 py-2 text-sm text-violet-950"
                role="status"
            >
                Esta grelha está também a ser editada noutro separador.
            </div>
        </section>

        <!--
          Why the correction cannot be closed, on the page and by name.

          A count told the teacher that one of six rows was unresolved; finding
          which one was theirs to do. Naming them turns a refusal into an
          instruction. It never says «faltou» — an absence is an event the
          teacher records, and «por avaliar» is only the decision still owed.
        -->
        <div
            v-if="completeBlockedReason"
            class="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900"
            role="status"
        >
            <p class="font-medium">{{ completeBlockedReason }}</p>
            <p v-if="pendingNamesText" class="mt-0.5">
                Por avaliar: <strong>{{ pendingNamesText }}</strong>.
            </p>
            <p v-if="pendingNamesText" class="mt-0.5 text-xs">
                Registe uma classificação ou escolha um estado no seletor da linha.
            </p>
        </div>

        <div v-if="isCancelled" class="flex items-center justify-between rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <span>Elemento de avaliação anulado — motivo: {{ instrument.cancellation_reason }}</span>
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
                            class="min-w-40 px-3 py-2 text-left font-medium"
                            title="Indicador só deste elemento de avaliação — não é a classificação oficial da turma/período, que pondera domínios e outras regras."
                        >Apreciação Qualitativa</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="(student, rowIndex) in students" :key="student.enrollment_id" class="hover:bg-muted/20">
                        <td class="sticky left-0 z-10 bg-background px-3 py-0.5 whitespace-nowrap">
                            <div class="flex items-center gap-1.5">
                                <span class="text-muted-foreground">{{ student.class_number ?? '—' }}</span>
                                <!-- Hidden on narrow viewports only here: this column is
                                     sticky and competes directly with the question columns
                                     the teacher is typing into. The name never hides. -->
                                <StudentAvatar :photo-url="student.photo_url" size="xs" class="hidden sm:inline-flex" />
                                <span class="font-medium">{{ student.name }}</span>
                                <span
                                    v-if="student.joined_after_instrument"
                                    class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] text-amber-900"
                                    title="Entrou depois desta avaliação — não é penalizado por ela."
                                >entrou depois</span>
                                <!-- Findable at a glance in a class of thirty.
                                     «Por avaliar» is a decision still owed, and
                                     is never read as an absence. -->
                                <span
                                    v-else-if="!isReadOnly && isUnresolved(student)"
                                    class="rounded border border-amber-300 px-1.5 py-0.5 text-[10px] text-amber-800"
                                    title="Ainda sem classificação nem estado — não é zero nem falta."
                                >Por avaliar</span>

                                <!--
                                  Beside the name rather than in a column of its
                                  own: the table is already very wide, and a
                                  seventeenth column of chrome would push the
                                  questions further off screen (§9).
                                -->
                                <select
                                    v-if="!isReadOnly && items.length > 1"
                                    :value="rowStateOf(student)"
                                    class="ml-1 h-6 cursor-pointer rounded border border-input bg-transparent text-[11px] text-muted-foreground"
                                    :aria-label="`Estado de ${student.name} em toda a avaliação`"
                                    title="Aplica o mesmo estado a todas as perguntas deste aluno."
                                    @change="onRowStateChange(student, ($event.target as HTMLSelectElement).value)"
                                >
                                    <!-- Shown, never chosen: the row disagrees
                                         with itself and saying otherwise would
                                         invite flattening it by accident (§6). -->
                                    <option v-if="rowStateOf(student) === MIXED" :value="MIXED" disabled>
                                        Vários
                                    </option>
                                    <option v-if="rowStateOf(student) === 'assessed'" value="assessed" disabled>
                                        Classificado
                                    </option>
                                    <option value="pending">Sem estado</option>
                                    <option
                                        v-for="state in rowStates.filter((s) => s.value !== 'pending')"
                                        :key="state.value"
                                        :value="state.value"
                                    >
                                        {{ state.label }}
                                    </option>
                                </select>
                            </div>
                        </td>

                        <td v-for="(item, columnIndex) in items" :key="item.id" class="px-1 py-0.5 text-center">
                            <div class="flex items-center justify-center gap-1">
                                <!--
                                  The score box is right-aligned, not centred.
                                  Its width is fixed and the browser's spin
                                  arrows sit pinned to its right inner edge, so
                                  centred digits slid sideways as the value's
                                  width changed ("5" vs "12.5") — which reads as
                                  the arrows jumping about. Against the right
                                  edge the last digit stays put whatever the
                                  value, so number and arrows hold a constant
                                  relationship. pr-5 keeps the digits clear of
                                  the arrows; the spinners themselves are
                                  deliberately kept, since onKeydown's arrow-key
                                  stepping makes them a real affordance rather
                                  than decoration.
                                -->
                                <input
                                    :data-cell="`${rowIndex}-${columnIndex}`"
                                    type="number"
                                    step="0.5"
                                    :min="minimumFor(item)"
                                    :max="item.points_possible"
                                    :value="cell(student.enrollment_id, item.id).state === 'assessed' ? cell(student.enrollment_id, item.id).points : ''"
                                    :disabled="isReadOnly || (cell(student.enrollment_id, item.id).state !== 'assessed' && cell(student.enrollment_id, item.id).state !== 'pending')"
                                    :class="[
                                        'h-7 w-16 rounded border bg-transparent pl-1.5 pr-5 text-right tabular-nums disabled:opacity-40',
                                        isOverMax(student, item) ? 'border-destructive text-destructive' : 'border-input',
                                    ]"
                                    :title="isOverMax(student, item) ? `Excede a cotação máxima (${item.points_possible} pts).` : undefined"
                                    @input="onPointsInput(student, item, ($event.target as HTMLInputElement).value)"
                                    @keydown="onKeydown($event, rowIndex, columnIndex)"
                                />
                                <select
                                    :value="cell(student.enrollment_id, item.id).state"
                                    :disabled="isReadOnly"
                                    :class="[
                                        'h-7 cursor-pointer rounded border bg-transparent text-xs',
                                        // Wider and dashed only while undecided: the word has to
                                        // fit, and a cell nobody has ruled on should not look settled.
                                        isPendingCell(student, item) && !isReadOnly
                                            ? 'w-24 border-dashed border-amber-400 text-amber-800'
                                            : 'w-14 border-input',
                                    ]"
                                    :aria-label="`Estado de ${student.name}${items.length > 1 ? ' em ' + item.code : ''}`"
                                    :title="states.find((s) => s.value === cell(student.enrollment_id, item.id).state)?.label"
                                    @change="onStateChange(student, item, ($event.target as HTMLSelectElement).value)"
                                >
                                    <option value="pending">{{ shortLabels.pending }}</option>
                                    <option value="assessed">✓</option>
                                    <option v-for="state in nonAssessedStates.filter((s) => s.value !== 'pending')" :key="state.value" :value="state.value">
                                        {{ shortLabels[state.value] ?? state.label }}
                                    </option>
                                </select>
                            </div>
                        </td>

                        <td class="px-3 py-0.5 text-right font-semibold tabular-nums">
                            <template v-if="totalFor(student) === null">
                                <span class="text-muted-foreground" title="Sem classificações registadas — não é zero.">—</span>
                            </template>
                            <template v-else>
                                {{ totalFor(student) }}<span v-if="instrument.total_points" class="font-normal text-muted-foreground">/{{ instrument.total_points }}</span>
                                <span v-if="percentFor(student) !== null" class="font-normal text-muted-foreground"> · {{ percentFor(student) }}%</span>
                                <span v-if="isPartial(student)" class="ml-1 text-xs font-normal text-amber-600" title="Ainda há questões por avaliar.">parcial</span>
                            </template>
                        </td>
                        <td class="px-3 py-0.5 text-left">
                            <div class="flex flex-col gap-1">
                                <div class="flex flex-wrap items-center gap-1.5 border-b border-border/40 pb-1">
                                    <span class="w-16 shrink-0 text-xs font-medium text-muted-foreground">Global</span>
                                    <template v-if="percentFor(student) !== null">
                                        <Badge v-if="qualitativeLabelFor(student)" :class="toneClassFor(percentFor(student))" class="text-[10px]">
                                            {{ qualitativeLabelFor(student) }}
                                        </Badge>
                                        <span v-if="isPartial(student)" class="text-[10px] text-amber-600">parcial</span>
                                    </template>
                                    <span v-else class="text-xs text-muted-foreground">—</span>
                                </div>
                                <div v-for="domain in domainSummariesFor(student)" :key="domain.domain_id" class="flex flex-wrap items-center gap-1.5">
                                    <span class="w-16 shrink-0 truncate text-xs text-muted-foreground" :title="domain.name">{{ domain.name }}</span>
                                    <template v-if="domain.percent !== null">
                                        <span class="text-xs tabular-nums text-muted-foreground">{{ domain.earned }}/{{ domain.possible }} · {{ domain.percent }}%</span>
                                        <Badge v-if="domain.label" :class="domain.toneClass" class="text-[10px]">{{ domain.label }}</Badge>
                                        <span v-if="domain.isPartial" class="text-[10px] text-amber-600">parcial</span>
                                    </template>
                                    <span v-else class="text-xs text-muted-foreground">—</span>
                                </div>
                            </div>
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

        <Dialog v-model:open="completeDialogOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Concluir a correção deste elemento de avaliação?</DialogTitle>
                    <DialogDescription>
                        Depois de concluída, a correção fica em modo de consulta. Para
                        voltar a alterá-la, será necessário reabrir a correção.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button type="button" variant="outline" @click="completeDialogOpen = false">
                        Cancelar
                    </Button>
                    <Button type="button" :disabled="workflowBusy" @click="completeCorrection">
                        Concluir correção
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!--
          Asked only when there is something to lose. Applying a state to a
          question that holds a mark removes the mark — that is the model's rule
          and not this screen's — so doing it to eight questions at once without
          saying so would be a loss dressed as a shortcut (§8).
        -->
        <Dialog :open="pendingBulk !== null" @update:open="(open: boolean) => { if (!open) pendingBulk = null }">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Aplicar a toda a avaliação?</DialogTitle>
                    <DialogDescription v-if="pendingBulk">
                        {{ pendingBulk.student.name }} já tem
                        {{ pendingBulk.marked }}
                        {{ pendingBulk.marked === 1 ? 'classificação registada' : 'classificações registadas' }}.
                        Aplicar «{{ states.find((s) => s.value === pendingBulk!.state)?.label }}»
                        a todas as perguntas substitui
                        {{ pendingBulk.marked === 1 ? 'essa classificação' : 'essas classificações' }}.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button type="button" variant="outline" @click="pendingBulk = null">
                        Cancelar
                    </Button>
                    <Button type="button" @click="confirmBulk">
                        Aplicar a todas
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="reopenDialogOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Reabrir a correção deste elemento de avaliação?</DialogTitle>
                    <DialogDescription>
                        Voltará a ser possível alterar as classificações.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button type="button" variant="outline" @click="reopenDialogOpen = false">
                        Cancelar
                    </Button>
                    <Button type="button" :disabled="workflowBusy" @click="reopenCorrection">
                        Reabrir correção
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="cancelDialogOpen">
            <DialogContent>
                <form @submit.prevent="submitCancel">
                    <DialogHeader>
                        <DialogTitle>Anular elemento de avaliação</DialogTitle>
                        <DialogDescription>
                            O elemento de avaliação deixa de contar para o cálculo e fica só-leitura até reverteres a anulação.
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
