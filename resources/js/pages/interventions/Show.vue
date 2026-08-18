<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { CheckCircle2, FileText, Pencil, Trash2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';

type LegalMapping = {
    mode: 'direct' | 'contextual' | 'evaluation_only';
    level: string | null;
    level_label: string | null;
    measure: string | null;
    measure_label: string | null;
    evaluation_adaptation: string | null;
    evaluation_adaptation_label: string | null;
};

type InterventionType = {
    value: string;
    label: string;
    context: string;
    context_label: string;
    requires_description: boolean;
    legal_mapping: LegalMapping | null;
};

type Review = {
    ulid: string;
    reviewed_on: string;
    effectiveness: string | null;
    effectiveness_label: string | null;
    notes: string | null;
};

type Intervention = {
    ulid: string;
    title: string;
    description: string | null;
    intervention_type: string | null;
    context: string | null;
    context_label: string | null;
    target_type: TargetType;
    target_label: string;
    participant_ids: number[];
    domain_relation: DomainRelation;
    domain_id: number | null;
    domain: string | null;
    status: string;
    status_label: string;
    is_closed: boolean;
    started_on: string;
    expected_end_on: string | null;
    concluded_on: string | null;
    available_for_reports: boolean;
    legal_framing: {
        level: string | null;
        level_label: string | null;
        measure: string | null;
        measure_label: string | null;
        evaluation_adaptation: string | null;
        evaluation_adaptation_label: string | null;
        source: string | null;
        source_label: string | null;
    } | null;
    reviews: Review[];
};

type TargetType = 'student' | 'group' | 'class';
type DomainRelation = 'none' | 'specific' | 'all';
type LegalFraming = 'auto' | 'manual' | 'none' | null;

const props = defineProps<{
    schoolClass: { ulid: string; label: string; subject: string };
    enrollments: { id: number; name: string }[];
    /** Ids of the students who are in the class today. */
    activeEnrollmentIds: number[];
    domains: { id: number; name: string }[];
    periods: { id: number; label: string }[];
    types: InterventionType[];
    contexts: { value: string; label: string }[];
    domainRelations: { value: string; label: string }[];
    targetTypes: { value: string; label: string }[];
    supportMeasureLevels: { value: string; label: string; measures: { value: string; label: string }[] }[];
    evaluationAdaptations: { value: string; label: string }[];
    effectivenessOptions: { value: string; label: string }[];
    filters: {
        enrollment_id?: number | null;
        intervention_type?: string | null;
        context?: string | null;
        domain_id?: number | null;
        period_id?: number | null;
        available_for_reports?: boolean | null;
        support_measure_level?: string | null;
    };
    interventions: Intervention[];
}>();

const today = new Date().toISOString().slice(0, 10);

type FormData = {
    target_type: TargetType;
    enrollment_ids: number[];
    intervention_type: string;
    domain_relation: DomainRelation;
    domain_id?: number | null;
    description: string;
    started_on: string;
    available_for_reports: boolean;
    legal_framing: LegalFraming;
    confirm_suggested_framing: boolean;
    support_measure_level: string | null;
    support_measure_code: string | null;
    evaluation_adaptation_code: string | null;
};

const form = useForm<FormData>({
    target_type: 'student',
    enrollment_ids: props.enrollments[0] ? [props.enrollments[0].id] : [],
    intervention_type: props.types[0]?.value ?? '',
    domain_relation: 'none',
    domain_id: null,
    description: '',
    started_on: today,
    available_for_reports: true,
    legal_framing: null,
    confirm_suggested_framing: false,
    support_measure_level: null,
    support_measure_code: null,
    evaluation_adaptation_code: null,
});

const typeGroups = computed(() => {
    const groups: { label: string; types: InterventionType[] }[] = [];

    for (const type of props.types) {
        let group = groups.find((candidate) => candidate.label === type.context_label);

        if (!group) {
            group = { label: type.context_label, types: [] };
            groups.push(group);
        }

        group.types.push(type);
    }

    return groups;
});

const selectedType = computed(() => props.types.find((type) => type.value === form.intervention_type) ?? null);
const selectedMapping = computed(() => selectedType.value?.legal_mapping ?? null);
const selectedMeasureLevel = computed(() => props.supportMeasureLevels.find((level) => level.value === form.support_measure_level) ?? null);
const availableMeasures = computed(() => selectedMeasureLevel.value?.measures ?? []);
const editingUlid = ref<string | null>(null);
/** Who the intervention being edited already names, including students who have left. */
const editingParticipantIds = ref<number[]>([]);

/**
 * WHO THIS FORM MAY NAME.
 *
 * A new intervention is about the class as it stands. Editing one is about the
 * students it already has — including anybody who has since moved class, whose
 * checkbox must still be there or saving the edit would silently drop them
 * from a record nobody asked to change (§3.1, §3.3).
 */
const selectableEnrollments = computed(() => props.enrollments.filter(
    (enrollment) => props.activeEnrollmentIds.includes(enrollment.id)
        || editingParticipantIds.value.includes(enrollment.id),
));
// Where the framing of the intervention being edited came from. A framing the
// teacher chose by hand survives a change of type; one the system derived
// belonged to the old type and must be recomputed.
const editingFramingSource = ref<string | null>(null);
const framingDetails = ref<HTMLDetailsElement | null>(null);

const canSubmit = computed(() => {
    if (form.target_type === 'student') {
        return form.enrollment_ids.length === 1;
    }

    if (form.target_type === 'group') {
        return form.enrollment_ids.length >= 2;
    }

    return true;
});

watch(
    () => form.target_type,
    (targetType) => {
        if (targetType === 'class') {
            form.enrollment_ids = [];
        } else if (targetType === 'student') {
            form.enrollment_ids = form.enrollment_ids.slice(0, 1);
        }
    },
);

watch(
    () => form.intervention_type,
    () => {
        // A hand-picked framing is the teacher's own decision and outlives a
        // change of type — sending no decision lets the server keep it, and the
        // server refuses the edit if the new type unambiguously disagrees.
        if (editingUlid.value && editingFramingSource.value === 'manual') {
            form.legal_framing = null;

            return;
        }

        // Everything else was derived from the type that just changed, so it is
        // recomputed from the new one. Without this, editing an intervention
        // that carried "Medida universal — Diferenciação pedagógica" and
        // switching it to an assessment adaptation left both on screen at once.
        resetAutomaticFraming();
    },
);

watch(
    () => form.support_measure_level,
    () => {
        if (form.support_measure_code && !availableMeasures.value.some((measure) => measure.value === form.support_measure_code)) {
            form.support_measure_code = null;
        }
    },
);

// The two manual sub-forms inside the advanced section. Kept closed unless the
// teacher asks for them, so an ordinary intervention never faces three empty
// selects it has no use for.
const manualMeasureOpen = ref(false);
const manualAdaptationOpen = ref(false);

// The assessment-adaptation field appears on its own for the types that ARE an
// adaptation, and otherwise only on request. It is never driven by the domain:
// "avaliação" is a context, not a subject domain.
const showAdaptationField = computed(() => selectedMapping.value?.mode === 'evaluation_only' || manualAdaptationOpen.value);

/**
 * An assessment-adaptation type shows its catalogue value as plain text until
 * the teacher asks to change it — a select sitting on "Não especificada" would
 * read as if nothing had been recorded, when in fact the adaptation IS the
 * intervention.
 */
const adaptationEditable = ref(false);

/** Whether the current type proposes a support measure of its own. */
const selectedMappingHasMeasure = computed(() => selectedMapping.value?.measure != null);

/** No formal framing proposed and none chosen — plain everyday teaching. */
const isPedagogicalStrategy = computed(() =>
    selectedMapping.value === null && !manualMeasureOpen.value && form.support_measure_level === null,
);

function editAdaptation(): void {
    adaptationEditable.value = true;

    if (form.evaluation_adaptation_code === null && selectedMapping.value?.evaluation_adaptation) {
        form.evaluation_adaptation_code = selectedMapping.value.evaluation_adaptation;
    }

    markFramingManual();
}

function resetAutomaticFraming(): void {
    form.legal_framing = selectedMapping.value ? 'auto' : null;
    form.confirm_suggested_framing = false;
    form.support_measure_level = null;
    form.support_measure_code = null;
    form.evaluation_adaptation_code = null;
    manualMeasureOpen.value = false;
    manualAdaptationOpen.value = false;
    adaptationEditable.value = false;
}

function openFramingDetails(): void {
    if (framingDetails.value) {
        framingDetails.value.open = true;
    }
}

/**
 * Opens the measure sub-form, seeded with whatever the catalogue proposed so
 * "Alterar" starts from the current framing instead of an empty form.
 */
function openManualMeasure(): void {
    manualMeasureOpen.value = true;

    if (form.support_measure_level === null && selectedMapping.value?.level) {
        form.support_measure_level = selectedMapping.value.level;
        form.support_measure_code = selectedMapping.value.measure;
    }

    markFramingManual();
    openFramingDetails();
}

function openManualAdaptation(): void {
    manualAdaptationOpen.value = true;
    // Attaching one by hand always starts from an empty choice, so it is
    // editable straight away.
    adaptationEditable.value = true;
    markFramingManual();
    openFramingDetails();
}

/** Clears the framing entirely — the intervention stays, its framing does not. */
function removeFraming(): void {
    form.legal_framing = 'none';
    form.confirm_suggested_framing = false;
    form.support_measure_level = null;
    form.support_measure_code = null;
    form.evaluation_adaptation_code = null;
    manualMeasureOpen.value = false;
    manualAdaptationOpen.value = false;
}

resetAutomaticFraming();

function selectAllEnrollments(): void {
    form.enrollment_ids = selectableEnrollments.value.map((enrollment) => enrollment.id);
}

function clearSelectedEnrollments(): void {
    form.enrollment_ids = [];
}

function chooseContextualFraming(choice: 'confirm' | 'manual' | 'none'): void {
    if (choice === 'confirm') {
        form.legal_framing = 'auto';
        form.confirm_suggested_framing = true;

        return;
    }

    if (choice === 'none') {
        removeFraming();

        return;
    }

    openManualMeasure();
}

function markFramingManual(): void {
    form.legal_framing = 'manual';
    form.confirm_suggested_framing = false;
}

function edit(intervention: Intervention): void {
    editingUlid.value = intervention.ulid;
    editingFramingSource.value = intervention.legal_framing?.source ?? null;
    form.clearErrors();
    form.target_type = intervention.target_type;
    editingParticipantIds.value = [...intervention.participant_ids];
    form.enrollment_ids = [...intervention.participant_ids];
    form.intervention_type = intervention.intervention_type ?? props.types[0]?.value ?? '';
    form.domain_relation = intervention.domain_relation;
    form.domain_id = intervention.domain_id;
    form.description = intervention.description ?? '';
    form.started_on = intervention.started_on.slice(0, 10);
    form.available_for_reports = intervention.available_for_reports;
    form.legal_framing = intervention.legal_framing?.source === 'manual' ? 'manual' : intervention.legal_framing ? 'auto' : null;
    form.confirm_suggested_framing = intervention.legal_framing?.source === 'system_suggested_confirmed';
    form.support_measure_level = intervention.legal_framing?.level ?? null;
    form.support_measure_code = intervention.legal_framing?.measure ?? null;
    form.evaluation_adaptation_code = intervention.legal_framing?.evaluation_adaptation ?? null;
    // Reveal whichever sub-forms this intervention actually uses, so an
    // existing framing is visible and editable instead of hidden behind a
    // button that looks like it would create a new one.
    manualMeasureOpen.value = intervention.legal_framing?.level != null;
    manualAdaptationOpen.value = intervention.legal_framing?.evaluation_adaptation != null;
    // A stored adaptation is shown in an editable select: it is a value this
    // intervention already has, not a catalogue proposal to be read back.
    adaptationEditable.value = intervention.legal_framing?.evaluation_adaptation != null;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function cancelEdit(): void {
    editingUlid.value = null;
    editingParticipantIds.value = [];
    editingFramingSource.value = null;
    form.reset();
    form.clearErrors();
    resetAutomaticFraming();
}

function submit(): void {
    if (!canSubmit.value) {
        return;
    }

    if (form.target_type === 'class') {
        form.enrollment_ids = [];
    }

    form.transform((data) => {
        const payload: FormData = { ...data, description: data.description.trim() };

        if (data.domain_relation !== 'specific') {
            delete payload.domain_id;
        }

        return payload;
    });

    const options = {
        preserveScroll: true,
        onSuccess: () => {
            editingUlid.value = null;
            editingParticipantIds.value = [];
            editingFramingSource.value = null;
            form.reset();
            resetAutomaticFraming();
        },
    };

    if (editingUlid.value) {
        form.put(`/interventions/${editingUlid.value}`, options);
    } else {
        form.post(`/classes/${props.schoolClass.ulid}/interventions`, options);
    }
}

function setStatus(intervention: Intervention, status: string): void {
    router.patch(`/interventions/${intervention.ulid}`, { status }, { preserveScroll: true });
}

function remove(intervention: Intervention): void {
    if (confirm('Eliminar esta intervenção?')) {
        router.delete(`/interventions/${intervention.ulid}`, { preserveScroll: true });
    }
}

const openReview = ref<string | null>(null);
const reviewForm = useForm<{ reviewed_on: string; effectiveness: string | null; notes: string }>({
    reviewed_on: today,
    effectiveness: null,
    notes: '',
});

function openReviewFor(intervention: Intervention): void {
    openReview.value = intervention.ulid;
    reviewForm.reset();
    reviewForm.reviewed_on = today;
}

function submitReview(intervention: Intervention): void {
    reviewForm.post(`/interventions/${intervention.ulid}/reviews`, {
        preserveScroll: true,
        onSuccess: () => (openReview.value = null),
    });
}

function when(date: string): string {
    return new Date(date).toLocaleDateString('pt-PT', { day: '2-digit', month: 'short', year: 'numeric' });
}

function framingLabel(intervention: Intervention): string {
    const framing = intervention.legal_framing;

    if (!framing) {
        return '';
    }

    return [framing.level_label, framing.measure_label, framing.evaluation_adaptation_label].filter(Boolean).join(' — ');
}

const statusClasses: Record<string, string> = {
    new: 'bg-muted text-muted-foreground',
    in_progress: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    concluded: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    cancelled: 'bg-muted text-muted-foreground line-through',
};

const filterEnrollmentId = ref<number | null>(props.filters.enrollment_id ?? null);
const filterInterventionType = ref<string | null>(props.filters.intervention_type ?? null);
const filterContext = ref<string | null>(props.filters.context ?? null);
const filterDomainId = ref<number | null>(props.filters.domain_id ?? null);
const filterPeriodId = ref<number | null>(props.filters.period_id ?? null);
const filterAvailableForReports = ref<boolean | null>(props.filters.available_for_reports ?? null);
const filterSupportMeasureLevel = ref<string | null>(props.filters.support_measure_level ?? null);

function applyFilters(): void {
    router.get(
        `/classes/${props.schoolClass.ulid}/interventions`,
        {
            enrollment_id: filterEnrollmentId.value,
            intervention_type: filterInterventionType.value,
            context: filterContext.value,
            domain_id: filterDomainId.value,
            period_id: filterPeriodId.value,
            available_for_reports: filterAvailableForReports.value,
            support_measure_level: filterSupportMeasureLevel.value,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function clearFilters(): void {
    filterEnrollmentId.value = null;
    filterInterventionType.value = null;
    filterContext.value = null;
    filterDomainId.value = null;
    filterPeriodId.value = null;
    filterAvailableForReports.value = null;
    filterSupportMeasureLevel.value = null;
    applyFilters();
}
</script>

<template>
    <Head :title="`Intervenções — ${schoolClass.label}`" />

    <div class="mx-auto w-full max-w-3xl space-y-5 p-4">
        <div>
            <Heading :title="`Intervenções — ${schoolClass.label}`" :description="schoolClass.subject" />
            <Link href="/interventions" class="text-sm text-muted-foreground hover:underline">← Todas as turmas</Link>
        </div>

        <p class="text-xs text-muted-foreground">As intervenções apoiam o acompanhamento pedagógico e não alteram automaticamente a classificação.</p>

        <form class="space-y-3 rounded-lg border border-border p-4" @submit.prevent="submit">
            <label class="block text-sm">
                <span class="mb-1 block text-xs text-muted-foreground">Destinatário</span>
                <span class="flex gap-1">
                    <button
                        v-for="targetType in targetTypes"
                        :key="targetType.value"
                        type="button"
                        class="rounded-md px-2 py-1 text-xs"
                        :class="form.target_type === targetType.value ? 'bg-accent text-accent-foreground' : 'text-muted-foreground hover:bg-muted/40'"
                        @click="form.target_type = targetType.value as TargetType"
                    >
                        {{ targetType.label }}
                    </button>
                </span>
            </label>

            <label v-if="form.target_type === 'student'" class="block text-sm">
                <span class="mb-1 block text-xs text-muted-foreground">Aluno</span>
                <select v-model="form.enrollment_ids[0]" class="w-full rounded-md border border-border bg-background px-2 py-1.5">
                    <option :value="undefined" disabled>Escolher…</option>
                    <option v-for="enrollment in selectableEnrollments" :key="enrollment.id" :value="enrollment.id">{{ enrollment.name }}</option>
                </select>
            </label>

            <div v-if="form.target_type === 'group'" class="rounded-md border border-border p-2">
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
                <p v-if="form.enrollment_ids.length < 2" class="mt-1 text-xs text-muted-foreground">Selecione pelo menos dois alunos.</p>
            </div>
            <p v-if="form.errors.enrollment_ids" class="text-xs text-red-600">{{ form.errors.enrollment_ids }}</p>

            <div class="grid gap-3 sm:grid-cols-2">
                <label class="text-sm">
                    <span class="mb-1 block text-xs text-muted-foreground">Intervenção</span>
                    <select v-model="form.intervention_type" class="w-full rounded-md border border-border bg-background px-2 py-1.5">
                        <optgroup v-for="group in typeGroups" :key="group.label" :label="group.label">
                            <option v-for="type in group.types" :key="type.value" :value="type.value">{{ type.label }}</option>
                        </optgroup>
                    </select>
                    <p v-if="form.errors.intervention_type" class="mt-1 text-xs text-red-600">{{ form.errors.intervention_type }}</p>
                </label>
                <label class="text-sm">
                    <span class="mb-1 block text-xs text-muted-foreground">Data</span>
                    <input v-model="form.started_on" type="date" class="w-full rounded-md border border-border bg-background px-2 py-1.5" />
                    <p v-if="form.errors.started_on" class="mt-1 text-xs text-red-600">{{ form.errors.started_on }}</p>
                </label>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <label class="text-sm">
                    <span class="mb-1 block text-xs text-muted-foreground">Domínio</span>
                    <select v-model="form.domain_relation" class="w-full rounded-md border border-border bg-background px-2 py-1.5">
                        <option v-for="relation in domainRelations" :key="relation.value" :value="relation.value">{{ relation.label }}</option>
                    </select>
                </label>
                <label v-if="form.domain_relation === 'specific'" class="text-sm">
                    <span class="mb-1 block text-xs text-muted-foreground">Domínio específico</span>
                    <select v-model="form.domain_id" class="w-full rounded-md border border-border bg-background px-2 py-1.5">
                        <option :value="null" disabled>Escolher…</option>
                        <option v-for="domain in domains" :key="domain.id" :value="domain.id">{{ domain.name }}</option>
                    </select>
                    <p v-if="form.errors.domain_id" class="mt-1 text-xs text-red-600">{{ form.errors.domain_id }}</p>
                </label>
            </div>

            <label class="block text-sm">
                <span class="mb-1 block text-xs text-muted-foreground">
                    Descrição <span v-if="selectedType?.requires_description" class="text-red-600">*</span><span v-else> (opcional)</span>
                </span>
                <textarea
                    v-model="form.description"
                    rows="2"
                    maxlength="5000"
                    class="w-full rounded-md border border-border bg-background px-3 py-2"
                    placeholder="Descreva brevemente a intervenção realizada."
                    :required="selectedType?.requires_description"
                ></textarea>
                <p v-if="form.errors.description" class="mt-1 text-xs text-red-600">{{ form.errors.description }}</p>
            </label>

            <label class="flex items-center gap-2 text-sm">
                <input v-model="form.available_for_reports" type="checkbox" class="rounded border-border" />
                Disponível para relatórios
            </label>

            <div v-if="selectedMapping?.mode === 'direct'" class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                <CheckCircle2 class="size-4 text-emerald-600" />
                <span>Enquadramento sugerido pelo sistema: {{ [selectedMapping.level_label, selectedMapping.measure_label].filter(Boolean).join(' — ') }}</span>
                <button type="button" class="text-primary hover:underline" @click="openManualMeasure">Alterar</button>
                <button type="button" class="text-muted-foreground hover:underline" @click="removeFraming">Remover enquadramento</button>
            </div>

            <div v-else-if="selectedMapping?.mode === 'contextual'" class="space-y-2 rounded-md bg-muted/30 p-3 text-xs">
                <p class="text-muted-foreground">Possível enquadramento: {{ [selectedMapping.level_label, selectedMapping.measure_label].filter(Boolean).join(' — ') }}</p>
                <div class="flex flex-wrap gap-2">
                    <button type="button" class="rounded-md border border-emerald-600 px-2.5 py-1 text-emerald-700 dark:text-emerald-400" @click="chooseContextualFraming('confirm')">Confirmar</button>
                    <button type="button" class="rounded-md border border-border px-2.5 py-1" @click="chooseContextualFraming('manual')">Alterar</button>
                    <button type="button" class="rounded-md border border-border px-2.5 py-1 text-muted-foreground" @click="chooseContextualFraming('none')">Sem enquadramento</button>
                </div>
                <p v-if="form.confirm_suggested_framing" class="text-emerald-700 dark:text-emerald-400">Sugestão confirmada.</p>
                <p v-else-if="form.legal_framing === 'none'" class="text-muted-foreground">Sem enquadramento selecionado.</p>
                <p v-else class="text-muted-foreground">Enquanto não confirmar, não fica registado como enquadramento formal.</p>
            </div>

            <div v-else-if="selectedMapping?.mode === 'evaluation_only'" class="text-xs text-muted-foreground">
                <p class="flex items-center gap-2"><CheckCircle2 class="size-4 text-emerald-600" /> Adaptação no processo de avaliação: {{ selectedMapping.evaluation_adaptation_label }}</p>
                <p class="mt-1 ml-6">Nível da medida não especificado.</p>
            </div>

            <details ref="framingDetails" class="rounded-md border border-border p-3">
                <summary class="cursor-pointer text-sm font-medium">Enquadramento pedagógico/legal</summary>

                <div class="mt-3 space-y-4">
                    <div v-if="isPedagogicalStrategy" class="text-xs">
                        <p>Enquadramento: <span class="font-medium">Estratégia pedagógica</span></p>
                        <p class="mt-0.5 text-muted-foreground">Esta intervenção não necessita de enquadramento formal.</p>
                    </div>

                    <div v-if="showAdaptationField" class="space-y-1">
                        <p class="text-xs font-medium text-muted-foreground uppercase">Adaptação no processo de avaliação</p>
                        <!-- For an assessment-adaptation type, the catalogue's own
                             value is the answer: shown as text, not as a select
                             sitting empty on "Não especificada". -->
                        <div v-if="!adaptationEditable" class="flex flex-wrap items-center gap-2 text-sm">
                            <span>{{ selectedMapping?.evaluation_adaptation_label }}</span>
                            <button type="button" class="text-xs text-primary hover:underline" @click="editAdaptation">Alterar adaptação</button>
                        </div>
                        <select
                            v-else
                            v-model="form.evaluation_adaptation_code"
                            class="w-full rounded-md border border-border bg-background px-2 py-1.5 text-sm sm:max-w-sm"
                            @change="markFramingManual"
                        >
                            <option :value="null">Não especificada</option>
                            <option v-for="adaptation in evaluationAdaptations" :key="adaptation.value" :value="adaptation.value">{{ adaptation.label }}</option>
                        </select>
                    </div>
                    <button v-else type="button" class="block text-xs text-primary hover:underline" @click="openManualAdaptation">
                        + Associar adaptação no processo de avaliação
                    </button>

                    <p class="text-xs font-medium text-muted-foreground uppercase">Medida de suporte à aprendizagem</p>
                    <p v-if="!manualMeasureOpen && !selectedMappingHasMeasure" class="-mt-3 text-xs text-muted-foreground">Nenhuma medida associada.</p>

                    <div v-if="manualMeasureOpen" class="grid gap-3 sm:grid-cols-2">
                        <label class="text-sm">
                            <span class="mb-1 block text-xs text-muted-foreground">Nível da medida</span>
                            <select v-model="form.support_measure_level" class="w-full rounded-md border border-border bg-background px-2 py-1.5" @change="markFramingManual">
                                <option :value="null">Não especificado</option>
                                <option v-for="level in supportMeasureLevels" :key="level.value" :value="level.value">{{ level.label }}</option>
                            </select>
                        </label>
                        <label class="text-sm">
                            <span class="mb-1 block text-xs text-muted-foreground">Medida</span>
                            <select v-model="form.support_measure_code" class="w-full rounded-md border border-border bg-background px-2 py-1.5" :disabled="!form.support_measure_level" @change="markFramingManual">
                                <option :value="null">Não especificada</option>
                                <option v-for="measure in availableMeasures" :key="measure.value" :value="measure.value">{{ measure.label }}</option>
                            </select>
                            <p v-if="form.errors.support_measure_code" class="mt-1 text-xs text-red-600">{{ form.errors.support_measure_code }}</p>
                        </label>
                    </div>
                    <button v-else type="button" class="text-xs text-primary hover:underline" @click="openManualMeasure">
                        Associar medida de suporte à aprendizagem
                    </button>

                    <button
                        v-if="manualMeasureOpen || manualAdaptationOpen || selectedMapping"
                        type="button"
                        class="block text-xs text-muted-foreground hover:underline"
                        @click="removeFraming"
                    >
                        Remover enquadramento
                    </button>
                </div>
            </details>

            <div class="flex items-center justify-end gap-2">
                <button v-if="editingUlid" type="button" class="rounded-md px-3 py-2 text-sm text-muted-foreground hover:underline" @click="cancelEdit">Cancelar</button>
                <button type="submit" class="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50" :disabled="form.processing || !canSubmit">
                    {{ editingUlid ? 'Guardar alterações' : 'Adicionar intervenção' }}
                </button>
            </div>
        </form>

        <div class="flex flex-wrap items-center gap-3 text-sm">
            <label class="flex items-center gap-2"><span class="text-xs text-muted-foreground">Aluno</span><select v-model="filterEnrollmentId" class="rounded-md border border-border bg-background px-2 py-1" @change="applyFilters"><option :value="null">Todos</option><option v-for="enrollment in enrollments" :key="enrollment.id" :value="enrollment.id">{{ enrollment.name }}</option></select></label>
            <label class="flex items-center gap-2"><span class="text-xs text-muted-foreground">Tipo</span><select v-model="filterInterventionType" class="rounded-md border border-border bg-background px-2 py-1" @change="applyFilters"><option :value="null">Todos</option><optgroup v-for="group in typeGroups" :key="group.label" :label="group.label"><option v-for="type in group.types" :key="type.value" :value="type.value">{{ type.label }}</option></optgroup></select></label>
            <label class="flex items-center gap-2"><span class="text-xs text-muted-foreground">Contexto</span><select v-model="filterContext" class="rounded-md border border-border bg-background px-2 py-1" @change="applyFilters"><option :value="null">Todos</option><option v-for="context in contexts" :key="context.value" :value="context.value">{{ context.label }}</option></select></label>
            <label class="flex items-center gap-2"><span class="text-xs text-muted-foreground">Domínio</span><select v-model="filterDomainId" class="rounded-md border border-border bg-background px-2 py-1" @change="applyFilters"><option :value="null">Todos</option><option v-for="domain in domains" :key="domain.id" :value="domain.id">{{ domain.name }}</option></select></label>
            <label class="flex items-center gap-2"><span class="text-xs text-muted-foreground">Período</span><select v-model="filterPeriodId" class="rounded-md border border-border bg-background px-2 py-1" @change="applyFilters"><option :value="null">Todos</option><option v-for="period in periods" :key="period.id" :value="period.id">{{ period.label }}</option></select></label>
            <label class="flex items-center gap-2"><span class="text-xs text-muted-foreground">Relatórios</span><select v-model="filterAvailableForReports" class="rounded-md border border-border bg-background px-2 py-1" @change="applyFilters"><option :value="null">Todos</option><option :value="true">Disponível</option><option :value="false">Não disponível</option></select></label>
            <label class="flex items-center gap-2"><span class="text-xs text-muted-foreground">Nível</span><select v-model="filterSupportMeasureLevel" class="rounded-md border border-border bg-background px-2 py-1" @change="applyFilters"><option :value="null">Todos</option><option v-for="level in supportMeasureLevels" :key="level.value" :value="level.value">{{ level.label }}</option></select></label>
            <button type="button" class="text-xs text-primary hover:underline" @click="clearFilters">Limpar filtros</button>
        </div>

        <div v-if="interventions.length === 0" class="rounded-lg border border-dashed border-border p-10 text-center">
            <p class="text-sm text-muted-foreground">Ainda não há intervenções nesta turma.</p>
        </div>

        <ul v-else class="space-y-2">
            <li v-for="intervention in interventions" :key="intervention.ulid" class="space-y-3 rounded-lg border border-border p-3">
                <div class="flex items-start gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded-full px-2 py-0.5 text-xs" :class="statusClasses[intervention.status]">{{ intervention.status_label }}</span>
                            <span class="text-sm font-medium">{{ intervention.target_label }}</span>
                            <span class="text-sm">— {{ intervention.title }}</span>
                            <span class="ml-auto text-xs text-muted-foreground tabular-nums">{{ when(intervention.started_on) }}</span>
                        </div>
                        <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                            <span v-if="intervention.domain">{{ intervention.domain }}</span>
                            <span v-if="intervention.legal_framing" class="rounded-full bg-accent px-2 py-0.5 text-accent-foreground">{{ framingLabel(intervention) }}</span>
                            <span v-if="intervention.available_for_reports" class="inline-flex items-center gap-1"><FileText class="size-3.5 text-emerald-500" /> Disponível para relatórios</span>
                        </div>
                        <p v-if="intervention.description" class="mt-1 text-sm text-muted-foreground">{{ intervention.description }}</p>
                    </div>
                    <div class="flex shrink-0 items-center gap-1">
                        <button type="button" class="rounded-md p-1.5 text-muted-foreground hover:bg-muted/40" title="Editar" @click="edit(intervention)"><Pencil class="size-4" /></button>
                        <button type="button" class="rounded-md p-1.5 text-muted-foreground hover:bg-muted/40 hover:text-red-600" title="Remover" @click="remove(intervention)"><Trash2 class="size-4" /></button>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2 text-xs">
                    <template v-if="!intervention.is_closed">
                        <button v-if="intervention.status === 'new'" type="button" class="rounded-md border border-border px-2.5 py-1 hover:bg-muted/40" @click="setStatus(intervention, 'in_progress')">Marcar em curso</button>
                        <button type="button" class="rounded-md border border-emerald-600 px-2.5 py-1 text-emerald-700 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-950" @click="setStatus(intervention, 'concluded')">Concluir</button>
                        <button type="button" class="rounded-md border border-border px-2.5 py-1 text-muted-foreground hover:bg-muted/40" @click="setStatus(intervention, 'cancelled')">Cancelar</button>
                    </template>
                    <button v-if="openReview !== intervention.ulid" type="button" class="text-primary hover:underline" @click="openReviewFor(intervention)">+ Apreciação</button>
                </div>

                <div class="border-t border-border pt-3">
                    <div v-if="intervention.reviews.length" class="mb-2 space-y-1.5">
                        <div v-for="review in intervention.reviews" :key="review.ulid" class="text-sm">
                            <span class="text-xs text-muted-foreground tabular-nums">{{ when(review.reviewed_on) }}</span>
                            <span v-if="review.effectiveness_label" class="ml-2 rounded-full bg-accent px-2 py-0.5 text-xs text-accent-foreground">{{ review.effectiveness_label }}</span>
                            <p v-if="review.notes" class="text-muted-foreground">{{ review.notes }}</p>
                        </div>
                    </div>

                    <div v-if="openReview === intervention.ulid" class="flex flex-wrap items-end gap-2">
                        <label class="text-sm"><span class="mb-1 block text-xs text-muted-foreground">Data</span><input v-model="reviewForm.reviewed_on" type="date" class="rounded-md border border-border bg-background px-2 py-1" /></label>
                        <label class="text-sm"><span class="mb-1 block text-xs text-muted-foreground">Eficácia</span><select v-model="reviewForm.effectiveness" class="rounded-md border border-border bg-background px-2 py-1"><option :value="null">—</option><option v-for="option in effectivenessOptions" :key="option.value" :value="option.value">{{ option.label }}</option></select></label>
                        <input v-model="reviewForm.notes" type="text" maxlength="2000" class="min-w-40 flex-1 rounded-md border border-border bg-background px-2 py-1 text-sm" placeholder="Notas…" />
                        <button type="button" class="rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50" :disabled="reviewForm.processing" @click="submitReview(intervention)">Guardar</button>
                        <button type="button" class="rounded-md px-2 py-1.5 text-sm text-muted-foreground hover:underline" @click="openReview = null">Cancelar</button>
                    </div>
                </div>
            </li>
        </ul>
    </div>
</template>
