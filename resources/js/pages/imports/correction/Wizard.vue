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

const props = defineProps<{
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

const STEPS = [
    'Origem e ficheiro',
    'Alunos e resultados',
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

const GRANULARITIES = [
    {
        value: 'overall',
        label: 'Resultado global',
        hint: 'Um único resultado por aluno, para um domínio.',
    },
    {
        value: 'per_group',
        label: 'Resultados por grupos',
        hint: 'Um resultado por grupo do teste, cada um para o seu domínio.',
    },
    {
        value: 'per_question',
        label: 'Detalhe por perguntas',
        hint: 'Cada pergunta com a sua cotação e o seu domínio.',
    },
];

const granularities = computed(() =>
    GRANULARITIES.filter(
        (option) => option.value !== 'per_group' || hasGroups.value,
    ),
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
    <Head title="Importar resultados de outra plataforma" />

    <div class="mx-auto w-full max-w-6xl space-y-6 p-4">
        <div>
            <Heading
                title="Importar resultados de outra plataforma"
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
            <div>
                <h2 class="text-base font-semibold">Alunos e resultados</h2>
                <p class="text-sm text-muted-foreground">
                    Confirme os resultados encontrados no ficheiro e a
                    correspondência com os alunos da turma.
                </p>
            </div>

            <!--
              Which file this is, named on the screen. A wizard that shows a
              class without saying where it came from leaves the teacher to
              trust that the right file was read — and that trust is exactly
              what is worth nothing after they have seen it be wrong once (§7).
            -->
            <div
                class="rounded-md border border-border bg-muted/30 px-4 py-3 text-sm"
            >
                <p>
                    <span class="text-muted-foreground">Ficheiro:</span>
                    <strong>{{
                        correctionImport.originalFilename ?? '(sem nome)'
                    }}</strong>
                    <template v-if="preview.suggested_title">
                        <span class="text-muted-foreground">
                            · Título no ficheiro:</span
                        >
                        <strong>{{ preview.suggested_title }}</strong>
                    </template>
                </p>
                <p class="mt-1">
                    <strong>{{ preview.counts.students_in_file }}</strong>
                    alunos no ficheiro ·
                    <strong>{{
                        preview.counts.students_in_file -
                        preview.counts.non_participants
                    }}</strong>
                    participaram ·
                    <strong>{{ preview.counts.non_participants }}</strong> não
                    participaram ·
                    <strong>{{ preview.counts.questions }}</strong> perguntas
                </p>
            </div>

            <div class="overflow-x-auto rounded-lg border border-border">
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
                                Resultado na plataforma
                            </th>
                            <th class="px-3 py-2 font-medium">Respostas</th>
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
                                <template v-else>{{
                                    attempts(student)
                                }}</template>
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
                                    v-if="!student.participated"
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

            <p class="text-xs text-muted-foreground">
                Alunos da turma que não constem do ficheiro ficam por avaliar —
                não recebem zero nem falta. Uma resposta em branco também não é
                uma resposta errada.
            </p>

            <div class="flex flex-wrap items-center gap-3">
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
            -->
            <div class="space-y-3 rounded-md border border-border p-4">
                <fieldset class="space-y-2">
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
                    <dt class="inline text-muted-foreground">Perguntas:</dt>
                    <dd class="inline">{{ preview.counts.questions }}</dd>
                </div>
                <div>
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
                    <dt class="inline text-muted-foreground">Plataforma:</dt>
                    <dd class="inline">{{ preview.source_label }}</dd>
                </div>
            </dl>

            <div class="overflow-x-auto rounded-lg border border-border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-3 py-2 font-medium">Aluno</th>
                            <th class="px-3 py-2 text-right font-medium">
                                {{
                                    simple
                                        ? 'Resultado'
                                        : 'Resultado na plataforma'
                                }}
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
                                    v-if="!student.participated"
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
