<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck, CircleHelp, MinusCircle } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

type Issue = {
    code: string;
    severity: string;
    message: string;
    context: Record<string, unknown>;
};
type StudentRow = {
    source_key: string;
    card_number: string | null;
    display_name: string | null;
    source_score: string | null;
    source_correct: number | null;
    source_answered: number | null;
    lapis_percentage: string | null;
    group_results?: GroupResult[];
    lapis_total?: string | null;
    participated: boolean;
    enrollment_id: number | null;
    status: 'matched' | 'ambiguous' | 'unmatched' | 'ignored';
    reason: string | null;
    candidates: { id: number; label: string }[];
    suggestions: { id: number; label: string }[];
};
type ItemRow = {
    source_key: string;
    sequence: number;
    code: string | null;
    label: string | null;
    question_text: string | null;
    answer_key: string | null;
    source_points: string | null;
    points: string | null;
    points_locked: boolean;
    instrument_item_id: number | null;
    domains: { domain_id: number; allocation_percent: string }[];
};
type EligibleInstrument = {
    id: number;
    title: string;
    applied_on: string;
    status_label: string;
    items: {
        id: number;
        code: string;
        label: string | null;
        group: string | null;
        points_possible: string;
    }[];
};
type Conflict = {
    enrollment_id: number;
    instrument_item_id: number;
    current: string;
    incoming: string;
};
type Allocation = { domain_id: number; allocation_percent: string };

/** A section of the test as the source describes it. Structure, never a domain. */
type SourceGroup = {
    source_key: string;
    sequence: number;
    label: string | null;
    questions: number;
    points_possible: string;
    domains: Allocation[];
};

type GroupResult = {
    source_key: string;
    label: string | null;
    earned: string | null;
    possible: string;
};

type Reconciliation = {
    applicable: boolean;
    groups_total?: string;
    source_total?: string | null;
    maximum_agrees?: boolean;
    students_disagreeing?: number;
};

/**
 * The teacher's own spreadsheet, as read — and only as read.
 *
 * Present only for a source whose file does not explain itself. Plickers and
 * Intuitivo send null here and never see any of the screen below, because a
 * mapping form for a file LÁPIS already understands is a form asking somebody to
 * type in what is already known.
 */
type TabularCellView = {
    text: string | null;
    number: string | null;
    is_percentage: boolean;
    is_formula: boolean;
};

type TableState = {
    sheet: string | null;
    header_row: number | null;
    student_column: string | null;
    result_columns: string[];
    value_kind: string;
    overall_maximum: string | null;
    total_column: string | null;
};

type TabularDescription = {
    readable: boolean;
    message?: string;
    kind?: string | null;
    sheets?: { name: string; rows: number; columns: number; empty: boolean }[];
    needs_sheet_choice?: boolean;
    selected_sheet?: string | null;
    row_numbers?: number[];
    columns?: {
        letter: string;
        heading: string | null;
        numeric_share: number;
        has_formula: boolean;
    }[];
    sample?: { row: number; cells: TabularCellView[] }[];
    suggestions?: {
        header_row?: number;
        student_column?: string | null;
        result_columns?: string[];
    };
    table?: TableState;
};

const props = defineProps<{
    tabular: TabularDescription | null;
    correctionImport: {
        ulid: string;
        status: string;
        statusLabel: string;
        originalFilename: string | null;
        class: { id: number; label: string };
    };
    preview: {
        source: string;
        source_label: string;
        // What this source is entitled to say about itself. Stated on the server
        // beside the source, so no screen here decides that «não participou» is
        // meaningful by comparing against a provider name (§6).
        source_states_participation: boolean;
        source_result_label: string;
        source_needs_describing: boolean;
        suggested_title: string | null;
        mode: string;
        result_mode: string;
        instrument_id: number | null;
        instrument_attributes: Record<string, unknown>;
        overall_domains: Allocation[];
        groups: SourceGroup[];
        reconciliation: Reconciliation;
        overall_item_id: number | null;
        items: ItemRow[];
        students: StudentRow[];
        counts: Record<string, number>;
        issues: Issue[];
        can_confirm: boolean;
        eligible_instruments: EligibleInstrument[];
    };
    conflicts: Conflict[];
    duplicateOfEarlierImport: boolean;
    catalogue: {
        instrument_types: { id: number; code: string; name: string }[];
        domains: { id: number; name: string }[];
        periods: {
            id: number;
            label: string;
            starts_on: string;
            ends_on: string;
        }[];
    };
}>();

// The wizard opens on the students, not on the questions. What a teacher needs
// to see first is whether their class was found and what each of them scored;
// the structure of the test is something they configure afterwards, and in the
// ordinary case they never configure it at all.
const step = ref(2);

// «Alunos e resultados» promised both before either existed: for a spreadsheet,
// this step may still be working out which sheet the data is even on. «Ler
// resultados» is true from the moment the step opens, for every source, and the
// students keep their own heading inside it once they are there (§6).
const STEPS = [
    'Origem e ficheiro',
    'Ler resultados',
    'Configurar avaliação',
    'Rever e importar',
];

/**
 * Everything the form holds, derived from the import currently in the URL.
 *
 * Extracted into a function rather than written inline because it has to be
 * runnable a second time. Inertia reuses a page component when a navigation
 * only changes its props, so `useForm({...})` at setup runs ONCE for the life
 * of the component — analyse a second file and the form still holds the first
 * one's students, questions and cotações. That is how one class's marks end up
 * on another class's screen.
 */
type WizardState = {
    mode: string;
    result_mode: string;
    instrument_id: number | null;
    students: Record<string, number | null>;
    items: Record<string, number | null>;
    points: Record<string, string>;
    domains: Record<string, Allocation[]>;
    overall_domains: Allocation[];
    overall_item_id: number | null;
    group_domains: Record<string, Allocation[]>;
    table: TableState;
    conflicts: Record<string, string>;
    instrument: {
        title: string;
        instrument_type_id: number | null;
        applied_on: string;
        academic_period_id: number | null;
        purpose: string;
        counts_toward_classification: boolean;
        total_points: number | null;
    };
};

/**
 * What the teacher has said about their sheet, with the structural suggestions
 * filled in where they have said nothing yet.
 *
 * A stored answer always wins over a suggestion, including a stored answer that
 * happens to equal one: re-suggesting over a decision would quietly undo a
 * correction the teacher made on purpose.
 */
function tableStateFor(): TableState {
    const stored = props.tabular?.table;
    const suggested = props.tabular?.suggestions ?? {};

    return {
        sheet: stored?.sheet ?? null,
        header_row: stored?.header_row ?? suggested.header_row ?? null,
        student_column: stored?.student_column ?? suggested.student_column ?? null,
        result_columns:
            stored?.result_columns && stored.result_columns.length > 0
                ? [...stored.result_columns]
                : [...(suggested.result_columns ?? [])],
        value_kind: stored?.value_kind ?? 'points',
        overall_maximum: stored?.overall_maximum ?? null,
        total_column: stored?.total_column ?? null,
    };
}

function stateFor(): WizardState {
    return {
        mode: props.preview.mode,
        result_mode: props.preview.result_mode,
        instrument_id: props.preview.instrument_id,
        students: Object.fromEntries(
            props.preview.students
                .filter(
                    (student) =>
                        student.status === 'matched' ||
                        student.status === 'ignored',
                )
                .map((student) => [student.source_key, student.enrollment_id]),
        ) as Record<string, number | null>,
        items: Object.fromEntries(
            props.preview.items
                .filter((item) => item.instrument_item_id !== null)
                .map((item) => [item.source_key, item.instrument_item_id]),
        ) as Record<string, number | null>,
        points: Object.fromEntries(
            props.preview.items.map((item) => [
                item.source_key,
                item.points ?? '',
            ]),
        ) as Record<string, string>,
        domains: Object.fromEntries(
            props.preview.items.map((item) => [item.source_key, item.domains]),
        ) as Record<string, Allocation[]>,
        overall_domains: props.preview.overall_domains,
        group_domains: Object.fromEntries(
            props.preview.groups.map((group) => [
                group.source_key,
                group.domains,
            ]),
        ) as Record<string, Allocation[]>,
        overall_item_id: props.preview.overall_item_id,
        // Suggestions are PRE-FILLED, never applied. What arrives here is a form
        // the teacher submits; until they do, nothing about the sheet has been
        // decided and the import says so. That is the whole difference between
        // helping and guessing (§14).
        table: tableStateFor(),
        conflicts: {} as Record<string, string>,
        instrument: {
            title:
                (props.preview.instrument_attributes.title as string) ??
                props.preview.suggested_title ??
                '',
            instrument_type_id:
                (props.preview.instrument_attributes
                    .instrument_type_id as number) ?? null,
            // Deliberately empty: the export's «(25/26)» is a school year, not a
            // date, and the date decides which students the instrument applies to.
            applied_on:
                (props.preview.instrument_attributes.applied_on as string) ??
                '',
            academic_period_id:
                (props.preview.instrument_attributes
                    .academic_period_id as number) ?? null,
            purpose:
                (props.preview.instrument_attributes.purpose as string) ??
                'formative',
            counts_toward_classification:
                (props.preview.instrument_attributes
                    .counts_toward_classification as boolean) ?? true,
            total_points:
                (props.preview.instrument_attributes.total_points as number) ??
                null,
        },
    };
}

const form = useForm(stateFor());

const errors = computed(() =>
    props.preview.issues.filter((issue) => issue.severity === 'error'),
);
const warnings = computed(() =>
    props.preview.issues.filter((issue) => issue.severity === 'warning'),
);
const infos = computed(() =>
    props.preview.issues.filter((issue) => issue.severity === 'info'),
);

const creating = computed(() => form.mode === 'create_new');
const chosenInstrument = computed(() =>
    props.preview.eligible_instruments.find(
        (row) => row.id === form.instrument_id,
    ),
);

/**
 * How much of the file becomes assessment.
 *
 * Three granularities, all provider-neutral. The source decides only which one
 * the wizard opens on — Plickers states one score per student, Intuitivo states
 * the test's sections — and the teacher changes it in one click.
 *
 * The middle one is what makes a multi-domain paper importable: a Português test
 * whose Grupo I assesses Leitura and whose Grupo III assesses Gramática becomes
 * ONE evaluation with one result per section, each counting toward its own
 * domain.
 */
const simple = computed(() => form.result_mode === 'overall');
const grouped = computed(() => form.result_mode === 'per_group');
const detailed = computed(() => form.result_mode === 'per_question');

/** Only offered when the file actually states sections. */
const hasGroups = computed(() => props.preview.groups.length > 0);

/**
 * Whether this source needs the teacher to say what the file means.
 *
 * Asked of the payload, never of the source name: a fourth format that also
 * needs describing gets this screen without a line changing here, and the two
 * that explain themselves never see it.
 */
const isTabular = computed(() => props.tabular !== null);
const tabularReadable = computed(() => props.tabular?.readable === true);

/**
 * Vocabulary that belongs to the source, taken from the source.
 *
 * «Não participou nesta aplicação» is a true sentence about a Plickers export,
 * which reports how many questions each student answered. About a spreadsheet
 * somebody typed it is an invention — and an invention about attendance is one a
 * teacher could act on (§6).
 */
const statesParticipation = computed(
    () => props.preview.source_states_participation,
);
const sourceResultLabel = computed(() => props.preview.source_result_label);

/**
 * Whether the file has actually been read yet.
 *
 * A source that explains itself is read the moment it is uploaded. A sheet
 * nobody has described is not read at all — and everything downstream of the
 * reading (the counts, the class, the note about blanks, the button that moves
 * on) is a statement about results that do not exist yet. Shown together,
 * because they become true together (§8).
 */
const resultsAreRead = computed(
    () =>
        !props.preview.source_needs_describing ||
        props.preview.students.length > 0,
);

const sheetChoices = computed(() =>
    (props.tabular?.sheets ?? []).filter((sheet) => !sheet.empty),
);
const mustChooseSheet = computed(
    () => sheetChoices.value.length > 1 && form.table.sheet === null,
);

const sheetColumns = computed(() => props.tabular?.columns ?? []);
const sampleRows = computed(() => props.tabular?.sample ?? []);
const headerRowChoices = computed(() =>
    (props.tabular?.row_numbers ?? []).slice(0, 30),
);

/** Every column except the one naming the student — that one is not a result. */
const resultColumnChoices = computed(() =>
    sheetColumns.value.filter(
        (column) => column.letter !== form.table.student_column,
    ),
);

/** The teacher has answered enough for the sheet to be read at all. */
const sheetIsDescribed = computed(
    () =>
        form.table.sheet !== null &&
        form.table.header_row !== null &&
        form.table.student_column !== null &&
        form.table.result_columns.length > 0,
);

/**
 * What to call a column on screen.
 *
 * The heading the teacher wrote, because that is what they will look for. Two
 * columns headed «Item 1» are a real and ordinary thing, so those are told apart
 * by the letter the spreadsheet itself puts at the top of the column — «Item 1
 * (coluna B)» — which the teacher can verify by glancing at their own file. That
 * letter is the spreadsheet's own vocabulary, not the importer's — the internal
 * source key for a column is a different string entirely, and it never reaches
 * the screen (§12).
 */
function columnName(letter: string): string {
    const column = sheetColumns.value.find((each) => each.letter === letter);
    const heading = column?.heading ?? null;

    if (heading === null) {
        return `Coluna ${letter}`;
    }

    const sharing = sheetColumns.value.filter(
        (each) => each.heading === heading,
    );

    return sharing.length > 1 ? `${heading} (coluna ${letter})` : heading;
}

const studentColumnName = computed(() =>
    form.table.student_column === null
        ? null
        : columnName(form.table.student_column),
);

/** How many students the sheet yielded with the answers given so far. */
const importedRows = computed(() => props.preview.students.length);

/**
 * The next answer required, in a sentence.
 *
 * A disabled button with no explanation sends a teacher hunting for the reason,
 * which is exactly the state the smoke found: the action offered, and refusing,
 * on a workbook whose tab had not been chosen yet (§11).
 */
const whatIsStillMissing = computed(() => {
    if (mustChooseSheet.value) {
        return 'Escolha primeiro a folha do Excel que contém os resultados.';
    }

    if (form.table.student_column === null) {
        return 'Falta indicar a coluna com o nome dos alunos.';
    }

    if (form.table.result_columns.length === 0) {
        return simple.value
            ? 'Falta escolher a coluna com a classificação.'
            : 'Falta escolher pelo menos uma coluna com resultados.';
    }

    if (form.table.header_row === null) {
        return 'Falta indicar a linha com os títulos, em «Alterar interpretação da folha».';
    }

    return 'Pode alterar estas respostas a qualquer momento — a folha é lida outra vez.';
});

function toggleResultColumn(letter: string): void {
    form.table.result_columns = form.table.result_columns.includes(letter)
        ? form.table.result_columns.filter((each) => each !== letter)
        : [...form.table.result_columns, letter].sort();
}

/**
 * Re-reads the sheet with the answers given so far, and stays where it is.
 *
 * Saving is what makes the file mean something: the grid is rebuilt on the
 * server from the upload plus these answers, so the students appearing below is
 * the confirmation that the description was right.
 */
function readSheetAgain(): void {
    save();
}

/**
 * The three granularities, named for what the teacher is looking at.
 *
 * The middle one is deliberately NOT called the same thing for every source. An
 * Intuitivo export states «GRUPO I» — a section of the paper, which the teacher
 * then points at a domain — and calling that mode «resultados por domínio» would
 * merge the two ideas the whole design keeps apart. A column the teacher chose
 * in their own spreadsheet has no section to speak of: they pick a column and
 * they pick a domain for it, so «por domínio» is exactly what it is.
 *
 * Same three values, same arithmetic, same persistence. Only the sentence
 * changes, and it changes because the two files are saying different things.
 */
const GRANULARITIES = [
    {
        value: 'overall',
        label: 'Uma classificação global por aluno',
        forDescribedSheet: 'Uma classificação global por aluno',
        hint: 'Um único resultado por aluno, para um domínio.',
    },
    {
        value: 'per_group',
        label: 'Resultados por grupos',
        forDescribedSheet: 'Resultados por domínio',
        hint: 'Um resultado por grupo do teste, cada um para o seu domínio.',
    },
    {
        value: 'per_question',
        label: 'Detalhe por perguntas',
        forDescribedSheet: 'Resultados por questão',
        hint: 'Cada pergunta com a sua cotação e o seu domínio.',
    },
];

const granularities = computed(() =>
    GRANULARITIES.filter(
        // Sections exist for a known export the moment it is read. For a sheet
        // the teacher describes, they exist only AFTER they choose this mode and
        // pick the columns — so offering it has to come first, or the mode that
        // makes a multi-domain import possible could never be reached.
        (option) =>
            option.value !== 'per_group' || hasGroups.value || isTabular.value,
    ).map((option) => ({
        value: option.value,
        label: isTabular.value ? option.forDescribedSheet : option.label,
        hint: option.hint,
    })),
);

const granularityLabel = computed(
    () =>
        granularities.value.find((option) => option.value === form.result_mode)
            ?.label ?? '',
);

/** One domain for the global result, held in the allocation shape the model uses. */
const overallDomainId = computed<number | null>({
    get: () => form.overall_domains[0]?.domain_id ?? null,
    set: (value) => {
        form.overall_domains =
            value === null
                ? []
                : [{ domain_id: value, allocation_percent: '100' }];
    },
});

const overallDomainName = computed(
    () =>
        props.catalogue.domains.find(
            (domain) => domain.id === overallDomainId.value,
        )?.name ?? null,
);

/** The domain a section counts toward, as a select value. */
function domainOfGroup(sourceKey: string): string {
    const domainId = (form.group_domains[sourceKey] ?? [])[0]?.domain_id;

    return domainId === undefined ? '' : String(domainId);
}

function setDomainOfGroup(sourceKey: string, value: string): void {
    form.group_domains = {
        ...form.group_domains,
        [sourceKey]:
            value === ''
                ? []
                : [{ domain_id: Number(value), allocation_percent: '100' }],
    };
}

function domainNameOfGroup(sourceKey: string): string | null {
    const domainId = (form.group_domains[sourceKey] ?? [])[0]?.domain_id;

    return (
        props.catalogue.domains.find((domain) => domain.id === domainId)
            ?.name ?? null
    );
}

/**
 * A row with no entry at all is one nobody looked at; a row mapped to null is
 * one the teacher chose to leave out. Only the first blocks (§16).
 */
const undecidedStudents = computed(
    () =>
        props.preview.students.filter(
            (student) => !(student.source_key in form.students),
        ).length,
);

/**
 * Exactly what is still missing, in the teacher's words, one entry per control.
 *
 * This replaced a single boolean whose only message was «falta preencher a
 * configuração da avaliação ou a cotação das perguntas» — true, unhelpful, and
 * in the one case that mattered actively misleading: the field that was missing
 * had no input on the screen at all (§11).
 */
const missing = computed<string[]>(() => {
    const out: string[] = [];

    if (creating.value) {
        if (!form.instrument.title) {
            out.push('Indique a designação da avaliação.');
        }

        if (!form.instrument.applied_on) {
            out.push('Indique a data de aplicação.');
        }

        if (form.instrument.instrument_type_id === null) {
            out.push('Selecione o tipo de avaliação.');
        }

        if (form.instrument.academic_period_id === null) {
            out.push('Selecione o período a que esta avaliação pertence.');
        }

        // Only when it counts: a result that enters no calculation needs no
        // domain, and asking for one anyway would be paperwork (§6).
        if (
            simple.value &&
            form.instrument.counts_toward_classification &&
            overallDomainId.value === null
        ) {
            out.push('Selecione o domínio avaliado.');
        }

        if (grouped.value && form.instrument.counts_toward_classification) {
            const semDominio = props.preview.groups.filter(
                (group) => !(form.group_domains[group.source_key] ?? []).length,
            );

            if (semDominio.length) {
                out.push(
                    `Indique o domínio avaliado por: ${semDominio.map((group) => group.label ?? group.source_key).join(', ')}.`,
                );
            }
        }
    } else if (grouped.value) {
        out.push(
            'Os resultados por grupos só podem criar uma avaliação nova. Escolha «Criar uma nova avaliação», ou outra granularidade.',
        );
    } else if (form.instrument_id === null) {
        out.push('Escolha a avaliação a que os resultados se destinam.');
    } else if (simple.value && form.overall_item_id === null) {
        out.push(
            'Indique qual a pergunta da avaliação que recebe o resultado global.',
        );
    }

    // Neither of these asks for cotações or question mappings: the file already
    // states what each question is worth, and the marks land on the sections.
    if (simple.value || grouped.value) {
        return out;
    }

    if (creating.value) {
        const semCotacao = props.preview.items.filter(
            (item) => !form.points[item.source_key],
        ).length;

        if (semCotacao > 0) {
            out.push(
                semCotacao === 1
                    ? 'Existe 1 pergunta sem cotação.'
                    : `Existem ${semCotacao} perguntas sem cotação.`,
            );
        }
    } else if (form.instrument_id !== null) {
        const porAssociar = props.preview.items.filter(
            (item) => !form.items[item.source_key],
        ).length;

        if (porAssociar > 0) {
            out.push(
                porAssociar === 1
                    ? 'Há 1 pergunta do ficheiro por associar a uma pergunta da avaliação.'
                    : `Há ${porAssociar} perguntas do ficheiro por associar às perguntas da avaliação.`,
            );
        }
    }

    return out;
});

const showQuestions = ref(false);
const uniformPoints = ref('');
const uniformDomain = ref<number | null>(null);
const uniformNotice = ref<string | null>(null);

/**
 * A different import in the URL is a different file, and nothing from the last
 * one may survive into it — not the mapping, not the cotações, not even which
 * step was open. Without this the component simply keeps what it built at
 * setup, because Inertia never remounts it.
 */
watch(
    () => props.correctionImport.ulid,
    () => {
        form.defaults(stateFor());
        form.reset();
        form.clearErrors();
        step.value = 2;
        showQuestions.value = false;
        uniformPoints.value = '';
        uniformDomain.value = null;
        uniformNotice.value = null;
    },
);

/**
 * The period follows from the date, so the teacher states the date and nothing
 * else. Not an inference about assessment — a lookup of which period contains a
 * day, using the dates the periods themselves declare. It stays a visible,
 * editable field, because a lookup that silently decided something the teacher
 * cannot see would be worse than the question it replaced.
 */
watch(
    () => form.instrument.applied_on,
    (date) => {
        if (!date) {
            return;
        }

        const match = props.catalogue.periods.find(
            (period) => date >= period.starts_on && date <= period.ends_on,
        );

        if (match) {
            form.instrument.academic_period_id = match.id;
        }
    },
);

const derivedPeriod = computed(() =>
    props.catalogue.periods.find(
        (period) => period.id === form.instrument.academic_period_id,
    ),
);

/**
 * An evaluation with a single question has only one place a global result can
 * go, so it is chosen. With several there is a real decision to make and
 * nothing is preselected — the same rule the student dropdowns follow, and for
 * the same reason.
 */
watch(
    () => form.instrument_id,
    () => {
        const items = chosenInstrument.value?.items ?? [];
        form.overall_item_id = items.length === 1 ? items[0].id : null;
    },
);

/**
 * One cotação for every question.
 *
 * Applied when the field is committed — on blur, or on Enter — rather than
 * behind a separate button. A filled box that has not taken effect, next to a
 * disabled Save button, is a screen that tells the teacher nothing is wrong and
 * refuses to continue anyway (§10).
 */
function applyUniformPoints(): void {
    if (uniformPoints.value === '') {
        return;
    }

    let applied = 0;

    for (const item of props.preview.items) {
        if (!item.points_locked) {
            form.points[item.source_key] = uniformPoints.value;
            applied++;
        }
    }

    uniformNotice.value =
        applied === 1
            ? 'Cotação aplicada a 1 pergunta.'
            : `Cotação aplicada a ${applied} perguntas.`;
}

function applyUniformDomain(): void {
    if (uniformDomain.value === null) {
        return;
    }

    for (const item of props.preview.items) {
        form.domains[item.source_key] = [
            { domain_id: uniformDomain.value, allocation_percent: '100' },
        ];
    }

    const name =
        props.catalogue.domains.find(
            (domain) => domain.id === uniformDomain.value,
        )?.name ?? '';
    uniformNotice.value = `Domínio «${name}» aplicado a ${props.preview.items.length} perguntas.`;
}

function save(next?: number): void {
    form.patch(`/imports/correction/${props.correctionImport.ulid}`, {
        preserveScroll: true,
        onSuccess: () => {
            if (next !== undefined) {
                step.value = next;
            }
        },
    });
}

function confirmImport(): void {
    router.post(`/imports/correction/${props.correctionImport.ulid}/confirm`);
}

/**
 * Discarding a session the teacher decided against.
 *
 * Offered from every step, because the moment they realise it is the wrong file
 * is usually the moment they are looking at the students — not three screens
 * later. Never offered once the import has been confirmed: at that point the
 * marks exist and belong to an instrument, which has its own rules for undoing
 * things (§6).
 */
const cancelDialogOpen = ref(false);
const canCancel = computed(
    () =>
        !['imported', 'cancelled', 'failed'].includes(
            props.correctionImport.status,
        ),
);

function cancelImport(): void {
    cancelDialogOpen.value = false;
    router.delete(`/imports/correction/${props.correctionImport.ulid}`);
}

const statusIcon = {
    matched: CircleCheck,
    ambiguous: CircleHelp,
    unmatched: CircleAlert,
    ignored: MinusCircle,
};
const statusLabel = {
    matched: 'Correspondência',
    ambiguous: 'Confirmar',
    unmatched: 'Não encontrado',
    ignored: 'Ignorado',
};

/**
 * WHY the system paired two names, in words. A teacher asked to trust an
 * automatic association is entitled to know what it was based on — «nome exato»
 * and «nome semelhante» deserve different amounts of scrutiny, and a silent ✓
 * gives them the same weight.
 */
const REASONS: Record<string, string> = {
    exact_name: 'Nome exato',
    normalised_name: 'Nome normalizado',
    class_number: 'Número da turma',
    confirmed_by_teacher: 'Confirmado por si',
    ignored_by_teacher: 'Ignorado por si',
    already_taken: 'Já associado a outra linha',
};

function matchLabel(student: StudentRow): string {
    if (student.status === 'unmatched') {
        return 'Não encontrado';
    }

    const reason = student.reason === null ? null : REASONS[student.reason];

    if (student.status === 'ambiguous') {
        return reason ? `Confirmar — ${reason}` : 'Confirmar';
    }

    return reason ?? statusLabel[student.status];
}

/** «11 certas · 20 respondidas», and never a zero dressed up as a mark. */
function attempts(student: StudentRow): string {
    const correct = student.source_correct ?? 0;
    const answered = student.source_answered ?? 0;

    return `${correct} certas · ${answered} respondidas`;
}

const typeName = computed(
    () =>
        props.catalogue.instrument_types.find(
            (type) => type.id === form.instrument.instrument_type_id,
        )?.name ?? '—',
);
</script>

<template>
    <Head title="Importar resultados" />

    <div class="mx-auto w-full max-w-6xl space-y-6 p-4">
        <div>
            <Heading
                title="Importar resultados"
                :description="`${preview.source_label} · ${correctionImport.class.label}${correctionImport.originalFilename ? ' · ' + correctionImport.originalFilename : ''}`"
            />
            <div class="flex flex-wrap items-center gap-3 text-sm">
                <Link
                    href="/assessments"
                    class="text-muted-foreground hover:underline"
                    >← Voltar a Avaliações</Link
                >
                <!--
                  An explicit way back to step 1. Without it the only route is
                  the browser's Back button, which restores a cached page and is
                  precisely how a previous import stays on screen.
                -->
                <Link
                    href="/imports/correction/create"
                    class="text-primary hover:underline"
                    >Importar outro ficheiro →</Link
                >
                <button
                    v-if="canCancel"
                    type="button"
                    class="text-muted-foreground hover:text-destructive hover:underline"
                    @click="cancelDialogOpen = true"
                >
                    Cancelar importação
                </button>
            </div>
        </div>

        <Dialog v-model:open="cancelDialogOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Cancelar esta importação?</DialogTitle>
                    <DialogDescription>
                        Os dados analisados deste ficheiro serão descartados.
                        Nenhuma avaliação já existente será eliminada.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <button
                        type="button"
                        class="rounded-md border border-border px-4 py-2 text-sm"
                        @click="cancelDialogOpen = false"
                    >
                        Manter importação
                    </button>
                    <button
                        type="button"
                        class="rounded-md bg-destructive px-4 py-2 text-sm text-white"
                        @click="cancelImport"
                    >
                        Cancelar importação
                    </button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <ol class="flex flex-wrap gap-2 text-sm">
            <li v-for="(label, index) in STEPS" :key="label">
                <button
                    type="button"
                    class="rounded-md border px-3 py-1.5"
                    :class="
                        step === index + 1
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'border-border'
                    "
                    :aria-current="step === index + 1 ? 'step' : undefined"
                    :disabled="index === 0"
                    @click="step = index + 1"
                >
                    {{ index + 1 }}. {{ label
                    }}<span v-if="step === index + 1" class="sr-only">
                        (passo atual)</span
                    >
                </button>
            </li>
        </ol>

        <p
            v-if="duplicateOfEarlierImport"
            class="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900"
        >
            Este ficheiro parece já ter sido utilizado numa importação anterior.
        </p>

        <!-- ============================ PASSO 2 — ALUNOS E RESULTADOS ============ -->
        <section v-if="step === 2" class="space-y-4">
            <!--
              The heading follows the state of the step rather than its name:
              «Confirme os resultados encontrados» is a instruction about
              results, and on a sheet nobody has read yet there are none to
              confirm (§8).
            -->
            <div>
                <h2 class="text-base font-semibold">
                    {{ resultsAreRead ? 'Alunos e resultados' : 'Ler resultados' }}
                </h2>
                <p class="text-sm text-muted-foreground">
                    <template v-if="resultsAreRead">
                        Confirme os resultados encontrados no ficheiro e a
                        correspondência com os alunos da turma.
                    </template>
                    <template v-else>
                        Diga ao LÁPIS onde estão os resultados nesta folha de
                        cálculo.
                    </template>
                </p>
            </div>

            <!--
              ============ A FOLHA DO PROFESSOR ============

              Only for a source that does not explain itself. The questions are
              asked in the spreadsheet's own words — «linha dos títulos», «coluna
              que identifica o aluno» — and never in the importer's: nothing on
              this screen says canonical, source key or column index (§34).
            -->
            <div
                v-if="isTabular && !tabularReadable"
                class="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900"
            >
                {{ tabular?.message ?? 'Não foi possível ler esta folha.' }}
            </div>

            <!--
              ============ A FOLHA DO PROFESSOR ============

              The order is the teacher's, not the file format's: which tab, what
              it looks like, who the students are, what to import. The mechanics
              of a spreadsheet — which row holds the headings — sit underneath,
              pre-answered, because a teacher should be explaining what their
              data MEANS and not the structure of a worksheet (§17).
            -->
            <section
                v-else-if="isTabular"
                class="rounded-md border border-border bg-card"
            >
                <div class="px-4 py-3">
                    <h3 class="text-sm font-semibold">
                        Como devemos ler esta folha?
                    </h3>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        <template v-if="sheetIsDescribed && importedRows > 0">
                            A ler {{ importedRows }}
                            {{ importedRows === 1 ? 'aluno' : 'alunos' }} a
                            partir da coluna
                            {{ studentColumnName }}.
                        </template>
                        <template v-else>
                            Responda a estas perguntas e o LÁPIS lê os
                            resultados.
                        </template>
                    </p>
                </div>

                <div class="space-y-5 border-t border-border px-4 py-4">
                    <!--
                      A. Which tab. Only a question when there is more than one
                      answer — choosing between one option is not a choice, and
                      picking the first because it is first is the silent guess
                      this importer refuses to make (§7).
                    -->
                    <div v-if="sheetChoices.length > 1" class="grid gap-1.5">
                        <!-- «Separador» is what the format calls it. A teacher
                             calls it a folha, which is also what Excel prints
                             on the tab they are looking at (§3). -->
                        <label for="table-sheet" class="text-sm font-medium"
                            >Em que folha do Excel estão os resultados dos
                            alunos?</label
                        >
                        <p class="text-xs text-muted-foreground">
                            Este ficheiro contém várias folhas. Escolha aquela
                            que contém a tabela com os nomes dos alunos e os
                            respetivos resultados.
                        </p>
                        <select
                            id="table-sheet"
                            v-model="form.table.sheet"
                            class="h-9 w-full max-w-sm rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option :value="null">— Por escolher —</option>
                            <option
                                v-for="sheet in sheetChoices"
                                :key="sheet.name"
                                :value="sheet.name"
                            >
                                {{ sheet.name }} ({{ sheet.rows }}
                                {{ sheet.rows === 1 ? 'linha' : 'linhas' }})
                            </option>
                        </select>
                    </div>

                    <!--
                      B. The sheet itself. Shown BEFORE the questions about it,
                      because every one of those questions is easier to answer
                      while looking at the thing (§9). Only a few rows: a whole
                      class list in the page is a page nobody can use (§10).
                    -->
                    <div v-if="sampleRows.length > 0" class="grid gap-1.5">
                        <p class="text-sm font-medium">
                            As primeiras linhas do seu ficheiro
                        </p>
                        <div
                            class="overflow-x-auto rounded-md border border-border"
                        >
                            <table class="w-full text-xs">
                                <thead class="bg-muted/50">
                                    <tr>
                                        <th
                                            class="px-2 py-1 text-left font-medium text-muted-foreground"
                                        >
                                            Linha
                                        </th>
                                        <!--
                                          Named by what the teacher wrote, and by
                                          position when two columns share a
                                          heading. Never the internal key — a
                                          source key on screen is the importer
                                          explaining its own bookkeeping (§12).
                                        -->
                                        <th
                                            v-for="column in sheetColumns"
                                            :key="column.letter"
                                            class="px-2 py-1 text-left font-medium"
                                            :class="
                                                column.letter ===
                                                form.table.student_column
                                                    ? 'text-primary'
                                                    : ''
                                            "
                                        >
                                            {{ columnName(column.letter) }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="row in sampleRows"
                                        :key="row.row"
                                        class="border-t border-border"
                                        :class="
                                            row.row === form.table.header_row
                                                ? 'bg-primary/5 font-medium'
                                                : ''
                                        "
                                    >
                                        <td
                                            class="px-2 py-1 text-muted-foreground"
                                        >
                                            {{ row.row }}
                                            <span
                                                v-if="
                                                    row.row ===
                                                    form.table.header_row
                                                "
                                                class="text-[10px]"
                                                >· títulos</span
                                            >
                                        </td>
                                        <td
                                            v-for="(cell, index) in row.cells"
                                            :key="index"
                                            class="px-2 py-1"
                                            :class="
                                                sheetColumns[index]?.letter ===
                                                form.table.student_column
                                                    ? 'text-primary'
                                                    : ''
                                            "
                                        >
                                            <span
                                                v-if="cell.is_formula"
                                                class="text-amber-700"
                                                >fórmula</span
                                            >
                                            <span v-else>{{
                                                cell.text ?? ''
                                            }}</span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <template v-if="!mustChooseSheet">
                        <!-- C. Who the students are. -->
                        <div class="grid gap-1.5">
                            <label
                                for="table-student-column"
                                class="text-sm font-medium"
                                >Qual coluna tem o nome dos alunos?</label
                            >
                            <select
                                id="table-student-column"
                                v-model="form.table.student_column"
                                class="h-9 w-full max-w-sm rounded-md border border-input bg-background px-3 text-sm"
                            >
                                <option :value="null">— Por escolher —</option>
                                <option
                                    v-for="column in sheetColumns"
                                    :key="column.letter"
                                    :value="column.letter"
                                >
                                    {{ columnName(column.letter) }}
                                </option>
                            </select>
                            <p class="text-xs text-muted-foreground">
                                O nome é comparado exatamente com os alunos da
                                turma. Nada é associado por aproximação.
                            </p>
                        </div>

                        <!--
                          D. What to import. Asked here rather than two steps
                          later, because the answer decides which columns the
                          teacher is about to pick (§9).
                        -->
                        <fieldset class="grid gap-2">
                            <legend class="text-sm font-medium">
                                Que tipo de resultados quer importar?
                            </legend>
                            <label
                                v-for="option in granularities"
                                :key="option.value"
                                class="flex items-start gap-2 text-sm"
                            >
                                <input
                                    v-model="form.result_mode"
                                    type="radio"
                                    :value="option.value"
                                    class="mt-0.5"
                                />
                                <span>{{ option.label }}</span>
                            </label>
                        </fieldset>

                        <!-- E. Which columns carry the marks. -->
                        <fieldset class="grid gap-2">
                            <legend class="text-sm font-medium">
                                {{
                                    simple
                                        ? 'Em que coluna está a classificação?'
                                        : 'Que colunas têm resultados?'
                                }}
                            </legend>
                            <div class="flex flex-wrap gap-2">
                                <label
                                    v-for="column in resultColumnChoices"
                                    :key="column.letter"
                                    class="inline-flex items-center gap-2 rounded-md border px-3 py-1.5 text-sm"
                                    :class="
                                        form.table.result_columns.includes(
                                            column.letter,
                                        )
                                            ? 'border-primary/40 bg-primary/10'
                                            : 'border-border bg-background'
                                    "
                                >
                                    <input
                                        :type="simple ? 'radio' : 'checkbox'"
                                        :checked="
                                            form.table.result_columns.includes(
                                                column.letter,
                                            )
                                        "
                                        @change="
                                            simple
                                                ? (form.table.result_columns = [
                                                      column.letter,
                                                  ])
                                                : toggleResultColumn(
                                                      column.letter,
                                                  )
                                        "
                                    />
                                    <span>{{ columnName(column.letter) }}</span>
                                    <span
                                        v-if="column.has_formula"
                                        class="text-xs text-amber-700"
                                        >· fórmulas</span
                                    >
                                </label>
                            </div>
                        </fieldset>

                        <!--
                          F. Points or percentage, and out of what. Asked, never
                          inferred: 14 is not 14 em 20 until somebody says so,
                          and the best mark in the class is not the cotação
                          (§14).
                        -->
                        <div v-if="simple" class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-1.5">
                                <label
                                    for="table-value-kind"
                                    class="text-sm font-medium"
                                    >Os valores dessa coluna são</label
                                >
                                <select
                                    id="table-value-kind"
                                    v-model="form.table.value_kind"
                                    class="h-9 rounded-md border border-input bg-background px-3 text-sm"
                                >
                                    <option value="points">
                                        Pontos (ex.: 14 em 20)
                                    </option>
                                    <option value="percentage">
                                        Percentagem (ex.: 70%)
                                    </option>
                                </select>
                            </div>

                            <div
                                v-if="form.table.value_kind === 'points'"
                                class="grid gap-1.5"
                            >
                                <label
                                    for="table-maximum"
                                    class="text-sm font-medium"
                                    >Cotação máxima</label
                                >
                                <input
                                    id="table-maximum"
                                    v-model="form.table.overall_maximum"
                                    inputmode="decimal"
                                    placeholder="20"
                                    class="h-9 rounded-md border border-input bg-background px-3 text-sm"
                                />
                                <p class="text-xs text-muted-foreground">
                                    O LÁPIS não deduz o máximo a partir da melhor
                                    nota da turma.
                                </p>
                            </div>
                        </div>

                        <!--
                          Everything the system already answered safely, out of
                          the way but never hidden: the heading row comes with a
                          suggestion, and a teacher whose file is unusual has to
                          be able to correct it (§10).
                        -->
                        <details class="rounded-md border border-border">
                            <summary
                                class="cursor-pointer px-3 py-2 text-xs font-medium text-muted-foreground select-none"
                            >
                                Alterar interpretação da folha
                            </summary>
                            <div
                                class="grid gap-4 border-t border-border px-3 py-3 sm:grid-cols-2"
                            >
                                <div class="grid gap-1.5">
                                    <label
                                        for="table-header-row"
                                        class="text-sm font-medium"
                                        >Linha com os títulos das colunas</label
                                    >
                                    <select
                                        id="table-header-row"
                                        v-model.number="form.table.header_row"
                                        class="h-9 rounded-md border border-input bg-background px-3 text-sm"
                                    >
                                        <option :value="null">
                                            — Por escolher —
                                        </option>
                                        <option
                                            v-for="row in headerRowChoices"
                                            :key="row"
                                            :value="row"
                                        >
                                            Linha {{ row }}
                                        </option>
                                    </select>
                                    <p class="text-xs text-muted-foreground">
                                        Os alunos são lidos a partir da linha
                                        seguinte.
                                    </p>
                                </div>

                                <div v-if="!simple" class="grid gap-1.5">
                                    <label
                                        for="table-total-column"
                                        class="text-sm font-medium"
                                        >Coluna com o total do ficheiro</label
                                    >
                                    <select
                                        id="table-total-column"
                                        v-model="form.table.total_column"
                                        class="h-9 rounded-md border border-input bg-background px-3 text-sm"
                                    >
                                        <option :value="null">
                                            — Nenhuma —
                                        </option>
                                        <option
                                            v-for="column in resultColumnChoices"
                                            :key="column.letter"
                                            :value="column.letter"
                                        >
                                            {{ columnName(column.letter) }}
                                        </option>
                                    </select>
                                    <p class="text-xs text-muted-foreground">
                                        Serve para conferir contas. Nunca
                                        substitui o cálculo do LÁPIS.
                                    </p>
                                </div>
                            </div>
                        </details>
                    </template>

                    <!--
                      The action, and what is still missing before it can be
                      taken. A button that is merely disabled leaves the teacher
                      hunting for the reason; the sentence beside it names the
                      next answer required (§11).
                    -->
                    <div class="flex flex-wrap items-center gap-3 border-t border-border pt-4">
                        <Button
                            type="button"
                            :disabled="!sheetIsDescribed || form.processing"
                            @click="readSheetAgain"
                        >
                            Ler resultados
                        </Button>
                        <p
                            class="text-xs"
                            :class="
                                sheetIsDescribed
                                    ? 'text-muted-foreground'
                                    : 'text-amber-700'
                            "
                        >
                            {{ whatIsStillMissing }}
                        </p>
                    </div>
                </div>
            </section>

            <!--
              What was read, and only once there is something to say about it.
              A sheet nobody has described yet has no students and no questions;
              «0 alunos» about it reads as a finding rather than as a silence.

              The filename is deliberately NOT repeated here. It is already in
              the page heading, two lines above, and it was appearing a third
              time as the suggested title derived from it — three copies of one
              string turn a useful reassurance into noise (§2).
            -->
            <div
                v-if="resultsAreRead"
                class="rounded-md border border-border bg-muted/30 px-4 py-3 text-sm"
            >
                <p>
                    <strong>{{ preview.counts.students_in_file }}</strong>
                    {{
                        preview.counts.students_in_file === 1
                            ? 'aluno no ficheiro'
                            : 'alunos no ficheiro'
                    }}
                    <template v-if="statesParticipation">
                        ·
                        <strong>{{
                            preview.counts.students_in_file -
                            preview.counts.non_participants
                        }}</strong>
                        participaram ·
                        <strong>{{ preview.counts.non_participants }}</strong>
                        não participaram
                    </template>
                    <template v-if="!simple && preview.counts.questions > 0">
                        ·
                        <strong>{{ preview.counts.questions }}</strong>
                        {{ isTabular ? 'colunas de resultados' : 'perguntas' }}
                    </template>
                </p>
            </div>

            <!--
              The class, once there is a class to show.

              An empty table with four headings, on a sheet whose tab has not
              been chosen yet, does not read as «nothing here yet» — it reads as
              «we looked and found nobody», which is a different and alarming
              claim. Until the sheet has been read there is only one thing on
              this screen: the panel that reads it (§8).
            -->
            <div
                v-if="resultsAreRead"
                class="overflow-x-auto rounded-lg border border-border"
            >
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-3 py-2 font-medium">
                                Aluno no ficheiro
                            </th>
                            <th class="px-3 py-2 font-medium">
                                Aluno no LÁPIS
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                {{ sourceResultLabel }}
                            </th>
                            <!--
                              «Respostas» is a Plickers idea: it counts how many
                              questions each student answered. Elsewhere this
                              column holds the per-item breakdown, and in the
                              global mode of a source that counts nothing it
                              would hold «0 certas · 0 respondidas» — a sentence
                              about a file that never said either thing (§6).
                            -->
                            <th
                                v-if="statesParticipation || !simple"
                                class="px-3 py-2 font-medium"
                            >
                                {{ statesParticipation ? 'Respostas' : 'Resultados' }}
                            </th>
                            <th class="px-3 py-2 font-medium">Estado</th>
                        </tr>
                    </thead>
                    <!-- Source order preserved: the file lists the class as the
                         teacher's cards are numbered, and re-sorting would break
                         a reading they already know (§9). -->
                    <tbody class="divide-y divide-border">
                        <tr
                            v-for="student in preview.students"
                            :key="student.source_key"
                            class="hover:bg-muted/20"
                        >
                            <td class="px-3 py-2 whitespace-nowrap">
                                <span class="font-medium">{{
                                    student.display_name ?? '—'
                                }}</span>
                                <span
                                    v-if="student.card_number"
                                    class="block text-xs text-muted-foreground"
                                >
                                    cartão {{ student.card_number }}
                                </span>
                            </td>
                            <td class="px-3 py-2">
                                <!--
                                  Nothing is ever pre-selected that the server
                                  did not match. An unmatched row opens on
                                  «— Por associar —» and stays there until the
                                  teacher chooses: a dropdown that helpfully
                                  lands on the first student is how marks end up
                                  on the wrong child (§6).
                                -->
                                <select
                                    v-model="form.students[student.source_key]"
                                    :aria-label="`Aluno do LÁPIS para ${student.display_name ?? student.source_key}`"
                                    class="h-8 w-full min-w-56 rounded-md border border-input bg-transparent px-2 text-sm"
                                >
                                    <option :value="undefined">
                                        — Por associar —
                                    </option>
                                    <option :value="null">
                                        Ignorar esta linha
                                    </option>
                                    <optgroup
                                        v-if="student.suggestions.length"
                                        label="Sugestões"
                                    >
                                        <option
                                            v-for="suggestion in student.suggestions"
                                            :key="`s-${suggestion.id}`"
                                            :value="suggestion.id"
                                        >
                                            {{ suggestion.label }}
                                        </option>
                                    </optgroup>
                                    <optgroup label="Todos os alunos da turma">
                                        <option
                                            v-for="candidate in student.candidates"
                                            :key="candidate.id"
                                            :value="candidate.id"
                                        >
                                            {{ candidate.label }}
                                        </option>
                                    </optgroup>
                                </select>
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                <!-- Null is «—», never 0%: the platform gave no
                                     score, which is not the same as a zero (§5). -->
                                <span
                                    :class="
                                        student.source_score === null
                                            ? 'text-muted-foreground'
                                            : 'font-medium'
                                    "
                                >
                                    {{ student.source_score ?? '—' }}
                                </span>
                            </td>
                            <td
                                v-if="statesParticipation || !simple"
                                class="px-3 py-2 whitespace-nowrap text-muted-foreground"
                            >
                                <!-- The sections, when the file states them:
                                     four numbers a teacher recognises, rather
                                     than twenty-five they never asked to see
                                     (§23). -->
                                <template v-if="student.group_results?.length">
                                    <span
                                        v-for="result in student.group_results"
                                        :key="result.source_key"
                                        class="mr-2 inline-block text-xs"
                                    >
                                        {{ result.label }}:
                                        <strong
                                            :class="
                                                result.earned === null
                                                    ? 'text-amber-700'
                                                    : ''
                                            "
                                            >{{ result.earned ?? '—' }}</strong
                                        >/{{ result.possible }}
                                    </span>
                                </template>
                                <template v-else-if="statesParticipation">{{
                                    attempts(student)
                                }}</template>
                                <template v-else>—</template>
                            </td>
                            <!--
                              Two independent facts in one cell, and they must
                              not be read as one: whether the platform recorded
                              any participation, and whether we know who this is
                              (§9). A student can perfectly well be matched by
                              exact name AND have taken no part.
                            -->
                            <td class="px-3 py-2 whitespace-nowrap">
                                <span
                                    v-if="statesParticipation && !student.participated"
                                    class="block text-xs text-amber-700"
                                >
                                    Não participou nesta aplicação
                                </span>
                                <component
                                    :is="statusIcon[student.status]"
                                    class="inline size-3.5"
                                    aria-hidden="true"
                                />
                                {{ matchLabel(student) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p v-if="resultsAreRead" class="text-xs text-muted-foreground">
                Alunos da turma que não constem do ficheiro ficam por avaliar —
                não recebem zero nem falta. Uma resposta em branco também não é
                uma resposta errada.
            </p>

            <!--
              Offering «Guardar e continuar» before the sheet has been read
              presents an unfinished step as a finished one. There is nothing to
              save yet, and pressing it would take the teacher forward from a
              screen that has not done its job (§8).
            -->
            <div v-if="resultsAreRead" class="flex flex-wrap items-center gap-3">
                <button
                    type="button"
                    class="rounded-md bg-primary px-5 py-2.5 text-sm font-medium text-primary-foreground disabled:opacity-50"
                    :disabled="undecidedStudents > 0 || form.processing"
                    @click="save(3)"
                >
                    Guardar e continuar
                </button>
                <span
                    v-if="undecidedStudents > 0"
                    class="text-xs text-muted-foreground"
                >
                    Faltam decidir {{ undecidedStudents }} alunos: escolha o
                    aluno do LÁPIS ou ignore a linha.
                </span>
            </div>
        </section>

        <!-- ============================ PASSO 3 — CONFIGURAR AVALIAÇÃO ========== -->
        <section v-if="step === 3" class="space-y-5">
            <div>
                <h2 class="text-base font-semibold">Configurar avaliação</h2>
                <p class="text-sm text-muted-foreground">
                    <template v-if="simple">
                        Diga onde entra a classificação que o
                        {{ preview.source_label }} já calculou.
                    </template>
                    <template v-else
                        >Defina onde estes resultados entram e quanto vale cada
                        pergunta.</template
                    >
                </p>
            </div>

            <fieldset class="space-y-2">
                <legend class="text-sm font-medium">
                    O que fazer com estes resultados
                </legend>
                <label class="flex items-center gap-2 text-sm">
                    <input
                        v-model="form.mode"
                        type="radio"
                        value="create_new"
                    />
                    Criar uma nova avaliação
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input
                        v-model="form.mode"
                        type="radio"
                        value="associate_existing"
                    />
                    Associar a uma avaliação existente
                </label>
            </fieldset>

            <div
                v-if="creating"
                class="grid gap-4 rounded-md border border-border p-4 sm:grid-cols-2"
            >
                <div class="space-y-1.5">
                    <label for="i-title" class="text-sm font-medium"
                        >Designação</label
                    >
                    <input
                        id="i-title"
                        v-model="form.instrument.title"
                        type="text"
                        class="h-10 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                    />
                </div>
                <div class="space-y-1.5">
                    <label for="i-date" class="text-sm font-medium"
                        >Data de aplicação</label
                    >
                    <input
                        id="i-date"
                        v-model="form.instrument.applied_on"
                        type="date"
                        class="h-10 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                    />
                    <p class="text-xs text-muted-foreground">
                        O ficheiro não indica a data. É ela que decide a que
                        alunos a avaliação se aplica.
                    </p>
                </div>
                <div class="space-y-1.5">
                    <label for="i-type" class="text-sm font-medium">Tipo</label>
                    <select
                        id="i-type"
                        v-model="form.instrument.instrument_type_id"
                        class="h-10 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                    >
                        <option :value="null">—</option>
                        <option
                            v-for="type in catalogue.instrument_types"
                            :key="type.id"
                            :value="type.id"
                        >
                            {{ type.name }}
                        </option>
                    </select>
                </div>
                <div class="space-y-1.5">
                    <label for="i-purpose" class="text-sm font-medium"
                        >Finalidade</label
                    >
                    <select
                        id="i-purpose"
                        v-model="form.instrument.purpose"
                        class="h-10 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                    >
                        <option value="diagnostic">Diagnóstica</option>
                        <option value="formative">Formativa</option>
                        <option value="summative">Sumativa</option>
                    </select>
                </div>

                <!--
                  Filled in from the date rather than asked for. It stays visible
                  and editable, because the alternative — deciding it silently —
                  would hide the one field whose absence used to make the Save
                  button impossible to press (§10).
                -->
                <div class="space-y-1.5">
                    <label for="i-period" class="text-sm font-medium"
                        >Período</label
                    >
                    <select
                        id="i-period"
                        v-model="form.instrument.academic_period_id"
                        class="h-10 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                    >
                        <option :value="null">—</option>
                        <option
                            v-for="period in catalogue.periods"
                            :key="period.id"
                            :value="period.id"
                        >
                            {{ period.label }}
                        </option>
                    </select>
                    <p
                        v-if="catalogue.periods.length === 0"
                        class="text-xs text-destructive"
                    >
                        Esta turma não tem períodos definidos no ano letivo.
                    </p>
                    <p
                        v-else-if="derivedPeriod"
                        class="text-xs text-muted-foreground"
                    >
                        Determinado pela data de aplicação.
                    </p>
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <input
                        v-model="form.instrument.counts_toward_classification"
                        type="checkbox"
                    />
                    Conta para a classificação
                </label>

                <!-- The one pedagogical decision the simple path asks for: what
                     this result is evidence of. Never inferred from the text of
                     the questions — the teacher chooses (§6). -->
                <div v-if="simple" class="space-y-1.5 sm:col-span-2">
                    <label for="i-domain" class="text-sm font-medium"
                        >Domínio avaliado</label
                    >
                    <select
                        id="i-domain"
                        v-model="overallDomainId"
                        class="h-10 w-full rounded-md border border-input bg-transparent px-3 text-sm sm:max-w-xs"
                    >
                        <option :value="null">—</option>
                        <option
                            v-for="domain in catalogue.domains"
                            :key="domain.id"
                            :value="domain.id"
                        >
                            {{ domain.name }}
                        </option>
                    </select>
                    <p class="text-xs text-muted-foreground">
                        <template
                            v-if="form.instrument.counts_toward_classification"
                        >
                            É o domínio para o qual este resultado conta como
                            evidência.
                        </template>
                        <template v-else>
                            Opcional, porque esta avaliação não conta para a
                            classificação.
                        </template>
                    </p>
                </div>
            </div>

            <div v-else class="space-y-3 rounded-md border border-border p-4">
                <div class="space-y-1.5">
                    <label for="i-existing" class="text-sm font-medium"
                        >Avaliação</label
                    >
                    <select
                        id="i-existing"
                        v-model="form.instrument_id"
                        class="h-10 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                    >
                        <option :value="null">—</option>
                        <option
                            v-for="row in preview.eligible_instruments"
                            :key="row.id"
                            :value="row.id"
                        >
                            {{ row.title }} · {{ row.applied_on }} ·
                            {{ row.status_label }}
                        </option>
                    </select>
                    <p class="text-xs text-muted-foreground">
                        Só aparecem avaliações desta turma onde ainda é possível
                        registar classificações. Uma correção já concluída tem
                        de ser reaberta primeiro, na própria grelha.
                    </p>
                </div>

                <!-- Simple mode onto an existing evaluation: one question receives
                     the global result, at the cotação that evaluation already
                     declares. No question structure is required of the file. -->
                <div
                    v-if="simple && chosenInstrument"
                    class="space-y-1.5 border-t border-border pt-3"
                >
                    <label for="i-overall-item" class="text-sm font-medium"
                        >Onde entra o resultado global</label
                    >
                    <select
                        id="i-overall-item"
                        v-model="form.overall_item_id"
                        class="h-10 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                    >
                        <option :value="null">—</option>
                        <option
                            v-for="target in chosenInstrument.items"
                            :key="target.id"
                            :value="target.id"
                        >
                            {{ target.group ? target.group + ' · ' : ''
                            }}{{ target.code }} ({{ target.points_possible }}
                            pontos)
                        </option>
                    </select>
                    <p class="text-xs text-muted-foreground">
                        O resultado é convertido para a cotação desta pergunta.
                        O domínio é o que a avaliação já tem.
                    </p>
                </div>
            </div>

            <!--
              How much of the file becomes assessment. Three granularities, one
              of which is offered only when the file states sections at all.

              Asked here for a source that explains itself, and NOT for a sheet
              the teacher describes — that one answered it on step 2, while
              choosing which columns to read, because there the two questions are
              the same question (§9). Asking twice would let the two answers
              disagree on screen while being one field underneath.
            -->
            <div class="space-y-3 rounded-md border border-border p-4">
                <fieldset v-if="!isTabular" class="space-y-2">
                    <legend class="text-sm font-medium">
                        O que importar deste ficheiro
                    </legend>
                    <label
                        v-for="option in granularities"
                        :key="option.value"
                        class="flex items-start gap-2 text-sm"
                    >
                        <input
                            v-model="form.result_mode"
                            type="radio"
                            :value="option.value"
                            class="mt-0.5"
                        />
                        <span>
                            {{ option.label }}
                            <span
                                class="mt-0.5 block text-xs text-muted-foreground"
                            >
                                {{ option.hint }}
                            </span>
                        </span>
                    </label>
                </fieldset>

                <!--
                  For a sheet the teacher described, the choice was made on step
                  2. Said back to them here rather than silently omitted, so the
                  box does not open on a domain table with no stated reason.
                -->
                <p v-else class="text-sm">
                    <span class="text-muted-foreground">A importar:</span>
                    <strong>{{ granularityLabel }}</strong>
                    <span class="block text-xs text-muted-foreground">
                        Para mudar, volte a «Alunos e resultados».
                    </span>
                </p>

                <!--
                  Group to domain, one row each. The group is where a question
                  sits on the page; the domain is what it assesses. A source
                  that names a section «Leitura» has still said nothing about
                  curriculum, so nothing is pre-filled (§4).
                -->
                <div
                    v-if="grouped && creating"
                    class="space-y-2 border-t border-border pt-3"
                >
                    <h3 class="text-sm font-medium">Domínio de cada grupo</h3>
                    <div
                        class="overflow-x-auto rounded-md border border-border"
                    >
                        <table class="w-full text-sm">
                            <thead class="bg-muted/50 text-left">
                                <tr>
                                    <th class="px-3 py-2 font-medium">Grupo</th>
                                    <th
                                        class="px-3 py-2 text-right font-medium"
                                    >
                                        Cotação
                                    </th>
                                    <th class="px-3 py-2 font-medium">
                                        Domínio avaliado
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                <tr
                                    v-for="group in preview.groups"
                                    :key="group.source_key"
                                >
                                    <td
                                        class="px-3 py-1.5 font-medium whitespace-nowrap"
                                    >
                                        {{ group.label ?? '—' }}
                                        <span
                                            class="block text-xs font-normal text-muted-foreground"
                                        >
                                            {{ group.questions }} perguntas
                                        </span>
                                    </td>
                                    <td
                                        class="px-3 py-1.5 text-right tabular-nums"
                                    >
                                        {{ group.points_possible }}
                                    </td>
                                    <td class="px-3 py-1.5">
                                        <select
                                            :value="
                                                domainOfGroup(group.source_key)
                                            "
                                            :aria-label="`Domínio de ${group.label ?? group.source_key}`"
                                            class="h-8 w-full min-w-48 rounded-md border border-input bg-transparent px-2 text-sm"
                                            @change="
                                                setDomainOfGroup(
                                                    group.source_key,
                                                    (
                                                        $event.target as HTMLSelectElement
                                                    ).value,
                                                )
                                            "
                                        >
                                            <option value="">
                                                — Por escolher —
                                            </option>
                                            <option
                                                v-for="domain in catalogue.domains"
                                                :key="domain.id"
                                                :value="domain.id"
                                            >
                                                {{ domain.name }}
                                            </option>
                                        </select>
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot class="border-t border-border">
                                <tr>
                                    <td
                                        class="px-3 py-2 text-xs text-muted-foreground"
                                    >
                                        Total
                                    </td>
                                    <td
                                        class="px-3 py-2 text-right text-xs font-medium tabular-nums"
                                    >
                                        {{
                                            preview.reconciliation.groups_total
                                        }}
                                    </td>
                                    <td
                                        class="px-3 py-2 text-xs text-muted-foreground"
                                    >
                                        <span
                                            v-if="
                                                preview.reconciliation
                                                    .maximum_agrees
                                            "
                                        >
                                            confere com o total do ficheiro
                                        </span>
                                        <span v-else class="text-amber-700">
                                            o ficheiro declara
                                            {{
                                                preview.reconciliation
                                                    .source_total
                                            }}
                                        </span>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Vários grupos podem contar para o mesmo domínio. O grupo
                        é a estrutura do teste; o domínio é o que ele avalia.
                    </p>
                </div>

                <p v-if="simple" class="text-xs text-muted-foreground">
                    É importada a classificação que o
                    {{ preview.source_label }} já calculou para cada aluno — sem
                    ter de definir cotações.
                </p>

                <template v-if="detailed">
                    <div class="space-y-3 border-t border-border pt-3">
                        <h3 class="text-sm font-medium">
                            Cotação das perguntas
                        </h3>
                        <p class="text-xs text-muted-foreground">
                            O {{ preview.source_label }} não fornece uma cotação
                            por pergunta. Defina-a antes de importar — nada é
                            assumido por omissão.
                        </p>
                        <div
                            v-if="creating"
                            class="flex flex-wrap items-end gap-4"
                        >
                            <div class="space-y-1.5">
                                <label
                                    for="uniform-points"
                                    class="text-xs font-medium"
                                    >Mesma cotação para todas</label
                                >
                                <!-- Applied on change, not on a button: `change`
                                     fires when the value is committed, which is
                                     exactly when the teacher considers it said. -->
                                <input
                                    id="uniform-points"
                                    v-model="uniformPoints"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    class="h-9 w-28 rounded-md border border-input bg-transparent px-2 text-sm"
                                    @change="applyUniformPoints"
                                />
                            </div>
                            <div class="space-y-1.5">
                                <label
                                    for="uniform-domain"
                                    class="text-xs font-medium"
                                    >Mesmo domínio para todas</label
                                >
                                <select
                                    id="uniform-domain"
                                    v-model="uniformDomain"
                                    class="h-9 rounded-md border border-input bg-transparent px-2 text-sm"
                                    @change="applyUniformDomain"
                                >
                                    <option :value="null">—</option>
                                    <option
                                        v-for="domain in catalogue.domains"
                                        :key="domain.id"
                                        :value="domain.id"
                                    >
                                        {{ domain.name }}
                                    </option>
                                </select>
                            </div>
                            <p
                                v-if="uniformNotice"
                                class="text-xs text-muted-foreground"
                                role="status"
                                aria-live="polite"
                            >
                                {{ uniformNotice }}
                            </p>
                        </div>
                        <p
                            v-if="creating"
                            class="text-xs text-muted-foreground"
                        >
                            O ficheiro não indica domínios curriculares e o
                            LÁPIS não os infere pelo texto das perguntas.
                        </p>
                    </div>

                    <div class="rounded-md border border-border">
                        <button
                            type="button"
                            class="flex w-full items-center justify-between px-4 py-3 text-left text-sm font-medium"
                            :aria-expanded="showQuestions"
                            aria-controls="estrutura-do-teste"
                            @click="showQuestions = !showQuestions"
                        >
                            <span
                                >Estrutura do teste —
                                {{ preview.counts.questions }} perguntas</span
                            >
                            <span class="text-xs text-muted-foreground">
                                {{
                                    showQuestions
                                        ? 'Ocultar'
                                        : 'Ver perguntas e respostas corretas'
                                }}
                            </span>
                        </button>

                        <div
                            v-show="showQuestions"
                            id="estrutura-do-teste"
                            class="overflow-x-auto border-t border-border"
                        >
                            <table class="w-full text-sm">
                                <thead class="text-left">
                                    <tr>
                                        <th class="px-4 py-2 font-medium">
                                            Código
                                        </th>
                                        <th class="px-4 py-2 font-medium">
                                            Pergunta
                                        </th>
                                        <th class="px-4 py-2 font-medium">
                                            Correta
                                        </th>
                                        <th class="px-4 py-2 font-medium">
                                            Cotação
                                        </th>
                                        <th
                                            v-if="!creating"
                                            class="px-4 py-2 font-medium"
                                        >
                                            Pergunta da avaliação
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    <tr
                                        v-for="item in preview.items"
                                        :key="item.source_key"
                                    >
                                        <td
                                            class="px-4 py-1.5 font-medium whitespace-nowrap"
                                        >
                                            {{ item.code }}
                                        </td>
                                        <!-- The full question goes in the title attribute
                                             rather than on screen; external ids never
                                             appear at all (§13). -->
                                        <td
                                            class="max-w-md truncate px-4 py-1.5"
                                            :title="
                                                item.question_text ?? undefined
                                            "
                                        >
                                            {{ item.label ?? '—' }}
                                        </td>
                                        <td class="px-4 py-1.5">
                                            {{ item.answer_key ?? '—' }}
                                        </td>
                                        <td class="px-4 py-1.5">
                                            <input
                                                v-model="
                                                    form.points[item.source_key]
                                                "
                                                type="number"
                                                min="0"
                                                step="0.01"
                                                :disabled="item.points_locked"
                                                :aria-label="`Cotação da pergunta ${item.code}`"
                                                class="h-8 w-24 rounded-md border border-input bg-transparent px-2 text-sm disabled:opacity-60"
                                            />
                                        </td>
                                        <td
                                            v-if="!creating"
                                            class="px-4 py-1.5"
                                        >
                                            <select
                                                v-model="
                                                    form.items[item.source_key]
                                                "
                                                :aria-label="`Pergunta da avaliação para ${item.code}`"
                                                class="h-8 rounded-md border border-input bg-transparent px-2 text-sm"
                                            >
                                                <option :value="null">—</option>
                                                <option
                                                    v-for="target in chosenInstrument?.items ??
                                                    []"
                                                    :key="target.id"
                                                    :value="target.id"
                                                >
                                                    {{
                                                        target.group
                                                            ? target.group +
                                                              ' · '
                                                            : ''
                                                    }}{{ target.code }}
                                                </option>
                                            </select>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </template>
            </div>

            <div class="space-y-2">
                <button
                    type="button"
                    class="rounded-md bg-primary px-5 py-2.5 text-sm font-medium text-primary-foreground disabled:opacity-50"
                    :disabled="missing.length > 0 || form.processing"
                    @click="save(4)"
                >
                    Guardar e continuar
                </button>
                <!-- Never a generic «falta preencher a configuração»: one line
                     per control that is actually empty (§11). -->
                <ul
                    v-if="missing.length"
                    class="list-inside list-disc text-xs text-muted-foreground"
                >
                    <li v-for="message in missing" :key="message">
                        {{ message }}
                    </li>
                </ul>
            </div>
        </section>

        <!-- ============================ PASSO 4 — REVER E IMPORTAR ============== -->
        <section v-if="step === 4" class="space-y-4">
            <div>
                <h2 class="text-base font-semibold">Rever e importar</h2>
                <p class="text-sm text-muted-foreground">
                    Confirme os resultados antes de os adicionar às avaliações.
                </p>
            </div>

            <!--
              In the simple mode this is the whole review: the evaluation, the
              domain, the students and their results. A teacher must be able to
              confirm without ever having seen the twenty questions (§9).
            -->
            <dl
                class="grid gap-2 rounded-md border border-border p-4 text-sm sm:grid-cols-2"
            >
                <div>
                    <dt class="inline text-muted-foreground">Avaliação:</dt>
                    <dd class="inline">
                        {{
                            creating
                                ? form.instrument.title || '(por definir)'
                                : (chosenInstrument?.title ?? '(por escolher)')
                        }}
                    </dd>
                </div>
                <div v-if="creating">
                    <dt class="inline text-muted-foreground">Tipo:</dt>
                    <dd class="inline">{{ typeName }}</dd>
                </div>
                <div v-if="simple && creating">
                    <dt class="inline text-muted-foreground">Domínio:</dt>
                    <dd class="inline">{{ overallDomainName ?? '—' }}</dd>
                </div>
                <div v-if="grouped" class="sm:col-span-2">
                    <dt class="inline text-muted-foreground">Grupos:</dt>
                    <dd class="inline">
                        <span
                            v-for="group in preview.groups"
                            :key="group.source_key"
                            class="mr-3 inline-block"
                        >
                            {{ group.label }} ({{ group.points_possible }}) →
                            <strong>{{
                                domainNameOfGroup(group.source_key) ?? '—'
                            }}</strong>
                        </span>
                    </dd>
                </div>
                <div>
                    <dt class="inline text-muted-foreground">Turma:</dt>
                    <dd class="inline">{{ correctionImport.class.label }}</dd>
                </div>
                <div>
                    <dt class="inline text-muted-foreground">Alunos:</dt>
                    <dd class="inline">
                        {{ preview.counts.students_in_file }}
                    </dd>
                </div>
                <div v-if="!simple">
                    <dt class="inline text-muted-foreground">
                        {{ isTabular ? 'Colunas:' : 'Perguntas:' }}
                    </dt>
                    <dd class="inline">{{ preview.counts.questions }}</dd>
                </div>
                <div v-if="statesParticipation">
                    <dt class="inline text-muted-foreground">
                        Não participaram:
                    </dt>
                    <dd class="inline">
                        {{ preview.counts.non_participants }}
                    </dd>
                </div>
                <div>
                    <dt class="inline text-muted-foreground">Ignorados:</dt>
                    <dd class="inline">{{ preview.counts.ignored }}</dd>
                </div>
                <div v-if="!simple">
                    <dt class="inline text-muted-foreground">Conflitos:</dt>
                    <dd class="inline">{{ conflicts.length }}</dd>
                </div>
                <div>
                    <dt class="inline text-muted-foreground">Origem:</dt>
                    <dd class="inline">{{ preview.source_label }}</dd>
                </div>
            </dl>

            <div class="overflow-x-auto rounded-lg border border-border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-3 py-2 font-medium">Aluno</th>
                            <th class="px-3 py-2 text-right font-medium">
                                {{ simple ? 'Resultado' : sourceResultLabel }}
                            </th>
                            <th
                                v-if="!simple"
                                class="px-3 py-2 text-right font-medium"
                            >
                                Resultado LÁPIS
                            </th>
                            <th class="px-3 py-2 font-medium">Estado</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr
                            v-for="student in preview.students"
                            :key="student.source_key"
                        >
                            <td class="px-3 py-2 font-medium whitespace-nowrap">
                                {{ student.display_name ?? '—' }}
                            </td>
                            <td
                                class="px-3 py-2 text-right tabular-nums"
                                :class="
                                    student.source_score === null
                                        ? 'text-muted-foreground'
                                        : ''
                                "
                            >
                                {{ student.source_score ?? '—' }}
                            </td>
                            <td
                                v-if="!simple"
                                class="px-3 py-2 text-right tabular-nums"
                                :class="
                                    student.lapis_percentage === null
                                        ? 'text-muted-foreground'
                                        : 'font-medium'
                                "
                            >
                                {{
                                    student.lapis_percentage === null
                                        ? '—'
                                        : `${student.lapis_percentage}%`
                                }}
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap">
                                <span
                                    v-if="statesParticipation && !student.participated"
                                    class="text-amber-700"
                                    >Não participou nesta aplicação</span
                                >
                                <span v-else>{{
                                    statusLabel[student.status]
                                }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- The two numbers side by side, and what LÁPIS does about it: its
                 own arithmetic, always. A source total is provenance (§27). -->
            <p
                v-if="grouped && preview.reconciliation.applicable"
                class="text-xs"
                :class="
                    preview.reconciliation.maximum_agrees &&
                    !preview.reconciliation.students_disagreeing
                        ? 'text-muted-foreground'
                        : 'text-amber-700'
                "
            >
                Cotação total dos grupos:
                <strong>{{ preview.reconciliation.groups_total }}</strong
                >. O ficheiro declara
                <strong>{{ preview.reconciliation.source_total ?? '—' }}</strong
                >.
                <template v-if="preview.reconciliation.students_disagreeing">
                    Há
                    {{ preview.reconciliation.students_disagreeing }} aluno(s)
                    cujo total no ficheiro não coincide com as próprias
                    classificações.
                </template>
                O resultado do LÁPIS resulta sempre das classificações
                importadas, nunca do total da origem.
            </p>

            <p v-if="simple" class="text-xs text-muted-foreground">
                É importada a classificação que o
                {{ preview.source_label }} calculou para cada aluno. Um aluno
                sem resultado fica por avaliar — não recebe zero nem falta.
            </p>
            <p v-else class="text-xs text-muted-foreground">
                O resultado da plataforma é apresentado como referência. O
                resultado LÁPIS reflete as cotações definidas nesta avaliação,
                por isso os dois podem divergir sem que nenhum esteja errado.
            </p>

            <div
                v-if="errors.length"
                class="space-y-1 rounded-md border border-destructive/40 bg-destructive/5 p-4"
            >
                <p class="text-sm font-medium">
                    Por resolver antes de importar
                </p>
                <ul class="list-inside list-disc text-sm">
                    <li
                        v-for="issue in errors"
                        :key="issue.code + issue.message"
                    >
                        {{ issue.message }}
                    </li>
                </ul>
            </div>

            <div
                v-if="warnings.length"
                class="space-y-1 rounded-md border border-amber-300 bg-amber-50 p-4 text-amber-900"
            >
                <p class="text-sm font-medium">Avisos</p>
                <ul class="list-inside list-disc text-sm">
                    <li
                        v-for="issue in warnings"
                        :key="issue.code + issue.message"
                    >
                        {{ issue.message }}
                    </li>
                </ul>
            </div>

            <ul
                v-if="infos.length"
                class="list-inside list-disc text-xs text-muted-foreground"
            >
                <li v-for="issue in infos" :key="issue.code + issue.message">
                    {{ issue.message }}
                </li>
            </ul>

            <div
                class="flex flex-wrap items-center gap-3 border-t border-border pt-4"
            >
                <button
                    type="button"
                    class="rounded-md bg-primary px-5 py-2.5 text-sm font-medium text-primary-foreground disabled:opacity-50"
                    :disabled="!preview.can_confirm"
                    @click="confirmImport"
                >
                    Confirmar importação
                </button>
                <span
                    v-if="!preview.can_confirm"
                    class="text-xs text-muted-foreground"
                    >Ainda há pontos por resolver acima.</span
                >
                <!-- Cancelling lives in the header, reachable from every step. -->
            </div>

            <p class="text-xs text-muted-foreground">
                Depois de importar, a avaliação fica
                <strong>em correção</strong>. Reveja a correção antes de a
                concluir — concluir continua a ser uma decisão sua.
            </p>
        </section>
    </div>
</template>
