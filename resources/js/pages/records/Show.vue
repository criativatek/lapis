<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Pencil, Trash2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import HomeworkGrid from './HomeworkGrid.vue';

type EvidenceRecordRow = {
    ulid: string;
    kind: string;
    kind_label: string;
    enrollment_id: number | null;
    domain_id: number | null;
    disciplinary_severity: string | null;
    disciplinary_severity_label: string | null;
    homework_status: string | null;
    participation_level: string | null;
    activity_evaluation: string | null;
    activity_include_in_report: boolean | null;
    detail_label: string | null;
    description: string;
    student: string | null;
    domain: string | null;
    occurred_at: string;
};

type Kind = { value: string; label: string; group: string; group_label: string };

const props = defineProps<{
    schoolClass: { ulid: string; label: string; subject: string };
    enrollments: { id: number; name: string }[];
    /** Ids of the students who are in the class today. */
    activeEnrollmentIds: number[];
    domains: { id: number; name: string }[];
    kinds: Kind[];
    severities: { value: string; label: string }[];
    periods: { id: number; label: string }[];
    filters: { enrollment_id: number | null; kind: string | null; period_id: number | null };
    records: EvidenceRecordRow[];
}>();

// Fixed lists for the type-specific fields — mirror the backend enums
// (HomeworkStatus/ParticipationLevel/ActivityEvaluation), never fetched:
// they never change independently of a code deploy.
const HOMEWORK_STATUSES = [
    { value: 'done', label: 'Realizado' },
    { value: 'partially_done', label: 'Parcialmente realizado' },
    { value: 'not_done', label: 'Não realizado' },
];
const PARTICIPATION_LEVELS = [
    { value: 'positive', label: 'Positiva' },
    { value: 'adequate', label: 'Adequada' },
    { value: 'reduced', label: 'Reduzida' },
];
const ACTIVITY_EVALUATIONS = [
    { value: 'very_positive', label: 'Muito positiva' },
    { value: 'positive', label: 'Positiva' },
    { value: 'satisfactory', label: 'Satisfatória' },
    { value: 'not_very_positive', label: 'Pouco positiva' },
];

type KindMeta = { descriptionLabel: string; descriptionHint: string; descriptionPlaceholder: string };

// One place per type: the description's label, hint and placeholder. Keeps
// the per-type wording out of the template so a type never needs its own
// v-if just to change a sentence.
const KIND_META: Record<string, KindMeta> = {
    homework: { descriptionLabel: 'Descrição', descriptionHint: 'Registe apenas a informação essencial.', descriptionPlaceholder: 'Ex.: Não realizou os exercícios solicitados.' },
    participation: { descriptionLabel: 'Descrição', descriptionHint: 'Registe apenas a informação essencial.', descriptionPlaceholder: 'Ex.: Participou espontaneamente na análise do texto.' },
    progress: { descriptionLabel: 'Descrição', descriptionHint: 'Registe a evolução observada.', descriptionPlaceholder: 'Ex.: Demonstrou maior autonomia na produção escrita.' },
    difficulty: { descriptionLabel: 'Descrição', descriptionHint: 'Identifique de forma breve a dificuldade observada.', descriptionPlaceholder: 'Ex.: Revela dificuldade na organização das ideias.' },
    incident: { descriptionLabel: 'Descrição', descriptionHint: 'Registe apenas a informação essencial.', descriptionPlaceholder: 'Descreva brevemente o que ocorreu.' },
    positive_behaviour: { descriptionLabel: 'Descrição', descriptionHint: 'Registe apenas a informação essencial.', descriptionPlaceholder: 'Ex.: Ajudou espontaneamente um colega durante a atividade.' },
    support: { descriptionLabel: 'Descrição', descriptionHint: 'Registe apenas a informação essencial.', descriptionPlaceholder: 'Ex.: Apoio individual na organização do texto.' },
    contact: { descriptionLabel: 'Descrição', descriptionHint: 'Registe apenas a informação essencial.', descriptionPlaceholder: 'Ex.: Contacto com o encarregado de educação sobre a participação do aluno.' },
    activity: { descriptionLabel: 'Descrição da atividade', descriptionHint: 'Registe apenas a informação essencial.', descriptionPlaceholder: 'Ex.: Visionamento da peça Leandro, Rei da Helíria.' },
    note: { descriptionLabel: 'Descrição', descriptionHint: 'Registe apenas a informação essencial.', descriptionPlaceholder: 'Ex.: Nota relevante para acompanhamento posterior.' },
    lateness: { descriptionLabel: 'Descrição', descriptionHint: 'Registe apenas a informação essencial.', descriptionPlaceholder: 'Ex.: Chegou atrasado ao início da aula.' },
    missing_material: { descriptionLabel: 'Descrição', descriptionHint: 'Registe apenas a informação essencial.', descriptionPlaceholder: 'Ex.: Não trouxe o manual nem o caderno.' },
};

function metaFor(kind: string): KindMeta {
    return KIND_META[kind] ?? KIND_META.note;
}

// Groups the flat `kinds` list by group_label, preserving arrival order
// (the backend already sends them in EvidenceKind::cases() order) — feeds
// the <optgroup> in both the type selector and the type filter.
const kindGroups = computed(() => {
    const groups: { label: string; kinds: Kind[] }[] = [];

    for (const kind of props.kinds) {
        let group = groups.find((candidate) => candidate.label === kind.group_label);

        if (!group) {
            group = { label: kind.group_label, kinds: [] };
            groups.push(group);
        }

        group.kinds.push(kind);
    }

    return groups;
});

// Today, in the yyyy-mm-dd shape a date input expects.
const today = new Date().toISOString().slice(0, 10);

type FormData = {
    kind: string;
    disciplinary_severity: string | null;
    homework_status: string | null;
    participation_level: string | null;
    activity_evaluation: string | null;
    activity_include_in_report: boolean | null;
    description: string;
    occurred_at: string;
    enrollment_id: number | null;
    enrollment_ids: number[];
    domain_id: number | null;
};

const form = useForm<FormData>({
    kind: 'note',
    disciplinary_severity: null,
    homework_status: null,
    participation_level: null,
    activity_evaluation: null,
    activity_include_in_report: null,
    description: '',
    occurred_at: today,
    enrollment_id: null,
    enrollment_ids: [],
    domain_id: null,
});

// Creating (never editing — one existing record always targets at most one
// student) can target the whole class or one/many specific students at
// once. A grid beats a dropdown once a class has more than a handful of
// names (§ pedido do professor) — "Selecionar todos" is a shortcut into the
// grid, not a third state: the teacher can still deselect a few afterwards.
type CreateTargetMode = 'whole_class' | 'students';
const createTargetMode = ref<CreateTargetMode>('whole_class');

/**
 * WHO A NEW RECORD MAY NAME: the class as it stands.
 *
 * enrollments still holds everyone who was ever on this roll, because the
 * filter below and the edit form both need them — a record made in November
 * belongs to November's students, and neither finding it nor correcting it may
 * depend on them still being here.
 */
const selectableEnrollments = computed(
    () => props.enrollments.filter((enrollment) => props.activeEnrollmentIds.includes(enrollment.id)),
);

function selectAllEnrollments(): void {
    form.enrollment_ids = selectableEnrollments.value.map((enrollment) => enrollment.id);
}

function clearSelectedEnrollments(): void {
    form.enrollment_ids = [];
}

// Blocks the confusing case of "Alunos" mode with nothing checked silently
// being saved as a whole-class record (the backend treats an empty list
// exactly as "turma inteira" — correct for the toggle default, wrong here).
const canSubmitCreate = computed(() => createTargetMode.value === 'whole_class' || form.enrollment_ids.length > 0);

// "Incluir no relatório" defaults to Sim the moment the teacher picks
// Atividade — a suggestion, not a silent assumption; still fully editable.
watch(
    () => form.kind,
    (kind) => {
        if (kind === 'activity' && form.activity_include_in_report === null) {
            form.activity_include_in_report = true;
        }
    },
);

const editingUlid = ref<string | null>(null);
const isHomeworkGrid = computed(() => editingUlid.value === null && form.kind === 'homework');

function edit(record: EvidenceRecordRow): void {
    editingUlid.value = record.ulid;
    form.clearErrors();
    form.kind = record.kind;
    form.description = record.description;
    form.occurred_at = record.occurred_at.slice(0, 10);
    form.enrollment_id = record.enrollment_id;
    form.domain_id = record.domain_id;
    form.disciplinary_severity = record.disciplinary_severity;
    form.homework_status = record.homework_status;
    form.participation_level = record.participation_level;
    form.activity_evaluation = record.activity_evaluation;
    form.activity_include_in_report = record.activity_include_in_report;
}

function cancelEdit(): void {
    editingUlid.value = null;
    form.reset();
    form.clearErrors();
    createTargetMode.value = 'whole_class';
}

function submit(): void {
    if (editingUlid.value) {
        form.put(`/records/${editingUlid.value}`, {
            preserveScroll: true,
            onSuccess: () => {
                editingUlid.value = null;
                form.reset();
            },
        });

        return;
    }

    if (!canSubmitCreate.value) {
        return;
    }

    form.enrollment_ids = createTargetMode.value === 'whole_class' ? [] : form.enrollment_ids;

    form.post(`/classes/${props.schoolClass.ulid}/records`, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset(
                'description',
                'enrollment_id',
                'enrollment_ids',
                'domain_id',
                'disciplinary_severity',
                'homework_status',
                'participation_level',
                'activity_evaluation',
            );
            createTargetMode.value = 'whole_class';
        },
    });
}

function remove(record: EvidenceRecordRow): void {
    if (confirm('Eliminar este registo?')) {
        router.delete(`/records/${record.ulid}`, { preserveScroll: true });
    }
}

function refreshRecords(): void {
    router.reload({ only: ['records'] });
}

function when(iso: string): string {
    return new Date(iso).toLocaleDateString('pt-PT', { day: '2-digit', month: 'short', year: 'numeric' });
}

const hasRecords = computed(() => props.records.length > 0);

// A neutral, factual count by type for whatever is currently listed (§21) —
// never a judgement ("aluno em risco", "penalização"), just what exists.
const recordSummary = computed(() => {
    if (props.records.length === 0) {
        return null;
    }

    const counts = new Map<string, number>();

    for (const record of props.records) {
        counts.set(record.kind_label, (counts.get(record.kind_label) ?? 0) + 1);
    }

    const parts = Array.from(counts.entries()).map(([label, count]) => `${label} (${count})`);

    return `Registos apresentados: ${parts.join(', ')}.`;
});

const filterEnrollmentId = ref<number | null>(props.filters.enrollment_id);
const filterKind = ref<string | null>(props.filters.kind);
const filterPeriodId = ref<number | null>(props.filters.period_id);

function applyFilters(): void {
    router.get(
        `/classes/${props.schoolClass.ulid}/records`,
        {
            enrollment_id: filterEnrollmentId.value,
            kind: filterKind.value,
            period_id: filterPeriodId.value,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}
</script>

<template>
    <Head :title="`Registos — ${schoolClass.label}`" />

    <div class="mx-auto w-full max-w-3xl space-y-5 p-4">
        <div>
            <Heading :title="`Registos — ${schoolClass.label}`" :description="schoolClass.subject" />
            <Link href="/records" class="text-sm text-muted-foreground hover:underline">← Todas as turmas</Link>
        </div>

        <p class="text-xs text-muted-foreground">Os registos não alteram automaticamente a classificação do aluno.</p>

        <form class="space-y-3 rounded-lg border border-border p-4" @submit.prevent="submit">
            <div class="grid gap-3" :class="isHomeworkGrid ? 'sm:grid-cols-2' : 'sm:grid-cols-3'">
                <label class="text-sm">
                    <span class="mb-1 block text-xs text-muted-foreground">Tipo</span>
                    <select v-model="form.kind" class="w-full rounded-md border border-border bg-background px-2 py-1.5">
                        <optgroup v-for="group in kindGroups" :key="group.label" :label="group.label">
                            <option v-for="kind in group.kinds" :key="kind.value" :value="kind.value">{{ kind.label }}</option>
                        </optgroup>
                    </select>
                    <p class="mt-1 text-xs text-muted-foreground">Escolha a opção que melhor descreve a situação observada.</p>
                </label>
                <label class="text-sm">
                    <span class="mb-1 block text-xs text-muted-foreground">Data</span>
                    <input v-model="form.occurred_at" type="date" class="w-full rounded-md border border-border bg-background px-2 py-1.5" />
                </label>
                <label v-if="!isHomeworkGrid" class="text-sm">
                    <span class="mb-1 block text-xs text-muted-foreground">Aluno</span>

                    <!-- Editing always targets the one student (or none) the
                    record already has — no grid, same select as before. -->
                    <select v-if="editingUlid" v-model="form.enrollment_id" class="w-full rounded-md border border-border bg-background px-2 py-1.5">
                        <option :value="null">Turma inteira</option>
                        <option v-for="enrollment in enrollments" :key="enrollment.id" :value="enrollment.id">{{ enrollment.name }}</option>
                    </select>

                    <template v-else>
                        <div class="flex gap-1">
                            <button
                                type="button"
                                class="rounded-md px-2 py-1 text-xs"
                                :class="createTargetMode === 'whole_class' ? 'bg-accent text-accent-foreground' : 'text-muted-foreground hover:bg-muted/40'"
                                @click="createTargetMode = 'whole_class'"
                            >
                                Turma inteira
                            </button>
                            <button
                                type="button"
                                class="rounded-md px-2 py-1 text-xs"
                                :class="createTargetMode === 'students' ? 'bg-accent text-accent-foreground' : 'text-muted-foreground hover:bg-muted/40'"
                                @click="createTargetMode = 'students'"
                            >
                                Alunos
                            </button>
                        </div>

                        <div v-if="createTargetMode === 'students'" class="mt-1 rounded-md border border-border p-2">
                            <div class="mb-1 flex items-center justify-between text-xs">
                                <button type="button" class="text-primary hover:underline" @click="selectAllEnrollments">Selecionar todos</button>
                                <button type="button" class="text-muted-foreground hover:underline" @click="clearSelectedEnrollments">Limpar seleção</button>
                            </div>
                            <div class="max-h-48 space-y-1 overflow-y-auto">
                                <label v-for="enrollment in selectableEnrollments" :key="enrollment.id" class="flex items-center gap-2 text-sm">
                                    <input v-model="form.enrollment_ids" type="checkbox" :value="enrollment.id" class="rounded border-border" />
                                    {{ enrollment.name }}
                                </label>
                            </div>
                            <p v-if="form.enrollment_ids.length === 0" class="mt-1 text-xs text-muted-foreground">Selecione pelo menos um aluno.</p>
                        </div>
                    </template>

                    <p class="mt-1 text-xs text-muted-foreground">Selecione um ou mais alunos, ou registe para toda a turma.</p>
                    <p v-if="form.errors.enrollment_ids" class="mt-1 text-xs text-red-600">{{ form.errors.enrollment_ids }}</p>
                </label>
            </div>

            <HomeworkGrid
                v-if="isHomeworkGrid"
                :class-ulid="schoolClass.ulid"
                :occurred-at="form.occurred_at"
                @saved="refreshRecords"
            />

            <label v-if="form.kind === 'homework' && editingUlid" class="block text-sm">
                <span class="mb-1 block text-xs text-muted-foreground">Situação</span>
                <select v-model="form.homework_status" class="w-full rounded-md border border-border bg-background px-2 py-1.5">
                    <option :value="null" disabled>Escolher…</option>
                    <option v-for="status in HOMEWORK_STATUSES" :key="status.value" :value="status.value">{{ status.label }}</option>
                </select>
                <p class="mt-1 text-xs text-muted-foreground">Indique apenas o grau de realização do trabalho solicitado.</p>
                <p v-if="form.errors.homework_status" class="mt-1 text-xs text-red-600">{{ form.errors.homework_status }}</p>
            </label>

            <label v-if="form.kind === 'participation'" class="block text-sm">
                <span class="mb-1 block text-xs text-muted-foreground">Participação observada</span>
                <select v-model="form.participation_level" class="w-full rounded-md border border-border bg-background px-2 py-1.5">
                    <option :value="null" disabled>Escolher…</option>
                    <option v-for="level in PARTICIPATION_LEVELS" :key="level.value" :value="level.value">{{ level.label }}</option>
                </select>
                <p class="mt-1 text-xs text-muted-foreground">Considere a participação nas atividades de aprendizagem.</p>
                <p v-if="form.errors.participation_level" class="mt-1 text-xs text-red-600">{{ form.errors.participation_level }}</p>
            </label>

            <label v-if="form.kind === 'progress' || form.kind === 'difficulty'" class="block text-sm">
                <span class="mb-1 block text-xs text-muted-foreground">Domínio relacionado — opcional</span>
                <select v-model="form.domain_id" class="w-full rounded-md border border-border bg-background px-2 py-1.5">
                    <option :value="null">Sem associação a domínio</option>
                    <option v-for="domain in domains" :key="domain.id" :value="domain.id">{{ domain.name }}</option>
                </select>
                <p class="mt-1 text-xs text-muted-foreground">Selecione apenas quando o registo estiver relacionado com uma aprendizagem específica.</p>
            </label>

            <label v-if="form.kind === 'incident'" class="block text-sm">
                <span class="mb-1 block text-xs text-muted-foreground">Gravidade</span>
                <select v-model="form.disciplinary_severity" class="w-full rounded-md border border-border bg-background px-2 py-1.5">
                    <option :value="null" disabled>Escolher…</option>
                    <option v-for="severity in severities" :key="severity.value" :value="severity.value">{{ severity.label }}</option>
                </select>
                <p class="mt-1 text-xs text-muted-foreground">Selecione a ocorrência que melhor corresponde à situação registada.</p>
                <p v-if="form.errors.disciplinary_severity" class="mt-1 text-xs text-red-600">{{ form.errors.disciplinary_severity }}</p>
            </label>

            <div v-if="form.kind === 'activity'" class="grid gap-3 sm:grid-cols-2">
                <label class="block text-sm">
                    <span class="mb-1 block text-xs text-muted-foreground">Avaliação global</span>
                    <select v-model="form.activity_evaluation" class="w-full rounded-md border border-border bg-background px-2 py-1.5">
                        <option :value="null" disabled>Escolher…</option>
                        <option v-for="evaluation in ACTIVITY_EVALUATIONS" :key="evaluation.value" :value="evaluation.value">{{ evaluation.label }}</option>
                    </select>
                    <p class="mt-1 text-xs text-muted-foreground">Faça uma apreciação global do interesse e da participação dos alunos.</p>
                    <p v-if="form.errors.activity_evaluation" class="mt-1 text-xs text-red-600">{{ form.errors.activity_evaluation }}</p>
                </label>
                <label class="block text-sm">
                    <span class="mb-1 block text-xs text-muted-foreground">Incluir no relatório</span>
                    <select v-model="form.activity_include_in_report" class="w-full rounded-md border border-border bg-background px-2 py-1.5">
                        <option :value="true">Sim</option>
                        <option :value="false">Não</option>
                    </select>
                    <p class="mt-1 text-xs text-muted-foreground">Permite recuperar esta atividade na preparação do relatório.</p>
                </label>
            </div>

            <label v-if="!isHomeworkGrid" class="block text-sm">
                <span class="mb-1 block text-xs text-muted-foreground">{{ metaFor(form.kind).descriptionLabel }}</span>
                <textarea
                    v-model="form.description"
                    rows="2"
                    maxlength="1000"
                    class="w-full rounded-md border border-border bg-background px-3 py-2"
                    :placeholder="metaFor(form.kind).descriptionPlaceholder"
                ></textarea>
                <p class="mt-1 text-xs text-muted-foreground">{{ metaFor(form.kind).descriptionHint }}</p>
            </label>
            <p v-if="!isHomeworkGrid && form.errors.description" class="text-xs text-red-600">{{ form.errors.description }}</p>

            <div v-if="!isHomeworkGrid" class="flex items-center justify-end gap-2">
                <button
                    v-if="editingUlid"
                    type="button"
                    class="rounded-md px-3 py-2 text-sm text-muted-foreground hover:underline"
                    @click="cancelEdit"
                >
                    Cancelar
                </button>
                <button
                    type="submit"
                    class="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50"
                    :disabled="form.processing || (!editingUlid && !canSubmitCreate)"
                >
                    {{ editingUlid ? 'Guardar alterações' : 'Adicionar registo' }}
                </button>
            </div>
        </form>

        <div class="flex flex-wrap items-center gap-3 text-sm">
            <label class="flex items-center gap-2">
                <span class="text-xs text-muted-foreground">Aluno</span>
                <select v-model="filterEnrollmentId" class="rounded-md border border-border bg-background px-2 py-1" @change="applyFilters">
                    <option :value="null">Todos</option>
                    <option v-for="enrollment in enrollments" :key="enrollment.id" :value="enrollment.id">{{ enrollment.name }}</option>
                </select>
            </label>
            <label class="flex items-center gap-2">
                <span class="text-xs text-muted-foreground">Tipo</span>
                <select v-model="filterKind" class="rounded-md border border-border bg-background px-2 py-1" @change="applyFilters">
                    <option :value="null">Todos</option>
                    <optgroup v-for="group in kindGroups" :key="group.label" :label="group.label">
                        <option v-for="kind in group.kinds" :key="kind.value" :value="kind.value">{{ kind.label }}</option>
                    </optgroup>
                </select>
            </label>
            <label class="flex items-center gap-2">
                <span class="text-xs text-muted-foreground">Período</span>
                <select v-model="filterPeriodId" class="rounded-md border border-border bg-background px-2 py-1" @change="applyFilters">
                    <option :value="null">Todos</option>
                    <option v-for="period in periods" :key="period.id" :value="period.id">{{ period.label }}</option>
                </select>
            </label>
        </div>

        <p v-if="recordSummary" class="text-xs text-muted-foreground">{{ recordSummary }}</p>

        <EmptyState v-if="!hasRecords" title="Ainda não há registos nesta turma." />

        <ul v-else class="space-y-2">
            <li v-for="record in records" :key="record.ulid" class="flex items-start gap-3 rounded-lg border border-border p-3">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded-full bg-accent px-2 py-0.5 text-xs text-accent-foreground">{{ record.kind_label }}</span>
                        <span v-if="record.detail_label" class="text-xs text-muted-foreground">— {{ record.detail_label }}</span>
                        <span v-if="record.disciplinary_severity_label" class="rounded-full bg-destructive/10 px-2 py-0.5 text-xs text-destructive">{{ record.disciplinary_severity_label }}</span>
                        <span class="text-sm font-medium">{{ record.student ?? 'Turma inteira' }}</span>
                        <span class="ml-auto text-xs text-muted-foreground tabular-nums">{{ when(record.occurred_at) }}</span>
                    </div>
                    <p class="mt-1 text-sm text-muted-foreground">{{ record.description }}</p>
                </div>
                <div class="flex shrink-0 items-center gap-1">
                    <button
                        type="button"
                        class="rounded-md p-1.5 text-muted-foreground hover:bg-muted/40"
                        title="Editar"
                        @click="edit(record)"
                    >
                        <Pencil class="size-4" />
                    </button>
                    <button
                        type="button"
                        class="rounded-md p-1.5 text-muted-foreground hover:bg-muted/40 hover:text-red-600"
                        title="Remover"
                        @click="remove(record)"
                    >
                        <Trash2 class="size-4" />
                    </button>
                </div>
            </li>
        </ul>
    </div>
</template>
