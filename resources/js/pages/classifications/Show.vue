<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { CircleAlert, Lock, PencilLine, RefreshCw, Send } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import StudentAvatar from '@/components/StudentAvatar.vue';

type Proposal = {
    value: string | null;
    state: 'resolved' | 'unconfigured' | 'no_result';
    is_percentage: boolean;
};
/** What the teacher decided, on the scale: «4» plus «Bom», or just a number. */
type Decision = { code: string; label: string | null; scale_level_id: number | null };
/** The student's own overall judgement, as Resultados shows it. */
type SelfAssessment = { code: string; label: string; sequence: number; is_negative: boolean } | null;
type Level = { id: number; code: string; label: string };
type Classification = {
    ulid: string;
    status: string;
    status_label: string;
    proposal: Proposal;
    proposed_scale_level_id: number | null;
    decision: Decision | null;
    overridden: boolean;
    observation: string | null;
    /** «Usar proposta» — a first decision only. */
    can_confirm: boolean;
    /** «Alterar» — true until the classification is published. */
    can_change: boolean;
    is_published: boolean;
};
type Row = {
    enrollment_ulid: string;
    name: string;
    photo_url: string | null;
    class_number: number | null;
    weighted_average: string | null;
    self_assessment: SelfAssessment;
    classification: Classification | null;
};

const props = defineProps<{
    schoolClass: {
        ulid: string;
        label: string;
        subject: string;
        has_profile: boolean;
        scale_name: string | null;
        /** «Nível atribuído» or «Classificação atribuída» — the scale's own terms. */
        decision_label: string;
        classifies_by_level: boolean;
        levels: Level[];
        min_value: string | null;
        max_value: string | null;
    };
    scope: 'period' | 'accumulated';
    periods: { ulid: string; label: string; selected: boolean }[];
    rows: Row[];
}>();

const page = usePage();
const selectedPeriod = computed(() => props.periods.find((period) => period.selected) ?? null);
const pending = computed(() => props.rows.filter((row) => row.classification?.can_confirm).length);
const confirmedCount = computed(() => props.rows.filter((row) => row.classification?.status === 'confirmed').length);

function basePath(periodUlid?: string): string {
    const period = periodUlid ?? selectedPeriod.value?.ulid ?? '';

    return `/classes/${props.schoolClass.ulid}/classifications/${period}`;
}

function selectScope(scope: 'period' | 'accumulated'): void {
    router.get(basePath(), { scope }, { preserveScroll: true });
}

// The period's own weighted average, as Resultados prints it. "—" for a null (no
// computable result), never 0.
function pct(value: string | null): string {
    return value === null ? '—' : `${Number(value).toFixed(1)}%`;
}

// «15.000» stored is «15» read; «15.500» stays «15,5». Never string surgery on
// the zeros — «100.000» would lose its own.
function onScale(value: string): string {
    return String(Number(value)).replace('.', ',');
}

/**
 * The decision as the cell shows it. A band is named by the scale — which may
 * be «MB» and not a number at all — so its code is printed as it stands; an
 * interval scale's decision is a number and is read as one.
 */
function decisionText(decision: Decision): string {
    return decision.scale_level_id === null ? onScale(decision.code) : decision.code;
}

/** «4 — Bom» when the scale has bands, «15» when it is an interval. */
function decisionTitle(decision: Decision): string {
    return decision.label === null ? decisionText(decision) : `${decision.code} — ${decision.label}`;
}

// The proposal already comes translated to the profile's scale — a 4, a "Bom",
// an 80%. The "%" is only appended when the scale is itself a percentage.
function proposalLabel(proposal: Proposal): string {
    if (proposal.value === null) {
        return '—';
    }

    return proposal.is_percentage ? `${proposal.value}%` : proposal.value;
}

function selectPeriod(ulid: string): void {
    router.get(basePath(ulid), { scope: props.scope }, { preserveScroll: true });
}

const proposing = ref(false);
const publishing = ref(false);

function propose(): void {
    if (selectedPeriod.value === null) {
        return;
    }

    router.post(
        `${basePath()}/propose`,
        { scope: props.scope },
        {
            preserveScroll: true,
            onStart: () => (proposing.value = true),
            onFinish: () => (proposing.value = false),
        },
    );
}

function publish(): void {
    if (selectedPeriod.value === null || confirmedCount.value === 0) {
        return;
    }

    router.post(
        `${basePath()}/publish`,
        { scope: props.scope },
        {
            preserveScroll: true,
            onStart: () => (publishing.value = true),
            onFinish: () => (publishing.value = false),
        },
    );
}

/**
 * The one endpoint a decision is written through, from either screen.
 *
 * Addressed by the student and the period rather than by a stored row: a period
 * whose proposals were never generated has none yet, and the teacher's
 * classification does not wait on one.
 */
function decisionUrl(row: Row): string {
    return `${basePath()}/${row.enrollment_ulid}/decide`;
}

/**
 * Whether the teacher may still classify this student here. Not «is there a
 * proposal» — what closes the door is publication, and only that.
 */
function canDecide(row: Row): boolean {
    if (! props.schoolClass.has_profile || selectedPeriod.value === null) {
        return false;
    }

    return row.classification === null || row.classification.can_change;
}

// One editor at a time — which row's, if any.
const openUlid = ref<string | null>(null);
const confirmForm = useForm<{ final_scale_level_id: number | null; final_value: string | null; override_reason: string }>({
    final_scale_level_id: null,
    final_value: null,
    override_reason: '',
});

/**
 * «Usar proposta» — confirms with no decision of its own, which the service
 * reads as «the proposal IS the decision» and writes down explicitly. Nothing
 * is ever filled in for the teacher without this click (§6, §11).
 */
function useProposal(row: Row): void {
    if (! row.classification?.can_confirm) {
        return;
    }

    confirmForm.transform(() => ({ final_scale_level_id: null, final_value: null, override_reason: '' }));
    confirmForm.post(decisionUrl(row), { preserveScroll: true });
}

function openEditor(row: Row): void {
    if (! canDecide(row)) {
        return;
    }

    openUlid.value = row.enrollment_ulid;
    confirmForm.clearErrors();
    // The editor opens on what the row currently says — the decision already
    // taken, or the proposal while there is none — in plain sight, and still
    // takes a click to become a decision.
    const decided = row.classification?.decision ?? null;

    confirmForm.final_scale_level_id = decided?.scale_level_id ?? row.classification?.proposed_scale_level_id ?? null;
    confirmForm.final_value = decided !== null && decided.scale_level_id === null ? decided.code : (row.classification?.proposal.value ?? null);
    confirmForm.override_reason = row.classification?.observation ?? '';
}

function submitDecision(row: Row): void {
    // Only the field this scale is decided in travels — a level id on a scale
    // made of levels, a value on one that is an interval.
    confirmForm.transform((data) => ({
        final_scale_level_id: props.schoolClass.classifies_by_level ? data.final_scale_level_id : null,
        final_value: props.schoolClass.classifies_by_level ? null : data.final_value,
        override_reason: data.override_reason,
    }));
    confirmForm.post(decisionUrl(row), {
        preserveScroll: true,
        onSuccess: () => {
            openUlid.value = null;
        },
    });
}

const errorFor = computed(() => (page.props.errors as Record<string, string>)?.final_value ?? null);
</script>

<template>
    <Head :title="`Classificações — ${schoolClass.label}`" />

    <div class="space-y-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <Heading :title="`Classificações — ${schoolClass.label}`" :description="schoolClass.subject" />
                <Link :href="`/classes/${schoolClass.ulid}/results`" class="text-sm text-muted-foreground hover:underline">
                    ← Ver resultados
                </Link>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex gap-1 rounded-md bg-muted/40 p-0.5">
                    <button
                        type="button"
                        class="rounded px-3 py-1 text-sm"
                        :class="scope === 'period' ? 'bg-background font-medium shadow-sm' : 'text-muted-foreground hover:text-foreground'"
                        @click="selectScope('period')"
                    >
                        Por período
                    </button>
                    <button
                        type="button"
                        class="rounded px-3 py-1 text-sm"
                        :class="scope === 'accumulated' ? 'bg-background font-medium shadow-sm' : 'text-muted-foreground hover:text-foreground'"
                        @click="selectScope('accumulated')"
                    >
                        Acumulado
                    </button>
                </div>
                <div v-if="periods.length" class="flex gap-1">
                    <button
                        v-for="period in periods"
                        :key="period.ulid"
                        type="button"
                        class="rounded-md border px-3 py-1.5 text-sm"
                        :class="period.selected ? 'border-primary bg-primary text-primary-foreground' : 'border-border hover:bg-muted/40'"
                        @click="selectPeriod(period.ulid)"
                    >
                        {{ period.label }}
                    </button>
                </div>
            </div>
        </div>

        <p v-if="!schoolClass.has_profile" class="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            Esta turma não tem perfil de avaliação associado, por isso não há classificações a propor.
        </p>

        <template v-else>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-muted-foreground">
                    <template v-if="scope === 'accumulated'">
                        Acumulado: reprocessa todos os elementos válidos do ano até este período (não é a média dos períodos).
                    </template>
                    <template v-else>O sistema propõe; o professor confirma.</template>
                    {{ pending }} por confirmar.
                </p>
                <div class="flex gap-2">
                    <button
                        v-if="confirmedCount > 0"
                        type="button"
                        class="inline-flex items-center gap-2 rounded-md border border-emerald-600 px-4 py-2 text-sm font-medium text-emerald-700 transition-colors hover:bg-emerald-50 disabled:opacity-50 dark:text-emerald-400 dark:hover:bg-emerald-950"
                        :disabled="publishing"
                        @click="publish"
                    >
                        <Send class="size-4" />
                        Publicar confirmadas ({{ confirmedCount }})
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground transition-opacity hover:opacity-90 disabled:opacity-50"
                        :disabled="selectedPeriod === null || proposing"
                        @click="propose"
                    >
                        <RefreshCw class="size-4" :class="{ 'animate-spin': proposing }" />
                        Gerar / atualizar propostas
                    </button>
                </div>
            </div>

            <!-- An accept/confirm that fails (stale proposal, already confirmed)
                 comes back with an error but no editor open — show it here so the
                 click is never silently swallowed. -->
            <p
                v-if="errorFor && openUlid === null"
                class="rounded-md border border-red-300 bg-red-50 px-4 py-2 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300"
            >
                {{ errorFor }}
            </p>

            <div v-if="rows.length === 0" class="rounded-lg border border-dashed border-border p-10 text-center">
                <p class="text-sm text-muted-foreground">Sem alunos neste período.</p>
            </div>

            <div v-else class="overflow-x-auto rounded-lg border border-border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <!-- The same reading order as Resultados: the evidence,
                             what LÁPIS proposes from it, what the student said,
                             and then the decision (§10). -->
                        <tr>
                            <th class="px-3 py-2 font-medium">Aluno</th>
                            <th class="px-3 py-2 text-center font-medium">Estado</th>
                            <th class="px-3 py-2 text-right font-medium">
                                {{ scope === 'accumulated' ? 'Média Ponderada Acumulada' : 'Média Ponderada' }}
                            </th>
                            <th class="px-3 py-2 text-right font-medium">Proposta</th>
                            <th class="px-3 py-2 text-right font-medium">Autoavaliação</th>
                            <th class="px-3 py-2 text-right font-medium">{{ schoolClass.decision_label }}</th>
                            <th class="px-3 py-2 text-right font-medium">Ação</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <template v-for="row in rows" :key="row.name">
                            <tr class="hover:bg-muted/20">
                                <td class="px-3 py-2 whitespace-nowrap">
                                    <div class="flex items-center gap-1.5">
                                        <span class="text-muted-foreground">{{ row.class_number ?? '—' }}</span>
                                        <StudentAvatar :photo-url="row.photo_url" size="xs" />
                                        <span class="font-medium">{{ row.name }}</span>
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-center">
                                    <span
                                        v-if="row.classification"
                                        class="rounded-full px-2 py-0.5 text-xs"
                                        :class="{
                                            'bg-muted text-muted-foreground': row.classification.status === 'proposed',
                                            'bg-emerald-100 text-emerald-800': row.classification.status === 'confirmed' || row.classification.status === 'published',
                                        }"
                                    >{{ row.classification.status_label }}</span>
                                    <span v-else class="text-xs text-muted-foreground">Sem proposta</span>
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">
                                    <span :class="{ 'text-muted-foreground': row.weighted_average === null }">{{ pct(row.weighted_average) }}</span>
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">
                                    <span v-if="row.classification" :class="{ 'text-muted-foreground': row.classification.proposal.value === null }">
                                        {{ proposalLabel(row.classification.proposal) }}
                                    </span>
                                    <span v-else class="text-muted-foreground">—</span>
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">
                                    <span
                                        v-if="row.self_assessment"
                                        :title="`Autoavaliação do aluno: ${row.self_assessment.code} — ${row.self_assessment.label}`"
                                    >{{ row.self_assessment.code }}</span>
                                    <span v-else class="text-muted-foreground" title="O aluno não respondeu à autoavaliação global deste período.">—</span>
                                </td>
                                <!-- The decision, on the scale — a 4, a 15. Never
                                     the normalized percentage, which is a
                                     different number about a different thing. -->
                                <td class="px-3 py-2 text-right font-semibold tabular-nums">
                                    <template v-if="row.classification?.decision">
                                        <span :title="decisionTitle(row.classification.decision)">
                                            {{ decisionText(row.classification.decision) }}
                                        </span>
                                        <CircleAlert
                                            v-if="row.classification.overridden"
                                            class="ml-0.5 inline size-3 text-amber-500"
                                            :title="
                                                row.classification.observation
                                                    ? `Diferente da proposta. ${row.classification.observation}`
                                                    : 'Diferente da proposta do LÁPIS.'
                                            "
                                        />
                                    </template>
                                    <span v-else class="text-muted-foreground">—</span>
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <!-- Until it is published, the decision is
                                         still the teacher's to revise; «Usar
                                         proposta» is how a first one is made and
                                         belongs to a proposal alone. -->
                                    <div v-if="canDecide(row)" class="flex justify-end gap-2">
                                        <button
                                            type="button"
                                            class="rounded-md border border-border px-2.5 py-1 text-xs hover:bg-muted/40"
                                            @click="openEditor(row)"
                                        >
                                            <PencilLine class="mr-1 inline size-3" />Alterar
                                        </button>
                                        <button
                                            v-if="row.classification?.can_confirm"
                                            type="button"
                                            class="rounded-md bg-primary px-2.5 py-1 text-xs font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50"
                                            :disabled="confirmForm.processing"
                                            @click="useProposal(row)"
                                        >
                                            Usar proposta
                                        </button>
                                    </div>
                                    <span
                                        v-else-if="row.classification?.is_published"
                                        class="inline-flex items-center gap-1 text-xs text-muted-foreground"
                                        title="Esta classificação já foi publicada. Uma decisão publicada só muda por substituição, e esse mecanismo ainda não existe no LÁPIS."
                                    >
                                        <Lock class="size-3" />Publicada
                                    </span>
                                    <span v-else-if="row.classification" class="text-xs text-muted-foreground">✓</span>
                                </td>
                            </tr>
                            <tr v-if="openUlid === row.enrollment_ulid" class="bg-muted/20">
                                <td colspan="7" class="px-3 py-3">
                                    <div class="flex flex-wrap items-end gap-3">
                                        <label class="text-sm">
                                            <span class="mb-1 block text-xs text-muted-foreground">{{ schoolClass.decision_label }}</span>
                                            <!-- A closed list on a scale made of
                                                 levels: there is no 3,5 to assign,
                                                 and a free field would invite one. -->
                                            <select
                                                v-if="schoolClass.classifies_by_level"
                                                v-model="confirmForm.final_scale_level_id"
                                                class="w-48 rounded-md border border-border bg-background px-2 py-1"
                                            >
                                                <option v-for="level in schoolClass.levels" :key="level.id" :value="level.id">
                                                    {{ level.code }} — {{ level.label }}
                                                </option>
                                            </select>
                                            <!-- An interval: its own limits, taken
                                                 from the scale and not written here. -->
                                            <input
                                                v-else
                                                v-model="confirmForm.final_value"
                                                type="number"
                                                step="0.001"
                                                :min="schoolClass.min_value ?? undefined"
                                                :max="schoolClass.max_value ?? undefined"
                                                class="w-28 rounded-md border border-border bg-background px-2 py-1 tabular-nums"
                                            />
                                        </label>
                                        <label class="flex-1 text-sm">
                                            <span class="mb-1 block text-xs text-muted-foreground">Observação (opcional)</span>
                                            <input
                                                v-model="confirmForm.override_reason"
                                                type="text"
                                                maxlength="1000"
                                                class="w-full rounded-md border border-border bg-background px-2 py-1"
                                                placeholder="Ex.: participação sustentada não refletida nos instrumentos"
                                            />
                                        </label>
                                        <div class="flex gap-2">
                                            <button
                                                type="button"
                                                class="rounded-md border border-border px-3 py-1.5 text-sm hover:bg-muted/40"
                                                @click="openUlid = null"
                                            >
                                                Cancelar
                                            </button>
                                            <button
                                                type="button"
                                                class="rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50"
                                                :disabled="confirmForm.processing"
                                                @click="submitDecision(row)"
                                            >
                                                Confirmar
                                            </button>
                                        </div>
                                    </div>
                                    <p v-if="errorFor" class="mt-2 text-xs text-red-600">{{ errorFor }}</p>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </template>

        <p class="flex items-start gap-2 text-xs text-muted-foreground">
            <CircleAlert class="mt-0.5 size-3.5 shrink-0" />
            <span>
                A <strong>Média Ponderada</strong> é o resultado deste período em percentagem, e a
                <strong>Proposta</strong> é esse resultado lido na escala do perfil<template v-if="schoolClass.scale_name">
                ({{ schoolClass.scale_name }})</template>. O <strong>{{ schoolClass.decision_label }}</strong>
                é a decisão do professor, dada nessa mesma escala e nunca em percentagem — «Usar proposta» adota
                a proposta, «Alterar» atribui outra. Atribuir diferente da proposta não exige justificação; a
                observação é opcional e fica registada, tal como a proposta original, o autor e a data.
                Uma classificação <strong>confirmada continua a poder ser alterada</strong> até ser publicada;
                depois de <strong>publicada</strong> fica fechada. A confirmação congela um registo de como o
                valor foi obtido. A <strong>Autoavaliação</strong> é a resposta do aluno e é independente das
                duas. "—" significa sem elementos, nunca zero.
            </span>
        </p>
    </div>
</template>
