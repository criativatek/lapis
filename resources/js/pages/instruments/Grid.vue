<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { CheckCircle2, ChevronDown, CircleAlert, Save, WifiOff } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import DomainAssignmentCallout from '@/components/instruments/DomainAssignmentCallout.vue';
import type { DomainAssignment } from '@/components/instruments/DomainAssignmentCallout.vue';
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
import { scaleBandFor } from '@/lib/instrumentQualitativeRating';
import { qualitativeToneClasses, qualitativeToneFor } from '@/lib/qualitativeTone';
import { meanOf, resultTone, resultToneClasses, resultToneTitle } from '@/lib/resultTone';
import type { ResultTone } from '@/lib/resultTone';
import { formatScoreInput, parseScore } from '@/lib/scoreInput';
import { statusToneClasses } from '@/lib/statusTone';
import type { Cell as OfficialCell } from '@/types/resultsAnalysis';

// Preserves where the teacher came from without a general-purpose breadcrumb:
// arriving from assessments/Show.vue (via ?from=assessments) returns there;
// every other entry point (Elementos de Avaliação, the class page) keeps today's
// unchanged "voltar à turma".
const cameFromAssessments = new URLSearchParams(window.location.search).get('from') === 'assessments';

// O mesmo mecanismo, para o Calendário: quem chegou aqui de uma célula do mês
// volta ao MÊS de onde partiu, e não ao mês de hoje. O «month» vem no endereço
// e vai só para /calendar — nunca para um destino arbitrário — e a rota volta a
// validá-lo no servidor (CalendarMonthRequest), pelo que este teste de formato é
// apenas uma sanidade: evita construir um link visivelmente inválido.
const calendarParams = new URLSearchParams(window.location.search);
const calendarMonth = calendarParams.get('month');
const cameFromCalendar =
    calendarParams.get('from') === 'calendar' && calendarMonth !== null && /^\d{4}-\d{2}$/.test(calendarMonth);

type Item = {
    id: number;
    /** Posição do grupo (secção do teste) em `groups`. */
    group_index?: number;
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
    /** Perguntas ainda sem domínio e o passo que o resolve (ver DomainAssignmentCallout). */
    domainAssignment?: DomainAssignment;
    /** As secções do teste; só se desenham quando há mais do que uma (um teste simples não mostra estrutura). */
    groups?: { ulid: string; label: string | null }[];
    items: Item[];
    students: Student[];
    scores: Score[];
    states: StateOption[];
    scaleBands: { label: string; band_min: string; band_max: string; sequence: number; is_negative: boolean }[];
    official: {
        status: 'official' | 'provisional';
        label: string;
        threshold: string | null;
        domains: Array<{ key: string; id: number; name: string }>;
        students: Record<string, { status: string; status_label: string; global: OfficialCell; domains: Record<string, OfficialCell | null> }>;
    };
}>();

/**
 * A data da avaliação como se escreve em Portugal — «13/09/2026», e não o
 * «2026-09-13» com que o servidor a transporta.
 *
 * O par `${data}T00:00:00Z` + `timeZone: 'UTC'` é o mesmo idioma que o
 * Calendário já usa para ler uma data «Y-m-d» sem a deixar escorregar um dia
 * para trás no fuso.
 */
const appliedOnDateFormatter = new Intl.DateTimeFormat('pt-PT', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    timeZone: 'UTC',
});

const appliedOnLabel = computed(() =>
    appliedOnDateFormatter.format(new Date(`${props.instrument.applied_on}T00:00:00Z`)),
);

type Cell = { state: string; points: number | null; reason: string | null; lock_version: number };

const cellKey = (enrollmentId: number, itemId: number) => `${enrollmentId}:${itemId}`;

// Cells the teacher has touched, keyed so only these get sent on save. Untouched
// cells are never transmitted, so two teachers marking different columns of the
// same class do not overwrite one another.
const dirty = reactive(new Set<string>());

const cells = reactive<Record<string, Cell>>({});

// O texto da célula que está a ser escrita. Fica fora de `cells` para não se perderem dígitos
// («3,» ainda não é um número) nem se trocar o separador a meio da escrita.
const editing = ref<{ key: string; raw: string } | null>(null);

// Células cujo texto não é um número: nunca entram em `cells`, nem contam como alteração.
const invalidRaw = reactive<Record<string, string>>({});

// O que a célula tinha quando ganhou o foco: é para AÍ que o Esc volta — cancelar uma edição é
// desfazer tudo o que se escreveu desde que se entrou na célula, não só o último carácter
// inválido (escrever «3,5» e depois «,1» e carregar Esc não pode deixar 3,5).
const editingOrigin = ref<{ key: string; state: string; points: number | null } | null>(null);

// As classificações tal como vieram do servidor, para uma célula reposta ao que estava deixar de
// contar como alteração por guardar.
const savedByKey = new Map(props.scores.map((score) => [cellKey(score.enrollment_id, score.instrument_item_id), score]));

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

// Barra de deslocação horizontal colada ao fundo da janela. A tabela é larga e o fim do contentor pode
// estar muito abaixo do ecrã; a barra nativa do contentor só se vê aí. Quando há overflow, a barra
// nativa é escondida (só a colada existe, sem duplicar) e as duas são mantidas em sincronia. O
// toque continua a deslocar o contentor de forma nativa.
const scrollContainer = ref<HTMLElement | null>(null);
const scrollTable = ref<HTMLElement | null>(null);
const scrollBar = ref<HTMLElement | null>(null);
const scrollBarWidth = ref(0);
const hasHorizontalOverflow = ref(false);
let layoutObserver: ResizeObserver | null = null;

// A barra é `position: fixed`, não `sticky`: o <main> da aplicação tem `overflow-y: auto`, e um
// antepassado com overflow passa a ser a referência do sticky — a barra ficava presa ao fundo do
// <main> (lá em baixo, fora do ecrã) em vez do fundo da janela. Fixa ao fundo da janela, alinhada
// com a grelha (left/width medidos) e só visível enquanto a tabela estiver no ecrã.
const barStyle = ref<Record<string, string>>({});
const barInView = ref(true);

function measureHorizontalOverflow(): void {
    const container = scrollContainer.value;
    const table = scrollTable.value;

    if (container === null || table === null) {
        hasHorizontalOverflow.value = false;

        return;
    }

    scrollBarWidth.value = table.scrollWidth;
    hasHorizontalOverflow.value = table.scrollWidth > container.clientWidth;

    const rect = container.getBoundingClientRect();
    barStyle.value = { left: `${rect.left}px`, width: `${rect.width}px` };
    // Sem layout (jsdom) o retângulo é todo zeros: considera-se à vista.
    barInView.value = rect.height === 0 || (rect.top < window.innerHeight && rect.bottom > 0);
}

const barVisible = computed(() => hasHorizontalOverflow.value && barInView.value);

// Sem flag: atribuir um scrollLeft igual ao atual não dispara `scroll`, por isso a comparação
// corta o ciclo contêiner -> barra -> contêiner.
function syncScroll(from: 'container' | 'bar'): void {
    const container = scrollContainer.value;
    const bar = scrollBar.value;

    if (container === null || bar === null) {
        return;
    }

    if (from === 'bar' && container.scrollLeft !== bar.scrollLeft) {
        container.scrollLeft = bar.scrollLeft;
    } else if (from === 'container' && bar.scrollLeft !== container.scrollLeft) {
        bar.scrollLeft = container.scrollLeft;
    }
}

onMounted(() => {
    measureHorizontalOverflow();
    window.addEventListener('scroll', measureHorizontalOverflow, { passive: true });
    window.addEventListener('resize', measureHorizontalOverflow, { passive: true });

    if (typeof ResizeObserver !== 'undefined') {
        layoutObserver = new ResizeObserver(measureHorizontalOverflow);

        if (scrollTable.value !== null) {
            layoutObserver.observe(scrollTable.value);
        }

        if (scrollContainer.value !== null) {
            layoutObserver.observe(scrollContainer.value);
        }
    }

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
    layoutObserver?.disconnect();
    window.removeEventListener('scroll', measureHorizontalOverflow);
    window.removeEventListener('resize', measureHorizontalOverflow);
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
    const key = cellKey(student.enrollment_id, item.id);
    const current = cell(student.enrollment_id, item.id);
    const parsed = parseScore(value);

    // O que o professor escreveu fica tal e qual enquanto escreve; só depois de sair da célula
    // é que passa ao formato com vírgula.
    editing.value = { key, raw: value };

    if (parsed.kind === 'invalid') {
        // Texto que não é número («abc», «3,5,1», «3,»): não vai para `cells`, não é uma alteração
        // e bloqueia o Guardar até ser corrigido ou reposto. A célula guarda o último valor válido.
        invalidRaw[key] = value;

        return;
    }

    delete invalidRaw[key];

    if (parsed.kind === 'empty') {
        // Empty is «no data», never a zero and never an assessment.
        current.state = 'pending';
        current.points = null;
    } else {
        current.state = 'assessed';
        current.points = parsed.value;
    }

    markDirty(student.enrollment_id, item.id);
}

/** O texto de uma célula quando não está a ser editada: o valor confirmado, com vírgula decimal. */
function confirmedText(student: Student, item: Item): string {
    const current = cell(student.enrollment_id, item.id);

    return current.state === 'assessed' ? formatScoreInput(current.points) : '';
}

/** O que a caixa mostra: o texto em edição, o texto inválido que ficou por corrigir, ou o valor confirmado. */
function displayedText(student: Student, item: Item): string {
    const key = cellKey(student.enrollment_id, item.id);

    if (editing.value?.key === key) {
        return editing.value.raw;
    }

    return invalidRaw[key] ?? confirmedText(student, item);
}

function isInvalidCell(student: Student, item: Item): boolean {
    return cellKey(student.enrollment_id, item.id) in invalidRaw;
}

const hasInvalidCell = computed(() => Object.keys(invalidRaw).length > 0);

function onPointsFocus(student: Student, item: Item): void {
    const current = cell(student.enrollment_id, item.id);

    editingOrigin.value = { key: cellKey(student.enrollment_id, item.id), state: current.state, points: current.points };
}

/** Esc: a célula volta ao que tinha quando ganhou o foco; se isso for o que está guardado, deixa de ser uma alteração. */
function restoreEditingOrigin(student: Student, item: Item): void {
    const key = cellKey(student.enrollment_id, item.id);
    const origin = editingOrigin.value?.key === key ? editingOrigin.value : null;

    delete invalidRaw[key];

    if (origin !== null) {
        const current = cell(student.enrollment_id, item.id);
        current.state = origin.state as typeof current.state;
        current.points = origin.points;

        const saved = savedByKey.get(key);
        const sameAsSaved = saved === undefined
            ? current.state === 'pending' && current.points === null
            : saved.result_state === current.state && (saved.points_earned ?? null) === current.points;

        if (sameAsSaved) {
            dirty.delete(key);
        } else {
            dirty.add(key);
        }

        writeCurrentDraft();
    }

    editing.value = { key, raw: confirmedText(student, item) };
}

function onPointsBlur(student: Student, item: Item): void {
    if (editing.value?.key === cellKey(student.enrollment_id, item.id)) {
        // Texto válido: passa a mostrar-se confirmado (com vírgula). Texto inválido continua
        // visível através de `invalidRaw`.
        editing.value = null;
    }
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

/**
 * Navegação por teclado, estilo folha de cálculo.
 *
 * - Enter e ↓/↑ andam na mesma pergunta, para o aluno seguinte/anterior.
 * - → e ← andam na mesma linha, para a pergunta seguinte/anterior (as colunas de subtotal e total
 *   não têm célula e saltam-se). Para não lutar com a edição do texto, só mudam de célula quando o
 *   cursor já está no início (←) ou no fim (→) do texto, ou o campo está vazio; no resto do tempo
 *   movem o cursor, como nativo. Com o texto todo selecionado (o que acontece ao entrar na célula)
 *   as duas setas mudam de célula.
 * - Esc repõe o texto para o último valor confirmado e mantém o foco.
 * - Tab e Shift+Tab ficam com o navegador.
 * - Texto inválido prende o foco: nem as setas nem o Enter saem da célula até ser corrigido ou reposto.
 * - Nas extremidades nada acontece (não há salto para outra linha ou coluna).
 */
function onKeydown(event: KeyboardEvent, rowIndex: number, columnIndex: number, student: Student, item: Item): void {
    const input = event.target as HTMLInputElement;
    const key = cellKey(student.enrollment_id, item.id);

    if (event.key === 'Escape') {
        if (isReadOnly.value) {
            return;
        }

        event.preventDefault();
        restoreEditingOrigin(student, item);

        return;
    }

    if (event.shiftKey || event.ctrlKey || event.altKey || event.metaKey) {
        return;
    }

    let rowStep = 0;
    let columnStep = 0;

    switch (event.key) {
        case 'Enter':
        case 'ArrowDown':
            rowStep = 1;
            break;
        case 'ArrowUp':
            rowStep = -1;
            break;
        case 'ArrowRight':
            columnStep = 1;
            break;
        case 'ArrowLeft':
            columnStep = -1;
            break;
        default:
            return;
    }

    if (columnStep !== 0) {
        const length = input.value.length;
        const atEdge = length === 0 || (columnStep < 0 ? input.selectionStart === 0 : input.selectionEnd === length);

        if (! atEdge) {
            return;
        }
    }

    if (key in invalidRaw) {
        // Fica onde está, com o estado inválido à vista.
        event.preventDefault();

        return;
    }

    const next = rowStep !== 0
        ? findEnabledCell(rowIndex + rowStep, columnIndex, 0)
        : findEnabledCell(rowIndex, columnIndex, columnStep);

    if (event.key === 'Enter') {
        // Confirmar é sair do modo de escrita, haja ou não célula abaixo.
        event.preventDefault();
        editing.value = null;
    }

    if (next === null) {
        return;
    }

    event.preventDefault();
    editing.value = null;
    next.focus();
    next.select();
    next.scrollIntoView?.({ block: 'nearest', inline: 'nearest' });
}

/**
 * A célula editável vizinha. `columnStep` 0 é movimento vertical: a mesma pergunta, outra linha.
 * Horizontal: a pergunta seguinte/anterior pela ordem em que as colunas se desenham (não a de `items`),
 * a saltar as desativadas (estado «ausente», etc.).
 */
function findEnabledCell(row: number, column: number, columnStep: number): HTMLInputElement | null {
    const query = (r: number, c: number) => document.querySelector<HTMLInputElement>(`[data-cell="${r}-${c}"]`);

    if (columnStep === 0) {
        const found = query(row, column);

        return found && ! found.disabled ? found : null;
    }

    const order = columns.value.filter((entry) => entry.item !== null).map((entry) => entry.columnIndex);

    for (let position = order.indexOf(column) + columnStep; position >= 0 && position < order.length; position += columnStep) {
        const found = query(row, order[position]);

        if (found && ! found.disabled) {
            return found;
        }
    }

    return null;
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
 * Os pontos como se mostram: sem ruído de vírgula flutuante (11.329999999 → 11,33) e sem zeros à direita.
 * As classificações têm até 4 casas na base; a soma nunca mostra mais do que isso.
 */
function formatPoints(value: number): string {
    return String(Number(value.toFixed(4))).replace('.', ',');
}

/**
 * A cotação máxima global: a declarada no teste ou, quando não há (uma ficha importada não a traz), a soma das
 * cotações das perguntas sem as de bónus — a mesma conta do máximo de cada grupo.
 */
const totalMax = computed<number | null>(() => {
    if (props.instrument.total_points) {
        return props.instrument.total_points;
    }

    const sum = props.items.filter((item) => !item.is_bonus).reduce((total, item) => total + (item.points_possible ?? 0), 0);

    return sum > 0 ? sum : null;
});

type Section = { index: number; label: string; items: Item[]; max: number };

/**
 * As secções do teste, na ordem do teste, cada uma com a sua cotação máxima (a soma das cotações das suas
 * perguntas, sem as de bónus — o mesmo critério do denominador da percentagem). Só existem quando o teste
 * tem mais do que um grupo: um teste simples continua a ser uma só fila de perguntas.
 */
const sections = computed<Section[]>(() => {
    const groups = props.groups ?? [];

    if (groups.length < 2) {
        return [];
    }

    return groups
        .map((group, index) => {
            const own = props.items.filter((item) => (item.group_index ?? 0) === index);

            return {
                index,
                label: group.label?.trim() || `Grupo ${index + 1}`,
                items: own,
                max: own.filter((item) => !item.is_bonus).reduce((sum, item) => sum + (item.points_possible ?? 0), 0),
            };
        })
        .filter((section) => section.items.length > 0);
});

type Column = { key: string; item: Item | null; columnIndex: number; section: Section | null };

/**
 * As colunas pela ordem em que se desenham: cada pergunta e, no fim das de cada grupo, o subtotal desse
 * grupo. `columnIndex` é a posição da pergunta em `items` (a navegação por teclado depende dela, não da coluna).
 */
const columns = computed<Column[]>(() => {
    if (sections.value.length === 0) {
        return props.items.map((item, columnIndex) => ({ key: `item-${item.id}`, item, columnIndex, section: null }));
    }

    const result: Column[] = [];

    for (const section of sections.value) {
        for (const item of section.items) {
            result.push({ key: `item-${item.id}`, item, columnIndex: props.items.indexOf(item), section: null });
        }

        result.push({ key: `subtotal-${section.index}`, item: null, columnIndex: -1, section });
    }

    return result;
});

/** O subtotal de um grupo: a soma das classificações já registadas nas suas perguntas; null se nenhuma. */
function subtotalFor(student: Student, section: Section): number | null {
    let sum = 0;
    let assessed = 0;

    for (const item of section.items) {
        const current = cells[cellKey(student.enrollment_id, item.id)];

        if (current?.state === 'assessed' && current.points !== null) {
            sum += current.points;
            assessed += 1;
        }
    }

    return assessed === 0 ? null : sum;
}

// ------------------------------------------------- menções indicativas e cores

const MENTION_TITLE =
    'Menção indicativa pela escala do perfil, calculada sobre os pontos desta secção; a classificação oficial está na coluna da direita.';

/** «75,0 %» — 1 casa, vírgula decimal. Só para mostrar: as decisões usam sempre o valor exato. */
function formatPercent(value: number): string {
    return `${value.toFixed(1).replace('.', ',')} %`;
}

/** A menção da escala do perfil para uma percentagem exata; null sem bandas ou sem banda correspondente. */
function mentionFor(percentExact: number | null): string | null {
    return scaleBandFor(percentExact, props.scaleBands)?.label ?? null;
}

/** Percentagem exata do aluno numa secção: subtotal sobre a cotação da secção, sem arredondar. */
function subtotalPercentExact(student: Student, section: Section): number | null {
    const subtotal = subtotalFor(student, section);

    return subtotal === null || section.max <= 0 ? null : (subtotal / section.max) * 100;
}

/**
 * A percentagem do total sobre as perguntas já classificadas (vazio não é zero; bónus soma ao numerador mas
 * não ao denominador) — a mesma regra de percentFor() em instrumentQualitativeRating.ts, mas sem arredondar,
 * porque é com o valor exato que se decide a menção e a cor. null quando nada está classificado.
 */
function totalPercentExact(student: Student): number | null {
    let earned = 0;
    let possible = 0;
    let assessed = 0;

    for (const item of props.items) {
        const current = cells[cellKey(student.enrollment_id, item.id)];

        if (current?.state === 'assessed' && current.points !== null) {
            earned += current.points;

            if (!item.is_bonus) {
                possible += item.points_possible ?? 0;
            }

            assessed += 1;
        }
    }

    return assessed === 0 || possible === 0 ? null : (earned / possible) * 100;
}

const subtotalMeans = computed(
    () =>
        new Map<number, number | null>(
            sections.value.map((section) => [
                section.index,
                meanOf(props.students.map((student) => subtotalPercentExact(student, section))),
            ]),
        ),
);

const totalMean = computed(() => meanOf(props.students.map((student) => totalPercentExact(student))));

/** Média exata da turma (só alunos com valor oficial) por «global» e por cada domínio. */
const officialMeans = computed(() => {
    const exactOf = (student: Student, key: string): number | null => {
        const official = officialFor(student);
        const cell = key === 'global' ? official?.global : official?.domains[key];

        return cell?.exact != null ? Number(cell.exact) : null;
    };
    const means: Record<string, number | null> = {};

    for (const key of ['global', ...props.official.domains.map((domain) => domain.key)]) {
        means[key] = meanOf(props.students.map((student) => exactOf(student, key)));
    }

    return means;
});

function subtotalTone(student: Student, section: Section): ResultTone | null {
    return resultTone(subtotalPercentExact(student, section), subtotalMeans.value.get(section.index) ?? null);
}

function totalTone(student: Student): ResultTone | null {
    return resultTone(totalPercentExact(student), totalMean.value);
}

function officialTone(cell: OfficialCell | null | undefined, key: string): ResultTone | null {
    return resultTone(cell?.exact != null ? Number(cell.exact) : null, officialMeans.value[key] ?? null);
}

function toneClass(tone: ResultTone | null): string {
    return tone === null ? '' : resultToneClasses[tone];
}

// ------------------------------------------------- classificação oficial

/** O resultado oficial (ou provisório) deste aluno, tal como o motor o calculou — nunca recalculado no cliente. */
function officialFor(student: Student) {
    return props.official.students[String(student.enrollment_id)] ?? null;
}

function officialCellDisplay(cell: OfficialCell): string {
    const raw = cell.value_precise ?? cell.value;

    if (raw === null) {
        return '—';
    }

    const decimals = cell.value_precise !== null ? 2 : 1;

    return `${Number(raw).toFixed(decimals).replace('.', ',')} %`;
}

/** «18/25» — os pontos do domínio tal como o motor os somou; null quando o servidor não os enviou. */
function officialPointsDisplay(cell: OfficialCell): string | null {
    if (cell.points_earned == null || cell.points_possible == null) {
        return null;
    }

    const format = (value: string) => String(Number(value)).replace('.', ',');

    return `${format(cell.points_earned)}/${format(cell.points_possible)}`;
}

function officialToneClass(cell: OfficialCell): string {
    if (cell.band === null) {
        return qualitativeToneClasses.neutral;
    }

    return qualitativeToneClasses[qualitativeToneFor(cell.band, props.scaleBands)];
}

/**
 * O valor oficial só reflete o que está guardado: uma célula alterada mas
 * ainda não guardada (dirty) não mudou o cálculo do servidor, por isso o
 * aluno precisa de saber que aquele número ainda não é o de agora.
 */
function studentIsDirty(student: Student): boolean {
    return props.items.some((item) => dirty.has(cellKey(student.enrollment_id, item.id)));
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
        // A MESMA data, escrita da MESMA maneira que no cabeçalho: a mesma
        // página não pode escrever a mesma data de duas formas diferentes.
        return `Esta avaliação está datada de ${appliedOnLabel.value} e nenhum aluno da turma estava inscrito nessa data, por isso não se aplica a ninguém. Corrija a data da avaliação em «Editar elemento de avaliação».`;
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
    if (hasInvalidCell.value) {
        return 'Há classificações inválidas.';
    }

    if (hasOverMaxCell.value) {
        return 'Há notas acima da cotação máxima.';
    }

    if (dirtyCount.value === 0) {
        return 'Sem alterações por guardar.';
    }

    return dirtyCount.value === 1 ? 'Guardar 1 alteração.' : `Guardar ${dirtyCount.value} alterações.`;
});

/**
 * O gesto «arrumar» (DESIGN.md §Movimento): fechar a correção não dispara
 * um toast — escreve-se, em serifa itálica, «Arrumado — guardado às H:MM»
 * ao lado do estado, a assentar com o easing único. É a página a ficar
 * visívelmente mais leve, não uma notificação por cima dela.
 */
const justCompleted = ref(false);

const completedAtLabel = computed(() => {
    if (props.instrument.completed_at === null) {
        return null;
    }

    return new Intl.DateTimeFormat('pt-PT', {
        hour: 'numeric',
        minute: '2-digit',
        timeZone: 'Europe/Lisbon',
    }).format(new Date(props.instrument.completed_at));
});

function completeCorrection(): void {
    router.post(`/instruments/${props.instrument.ulid}/complete`, {}, {
        preserveScroll: true,
        onStart: () => (workflowBusy.value = true),
        onSuccess: () => (justCompleted.value = true),
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
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0 space-y-1">
                <div class="flex flex-wrap items-start gap-3">
                    <Heading :title="instrument.title" :description="`${instrument.class_label} · ${instrument.period} · ${appliedOnLabel}`" />
                    <Badge class="mt-1" variant="secondary" :class="statusToneClasses(instrument.status)">{{ instrument.status_label }}</Badge>
                </div>
                <!-- A closed correction is consulted, not edited. -->
                <p v-if="instrument.is_completed && !isCancelled" class="text-sm text-muted-foreground">
                    Correção concluída — em modo de consulta.
                </p>
                <p
                    v-if="justCompleted && completedAtLabel"
                    class="ink-settle font-serif text-sm italic text-muted-foreground"
                >Arrumado — guardado às {{ completedAtLabel }}.</p>
                <Link
                    v-if="cameFromAssessments"
                    :href="`/assessments/${instrument.ulid}`"
                    class="text-sm text-muted-foreground hover:underline"
                >
                    ← Voltar a Grelhas de correção
                </Link>
                <Link
                    v-else-if="cameFromCalendar"
                    :href="`/calendar?month=${calendarMonth}`"
                    class="text-sm text-muted-foreground hover:underline"
                >
                    ← Voltar ao Calendário
                </Link>
                <Link v-else :href="`/classes/${instrument.class_ulid}`" class="text-sm text-muted-foreground hover:underline">
                    ← Voltar à turma
                </Link>
                <nav class="flex gap-1 border-b border-border" aria-label="Secções do elemento de avaliação">
                    <span class="border-b-2 border-primary px-3 py-2 text-sm font-medium text-foreground" aria-current="page">
                        Grelha de correção
                    </span>
                    <Link
                        :href="`/instruments/${instrument.ulid}/resultados`"
                        class="border-b-2 border-transparent px-3 py-2 text-sm text-muted-foreground hover:text-foreground"
                    >
                        Resultados
                    </Link>
                    <!-- The grid as a document to print: not the spreadsheet below
                         («Descarregar grelha»), which is the one filled in offline.
                         An annulled instrument has no grid to print. -->
                    <Link
                        v-if="!isCancelled"
                        :href="`/instruments/${instrument.ulid}/impressao`"
                        class="ml-auto px-3 py-2 text-sm text-muted-foreground hover:text-foreground"
                    >
                        Imprimir / PDF
                    </Link>
                </nav>
            </div>
            <div v-if="!isCancelled" class="flex flex-wrap items-center justify-end gap-3">
                <div class="flex flex-wrap items-center justify-end gap-3">
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
                    <!-- A folha com as classificações atribuídas e os totais por
                         domínio — não a grelha em branco acima, que se preenche offline. -->
                    <a
                        v-if="instrument.status !== 'draft'"
                        :href="`/instruments/${instrument.ulid}/grelha/resultados`"
                        class="text-sm text-muted-foreground hover:underline"
                        data-testid="download-results"
                    >
                        Exportar resultados (Excel)
                    </a>
                    <Link :href="`/instruments/${instrument.ulid}/edit`" class="text-sm text-muted-foreground hover:underline">
                        Editar elemento de avaliação
                    </Link>
                </div>
                <div class="flex flex-wrap items-center justify-end gap-3 border-l border-border pl-3">
                    <Button v-if="!instrument.is_completed" type="button" variant="outline" size="sm" @click="openCancelDialog">
                        Anular elemento de avaliação
                    </Button>

                    <template v-if="instrument.is_completed">
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
                        <span v-else-if="!hasOverMaxCell && !hasInvalidCell" class="text-sm text-muted-foreground">
                            Sem alterações por guardar.
                        </span>
                        <span v-if="hasOverMaxCell" class="text-sm text-destructive">
                            Há notas acima da cotação máxima
                        </span>
                        <span v-if="hasInvalidCell" class="text-sm text-destructive">
                            Há classificações inválidas
                        </span>
                        <Button :disabled="dirtyCount === 0 || saving || hasOverMaxCell || hasInvalidCell" :title="saveHint" @click="save">
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
                </div>
            </div>
        </div>

        <section class="space-y-2" aria-label="Proteção das alterações locais">
            <div
                v-if="recoverableDraft && !isReadOnly"
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

        <DomainAssignmentCallout
            v-if="domainAssignment"
            :post-url="`/instruments/${instrument.ulid}/domains`"
            :assignment="domainAssignment"
            :can-edit="!isCancelled"
        />

        <EmptyState v-if="students.length === 0" title="Esta turma ainda não tem alunos inscritos." />

        <div
            v-else
            ref="scrollContainer"
            :class="[
                'overflow-x-auto rounded-lg border border-border',
                hasHorizontalOverflow && '[scrollbar-width:none] [&::-webkit-scrollbar]:hidden',
            ]"
            data-testid="grid-scroll"
            @scroll="syncScroll('container')"
        >
            <table ref="scrollTable" class="w-full border-collapse text-sm">
                <thead class="bg-muted/50">
                    <!-- Com grupos, uma fila a nomeá-los (com a cotação de cada um) por cima das perguntas. -->
                    <tr v-if="sections.length > 0" data-testid="group-header-row">
                        <th rowspan="2" class="sticky left-0 z-10 bg-muted/50 px-3 py-2 text-left font-medium">Aluno</th>
                        <th
                            v-for="section in sections"
                            :key="section.index"
                            :colspan="section.items.length + 1"
                            class="border-l border-border px-2 py-1.5 text-center font-medium"
                            data-testid="group-header"
                        >
                            <div>{{ section.label }}</div>
                            <div class="text-xs font-normal text-muted-foreground">{{ formatPoints(section.max) }} pts</div>
                        </th>
                        <th rowspan="2" class="border-l border-border px-3 py-2 text-right font-medium">
                            <div>Pontuação bruta</div>
                            <div class="text-[10px] font-normal text-muted-foreground">pontos obtidos / cotação</div>
                        </th>
                        <th rowspan="2" class="min-w-40 px-3 py-2 text-left font-medium">
                            <div class="flex items-center gap-1.5">
                                <span>{{ official.label }}</span>
                                <span
                                    v-if="official.status === 'provisional'"
                                    class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-normal text-amber-900"
                                >provisória</span>
                            </div>
                        </th>
                    </tr>
                    <tr>
                        <th v-if="sections.length === 0" class="sticky left-0 z-10 bg-muted/50 px-3 py-2 text-left font-medium">Aluno</th>
                        <template v-for="column in columns" :key="column.key">
                            <th v-if="column.item" class="min-w-24 px-2 py-2 text-center font-medium">
                                <div>{{ column.item.code }}</div>
                                <div class="text-xs font-normal text-muted-foreground">
                                    {{ column.item.points_possible }} pts<span v-if="column.item.is_bonus"> · bónus</span>
                                </div>
                                <div v-if="column.item.domains.length" class="text-[10px] font-normal text-muted-foreground">
                                    {{ column.item.domains.map((d) => `${d.name} ${d.percent}%`).join(' · ') }}
                                </div>
                            </th>
                            <th v-else-if="column.section" class="min-w-24 bg-muted/70 px-2 py-2 text-center font-medium" data-testid="subtotal-header">
                                <div>Subtotal</div>
                                <div class="text-xs font-normal text-muted-foreground">{{ formatPoints(column.section.max) }} pts</div>
                            </th>
                        </template>
                        <template v-if="sections.length === 0">
                        <th class="px-3 py-2 text-right font-medium">
                            <div>Pontuação bruta</div>
                            <div class="text-[10px] font-normal text-muted-foreground">pontos obtidos / cotação</div>
                        </th>
                        <th class="min-w-40 px-3 py-2 text-left font-medium">
                            <div class="flex items-center gap-1.5">
                                <span>{{ official.label }}</span>
                                <span
                                    v-if="official.status === 'provisional'"
                                    class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-normal text-amber-900"
                                >provisória</span>
                            </div>
                        </th>
                        </template>
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
                                <StudentAvatar
                                    :photo-url="student.photo_url"
                                    :student-name="student.name"
                                    zoomable
                                    size="xs"
                                    class="hidden sm:inline-flex"
                                />
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

                        <template v-for="{ key, item, columnIndex, section } in columns" :key="key">
                        <td v-if="section" class="bg-muted/30 px-2 py-0.5 text-right font-semibold tabular-nums" data-testid="subtotal-cell">
                            <template v-if="subtotalFor(student, section) === null">
                                <span class="text-muted-foreground" title="Sem classificações neste grupo — não é zero.">—</span>
                            </template>
                            <template v-else>
                                <span class="whitespace-nowrap">
                                    {{ formatPoints(subtotalFor(student, section)!) }}<span class="font-normal text-muted-foreground">/{{ formatPoints(section.max) }}</span>
                                    <span
                                        v-if="subtotalPercentExact(student, section) !== null"
                                        class="text-xs font-normal"
                                        :class="toneClass(subtotalTone(student, section))"
                                        :title="MENTION_TITLE"
                                        data-testid="subtotal-mention"
                                    > · {{ formatPercent(subtotalPercentExact(student, section)!) }}<template v-if="mentionFor(subtotalPercentExact(student, section))"> · {{ mentionFor(subtotalPercentExact(student, section)) }}</template></span>
                                    <span v-if="subtotalTone(student, section)" class="sr-only">{{ resultToneTitle[subtotalTone(student, section)!] }}</span>
                                </span>
                            </template>
                        </td>
                        <td v-else-if="item" class="px-1 py-0.5 text-center">
                            <div class="flex items-center justify-center gap-1">
                                <!--
                                  A caixa é de texto (`inputmode="decimal"`), não `type="number"`: o
                                  navegador recusa a vírgula num campo numérico e não expõe a posição
                                  do cursor, de que as setas ← → dependem. O valor segue alinhado à
                                  direita e com largura fixa (w-20: «10,25» cabe sem ficar cortado),
                                  para o último dígito não se deslocar consoante o valor.
                                -->
                                <input
                                    :data-cell="`${rowIndex}-${columnIndex}`"
                                    type="text"
                                    inputmode="decimal"
                                    autocomplete="off"
                                    :data-min="minimumFor(item)"
                                    :data-max="item.points_possible"
                                    :aria-invalid="isInvalidCell(student, item) ? 'true' : undefined"
                                    :value="displayedText(student, item)"
                                    :readonly="isReadOnly"
                                    :disabled="!isReadOnly && cell(student.enrollment_id, item.id).state !== 'assessed' && cell(student.enrollment_id, item.id).state !== 'pending'"
                                    :class="[
                                        'h-7 w-20 rounded border bg-transparent px-1.5 text-right font-normal tabular-nums focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                        isReadOnly ? 'cursor-default border-transparent opacity-100' : isInvalidCell(student, item) ? 'border-destructive' : 'border-input disabled:opacity-40',
                                    ]"
                                    :aria-label="`Nota de ${student.name}${items.length > 1 ? ' em ' + item.code : ''}`"
                                    :title="isInvalidCell(student, item) ? 'Escreva um número, com vírgula ou ponto decimal.' : isOverMax(student, item) ? `Excede a cotação máxima (${item.points_possible} pts).` : undefined"
                                    @input="onPointsInput(student, item, ($event.target as HTMLInputElement).value)"
                                    @keydown="onKeydown($event, rowIndex, columnIndex, student, item)"
                                    @focus="onPointsFocus(student, item)"
                                    @blur="onPointsBlur(student, item)"
                                />
                                <!-- Sinal sempre visível, não só cor/hover: a regra da
                                     casa não deixa o vermelho tocar numa nota (por
                                     isso saiu do input), mas isto é um erro de
                                     sistema — validação, não juízo sobre o valor —
                                     e o `title` sozinho só aparecia ao pairar o rato. -->
                                <span
                                    v-if="isOverMax(student, item)"
                                    class="inline-flex items-center gap-0.5 text-xs font-medium text-destructive"
                                    :title="`Excede a cotação máxima (${item.points_possible} pts).`"
                                >
                                    <CircleAlert class="size-3.5" aria-hidden="true" />
                                    excede {{ item.points_possible }} pts
                                </span>
                                <div
                                    :class="[
                                        'relative inline-flex h-7 items-center rounded-md border bg-transparent text-xs focus-within:ring-2 focus-within:ring-ring focus-within:ring-offset-2',
                                        // Wider and dashed only while undecided: the word has to
                                        // fit, and a cell nobody has ruled on should not look settled.
                                        isPendingCell(student, item) && !isReadOnly
                                            ? 'w-24 border-dashed border-amber-400 text-amber-800'
                                            : 'w-14 border-input text-muted-foreground',
                                        isReadOnly ? 'cursor-default border-transparent' : 'cursor-pointer',
                                    ]"
                                >
                                    <span class="pointer-events-none flex-1 truncate pl-2 pr-5" aria-hidden="true">
                                        {{ shortLabels[cell(student.enrollment_id, item.id).state] ?? states.find((state) => state.value === cell(student.enrollment_id, item.id).state)?.label }}
                                    </span>
                                    <select
                                        :value="cell(student.enrollment_id, item.id).state"
                                        :disabled="isReadOnly"
                                        class="absolute inset-0 h-full w-full cursor-pointer appearance-none rounded-md opacity-0 disabled:cursor-default"
                                        :aria-label="`Estado de ${student.name}${items.length > 1 ? ' em ' + item.code : ''}`"
                                        :title="states.find((state) => state.value === cell(student.enrollment_id, item.id).state)?.label"
                                        @change="onStateChange(student, item, ($event.target as HTMLSelectElement).value)"
                                    >
                                        <option v-for="state in states" :key="state.value" :value="state.value">
                                            {{ state.value === 'assessed' ? `✓ ${state.label}` : state.label }}
                                        </option>
                                    </select>
                                    <ChevronDown class="pointer-events-none absolute right-1 size-3.5" aria-hidden="true" />
                                </div>
                            </div>
                        </td>
                        </template>

                        <td class="px-3 py-0.5 text-right font-semibold tabular-nums" data-testid="total-cell">
                            <template v-if="totalFor(student) === null">
                                <span class="text-muted-foreground" title="Sem classificações registadas — não é zero.">—</span>
                            </template>
                            <template v-else>
                                {{ formatPoints(totalFor(student)!) }}<span v-if="totalMax" class="font-normal text-muted-foreground">/{{ formatPoints(totalMax) }}</span>
                                <span
                                    v-if="totalPercentExact(student) !== null"
                                    class="text-xs font-normal"
                                    :class="toneClass(totalTone(student)) || 'text-muted-foreground'"
                                    :title="MENTION_TITLE"
                                    data-testid="total-mention"
                                > · {{ formatPercent(totalPercentExact(student)!) }}<template v-if="mentionFor(totalPercentExact(student))"> · {{ mentionFor(totalPercentExact(student)) }}</template></span>
                                <span v-if="totalTone(student)" class="sr-only">{{ resultToneTitle[totalTone(student)!] }}</span>
                                <span v-if="isPartial(student)" class="ml-1 text-xs font-normal text-amber-600" title="Ainda há questões por avaliar.">parcial</span>
                            </template>
                        </td>
<!-- Largura mínima e linhas sem quebra: com cinco domínios, uma coluna estreita partia cada linha
                             («Educação Literária 15,83/20 79,2 % Bom») em duas ou três e cada aluno passava a ocupar 300 px. -->
                        <td class="min-w-80 px-3 py-0.5 text-left">
                            <div class="flex flex-col gap-1">
                                <div class="flex items-center gap-1.5 border-b border-border/40 pb-1 whitespace-nowrap">
                                    <span class="w-16 shrink-0 text-xs font-medium text-muted-foreground">Global</span>
                                    <template v-if="officialFor(student)">
                                        <template v-if="officialFor(student)!.global.value !== null || officialFor(student)!.global.value_precise !== null">
                                            <span class="text-xs tabular-nums" :class="toneClass(officialTone(officialFor(student)!.global, 'global'))" :title="officialTone(officialFor(student)!.global, 'global') ? resultToneTitle[officialTone(officialFor(student)!.global, 'global')!] : undefined" data-testid="official-global-value">{{ officialCellDisplay(officialFor(student)!.global) }}</span>
                                            <span v-if="officialTone(officialFor(student)!.global, 'global')" class="sr-only">{{ resultToneTitle[officialTone(officialFor(student)!.global, 'global')!] }}</span>
                                            <Badge v-if="officialFor(student)!.global.band" :class="officialToneClass(officialFor(student)!.global)" class="text-[10px]">
                                                {{ officialFor(student)!.global.band!.label }}
                                            </Badge>
                                        </template>
                                        <span v-else class="text-xs text-muted-foreground">{{ officialFor(student)!.status_label }}</span>
                                    </template>
                                    <span v-else class="text-xs text-muted-foreground">—</span>
                                    <span
                                        v-if="studentIsDirty(student)"
                                        class="text-[10px] text-amber-600"
                                        title="Os valores oficiais só refletem o que está guardado."
                                    >guarde para recalcular</span>
                                </div>
                                <div v-for="domain in official.domains" :key="domain.key" class="flex items-center gap-1.5 whitespace-nowrap">
                                    <span class="w-16 shrink-0 truncate text-xs text-muted-foreground" :title="domain.name">{{ domain.name }}</span>
                                    <template v-if="officialFor(student)?.domains[domain.key]">
                                        <span class="flex items-center gap-1.5 whitespace-nowrap">
                                            <span
                                                v-if="officialPointsDisplay(officialFor(student)!.domains[domain.key]!)"
                                                class="text-xs font-medium tabular-nums"
                                                :data-testid="`domain-points-${domain.key}`"
                                            >{{ officialPointsDisplay(officialFor(student)!.domains[domain.key]!) }}</span>
                                            <span
                                                class="text-xs tabular-nums"
                                                :class="toneClass(officialTone(officialFor(student)!.domains[domain.key], domain.key))"
                                                :title="officialTone(officialFor(student)!.domains[domain.key], domain.key) ? resultToneTitle[officialTone(officialFor(student)!.domains[domain.key], domain.key)!] : undefined"
                                                :data-testid="`official-value-${domain.key}`"
                                            >{{ officialCellDisplay(officialFor(student)!.domains[domain.key]!) }}</span>
                                            <span v-if="officialTone(officialFor(student)!.domains[domain.key], domain.key)" class="sr-only">{{ resultToneTitle[officialTone(officialFor(student)!.domains[domain.key], domain.key)!] }}</span>
                                        </span>
                                        <Badge
                                            v-if="officialFor(student)!.domains[domain.key]!.band"
                                            :class="officialToneClass(officialFor(student)!.domains[domain.key]!)"
                                            class="text-[10px]"
                                        >{{ officialFor(student)!.domains[domain.key]!.band!.label }}</Badge>
                                    </template>
                                    <span v-else class="text-xs text-muted-foreground">—</span>
                                </div>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-show="barVisible"
            ref="scrollBar"
            class="fixed bottom-0 z-20 h-4 overflow-x-auto overflow-y-hidden border-t border-border bg-background"
            :style="barStyle"
            data-testid="grid-scrollbar"
            aria-hidden="true"
            @scroll="syncScroll('bar')"
        >
            <div class="h-px" :style="{ width: `${scrollBarWidth}px` }" />
        </div>

        <p
            v-if="official.status === 'provisional'"
            class="text-xs text-muted-foreground"
        >
            Valores provisórios: a correção ainda não está concluída. Os resultados oficiais e as
            estatísticas ficam disponíveis no separador Resultados depois de concluir a correção.
        </p>

        <p v-if="!isReadOnly" class="text-xs text-muted-foreground">
            Setas: mover entre células (← → no início/fim do texto) · Enter: confirmar e descer · Esc: repor
        </p>

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
