<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowUpDown,
    Check,
    Copy,
    Eye,
    FileDown,
    Lock,
    Pencil,
    RefreshCw,
    RotateCcw,
    Save,
    Trash2,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import type { ChosenDifficulty } from '@/components/reports/DifficultyPicker.vue';
import DifficultyPicker from '@/components/reports/DifficultyPicker.vue';
import ReportLetterhead from '@/components/reports/ReportLetterhead.vue';
import ReportSectionData from '@/components/reports/ReportSectionData.vue';
import type { RewriteSuggestion } from '@/components/reports/RewritePreview.vue';
import RewritePreview from '@/components/reports/RewritePreview.vue';
import type { OrderableSection } from '@/components/reports/SectionOrderList.vue';
import SectionOrderList from '@/components/reports/SectionOrderList.vue';
import type { WritingModeOption } from '@/components/reports/SectionRewrite.vue';
import SectionRewrite from '@/components/reports/SectionRewrite.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string };

type ReportPayload = {
    ulid: string;
    title: string;
    type: string;
    type_label: string;
    status: string;
    status_label: string;
    tone: string;
    scope_label: string;
    scope_kind: string;
    subject_label: string;
    teacher_input: Record<string, unknown>;
    name_students: boolean;
    author: string | null;
    updated_at: string;
    finalized_at: string | null;
    finalized_by: string | null;
    based_on: { ulid: string; title: string } | null;
};

type SectionPayload = {
    ulid: string;
    key: string;
    heading: string;
    position: number;
    included: boolean;
    body: string | null;
    edited: boolean;
    can_restore: boolean;
    has_content: boolean;
    sources: string[];
    data: Record<string, unknown> | null;
    can_rewrite?: boolean;
    may_name_students?: boolean;
};

type Identity = {
    name: string;
    header_lines: string[];
    footer_note: string | null;
    logo_url: string | null;
    is_configured: boolean;
};

type Characterisation = {
    available: boolean;
    // Which questions this report type would actually print an answer to.
    asks_behaviour?: boolean;
    asks_difficulties?: boolean;
    asks_attention?: boolean;
    asks_planning: boolean;
    behaviour?: Option[];
    attitude?: Option[];
    indicators?: Option[];
    standings?: Option[];
    planning: Option[];
};

type LibraryEntry = { code: string | null; label: string; objective: string | null };

type Library = {
    difficulties: LibraryEntry[];
    strategies: Record<string, LibraryEntry[]>;
    domains: string[];
};

type EnrollmentRow = { id: number; class_number: number | null; name: string };

type Comparison = {
    base: { ulid: string; title: string; scope_label: string; status: string };
    rows: { label: string; from: string; to: string }[];
    caveat: string;
};

const props = defineProps<{
    report: ReportPayload;
    sections: SectionPayload[];
    identity: Identity;
    characterisation: Characterisation;
    library: Library | null;
    enrollments: EnrollmentRow[];
    comparison: Comparison | null;
    can: { update: boolean; finalize: boolean; delete: boolean; export: boolean; derive: boolean };
    canSaveTemplate: { personal: boolean; institutional: boolean };
    ai: { available: boolean; reason: string | null; modes: WritingModeOption[] };
    rewrite?: RewriteSuggestion | null;
    rewriteError?: { section: string; message: string } | null;
}>();

// A finalized report opens on the document, because that is all it is now.
const mode = ref<'edit' | 'preview'>(props.report.status === 'draft' ? 'edit' : 'preview');
const editing = ref<string | null>(null);
const draftBody = ref('');

const printable = computed(() => props.sections.filter((section) => section.included && section.has_content));

const isDraft = computed(() => props.report.status === 'draft');

const dateTimeFormatter = new Intl.DateTimeFormat('pt-PT', {
    day: '2-digit',
    month: 'long',
    year: 'numeric',
});

function formatDate(value: string): string {
    return dateTimeFormatter.format(new Date(value));
}

// ------------------------------------------------------------------ envelope

const titleForm = useForm({ title: props.report.title });

function saveTitle() {
    titleForm.put(`/reports/${props.report.ulid}`, { preserveScroll: true });
}

// ---------------------------------------------------------- characterisation

type IndicatorChoice = { indicator: string; standing: string };

type FlaggedStudent = { enrollment_id: number; note: string | null };

const input = props.report.teacher_input as {
    behaviour?: string;
    attitude?: string;
    indicators?: IndicatorChoice[];
    observation?: string;
    difficulties?: ChosenDifficulty[];
    students_requiring_attention?: FlaggedStudent[];
    planning?: {
        compliance?: string;
        pending_content?: string[];
        postponed_content?: string[];
        reason?: string;
        recovery_plan?: string;
        note?: string;
    };
    final_note?: string;
};

const characterisationForm = useForm({
    name_students: props.report.name_students,
    teacher_input: {
        behaviour: input.behaviour ?? '',
        attitude: input.attitude ?? '',
        indicators: (input.indicators ?? []) as IndicatorChoice[],
        observation: input.observation ?? '',
        difficulties: (input.difficulties ?? []) as ChosenDifficulty[],
        students_requiring_attention: (input.students_requiring_attention ?? []) as FlaggedStudent[],
        planning: {
            compliance: input.planning?.compliance ?? '',
            pending_content: (input.planning?.pending_content ?? []).join('\n'),
            postponed_content: (input.planning?.postponed_content ?? []).join('\n'),
            reason: input.planning?.reason ?? '',
            recovery_plan: input.planning?.recovery_plan ?? '',
            note: input.planning?.note ?? '',
        },
        final_note: input.final_note ?? '',
    },
});

function standingOf(indicator: string): string | null {
    return characterisationForm.teacher_input.indicators.find((row) => row.indicator === indicator)?.standing ?? null;
}

function setStanding(indicator: string, standing: string) {
    const rows = characterisationForm.teacher_input.indicators;
    const index = rows.findIndex((row) => row.indicator === indicator);

    if (standing === '') {
        if (index !== -1) {
            rows.splice(index, 1);
        }

        return;
    }

    if (index === -1) {
        rows.push({ indicator, standing });
    } else {
        rows[index].standing = standing;
    }
}

function isFlagged(enrollmentId: number): boolean {
    return characterisationForm.teacher_input.students_requiring_attention.some(
        (row) => row.enrollment_id === enrollmentId,
    );
}

function toggleFlagged(enrollmentId: number) {
    const rows = characterisationForm.teacher_input.students_requiring_attention;
    const index = rows.findIndex((row) => row.enrollment_id === enrollmentId);

    if (index === -1) {
        rows.push({ enrollment_id: enrollmentId, note: null });
    } else {
        rows.splice(index, 1);
    }
}

function setFlaggedNote(enrollmentId: number, note: string) {
    const row = characterisationForm.teacher_input.students_requiring_attention.find(
        (candidate) => candidate.enrollment_id === enrollmentId,
    );

    if (row) {
        row.note = note.trim() === '' ? null : note;
    }
}

function saveCharacterisation() {
    characterisationForm
        .transform((data) => ({
            name_students: data.name_students,
            teacher_input: {
                ...data.teacher_input,
                // Empty means "unanswered", and unanswered must reach the server
                // as absent — never as a value that would print a sentence.
                behaviour: data.teacher_input.behaviour || null,
                attitude: data.teacher_input.attitude || null,
                observation: data.teacher_input.observation || null,
                final_note: data.teacher_input.final_note || null,
                planning: {
                    ...data.teacher_input.planning,
                    compliance: data.teacher_input.planning.compliance || null,
                    pending_content: splitLines(data.teacher_input.planning.pending_content),
                    postponed_content: splitLines(data.teacher_input.planning.postponed_content),
                    reason: data.teacher_input.planning.reason || null,
                    recovery_plan: data.teacher_input.planning.recovery_plan || null,
                    note: data.teacher_input.planning.note || null,
                },
            },
        }))
        .put(`/reports/${props.report.ulid}`, { preserveScroll: true });
}

function splitLines(value: string): string[] {
    return value
        .split('\n')
        .map((line) => line.trim())
        .filter((line) => line !== '');
}

const planningNeedsDetail = computed(() =>
    ['partially_complied', 'not_complied'].includes(characterisationForm.teacher_input.planning.compliance),
);

// ------------------------------------------------------------------ sections

function startEditing(section: SectionPayload) {
    editing.value = section.ulid;
    draftBody.value = section.body ?? '';
}

function cancelEditing() {
    editing.value = null;
    draftBody.value = '';
}

function saveSection(section: SectionPayload) {
    router.put(
        `/reports/${props.report.ulid}/seccoes/${section.ulid}`,
        { body: draftBody.value },
        { preserveScroll: true, onSuccess: cancelEditing },
    );
}

function toggleIncluded(section: SectionPayload) {
    router.put(
        `/reports/${props.report.ulid}/seccoes/${section.ulid}`,
        { included: !section.included },
        { preserveScroll: true },
    );
}

function regenerateSection(section: SectionPayload) {
    router.post(`/reports/${props.report.ulid}/seccoes/${section.ulid}/gerar`, {}, { preserveScroll: true });
}

function restoreSection(section: SectionPayload) {
    router.post(`/reports/${props.report.ulid}/seccoes/${section.ulid}/restaurar`, {}, { preserveScroll: true });
}

function regenerateAll() {
    router.post(`/reports/${props.report.ulid}/gerar`, {}, { preserveScroll: true });
}

// ------------------------------------------------------- aperfeiçoar redação

// The suggestion is a transient answer to one click. It arrives flashed in the
// props, lives here until the teacher decides, and is gone the moment anything
// else happens — because nothing about it was ever written (§19, §51).
const suggestion = ref<RewriteSuggestion | null>(props.rewrite ?? null);
const rewriteError = ref<{ section: string; message: string } | null>(props.rewriteError ?? null);
const rewritingSection = ref<string | null>(null);
const lastMode = ref<string | null>(null);

watch(
    () => props.rewrite,
    (value) => {
        suggestion.value = value ?? null;
    },
);

watch(
    () => props.rewriteError,
    (value) => {
        rewriteError.value = value ?? null;
    },
);

function requestRewrite(section: SectionPayload, mode: string) {
    lastMode.value = mode;
    rewritingSection.value = section.ulid;
    suggestion.value = null;
    rewriteError.value = null;

    router.post(
        `/reports/${props.report.ulid}/seccoes/${section.ulid}/aperfeicoar`,
        { mode },
        {
            preserveScroll: true,
            onFinish: () => {
                rewritingSection.value = null;
            },
        },
    );
}

function retryRewrite(section: SectionPayload) {
    requestRewrite(section, lastMode.value ?? props.ai.modes[0]?.value ?? 'same_tone');
}

// The one place that writes a body is the section editor, which is where this
// goes too. `assisted` changes nothing about what is stored — only what the
// audit trail records (§20, §21).
function acceptSuggestion(section: SectionPayload, text: string) {
    rewritingSection.value = section.ulid;

    router.put(
        `/reports/${props.report.ulid}/seccoes/${section.ulid}`,
        { body: text, assisted: true },
        {
            preserveScroll: true,
            onSuccess: () => {
                suggestion.value = null;
            },
            onFinish: () => {
                rewritingSection.value = null;
            },
        },
    );
}

function suggestionFor(section: SectionPayload): RewriteSuggestion | null {
    return suggestion.value?.section === section.ulid ? suggestion.value : null;
}

function errorFor(section: SectionPayload): string | null {
    return rewriteError.value?.section === section.ulid ? rewriteError.value.message : null;
}

// ----------------------------------------------------------------- reordering

const reordering = ref(false);
const savingOrder = ref(false);

// A working copy. Nothing is written until the teacher saves, so abandoning the
// panel leaves the report exactly as it was (§50, §51 — no autosave).
const draftOrder = ref<OrderableSection[]>([]);

function openReorder() {
    draftOrder.value = props.sections.map((section) => ({
        key: section.key,
        heading: section.heading,
        included: section.included,
    }));
    reordering.value = true;
}

const orderIsDirty = computed(() => {
    if (!reordering.value) {
        return false;
    }

    return draftOrder.value.some((section, index) => section.key !== props.sections[index]?.key);
});

function saveOrder() {
    savingOrder.value = true;

    router.put(
        `/reports/${props.report.ulid}/seccoes/ordem`,
        // The server matches by ulid; the working copy carries keys, so they
        // are mapped back here rather than shipped as indices (§8).
        {
            order: draftOrder.value
                .map((section) => props.sections.find((row) => row.key === section.key)?.ulid)
                .filter((ulid): ulid is string => typeof ulid === 'string'),
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                reordering.value = false;
            },
            onFinish: () => {
                savingOrder.value = false;
            },
        },
    );
}

function cancelReorder() {
    reordering.value = false;
    draftOrder.value = [];
}

// ------------------------------------------------------- save as template

const savingTemplate = ref(false);

const templateForm = useForm({
    kind: props.canSaveTemplate.institutional ? 'institutional' : 'personal',
    name: `${props.report.type_label} — ${props.report.subject_label}`,
    description: '',
    is_default: false,
});

function saveAsTemplate() {
    templateForm.post(`/reports/${props.report.ulid}/guardar-modelo`, {
        onSuccess: () => {
            savingTemplate.value = false;
        },
    });
}

function destroyReport() {
    router.delete(`/reports/${props.report.ulid}`);
}

// ------------------------------------------------------------- finalization

const confirmingFinalize = ref(false);

function finalize() {
    router.post(`/reports/${props.report.ulid}/finalizar`, {}, {
        preserveScroll: true,
        onFinish: () => {
            confirmingFinalize.value = false;
        },
    });
}

function derive() {
    router.post(`/reports/${props.report.ulid}/derivar`, {});
}
</script>

<template>
    <Head :title="report.title" />

    <div class="mx-auto w-full max-w-4xl space-y-6 p-4">
        <Link href="/reports" class="text-sm text-muted-foreground hover:underline">← Relatórios</Link>

        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                :title="report.title"
                :description="`${report.type_label} · ${report.subject_label} · ${report.scope_label}`"
            />

            <div class="flex flex-wrap items-center gap-2">
                <span
                    class="rounded-full px-2.5 py-1 text-xs font-medium"
                    :class="
                        isDraft
                            ? 'bg-amber-500/10 text-amber-700 dark:text-amber-400'
                            : 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400'
                    "
                >
                    {{ report.status_label }}
                </span>

                <!-- Plain links, not Inertia visits: a binary response cannot
                     come back through one. -->
                <div v-if="can.export" class="flex items-center gap-1">
                    <Button variant="outline" size="sm" as-child>
                        <a :href="`/reports/${report.ulid}/pdf`">
                            <FileDown class="size-3.5" />
                            PDF
                        </a>
                    </Button>
                    <Button variant="outline" size="sm" as-child>
                        <a :href="`/reports/${report.ulid}/word`">
                            <FileDown class="size-3.5" />
                            Word
                        </a>
                    </Button>
                </div>

                <div class="flex overflow-hidden rounded-md border border-border">
                    <button
                        v-if="isDraft"
                        type="button"
                        class="flex items-center gap-1.5 px-3 py-1.5 text-xs"
                        :class="mode === 'edit' ? 'bg-muted font-medium' : ''"
                        @click="mode = 'edit'"
                    >
                        <Pencil class="size-3.5" />
                        Editar
                    </button>
                    <button
                        type="button"
                        class="flex items-center gap-1.5 px-3 py-1.5 text-xs"
                        :class="mode === 'preview' ? 'bg-muted font-medium' : ''"
                        @click="mode = 'preview'"
                    >
                        <Eye class="size-3.5" />
                        Pré-visualizar
                    </button>
                </div>
            </div>
        </div>

        <!-- A finalized report is a document, and says so before anything else
             on the page suggests it can still be worked on (§37). -->
        <div
            v-if="!isDraft"
            class="rounded-lg border border-emerald-500/30 bg-emerald-500/5 p-4 text-sm"
        >
            <p class="font-medium">Relatório finalizado</p>
            <p class="mt-1 text-muted-foreground">
                O conteúdo está fixado — o texto, os números e a identidade da escola são os que existiam quando
                foi finalizado, a
                {{ report.finalized_at ? formatDate(report.finalized_at) : '—' }}
                <template v-if="report.finalized_by"> por {{ report.finalized_by }}</template>.
                Alterações posteriores aos dados não o reescrevem.
            </p>
            <Button v-if="can.derive" variant="outline" size="sm" class="mt-3" @click="derive">
                <Copy class="size-3.5" />
                Criar novo a partir deste
            </Button>
        </div>

        <!-- §35: what moved since the report this one started from. -->
        <section v-if="comparison" class="space-y-3 rounded-lg border border-border p-4">
            <div>
                <h2 class="font-medium">Desde «{{ comparison.base.title }}»</h2>
                <p class="mt-1 text-xs text-muted-foreground">{{ comparison.base.scope_label }}</p>
            </div>

            <ul class="divide-y divide-border overflow-hidden rounded-md border border-border">
                <li
                    v-for="row in comparison.rows"
                    :key="row.label"
                    class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm"
                >
                    <span>{{ row.label }}</span>
                    <span class="tabular-nums">
                        <span class="text-muted-foreground">{{ row.from }}</span>
                        <span class="mx-2 text-muted-foreground">→</span>
                        <span class="font-medium">{{ row.to }}</span>
                    </span>
                </li>
            </ul>

            <p class="text-xs text-muted-foreground">{{ comparison.caveat }}</p>
        </section>

        <!-- ============================================================ EDIT -->
        <template v-if="mode === 'edit' && isDraft">
            <section v-if="can.update" class="space-y-3 rounded-lg border border-border p-4">
                <div class="grid gap-2">
                    <Label for="title">Título</Label>
                    <div class="flex gap-2">
                        <Input id="title" v-model="titleForm.title" class="flex-1" />
                        <Button variant="outline" :disabled="titleForm.processing" @click="saveTitle">Guardar</Button>
                    </div>
                </div>
            </section>

            <!-- ------------------------------------------- caracterização -->
            <section v-if="can.update" class="space-y-5 rounded-lg border border-border p-4">
                <div>
                    <h2 class="font-medium">Caracterização</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        O Lapispro não dispõe de informação para caracterizar comportamento, atitude ou cumprimento da
                        planificação. O que indicar aqui é seu — e é o que dá origem às secções correspondentes.
                    </p>
                </div>

                <template v-if="characterisation.available && characterisation.asks_behaviour">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="behaviour">Comportamento</Label>
                            <select
                                id="behaviour"
                                v-model="characterisationForm.teacher_input.behaviour"
                                class="h-9 rounded-md border border-border bg-background px-2 text-sm"
                            >
                                <option value="">Sem resposta</option>
                                <option v-for="option in characterisation.behaviour" :key="option.value" :value="option.value">
                                    {{ option.label }}
                                </option>
                            </select>
                        </div>

                        <div class="grid gap-2">
                            <Label for="attitude">Atitude face às aprendizagens</Label>
                            <select
                                id="attitude"
                                v-model="characterisationForm.teacher_input.attitude"
                                class="h-9 rounded-md border border-border bg-background px-2 text-sm"
                            >
                                <option value="">Sem resposta</option>
                                <option v-for="option in characterisation.attitude" :key="option.value" :value="option.value">
                                    {{ option.label }}
                                </option>
                            </select>
                        </div>
                    </div>

                    <div class="space-y-2">
                        <Label>Aspetos a assinalar</Label>
                        <p class="text-xs text-muted-foreground">
                            Só entram no relatório os que assinalar, e sempre com o sentido que indicar.
                        </p>
                        <ul class="divide-y divide-border overflow-hidden rounded-md border border-border">
                            <li
                                v-for="indicator in characterisation.indicators"
                                :key="indicator.value"
                                class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm"
                            >
                                <span>{{ indicator.label }}</span>
                                <select
                                    class="h-8 rounded-md border border-border bg-background px-2 text-xs"
                                    :value="standingOf(indicator.value) ?? ''"
                                    @change="setStanding(indicator.value, ($event.target as HTMLSelectElement).value)"
                                >
                                    <option value="">Não assinalar</option>
                                    <option v-for="standing in characterisation.standings" :key="standing.value" :value="standing.value">
                                        {{ standing.label }}
                                    </option>
                                </select>
                            </li>
                        </ul>
                    </div>

                    <div class="grid gap-2">
                        <Label for="observation">Observação complementar</Label>
                        <textarea
                            id="observation"
                            v-model="characterisationForm.teacher_input.observation"
                            rows="3"
                            class="rounded-md border border-border bg-background p-2 text-sm"
                        ></textarea>
                    </div>

                    <!-- §14: dificuldade → estratégia → objetivo. -->
                    <div v-if="library && characterisation.asks_difficulties" class="space-y-2 border-t border-border pt-4">
                        <Label>Dificuldades identificadas</Label>
                        <p class="text-xs text-muted-foreground">
                            O Lapispro não infere dificuldades a partir dos resultados. Estas são as que validar — e
                            as estratégias que escolher ficam ligadas a cada uma.
                        </p>
                        <DifficultyPicker
                            v-model="characterisationForm.teacher_input.difficulties"
                            :difficulties="library.difficulties"
                            :strategies="library.strategies"
                            :domains="library.domains"
                        />
                    </div>

                    <!-- §57: two separate decisions, and both are the teacher's. -->
                    <div
                        v-if="enrollments.length > 0 && characterisation.asks_attention"
                        class="space-y-2 border-t border-border pt-4"
                    >
                        <Label>Alunos que requerem acompanhamento particular</Label>
                        <p class="text-xs text-muted-foreground">
                            Assinalar não é o mesmo que identificar. Sem a autorização abaixo, o relatório diz
                            quantos são e não diz quem.
                        </p>

                        <ul class="divide-y divide-border overflow-hidden rounded-md border border-border">
                            <li v-for="enrollment in enrollments" :key="enrollment.id" class="px-3 py-2 text-sm">
                                <label class="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        class="size-4"
                                        :checked="isFlagged(enrollment.id)"
                                        @change="toggleFlagged(enrollment.id)"
                                    />
                                    <span>
                                        <template v-if="enrollment.class_number">{{ enrollment.class_number }}. </template>
                                        {{ enrollment.name }}
                                    </span>
                                </label>
                                <Input
                                    v-if="isFlagged(enrollment.id)"
                                    class="mt-2 h-8"
                                    placeholder="Nota (opcional)"
                                    @update:model-value="setFlaggedNote(enrollment.id, String($event))"
                                />
                            </li>
                        </ul>

                        <label class="flex items-start gap-2 rounded-md border border-amber-500/30 bg-amber-500/5 p-3 text-sm">
                            <input v-model="characterisationForm.name_students" type="checkbox" class="mt-0.5 size-4" />
                            <span>
                                <span class="block font-medium">Identificar os alunos pelo nome no relatório</span>
                                <span class="block text-xs text-muted-foreground">
                                    Um relatório de turma é agregado por omissão.
                                </span>
                            </span>
                        </label>
                    </div>
                </template>

                <p
                    v-else-if="!characterisation.available"
                    class="rounded-md bg-muted/50 p-3 text-sm text-muted-foreground"
                >
                    A caracterização de comportamento, atitude e dificuldades faz parte do plano Pro. As secções
                    descritivas do relatório não dependem dela.
                </p>

                <!-- Planning is Base: transcription, not analysis. -->
                <div v-if="characterisation.asks_planning" class="space-y-4 border-t border-border pt-4">
                    <div class="grid gap-2">
                        <Label for="compliance">Cumprimento da planificação</Label>
                        <select
                            id="compliance"
                            v-model="characterisationForm.teacher_input.planning.compliance"
                            class="h-9 rounded-md border border-border bg-background px-2 text-sm"
                        >
                            <option value="">Sem resposta</option>
                            <option v-for="option in characterisation.planning" :key="option.value" :value="option.value">
                                {{ option.label }}
                            </option>
                        </select>
                    </div>

                    <template v-if="planningNeedsDetail">
                        <div class="grid gap-2">
                            <Label for="pending">Conteúdos não abordados (um por linha)</Label>
                            <textarea
                                id="pending"
                                v-model="characterisationForm.teacher_input.planning.pending_content"
                                rows="2"
                                class="rounded-md border border-border bg-background p-2 text-sm"
                            ></textarea>
                        </div>

                        <div class="grid gap-2">
                            <Label for="postponed">Conteúdos adiados (um por linha)</Label>
                            <textarea
                                id="postponed"
                                v-model="characterisationForm.teacher_input.planning.postponed_content"
                                rows="2"
                                class="rounded-md border border-border bg-background p-2 text-sm"
                            ></textarea>
                        </div>

                        <div class="grid gap-2">
                            <Label for="reason">Motivo</Label>
                            <Input id="reason" v-model="characterisationForm.teacher_input.planning.reason" />
                        </div>
                    </template>
                </div>

                <div class="grid gap-2 border-t border-border pt-4">
                    <Label for="final-note">Nota de síntese final</Label>
                    <textarea
                        id="final-note"
                        v-model="characterisationForm.teacher_input.final_note"
                        rows="3"
                        class="rounded-md border border-border bg-background p-2 text-sm"
                        placeholder="Perspetivas para o período seguinte."
                    ></textarea>
                </div>

                <Button :disabled="characterisationForm.processing" @click="saveCharacterisation">
                    Guardar e regenerar
                </Button>
            </section>

            <!-- ------------------------------------------------- as secções -->
            <section class="space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="font-medium">Secções</h2>
                    <div class="flex flex-wrap items-center gap-2">
                        <Button
                            v-if="can.update && !reordering"
                            variant="outline"
                            size="sm"
                            @click="openReorder"
                        >
                            <ArrowUpDown class="size-3.5" />
                            Reordenar
                        </Button>
                        <Button v-if="can.update" variant="outline" size="sm" @click="regenerateAll">
                            <RefreshCw class="size-3.5" />
                            Regenerar tudo
                        </Button>
                    </div>
                </div>

                <!-- §9, §10: mouse and keyboard, and nothing is written until
                     the teacher saves (§50, §51). -->
                <div v-if="reordering" class="space-y-3 rounded-lg border border-border p-4">
                    <div>
                        <h3 class="text-sm font-medium">Ordem das secções</h3>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Arraste, ou use as setas de cada linha. A ordem escolhida é a ordem do documento, da
                            pré-visualização e das exportações. Alterar a ordem não toca no texto.
                        </p>
                    </div>

                    <SectionOrderList v-model="draftOrder" />

                    <div class="flex flex-wrap items-center gap-2">
                        <Button size="sm" :disabled="!orderIsDirty || savingOrder" @click="saveOrder">
                            Guardar ordem
                        </Button>
                        <Button variant="ghost" size="sm" @click="cancelReorder">Cancelar</Button>
                        <span v-if="orderIsDirty" class="text-xs text-amber-700 dark:text-amber-400">
                            Alterações por guardar.
                        </span>
                    </div>
                </div>

                <p class="text-xs text-muted-foreground">
                    Regenerar não apaga o que reescreveu: só as secções que ainda têm o texto automático são
                    atualizadas.
                </p>

                <article
                    v-for="section in sections"
                    :key="section.ulid"
                    class="rounded-lg border p-4"
                    :class="section.included ? 'border-border' : 'border-dashed border-border bg-muted/20'"
                >
                    <header class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <h3 class="font-medium">{{ section.heading }}</h3>
                            <p class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                <span v-if="section.edited" class="rounded-full bg-blue-500/10 px-2 py-0.5 text-blue-700 dark:text-blue-400">
                                    Editada por si
                                </span>
                                <span v-if="!section.has_content">Sem conteúdo — não será impressa</span>
                            </p>
                        </div>

                        <div v-if="can.update" class="flex items-center gap-1">
                            <SectionRewrite
                                :can-rewrite="section.can_rewrite === true"
                                :may-name-students="section.may_name_students === true"
                                :available="ai.available"
                                :reason="ai.reason"
                                :modes="ai.modes"
                                :busy="rewritingSection === section.ulid"
                                @request="(mode) => requestRewrite(section, mode)"
                            />
                            <Button variant="ghost" size="sm" @click="toggleIncluded(section)">
                                {{ section.included ? 'Excluir' : 'Incluir' }}
                            </Button>
                            <Button
                                v-if="section.can_restore"
                                variant="ghost"
                                size="sm"
                                title="Restaurar o texto automático"
                                @click="restoreSection(section)"
                            >
                                <RotateCcw class="size-3.5" />
                            </Button>
                            <Button variant="ghost" size="sm" title="Regenerar a partir dos dados atuais" @click="regenerateSection(section)">
                                <RefreshCw class="size-3.5" />
                            </Button>
                            <Button v-if="editing !== section.ulid" variant="ghost" size="sm" @click="startEditing(section)">
                                <Pencil class="size-3.5" />
                            </Button>
                        </div>
                    </header>

                    <div v-if="editing === section.ulid" class="mt-3 space-y-2">
                        <textarea
                            v-model="draftBody"
                            rows="8"
                            class="w-full rounded-md border border-border bg-background p-3 text-sm leading-relaxed"
                        ></textarea>
                        <div class="flex gap-2">
                            <Button size="sm" @click="saveSection(section)">
                                <Check class="size-3.5" />
                                Guardar
                            </Button>
                            <Button variant="ghost" size="sm" @click="cancelEditing">
                                <X class="size-3.5" />
                                Cancelar
                            </Button>
                        </div>
                    </div>

                    <template v-else>
                        <p v-if="section.body" class="mt-3 text-sm leading-relaxed whitespace-pre-line">
                            {{ section.body }}
                        </p>
                        <p v-else class="mt-3 text-sm text-muted-foreground italic">
                            Sem texto gerado.
                        </p>

                        <ReportSectionData :section-key="section.key" :data="section.data" />
                    </template>

                    <p
                        v-if="errorFor(section)"
                        class="mt-3 rounded-md border border-amber-500/40 bg-amber-500/5 p-3 text-sm text-amber-800 dark:text-amber-400"
                    >
                        {{ errorFor(section) }}
                    </p>

                    <RewritePreview
                        v-if="suggestionFor(section)"
                        :suggestion="suggestionFor(section)!"
                        :busy="rewritingSection === section.ulid"
                        @accept="(text) => acceptSuggestion(section, text)"
                        @retry="retryRewrite(section)"
                        @dismiss="suggestion = null"
                    />
                </article>
            </section>

            <!-- ------------------------------------- guardar como modelo -->
            <section
                v-if="can.update && (canSaveTemplate.personal || canSaveTemplate.institutional)"
                class="space-y-3 rounded-lg border border-border p-4"
            >
                <div>
                    <h2 class="font-medium">Guardar estrutura como modelo</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Guarda apenas a organização deste relatório — que secções entram, por que ordem e com que
                        registo. Nenhum número, nome ou texto deste relatório vai para o modelo.
                    </p>
                </div>

                <template v-if="savingTemplate">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="grid gap-2 sm:col-span-2">
                            <Label for="template-name">Nome</Label>
                            <Input id="template-name" v-model="templateForm.name" />
                            <InputError :message="templateForm.errors.name" />
                        </div>

                        <div class="grid gap-2 sm:col-span-2">
                            <Label for="template-description">Descrição (opcional)</Label>
                            <Input id="template-description" v-model="templateForm.description" />
                        </div>

                        <div v-if="canSaveTemplate.institutional" class="grid gap-2">
                            <Label for="template-kind">Disponível para</Label>
                            <select
                                id="template-kind"
                                v-model="templateForm.kind"
                                class="h-9 rounded-md border border-border bg-background px-2 text-sm"
                            >
                                <option v-if="canSaveTemplate.personal" value="personal">Apenas para mim</option>
                                <option value="institutional">Toda a escola</option>
                            </select>
                        </div>
                    </div>

                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="templateForm.is_default" type="checkbox" class="size-4" />
                        Usar por omissão em novos relatórios deste tipo
                    </label>

                    <div class="flex flex-wrap items-center gap-2">
                        <Button size="sm" :disabled="templateForm.processing" @click="saveAsTemplate">
                            Guardar modelo
                        </Button>
                        <Button variant="ghost" size="sm" @click="savingTemplate = false">Cancelar</Button>
                    </div>
                </template>

                <Button v-else variant="outline" size="sm" @click="savingTemplate = true">
                    <Save class="size-3.5" />
                    Guardar como modelo
                </Button>
            </section>

            <!-- ---------------------------------------------- finalização -->
            <section v-if="can.finalize" class="space-y-3 rounded-lg border border-border p-4">
                <div>
                    <h2 class="font-medium">Finalizar</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Fixa o conteúdo. A partir daí, alterações às classificações, ao logótipo ou ao nome da
                        escola deixam de afetar este documento. Não é reversível — para o corrigir, cria-se um
                        novo a partir dele.
                    </p>
                </div>

                <div v-if="confirmingFinalize" class="flex flex-wrap items-center gap-2">
                    <Button size="sm" @click="finalize">Sim, finalizar</Button>
                    <Button variant="ghost" size="sm" @click="confirmingFinalize = false">Cancelar</Button>
                </div>
                <Button v-else variant="outline" size="sm" @click="confirmingFinalize = true">
                    <Lock class="size-3.5" />
                    Finalizar relatório
                </Button>
            </section>

            <div v-if="can.delete" class="border-t border-border pt-6">
                <Button variant="ghost" size="sm" class="text-destructive" @click="destroyReport">
                    <Trash2 class="size-3.5" />
                    Eliminar rascunho
                </Button>
            </div>
        </template>

        <!-- ========================================================= PREVIEW -->
        <template v-else>
            <div class="overflow-hidden rounded-lg border border-border bg-card">
                <div class="mx-auto max-w-[52rem] space-y-6 p-8">
                    <ReportLetterhead :identity="identity" />

                    <div class="space-y-1 border-b border-border pb-4">
                        <h1 class="text-lg font-semibold">{{ report.title }}</h1>
                        <p class="text-sm text-muted-foreground">
                            {{ report.subject_label }} · {{ report.scope_label }}
                        </p>
                    </div>

                    <section v-for="section in printable" :key="section.ulid" class="space-y-2">
                        <h2 class="text-sm font-semibold">{{ section.heading }}</h2>
                        <p v-if="section.body" class="text-sm leading-relaxed whitespace-pre-line">
                            {{ section.body }}
                        </p>
                        <ReportSectionData :section-key="section.key" :data="section.data" />
                    </section>

                    <p v-if="printable.length === 0" class="text-sm text-muted-foreground">
                        Nenhuma secção com conteúdo. Preencha a caracterização ou verifique se existem dados no
                        período escolhido.
                    </p>

                    <p v-if="identity.footer_note" class="border-t border-border pt-4 text-xs text-muted-foreground">
                        {{ identity.footer_note }}
                    </p>
                </div>
            </div>
        </template>
    </div>
</template>
