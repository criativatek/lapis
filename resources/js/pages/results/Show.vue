<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Check, CircleAlert, Lock, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import CoverageWarning from '@/components/CoverageWarning.vue';
import Heading from '@/components/Heading.vue';
import StudentAvatar from '@/components/StudentAvatar.vue';
import { qualitativeToneClasses, qualitativeToneFor } from '@/lib/qualitativeTone';
import { pct, TREND_SHAPE, trendArrow, trendClasses, trendTitle } from '@/lib/results';
import type { Evolution } from '@/lib/results';
import type { Coverage } from '@/types';

type DomainCol = { id: number; name: string };

/**
 * A band of the profile's own scale, for the canonical colour resolver.
 *
 * `code` is the value itself — a 4, a 16 — and `label` the qualitative mention
 * that comes with it on the scales that have one.
 */
type Level = { code: string; label: string; sequence: number; is_negative: boolean } | null;

type DomainValue = {
    domain_id: number;
    value: string | null;
    warning: boolean;
    coverage: Coverage;
    evolution: Evolution;
};
type Proposal = {
    value: string | null;
    state: 'resolved' | 'unconfigured' | 'no_result';
    is_percentage: boolean;
};
type Row = {
    enrollment_ulid: string;
    name: string;
    photo_url: string | null;
    class_number: number | null;
    overall: string | null;
    proposal: Proposal;
    has_value: boolean;
    coverage_warning: boolean;
    coverage: Coverage;
    domains: DomainValue[];
    evolution: Evolution;
    accumulated: string | null;
    self_assessment: Level;
    classification: {
        ulid: string;
        status: string;
        /** «Usar proposta» — how a first decision is made, and only that. */
        can_confirm: boolean;
        /** Editable until it is published. */
        can_change: boolean;
        is_published: boolean;
        proposed: Level;
        final: Level;
        differs_from_proposal: boolean;
    } | null;
};

/** The scale's own terms for the decision: what to call it, what to offer. */
type DecisionScale = {
    label: string;
    classifies_by_level: boolean;
    levels: { id: number; code: string; label: string }[];
    min_value: string | null;
    max_value: string | null;
};

// A row whose warning has no detail still gets a tooltip, not a blank one.
const NO_COVERAGE: Coverage = { absences: [], no_elements: false, excluded_domain_ids: [] };

const page = usePage();

const props = defineProps<{
    schoolClass: { ulid: string; label: string; subject: string; has_profile: boolean; scale_name: string | null };
    periods: { ulid: string; label: string; selected: boolean }[];
    domains: DomainCol[];
    rows: Row[];
    /** «Média Ponderada» — this screen shows the period's own evidence (§4). */
    weightedAverageLabel: string;
    isFirstPeriod: boolean;
    scaleBands: { label: string; sequence: number; is_negative: boolean }[];
    decision: DecisionScale;
}>();

/**
 * DESEMPENHO, from the canonical resolver — never a colour invented here.
 */
function levelClasses(level: Level): string {
    if (level === null) {
        return 'text-muted-foreground';
    }

    return qualitativeToneClasses[qualitativeToneFor(level, props.scaleBands)];
}

function domainValue(row: Row, domainId: number): DomainValue | undefined {
    return row.domains.find((domain) => domain.domain_id === domainId);
}

function domainCoverage(row: Row, domainId: number): Coverage {
    return domainValue(row, domainId)?.coverage ?? NO_COVERAGE;
}

function selectPeriod(ulid: string): void {
    router.get(`/classes/${props.schoolClass.ulid}/results/${ulid}`, {}, { preserveScroll: true });
}

// ---------------------------------------------------------------- a decisão

/**
 * The decision is taken HERE, where the student can actually be seen — the
 * domains, the average, the proposal and what they said about themselves, side
 * by side. It is written through the very endpoint Classificações posts to, so
 * there is one service, one validation, one lock and one trail; this screen only
 * says which classification and what was chosen.
 */
const editingUlid = ref<string | null>(null);
const savingUlid = ref<string | null>(null);
const chosenLevelId = ref<number | null>(null);
const chosenValue = ref<string | null>(null);

const decisionError = computed(() => (page.props.errors as Record<string, string>)?.final_value ?? null);
const selectedPeriod = computed(() => props.periods.find((period) => period.selected) ?? null);

/**
 * Whether the teacher may still classify this student in this period.
 *
 * NOT «is there a proposal». A period whose proposals were never generated, or
 * a student the engine could reach no value for, are both cases where the
 * teacher may well have a classification to assign — and the absence of a
 * proposal was standing in the way of them assigning it. What closes the door
 * is publication, and only that.
 */
function canDecide(row: Row): boolean {
    if (! props.schoolClass.has_profile || selectedPeriod.value === null) {
        return false;
    }

    return row.classification === null || row.classification.can_change;
}

function openDecision(row: Row): void {
    if (! canDecide(row)) {
        return;
    }

    editingUlid.value = row.enrollment_ulid;
    // Opens on what the row already says. Nothing is written until the teacher
    // chooses — a proposal on screen is not a decision (§5).
    chosenLevelId.value = null;
    chosenValue.value = null;
}

function closeDecision(): void {
    editingUlid.value = null;
}

function submitDecision(row: Row): void {
    const chosen = props.decision.classifies_by_level
        ? { final_scale_level_id: chosenLevelId.value, final_value: null }
        : { final_scale_level_id: null, final_value: chosenValue.value };

    if (chosen.final_scale_level_id === null && (chosen.final_value === null || chosen.final_value === '')) {
        return;
    }

    post(row, chosen);
}

/**
 * «Usar proposta»: an empty decision, which the service reads as «the proposal
 * is it». Offered only where there IS a proposal — manual assignment is not.
 */
function useProposal(row: Row): void {
    if (! row.classification?.can_confirm) {
        return;
    }

    post(row, { final_scale_level_id: null, final_value: null });
}

function post(row: Row, data: { final_scale_level_id: number | null; final_value: string | null }): void {
    if (selectedPeriod.value === null) {
        return;
    }

    // Addressed by the student and the period, not by a stored row — so a
    // period with no proposals yet is written exactly like any other, through
    // the same service.
    router.post(
        `/classes/${props.schoolClass.ulid}/classifications/${selectedPeriod.value.ulid}/${row.enrollment_ulid}/decide`,
        data,
        {
            preserveScroll: true,
            onStart: () => (savingUlid.value = row.enrollment_ulid),
            onFinish: () => (savingUlid.value = null),
            // Only on success: a refused write must leave the editor open with
            // the message, never a cell pretending it was saved (§10).
            onSuccess: () => closeDecision(),
        },
    );
}
</script>

<template>
    <Head :title="`Resultados — ${schoolClass.label}`" />

    <div class="space-y-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <Heading :title="`Resultados — ${schoolClass.label}`" :description="schoolClass.subject" />
                <div class="flex gap-3 text-sm">
                    <Link :href="`/classes/${schoolClass.ulid}`" class="text-muted-foreground hover:underline">← Voltar à turma</Link>
                    <!-- The decision is taken here; that page is where it is
                         formalised — the pauta, the states, the publication. -->
                    <Link :href="`/classes/${schoolClass.ulid}/classifications`" class="text-primary hover:underline">
                        Gerir classificações →
                    </Link>
                </div>
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
                <!-- After the real periods, and never one of them: the whole
                     year read at once, on the same numbers (§2). -->
                <Link
                    :href="`/classes/${schoolClass.ulid}/results/quadro-sintese`"
                    class="rounded-md border border-border px-3 py-1.5 text-sm hover:bg-muted/40"
                >
                    Quadro Síntese
                </Link>
            </div>
        </div>

        <!-- A refused decision — published, desatualizada, um valor que a escala
             não admite — diz-se aqui. A célula nunca finge que gravou (§10). -->
        <p
            v-if="decisionError"
            class="rounded-md border border-red-300 bg-red-50 px-4 py-2 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300"
        >
            {{ decisionError }}
        </p>

        <p v-if="!schoolClass.has_profile" class="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            Esta turma não tem perfil de avaliação associado, por isso não há classificações a calcular.
        </p>

        <div v-else-if="rows.length === 0" class="rounded-lg border border-dashed border-border p-10 text-center">
            <p class="text-sm text-muted-foreground">Sem alunos ou sem resultados neste período.</p>
        </div>

        <div v-else class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="sticky left-0 z-10 bg-muted/50 px-3 py-2 font-medium">Aluno</th>
                        <th v-for="domain in domains" :key="domain.id" class="px-3 py-2 text-center font-medium">{{ domain.name }}</th>
                        <!-- «Resultado» said nothing about which result. This
                             screen shows the period's own evidence, so it is the
                             weighted average of the period and says so (§4). -->
                        <th class="px-3 py-2 text-right font-medium">{{ weightedAverageLabel }}</th>
                        <th class="px-3 py-2 text-right font-medium">Proposta</th>
                        <th class="px-3 py-2 text-right font-medium">Autoavaliação</th>
                        <th class="px-3 py-2 text-right font-medium">{{ decision.label }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="row in rows" :key="row.name" class="hover:bg-muted/20">
                        <td class="sticky left-0 z-10 bg-background px-3 py-2 whitespace-nowrap">
                            <div class="flex items-center gap-1.5">
                                <span class="text-muted-foreground">{{ row.class_number ?? '—' }}</span>
                                <StudentAvatar :photo-url="row.photo_url" size="xs" />
                                <span class="font-medium">{{ row.name }}</span>
                            </div>
                        </td>
                        <!-- The tint is TREND and the text is the value. Never
                             the same ink for both (§9) — and the tint sits on
                             the value, not on the cell, so a row of students who
                             all improved is not one long green stripe. -->
                        <td
                            v-for="domain in domains"
                            :key="domain.id"
                            class="px-2 py-2 text-center tabular-nums"
                            :title="trendTitle(domainValue(row, domain.id)?.evolution ?? null, null)"
                        >
                            <span :class="[TREND_SHAPE, trendClasses(domainValue(row, domain.id)?.evolution ?? null)]">
                                <span :class="{ 'text-muted-foreground': domainValue(row, domain.id)?.value === null }">
                                    {{ pct(domainValue(row, domain.id)?.value ?? null) }}
                                </span>
                                <span class="text-xs">{{ trendArrow(domainValue(row, domain.id)?.evolution ?? null) }}</span>
                            </span>
                            <!-- Outside the tint, so the two are never read as
                                 one thing (§7). -->
                            <CoverageWarning
                                v-if="domainValue(row, domain.id)?.warning"
                                :coverage="domainCoverage(row, domain.id)"
                                :has-value="(domainValue(row, domain.id)?.value ?? null) !== null"
                                :domains="domains"
                            />
                        </td>
                        <td class="px-2 py-2 text-right tabular-nums" :title="trendTitle(row.evolution, row.accumulated)">
                            <span :class="[TREND_SHAPE, 'font-semibold', trendClasses(row.evolution)]">
                                <span :class="{ 'text-muted-foreground': !row.has_value }">{{ pct(row.overall) }}</span>
                                <span class="text-xs font-normal">{{ trendArrow(row.evolution) }}</span>
                            </span>
                            <CoverageWarning
                                v-if="row.coverage_warning"
                                :coverage="row.coverage"
                                :has-value="row.has_value"
                                :domains="domains"
                                scope="overall"
                            />
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">
                            <span v-if="row.proposal.value !== null" class="rounded bg-muted px-2 py-0.5">
                                {{ row.proposal.value }}<template v-if="row.proposal.is_percentage">%</template>
                            </span>
                            <span
                                v-else-if="row.proposal.state === 'unconfigured'"
                                class="text-muted-foreground"
                                title="Escala de classificação por configurar — o nível é atribuído pelo professor."
                            >—</span>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>

                        <!--
                          What the student said about themselves. The answer to
                          the global question and nothing else — «—» when they
                          did not answer it, never a zero and never the average
                          of what they said about each domain (§5).
                        -->
                        <td class="px-3 py-2 text-right tabular-nums">
                            <!-- What the student proposed is a value on the
                                 scale — a 4, a 16. The qualitative mention is
                                 support, and never stands in for it. -->
                            <span
                                v-if="row.self_assessment"
                                class="rounded px-2 py-0.5"
                                :class="levelClasses(row.self_assessment)"
                                :title="`Autoavaliação do aluno: ${row.self_assessment.code} — ${row.self_assessment.label}`"
                            >{{ row.self_assessment.code }}</span>
                            <span
                                v-else
                                class="text-muted-foreground"
                                title="O aluno não respondeu à autoavaliação global deste período."
                            >—</span>
                        </td>

                        <!--
                          The decision, and where it is taken. Bold and the
                          strongest of the three, because it is the one that is
                          true rather than proposed — and never filled in from
                          the proposal (§6).
                        -->
                        <td class="px-3 py-2 text-right tabular-nums">
                            <!-- Being decided: the scale's own bands, or its own
                                 interval. Nothing is preselected. -->
                            <div v-if="editingUlid === row.enrollment_ulid" class="flex items-center justify-end gap-1">
                                <select
                                    v-if="decision.classifies_by_level"
                                    v-model="chosenLevelId"
                                    class="rounded-md border border-border bg-background px-1.5 py-0.5 text-xs"
                                    @keyup.esc="closeDecision"
                                >
                                    <option :value="null">—</option>
                                    <option v-for="level in decision.levels" :key="level.id" :value="level.id">
                                        {{ level.code }} — {{ level.label }}
                                    </option>
                                </select>
                                <input
                                    v-else
                                    v-model="chosenValue"
                                    type="number"
                                    step="0.001"
                                    :min="decision.min_value ?? undefined"
                                    :max="decision.max_value ?? undefined"
                                    class="w-20 rounded-md border border-border bg-background px-1.5 py-0.5 text-xs tabular-nums"
                                    @keyup.esc="closeDecision"
                                />
                                <button
                                    type="button"
                                    class="rounded border border-border p-1 hover:bg-muted/40 disabled:opacity-50"
                                    :disabled="savingUlid !== null"
                                    title="Guardar"
                                    @click="submitDecision(row)"
                                >
                                    <Check class="size-3" />
                                </button>
                                <button type="button" class="rounded border border-border p-1 hover:bg-muted/40" title="Cancelar" @click="closeDecision">
                                    <X class="size-3" />
                                </button>
                            </div>

                            <!-- Published: the value, and the reason it can no
                                 longer be touched. -->
                            <span
                                v-else-if="row.classification?.is_published && row.classification.final"
                                class="inline-flex items-center gap-1 rounded px-2 py-0.5 font-bold"
                                :class="levelClasses(row.classification.final)"
                                :title="`${row.classification.final.code} — ${row.classification.final.label}. Esta classificação já foi publicada.`"
                            >
                                {{ row.classification.final.code }}
                                <Lock class="size-3 font-normal opacity-60" />
                            </span>

                            <!-- Decided and still open: the value, click to change. -->
                            <button
                                v-else-if="row.classification?.final"
                                type="button"
                                class="rounded px-2 py-0.5 font-bold hover:ring-1 hover:ring-border"
                                :class="levelClasses(row.classification.final)"
                                :title="`${row.classification.final.code} — ${row.classification.final.label}. Clique para alterar.`"
                                @click="openDecision(row)"
                            >
                                {{ row.classification.final.code }}
                                <span
                                    v-if="row.classification.differs_from_proposal"
                                    class="ml-0.5 text-xs font-normal"
                                    title="Diferente da proposta do LÁPIS."
                                >·</span>
                            </button>

                            <!-- Not decided yet. The teacher may classify whether
                                 or not a proposal exists; «Usar proposta» is the
                                 only part that needs one. -->
                            <span v-else-if="canDecide(row)" class="inline-flex items-center gap-1">
                                <button
                                    type="button"
                                    class="rounded border border-dashed border-border px-2 py-0.5 text-xs text-muted-foreground hover:bg-muted/40"
                                    title="Atribuir"
                                    @click="openDecision(row)"
                                >
                                    —
                                </button>
                                <button
                                    v-if="row.classification?.can_confirm"
                                    type="button"
                                    class="rounded border border-border px-1.5 py-0.5 text-[11px] hover:bg-muted/40 disabled:opacity-50"
                                    :disabled="savingUlid !== null"
                                    title="Adotar a proposta do LÁPIS"
                                    @click="useProposal(row)"
                                >
                                    Usar proposta
                                </button>
                            </span>

                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="flex items-start gap-2 text-xs text-muted-foreground">
            <CircleAlert class="mt-0.5 size-3.5 shrink-0" />
            <span>
                A <strong>Média Ponderada</strong> é o resultado deste período, em
                percentagem, calculado apenas com as evidências dele. A
                <strong>Proposta</strong> traduz esse resultado para a escala de classificação
                definida no perfil de avaliação<template v-if="schoolClass.scale_name">
                ({{ schoolClass.scale_name }})</template>. Quando a escala ainda não tem bandas
                definidas, a proposta fica por atribuir — o LÁPIS não infere limiares.
                O <strong>{{ decision.label }}</strong> é a decisão do professor e nunca é
                preenchido pela proposta: clique na célula para atribuir ou alterar, ou use
                «Usar proposta» para adotar a do LÁPIS. Depois de <strong>publicada</strong>,
                a classificação fica fechada — a publicação faz-se em «Gerir classificações».
                A <strong>Autoavaliação</strong> é o valor que o próprio aluno propôs na
                escala — a resposta à pergunta global, nunca a média do que disse sobre cada
                domínio.
                Um fundo <span class="rounded bg-emerald-50 px-1 dark:bg-emerald-950/40">verde</span>
                ou <span class="rounded bg-rose-50 px-1 dark:bg-rose-950/40">vermelho</span>
                indica <strong>tendência</strong> face ao período anterior, comparando
                médias do próprio período — nunca acumuladas — e é independente da cor
                do nível, que indica <strong>desempenho</strong>.
                "—" significa sem elementos, nunca zero. O
                <CircleAlert class="inline size-3 text-amber-500" /> assinala
                <strong>cobertura parcial</strong> — há resultado, mas assenta apenas em parte dos
                elementos aplicáveis — ou a ausência de elementos avaliados. Passa o rato ou o foco
                por cima para ver o detalhe.
            </span>
        </p>
    </div>
</template>
