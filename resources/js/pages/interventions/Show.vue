<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { CalendarClock, CheckCircle2, FileText, Pencil, Trash2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import { statusToneClasses } from '@/lib/statusTone';

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

/** A library entry, or the teacher's own words with no code. */
type LibraryEntry = { code: string | null; label: string; objective: string | null; related_code: string | null; is_system: boolean };

type Intervention = {
    ulid: string;
    created_batch_ulid: string | null;
    /**
     * Null when the row has no name of its own — an old record whose only
     * «title» was a label an old process generated. The list then names it by
     * its participants and its date, which is all it ever said.
     */
    title: string | null;
    description: string | null;
    /** PORQUÊ — the situation the teacher identified. Null on older rows. */
    motive_code: string | null;
    motive: string | null;
    /** O QUÊ — how the teacher named what they did. */
    strategy_code: string | null;
    strategy: string | null;
    /** PARA QUÊ. */
    objective: string | null;
    review_on: string | null;
    needs_review: boolean;
    /** Recuperação / Consolidação / Melhoria. Null on a row recorded before this existed. */
    purpose: string | null;
    purpose_label: string | null;
    frequency: string | null;
    tracking_indicator: string | null;
    /** Derived from the follow-ups — what the teacher last observed. */
    effectiveness: string | null;
    effectiveness_label: string | null;
    effectiveness_short: string | null;
    last_followup_on: string | null;
    followup_count: number;
    intervention_type_label: string | null;
    intervention_type: string | null;
    context: string | null;
    context_label: string | null;
    target_type: TargetType;
    target_label: string;
    /** Only resolved for a single-student intervention — links back to their own Evolução page. */
    target_enrollment_ulid: string | null;
    participant_ids: number[];
    domain_relation: DomainRelation;
    domain_id: number | null;
    domain: string | null;
    /** A real domain name, «Todos os domínios», or nothing at all (§5). */
    domain_label: string | null;
    status: string;
    status_label: string;
    is_closed: boolean;
    creator_name: string | null;
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
    support_measures: { ulid: string; level: string; level_label: string; code: string; code_label: string; source: string | null }[];
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
    effectivenessOptions: { value: string; label: string; short_label: string }[];
    statusOptions: { value: string; label: string }[];
    /** Recuperação / Consolidação / Melhoria (§8) — three, equally weighted. */
    purposeOptions: { value: string; label: string; description: string }[];
    /**
     * The SAME library Relatórios uses, not a second one. Possibly empty — a
     * school that never seeded one types its own words and the module works
     * exactly as well (§74).
     */
    library: { difficulties: LibraryEntry[]; strategies: Record<string, LibraryEntry[]> };
    filters: {
        enrollment_id?: number | null;
        intervention_type?: string | null;
        context?: string | null;
        domain_id?: number | null;
        period_id?: number | null;
        available_for_reports?: boolean | null;
        support_measure_level?: string | null;
        status?: string | null;
        needs_review?: boolean | null;
    };
    interventions: Intervention[];
    /**
     * Arriving from a student's page, with that student already chosen
     * (§17) — and, arriving from «Adicionar estratégia» / «Adaptar
     * sugestão» on Acompanhamento do Aluno, a suggested strategy the
     * teacher still has to submit (§13 of the AI brief).
     */
    prefill: {
        target_type: TargetType;
        enrollment_ids: number[];
        name: string | null;
        domain_relation: DomainRelation;
        domain_id: number | null;
        suggestion?: {
            motive_label: string | null;
            strategy_label: string | null;
            objective: string | null;
            description: string | null;
            purpose: string | null;
            frequency: string | null;
            tracking_indicator: string | null;
            review_suggestion: string | null;
        };
    } | null;
}>();

const today = new Date().toISOString().slice(0, 10);

type FormData = {
    target_type: TargetType;
    enrollment_ids: number[];
    intervention_type: string;
    intervention_types: string[];
    domain_relation: DomainRelation;
    domain_id?: number | null;
    /**
     * The teacher's reasoning. A code when it came from the library, and the
     * words either way — what gets stored is the words, so rewording the
     * library later cannot rewrite this intervention (§56).
     */
    motive_code: string | null;
    motive_label: string;
    strategy_code: string | null;
    strategy_label: string;
    objective: string;
    review_on: string;
    /** Recuperação / Consolidação / Melhoria — null until the teacher picks one (§8). */
    purpose: string | null;
    frequency: string;
    tracking_indicator: string;
    description: string;
    started_on: string;
    available_for_reports: boolean;
    legal_framing: LegalFraming;
    confirm_suggested_framing: boolean;
    support_measure_level: string | null;
    support_measure_code: string | null;
    support_measures: { level: string; code: string }[];
    evaluation_adaptation_code: string | null;
};

const suggestion = props.prefill?.suggestion ?? null;

const form = useForm<FormData>({
    target_type: props.prefill?.target_type ?? 'student',
    enrollment_ids: props.prefill?.enrollment_ids ?? (props.enrollments[0] ? [props.enrollments[0].id] : []),
    intervention_type: props.types[0]?.value ?? '',
    intervention_types: props.types[0] ? [props.types[0].value] : [],
    domain_relation: props.prefill?.domain_relation ?? 'none',
    domain_id: props.prefill?.domain_id ?? null,
    motive_code: null,
    motive_label: suggestion?.motive_label ?? '',
    strategy_code: null,
    strategy_label: suggestion?.strategy_label ?? '',
    objective: suggestion?.objective ?? '',
    review_on: '',
    purpose: suggestion?.purpose ?? null,
    frequency: suggestion?.frequency ?? '',
    tracking_indicator: suggestion?.tracking_indicator ?? '',
    description: suggestion?.description ?? '',
    started_on: today,
    available_for_reports: true,
    legal_framing: null,
    confirm_suggested_framing: false,
    support_measure_level: null,
    support_measure_code: null,
    support_measures: [],
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

/**
 * The strategies that answer the difficulty the teacher chose.
 *
 * NARROWED BY THE LIBRARY'S OWN `related_code`, and by nothing else. Offering
 * every strategy under every difficulty is what makes a module read like a form
 * letter, and — more importantly — nothing here is inferred: «guiões de
 * planificação» appears because somebody wrote down that it answers
 * «planificação da escrita», not because a result was low (§13, §65).
 *
 * A difficulty the teacher typed themselves has no code and therefore no
 * suggestions, which is the honest answer: the library has nothing to say about
 * a formulation it has never seen.
 */
const suggestedStrategies = computed<LibraryEntry[]>(() =>
    form.motive_code === null ? [] : (props.library.strategies[form.motive_code] ?? []),
);

/** Whether the library has anything at all to offer here (§74). */
const hasLibrary = computed(() => props.library.difficulties.length > 0);

function chooseMotive(entry: LibraryEntry | null): void {
    form.motive_code = entry?.code ?? null;
    form.motive_label = entry?.label ?? '';

    // Choosing a different situation invalidates a strategy picked for the
    // previous one. The teacher's own words are left alone.
    if (form.strategy_code !== null) {
        form.strategy_code = null;
        form.strategy_label = '';
    }
}

/**
 * Picking a strategy also OFFERS its objective — and only offers it. The text
 * lands in an editable field, and what is stored is what the teacher left there
 * (§14, §55).
 */
function chooseStrategy(entry: LibraryEntry): void {
    form.strategy_code = entry.code;
    form.strategy_label = entry.label;

    if (form.objective.trim() === '' && entry.objective) {
        form.objective = entry.objective;
    }
}

const editingUlid = ref<string | null>(null);
const selectedType = computed(() => props.types.find((type) => type.value === (editingUlid.value ? form.intervention_type : form.intervention_types[0])) ?? null);
const selectedMapping = computed(() => selectedType.value?.legal_mapping ?? null);
const selectedMeasureLevel = computed(() => props.supportMeasureLevels.find((level) => level.value === form.support_measure_level) ?? null);
const availableMeasures = computed(() => selectedMeasureLevel.value?.measures ?? []);
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
    if (!editingUlid.value && form.intervention_types.length === 0) {
        return false;
    }

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
    () => form.intervention_types.length,
    (count) => {
        if (!editingUlid.value && count > 1) {
            form.legal_framing = 'auto';
            form.confirm_suggested_framing = false;
            form.support_measures = [];
            form.support_measure_level = null;
            form.support_measure_code = null;
        }
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
    form.support_measures = [];
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
    form.intervention_types = intervention.intervention_type ? [intervention.intervention_type] : [];
    form.domain_relation = intervention.domain_relation;
    form.domain_id = intervention.domain_id;
    // The reasoning as it was RECORDED, not as the library reads today: what is
    // loaded back is the snapshot, so editing a date does not silently adopt a
    // reworded entry (§56).
    form.motive_code = intervention.motive_code;
    form.motive_label = intervention.motive ?? '';
    form.strategy_code = intervention.strategy_code;
    form.strategy_label = intervention.strategy ?? '';
    form.objective = intervention.objective ?? '';
    form.review_on = intervention.review_on ?? '';
    form.purpose = intervention.purpose;
    form.frequency = intervention.frequency ?? '';
    form.tracking_indicator = intervention.tracking_indicator ?? '';
    form.description = intervention.description ?? '';
    form.started_on = intervention.started_on.slice(0, 10);
    form.available_for_reports = intervention.available_for_reports;
    form.legal_framing = intervention.legal_framing?.source === 'manual' ? 'manual' : intervention.legal_framing ? 'auto' : null;
    form.confirm_suggested_framing = intervention.legal_framing?.source === 'system_suggested_confirmed';
    form.support_measure_level = intervention.legal_framing?.level ?? null;
    form.support_measure_code = intervention.legal_framing?.measure ?? null;
    form.support_measures = intervention.support_measures.map((measure) => ({ level: measure.level, code: measure.code }));
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

        if (editingUlid.value) {
            delete (payload as Partial<FormData>).intervention_types;
        } else {
            delete (payload as Partial<FormData>).intervention_type;
        }

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

function removeBatch(intervention: Intervention): void {
    if (confirm('Eliminar todas as medidas pedagógicas registadas neste lote?')) {
        router.delete(`/interventions/${intervention.ulid}/batch`, { preserveScroll: true });
    }
}

const typeSearch = ref('');
const filteredTypeGroups = computed(() => {
    const needle = typeSearch.value.trim().toLocaleLowerCase('pt-PT');

    if (!needle) {
return typeGroups.value;
}

    return typeGroups.value
        .map((group) => ({ ...group, types: group.types.filter((type) => type.label.toLocaleLowerCase('pt-PT').includes(needle)) }))
        .filter((group) => group.types.length > 0);
});

function removeSelectedType(value: string): void {
    form.intervention_types = form.intervention_types.filter((type) => type !== value);
}

function addSupportMeasure(): void {
    if (!form.support_measure_level || !form.support_measure_code) {
return;
}

    if (!form.support_measures.some((pair) => pair.level === form.support_measure_level && pair.code === form.support_measure_code)) {
        form.support_measures.push({ level: form.support_measure_level, code: form.support_measure_code });
    }

    markFramingManual();
}

function removeSupportMeasure(index: number): void {
    form.support_measures.splice(index, 1);
    markFramingManual();
}

/**
 * Adding to the history is NOT editing the intervention (§42).
 *
 * A teacher who observed something in March opens this, writes it, and leaves —
 * they never have to go through the edit form, and the edit form never touches
 * what earlier follow-ups said.
 */
const openReview = ref<string | null>(null);
const reviewForm = useForm<{
    reviewed_on: string;
    effectiveness: string | null;
    notes: string;
    review_on: string;
}>({
    reviewed_on: today,
    effectiveness: null,
    notes: '',
    review_on: '',
});

function openReviewFor(intervention: Intervention): void {
    openReview.value = intervention.ulid;
    reviewForm.reset();
    reviewForm.reviewed_on = today;
    // Recording what was seen is the natural moment to decide when to look
    // again, so the current date is offered rather than a blank field.
    reviewForm.review_on = intervention.review_on ?? '';
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

/** «Rever em 25 de setembro», the way a person says it (§82). */
function reviewWhen(date: string): string {
    return new Date(date).toLocaleDateString('pt-PT', { day: 'numeric', month: 'long' });
}

const filterEnrollmentId = ref<number | null>(props.filters.enrollment_id ?? null);
const filterInterventionType = ref<string | null>(props.filters.intervention_type ?? null);
const filterContext = ref<string | null>(props.filters.context ?? null);
const filterDomainId = ref<number | null>(props.filters.domain_id ?? null);
const filterPeriodId = ref<number | null>(props.filters.period_id ?? null);
const filterAvailableForReports = ref<boolean | null>(props.filters.available_for_reports ?? null);
const filterSupportMeasureLevel = ref<string | null>(props.filters.support_measure_level ?? null);
const filterStatus = ref<string | null>(props.filters.status ?? null);
const filterNeedsReview = ref<boolean>(props.filters.needs_review === true);

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
            status: filterStatus.value,
            needs_review: filterNeedsReview.value ? 1 : null,
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
    filterStatus.value = null;
    filterNeedsReview.value = false;
    applyFilters();
}

/** How many of the listed interventions the teacher said they would revisit by now. */
const pendingCount = computed(() => props.interventions.filter((row) => row.needs_review).length);
</script>

<template>
    <Head :title="`Estratégias e Medidas — ${schoolClass.label}`" />

    <div class="mx-auto w-full max-w-3xl space-y-5 p-4">
        <div>
            <Heading :title="`Estratégias e Medidas — ${schoolClass.label}`" :description="schoolClass.subject" />
            <Link href="/interventions" class="text-sm text-muted-foreground hover:underline">← Todas as turmas</Link>
        </div>

        <p class="text-xs text-muted-foreground">As estratégias e medidas apoiam o acompanhamento pedagógico e não alteram automaticamente a classificação.</p>

        <!-- §13 do apoio de IA: uma proposta, nunca um registo. Os campos
             abaixo ficam pré-preenchidos e editáveis — nada fica gravado
             enquanto o formulário não for submetido. -->
        <p v-if="suggestion" class="rounded-lg border border-primary/30 bg-primary/5 p-3 text-xs text-muted-foreground">
            Formulário pré-preenchido a partir de uma sugestão de estratégia (IA). Reveja e adapte antes de registar.
        </p>

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
                <label v-if="editingUlid" class="text-sm">
                    <span class="mb-1 block text-xs text-muted-foreground">Medida pedagógica</span>
                    <select v-model="form.intervention_type" class="w-full rounded-md border border-border bg-background px-2 py-1.5">
                        <optgroup v-for="group in typeGroups" :key="group.label" :label="group.label">
                            <option v-for="type in group.types" :key="type.value" :value="type.value">{{ type.label }}</option>
                        </optgroup>
                    </select>
                    <p v-if="form.errors.intervention_type" class="mt-1 text-xs text-red-600">{{ form.errors.intervention_type }}</p>
                </label>
                <div v-else class="text-sm">
                    <span class="mb-1 block text-xs text-muted-foreground">Medidas pedagógicas</span>
                    <div class="rounded-md border border-border bg-background p-2">
                        <div class="mb-2 flex flex-wrap gap-1.5">
                            <span v-for="value in form.intervention_types" :key="value" class="inline-flex items-center gap-1 rounded-full bg-accent px-2 py-1 text-xs">
                                {{ types.find((type) => type.value === value)?.label }}
                                <button type="button" :aria-label="`Remover ${types.find((type) => type.value === value)?.label}`" @click="removeSelectedType(value)">×</button>
                            </span>
                        </div>
                        <input v-model="typeSearch" type="search" class="mb-2 w-full rounded-md border border-border bg-background px-2 py-1.5" placeholder="Pesquisar e escolher várias…" />
                        <div class="max-h-44 space-y-2 overflow-y-auto">
                            <fieldset v-for="group in filteredTypeGroups" :key="group.label">
                                <legend class="text-xs font-medium text-muted-foreground">{{ group.label }}</legend>
                                <label v-for="type in group.types" :key="type.value" class="flex min-h-8 items-center gap-2 text-sm">
                                    <input v-model="form.intervention_types" type="checkbox" :value="type.value" :disabled="form.intervention_types.length >= 10 && !form.intervention_types.includes(type.value)" />
                                    {{ type.label }}
                                </label>
                            </fieldset>
                        </div>
                    </div>
                    <p class="mt-1 text-xs text-muted-foreground">Escolha entre uma e dez; cada medida terá acompanhamento independente.</p>
                    <p v-if="form.errors.intervention_types" class="mt-1 text-xs text-red-600">{{ form.errors.intervention_types }}</p>
                </div>
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

            <!-- ------------------------------------- o raciocínio pedagógico
                 PORQUÊ → O QUÊ → PARA QUÊ. Every field optional: registering
                 something small has to stay as fast as it was, and a teacher
                 who only wants to note what they did is never stopped by a
                 required objective (§5, §16). -->
            <fieldset class="space-y-3 rounded-lg border border-border p-3">
                <legend class="px-1 text-xs font-medium text-muted-foreground">
                    Raciocínio pedagógico <span class="font-normal">(opcional)</span>
                </legend>

                <div class="space-y-1.5">
                    <label for="motive" class="block text-xs text-muted-foreground">
                        Situação ou dificuldade que motivou
                    </label>

                    <!-- The library is offered as chips, never as the only way
                         in: the text field below takes whatever the teacher
                         wants to write, with or without a library entry behind
                         it (§53). -->
                    <div v-if="hasLibrary" class="flex flex-wrap gap-1.5">
                        <button
                            v-for="entry in library.difficulties"
                            :key="entry.code ?? entry.label"
                            type="button"
                            class="rounded-full border px-3 py-1 text-xs"
                            :class="form.motive_code === entry.code
                                ? 'border-primary bg-primary/10'
                                : 'border-border text-muted-foreground hover:bg-muted/40'"
                            :aria-pressed="form.motive_code === entry.code"
                            @click="chooseMotive(form.motive_code === entry.code ? null : entry)"
                        >
                            {{ entry.label }}
                        </button>
                    </div>

                    <input
                        id="motive"
                        v-model="form.motive_label"
                        type="text"
                        maxlength="300"
                        class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                        placeholder="Ex.: dificuldade na planificação da escrita"
                        @input="form.motive_code = null"
                    />
                    <p v-if="form.errors.motive_label" class="text-xs text-red-600">{{ form.errors.motive_label }}</p>
                </div>

                <div class="space-y-1.5">
                    <label for="strategy" class="block text-xs text-muted-foreground">Estratégia adotada</label>

                    <!-- Only the strategies that answer the chosen situation.
                         They appear because somebody wrote down that they answer
                         it, not because a result was low (§13). -->
                    <div v-if="suggestedStrategies.length > 0" class="flex flex-wrap gap-1.5">
                        <button
                            v-for="entry in suggestedStrategies"
                            :key="entry.code ?? entry.label"
                            type="button"
                            class="rounded-full border px-3 py-1 text-xs"
                            :class="form.strategy_code === entry.code
                                ? 'border-primary bg-primary/10'
                                : 'border-border text-muted-foreground hover:bg-muted/40'"
                            :aria-pressed="form.strategy_code === entry.code"
                            @click="chooseStrategy(entry)"
                        >
                            {{ entry.label }}
                        </button>
                    </div>

                    <input
                        id="strategy"
                        v-model="form.strategy_label"
                        type="text"
                        maxlength="300"
                        class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                        placeholder="Ex.: escrita orientada com guião de planificação"
                        @input="form.strategy_code = null"
                    />
                    <p v-if="form.errors.strategy_label" class="text-xs text-red-600">{{ form.errors.strategy_label }}</p>
                </div>

                <div class="space-y-1.5">
                    <label for="objective" class="block text-xs text-muted-foreground">Objetivo</label>
                    <textarea
                        id="objective"
                        v-model="form.objective"
                        rows="2"
                        maxlength="1000"
                        class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                        placeholder="Ex.: melhorar a organização e a estruturação do texto escrito."
                    ></textarea>
                    <p class="text-xs text-muted-foreground">
                        O que se pretende alcançar — não um resultado numérico.
                    </p>
                    <p v-if="form.errors.objective" class="text-xs text-red-600">{{ form.errors.objective }}</p>
                </div>

                <div class="space-y-1.5">
                    <label for="review-on" class="block text-xs text-muted-foreground">Rever em (opcional)</label>
                    <input
                        id="review-on"
                        v-model="form.review_on"
                        type="date"
                        class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm sm:w-56"
                    />
                    <p class="text-xs text-muted-foreground">
                        A partir desta data a intervenção aparece como «revisão pendente».
                    </p>
                    <p v-if="suggestion?.review_suggestion" class="text-xs text-primary">
                        Sugestão da IA: {{ suggestion.review_suggestion }}. Escolha a data que considerar adequada.
                    </p>
                    <p v-if="form.errors.review_on" class="text-xs text-red-600">{{ form.errors.review_on }}</p>
                </div>
            </fieldset>

            <!-- §8: finalidade, frequência e indicador de acompanhamento.
                 Three finalidades, equally weighted — Melhoria is a real
                 option and not an afterthought after Recuperação/Consolidação. -->
            <fieldset class="space-y-3 rounded-lg border border-border p-3">
                <legend class="px-1 text-xs font-medium text-muted-foreground">
                    Finalidade e acompanhamento <span class="font-normal">(opcional)</span>
                </legend>

                <div class="space-y-1.5">
                    <span class="block text-xs text-muted-foreground">Finalidade</span>
                    <div class="grid gap-2 sm:grid-cols-3">
                        <label
                            v-for="option in purposeOptions"
                            :key="option.value"
                            class="flex cursor-pointer flex-col gap-0.5 rounded-lg border p-2.5 text-xs"
                            :class="form.purpose === option.value ? 'border-primary bg-primary/5' : 'border-border'"
                        >
                            <span class="flex items-center gap-2">
                                <input v-model="form.purpose" type="radio" :value="option.value" class="size-3.5" />
                                <span class="font-medium">{{ option.label }}</span>
                            </span>
                            <span class="text-muted-foreground">{{ option.description }}</span>
                        </label>
                    </div>
                    <button
                        v-if="form.purpose !== null"
                        type="button"
                        class="text-xs text-muted-foreground hover:underline"
                        @click="form.purpose = null"
                    >
                        Limpar seleção — não especificada
                    </button>
                    <p v-if="form.errors.purpose" class="text-xs text-red-600">{{ form.errors.purpose }}</p>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="space-y-1.5">
                        <label for="frequency" class="block text-xs text-muted-foreground">Frequência (opcional)</label>
                        <input
                            id="frequency"
                            v-model="form.frequency"
                            type="text"
                            maxlength="200"
                            class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                            placeholder="Ex.: 2x por semana"
                        />
                        <p v-if="form.errors.frequency" class="text-xs text-red-600">{{ form.errors.frequency }}</p>
                    </div>

                    <div class="space-y-1.5">
                        <label for="tracking-indicator" class="block text-xs text-muted-foreground">
                            Indicador de acompanhamento (opcional)
                        </label>
                        <input
                            id="tracking-indicator"
                            v-model="form.tracking_indicator"
                            type="text"
                            maxlength="300"
                            class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                            placeholder="Ex.: n.º de leituras concluídas por semana"
                        />
                        <p v-if="form.errors.tracking_indicator" class="text-xs text-red-600">{{ form.errors.tracking_indicator }}</p>
                    </div>
                </div>
            </fieldset>

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

            <p v-if="!editingUlid && form.intervention_types.length > 1" class="text-xs text-muted-foreground">
                O enquadramento automático será calculado separadamente para cada medida. Pode ajustá-lo depois em cada registo.
            </p>
            <div v-else-if="selectedMapping?.mode === 'direct'" class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
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

            <details v-if="editingUlid || form.intervention_types.length === 1" ref="framingDetails" class="rounded-md border border-border p-3">
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

                    <p class="text-xs font-medium text-muted-foreground uppercase">Medidas de suporte à aprendizagem</p>
                    <div v-if="form.support_measures.length" class="space-y-2">
                        <div v-for="(pair, index) in form.support_measures" :key="`${pair.level}-${pair.code}`" class="flex items-center justify-between gap-2 rounded-md bg-muted/30 px-3 py-2 text-sm">
                            <span>{{ supportMeasureLevels.find((level) => level.value === pair.level)?.label }} — {{ supportMeasureLevels.flatMap((level) => level.measures).find((measure) => measure.value === pair.code)?.label }}</span>
                            <button type="button" class="text-xs text-muted-foreground hover:text-red-600" @click="removeSupportMeasure(index)">Remover</button>
                        </div>
                    </div>
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
                        <button type="button" class="self-end rounded-md border border-border px-3 py-2 text-sm" :disabled="!form.support_measure_level || !form.support_measure_code" @click="addSupportMeasure">Adicionar medida de suporte</button>
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

        <!-- «A acompanhar»: the ones whose own review date has arrived. No rule
             invents a deadline from elapsed time — this counts only dates the
             teacher chose (§37, §80). -->
        <button
            v-if="pendingCount > 0 && !filterNeedsReview"
            type="button"
            class="flex w-full items-center gap-2 rounded-lg border border-border bg-muted/30 px-3 py-2 text-left text-sm hover:bg-muted/50"
            @click="filterNeedsReview = true; applyFilters()"
        >
            <CalendarClock class="size-4 shrink-0 text-muted-foreground" />
            <span>
                {{ pendingCount }}
                {{ pendingCount === 1 ? 'intervenção com revisão pendente' : 'intervenções com revisão pendente' }}
            </span>
            <span class="ml-auto text-xs text-muted-foreground">Ver só estas</span>
        </button>

        <EmptyState
            v-if="interventions.length === 0"
            title="Ainda não existem estratégias ou medidas registadas."
            description="Use o formulário acima para registar a primeira."
        />

        <ul v-else class="space-y-2">
            <li v-for="intervention in interventions" :key="intervention.ulid" class="space-y-3 rounded-lg border border-border p-3">
                <div class="flex items-start gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded-full px-2 py-0.5 text-xs" :class="[statusToneClasses(intervention.status), intervention.status === 'cancelled' ? 'line-through' : '']">{{ intervention.status_label }}</span>
                            <Link
                                v-if="intervention.target_enrollment_ulid"
                                :href="`/classes/${schoolClass.ulid}/evolucao/${intervention.target_enrollment_ulid}`"
                                class="text-sm font-medium text-primary hover:underline"
                            >
                                {{ intervention.target_label }}
                            </Link>
                            <span v-else class="text-sm font-medium">{{ intervention.target_label }}</span>
                            <!-- Só quando há nome. Sem nome, o cartão fica
                                 «Álvaro Simões» e mais nada — que é tudo o que o
                                 registo alguma vez disse (§1, §10). -->
                            <span v-if="intervention.title" class="text-sm">— {{ intervention.title }}</span>
                            <span class="ml-auto text-xs text-muted-foreground tabular-nums">
                                {{ when(intervention.started_on) }}<template v-if="intervention.creator_name"> · {{ intervention.creator_name }}</template>
                            </span>
                        </div>
                        <!-- PORQUÊ e PARA QUÊ, quando o professor os registou.
                             Uma intervenção antiga não tem nenhum dos dois e não
                             mostra nenhum — nunca «objetivo geral» (§4). -->
                        <p v-if="intervention.motive" class="mt-1 text-sm text-muted-foreground">
                            <span class="text-xs uppercase tracking-wide">Situação:</span>
                            {{ intervention.motive }}
                        </p>
                        <p v-if="intervention.objective" class="text-sm text-muted-foreground">
                            <span class="text-xs uppercase tracking-wide">Objetivo:</span>
                            {{ intervention.objective }}
                        </p>
                        <p v-if="intervention.tracking_indicator" class="text-sm text-muted-foreground">
                            <span class="text-xs uppercase tracking-wide">Indicador:</span>
                            {{ intervention.tracking_indicator }}
                        </p>

                        <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                            <!-- Null shows nothing — «não especificada» is not
                                 printed on every older row (§8). -->
                            <span v-if="intervention.purpose_label" class="rounded-full bg-muted px-2 py-0.5">
                                {{ intervention.purpose_label }}
                            </span>
                            <span v-if="intervention.frequency">{{ intervention.frequency }}</span>
                            <span v-if="intervention.domain_label">{{ intervention.domain_label }}</span>
                            <!-- What the TEACHER observed, never derived from a
                                 result that moved (§26). -->
                            <span v-if="intervention.effectiveness_short" class="rounded-full bg-muted px-2 py-0.5">
                                {{ intervention.effectiveness_short }}
                            </span>
                            <!-- The badge carries an icon and words, never colour
                                 alone (§48, §80). -->
                            <span v-if="intervention.needs_review" class="inline-flex items-center gap-1 rounded-full bg-amber-500/10 px-2 py-0.5 text-amber-800 dark:text-amber-400">
                                <CalendarClock class="size-3.5" />
                                Revisão pendente
                            </span>
                            <span v-else-if="intervention.review_on" class="inline-flex items-center gap-1">
                                <CalendarClock class="size-3.5" />
                                Rever em {{ reviewWhen(intervention.review_on) }}
                            </span>
                            <span v-if="intervention.legal_framing" class="rounded-full bg-accent px-2 py-0.5 text-accent-foreground">{{ framingLabel(intervention) }}</span>
                            <span v-if="intervention.created_batch_ulid" class="rounded-full bg-muted px-2 py-0.5">Registada em conjunto</span>
                            <span v-if="intervention.available_for_reports" class="inline-flex items-center gap-1"><FileText class="size-3.5 text-emerald-500" /> Disponível para relatórios</span>
                        </div>
                        <p v-if="intervention.description" class="mt-1 text-sm text-muted-foreground">{{ intervention.description }}</p>
                    </div>
                    <div class="flex shrink-0 items-center gap-1">
                        <button type="button" class="rounded-md p-1.5 text-muted-foreground hover:bg-muted/40" title="Editar" @click="edit(intervention)"><Pencil class="size-4" /></button>
                        <button type="button" class="rounded-md p-1.5 text-muted-foreground hover:bg-muted/40 hover:text-red-600" title="Remover" @click="remove(intervention)"><Trash2 class="size-4" /></button>
                        <button v-if="intervention.created_batch_ulid" type="button" class="rounded-md px-2 py-1 text-xs text-muted-foreground hover:bg-muted/40 hover:text-red-600" @click="removeBatch(intervention)">Remover lote</button>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2 text-xs">
                    <template v-if="!intervention.is_closed">
                        <button v-if="intervention.status === 'new'" type="button" class="rounded-md border border-border px-2.5 py-1 hover:bg-muted/40" @click="setStatus(intervention, 'in_progress')">Marcar em curso</button>
                        <button type="button" class="rounded-md border border-emerald-600 px-2.5 py-1 text-emerald-700 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-950" @click="setStatus(intervention, 'concluded')">Concluir</button>
                        <!-- «Suspender», não «cancelar»: uma intervenção que
                             deixou de ser adequada continua a fazer parte do
                             ano (§45). -->
                        <button v-if="intervention.status !== 'suspended'" type="button" class="rounded-md border border-border px-2.5 py-1 text-muted-foreground hover:bg-muted/40" @click="setStatus(intervention, 'suspended')">Suspender</button>
                    </template>
                    <!-- Reabrir preserva a conclusão anterior no histórico (§44). -->
                    <button v-else type="button" class="rounded-md border border-border px-2.5 py-1 text-muted-foreground hover:bg-muted/40" @click="setStatus(intervention, 'in_progress')">Reabrir</button>
                    <!-- Acrescentar história nunca obriga a editar a intervenção
                         (§42). -->
                    <button v-if="openReview !== intervention.ulid" type="button" class="text-primary hover:underline" @click="openReviewFor(intervention)">+ Acompanhamento</button>
                </div>

                <div class="border-t border-border pt-3">
                    <!-- A pequena narrativa temporal: começou, foi acompanhada,
                         foi avaliada. Lida para a frente, do início para o
                         presente (§29, §97). -->
                    <ol v-if="intervention.reviews.length" class="mb-3 space-y-2">
                        <li class="flex gap-3 text-sm">
                            <span class="w-20 shrink-0 text-xs text-muted-foreground tabular-nums">{{ when(intervention.started_on) }}</span>
                            <span class="border-l border-border pl-3 text-muted-foreground">Intervenção iniciada</span>
                        </li>
                        <li v-for="review in [...intervention.reviews].reverse()" :key="review.ulid" class="flex gap-3 text-sm">
                            <span class="w-20 shrink-0 text-xs text-muted-foreground tabular-nums">{{ when(review.reviewed_on) }}</span>
                            <span class="min-w-0 flex-1 border-l border-border pl-3">
                                <span v-if="review.effectiveness_label" class="block text-xs font-medium">{{ review.effectiveness_label }}</span>
                                <span v-else class="block text-xs text-muted-foreground">Acompanhamento</span>
                                <span v-if="review.notes" class="mt-0.5 block text-muted-foreground">{{ review.notes }}</span>
                            </span>
                        </li>
                        <li v-if="intervention.concluded_on" class="flex gap-3 text-sm">
                            <span class="w-20 shrink-0 text-xs text-muted-foreground tabular-nums">{{ when(intervention.concluded_on) }}</span>
                            <span class="border-l border-border pl-3 text-muted-foreground">Concluída</span>
                        </li>
                    </ol>

                    <p v-else-if="openReview !== intervention.ulid" class="mb-2 text-xs text-muted-foreground">
                        Ainda não existem registos de acompanhamento.
                    </p>

                    <!-- Uma coluna em telemóvel, lado a lado a partir de sm:
                         estes campos são preenchidos de pé, num corredor (§76). -->
                    <div v-if="openReview === intervention.ulid" class="space-y-2">
                        <div class="grid gap-2 sm:grid-cols-2">
                            <label class="text-sm">
                                <span class="mb-1 block text-xs text-muted-foreground">Data da observação</span>
                                <input v-model="reviewForm.reviewed_on" type="date" class="w-full rounded-md border border-border bg-background px-2 py-1.5" />
                            </label>
                            <label class="text-sm">
                                <span class="mb-1 block text-xs text-muted-foreground">O que observou</span>
                                <select v-model="reviewForm.effectiveness" class="w-full rounded-md border border-border bg-background px-2 py-1.5">
                                    <option :value="null">Ainda não avaliado</option>
                                    <option v-for="option in effectivenessOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                                </select>
                            </label>
                        </div>
                        <textarea
                            v-model="reviewForm.notes"
                            rows="2"
                            maxlength="2000"
                            class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                            placeholder="Ex.: passou a utilizar o guião de planificação de forma mais autónoma."
                        ></textarea>
                        <label class="block text-sm">
                            <span class="mb-1 block text-xs text-muted-foreground">Rever novamente em (opcional)</span>
                            <input v-model="reviewForm.review_on" type="date" class="w-full rounded-md border border-border bg-background px-2 py-1.5 sm:w-56" />
                        </label>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" class="rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50" :disabled="reviewForm.processing" @click="submitReview(intervention)">Guardar acompanhamento</button>
                            <button type="button" class="rounded-md px-2 py-1.5 text-sm text-muted-foreground hover:underline" @click="openReview = null">Cancelar</button>
                        </div>
                    </div>
                </div>
            </li>
        </ul>
    </div>
</template>
