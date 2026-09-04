<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { CircleAlert, Lock } from '@lucide/vue';
import { computed } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import { qualitativeToneClasses, qualitativeToneFor } from '@/lib/qualitativeTone';
import { pct, TREND_SHAPE, trendArrow, trendClasses, trendPoints, trendTitle } from '@/lib/results';
import type { Evolution } from '@/lib/results';

/** A band of the profile's own scale. `code` is the value; `label` the mention. */
type Level = { code: string; label: string; sequence: number; is_negative: boolean } | null;

type Proposal = { value: string | null; state: string; is_percentage: boolean };

/**
 * The band the domain's accumulated figure falls in. Carries the scale's own
 * identity — id, code, rank — so a later export maps from the band and not from
 * the words shown here.
 */
type Mention = { scale_level_id: number; code: string; label: string; sequence: number; is_negative: boolean } | null;

type DomainCell = {
    domain_id: number;
    weighted_average: string | null;
    accumulated_average: string | null;
    coverage_warning: boolean;
    evolution: Evolution;
    self_assessment: Level;
    mention: Mention;
};

type PeriodCell = {
    period_id: number;
    period_label: string;
    weighted_average: string | null;
    accumulated_average: string | null;
    coverage_warning: boolean;
    evolution: Evolution;
    domains: DomainCell[];
    self_assessment: Level;
    classification: {
        ulid: string;
        status: string;
        is_published: boolean;
        proposal: Proposal;
        proposed: Level;
        final: Level;
        differs_from_proposal: boolean;
    } | null;
};

type Student = {
    enrollment_id: number;
    name: string;
    class_number: number | null;
    periods: PeriodCell[];
};

const props = defineProps<{
    schoolClass: { ulid: string; label: string; subject: string; has_profile: boolean; scale_name: string | null };
    decision: {
        label: string;
        classifies_by_level: boolean;
        levels: { id: number; code: string; label: string }[];
        min_value: string | null;
        max_value: string | null;
    };
    scaleBands: { label: string; sequence: number; is_negative: boolean }[];
    /** Whether this organization's plan includes the INOVAR export. */
    canExportToInovar: boolean;
    progression: {
        periods: { id: number; ulid: string; label: string; sequence: number }[];
        domains: { id: number; name: string }[];
        students: Student[];
    };
}>();

const periods = computed(() => props.progression.periods);
const domains = computed(() => props.progression.domains);
const students = computed(() => props.progression.students);

/**
 * «P1», «P2» — short enough for a table this wide, and never without the real
 * period name behind it (§14).
 */
function shortPeriod(index: number): string {
    return `P${index + 1}`;
}

// Per domain: one column per period, an evolution column after every period but
// the first, then the accumulated figure and the mention it falls in.
const domainColumns = computed(() => periods.value.length * 2 + 1);

/**
 * WHERE ONE DOMAIN ENDS AND THE NEXT BEGINS.
 *
 * A slightly firmer rule at each block's first column, in the header and in
 * every row alike, so the grouping is read down the table and not only across
 * its heading. Structure, never colour — the domain's name and this separator
 * both carry it, so the blocks hold without either.
 */
const BLOCK_EDGE = 'border-l-2 border-l-border';

/**
 * Two very soft tones, alternating, on the domain HEADINGS only.
 *
 * The body stays neutral on purpose: the trend tint has meaning and must own
 * the only colour in a data cell (§15). These say nothing pedagogical — they
 * are there so the eye finds the edge of a block, and are deliberately not the
 * greens and reds that do mean something.
 */
function domainTone(index: number): string {
    return index % 2 === 0 ? 'bg-muted' : 'bg-muted/60';
}

// Per period of the síntese: the standalone average, its evolution (except the
// first), the accumulated, the proposal, the self-assessment and the decision.
function synthesisColumns(index: number): number {
    return index === 0 ? 5 : 6;
}

/** DESEMPENHO, from the canonical resolver — never a colour chosen here. */
function levelClasses(level: Level): string {
    if (level === null) {
        return 'text-muted-foreground';
    }

    return qualitativeToneClasses[qualitativeToneFor(level, props.scaleBands)];
}

function domainCell(period: PeriodCell, domainId: number): DomainCell | undefined {
    return period.domains.find((domain) => domain.domain_id === domainId);
}

/** «Autoavaliação: 3 — Suficiente», for the discreet marker beside a domain's value. */
function selfAssessmentTitle(level: Level): string {
    return level === null ? '' : `Autoavaliação: ${level.code} — ${level.label}`;
}

function proposalText(proposal: Proposal | undefined): string {
    if (proposal === undefined || proposal.value === null) {
        return '—';
    }

    return proposal.is_percentage ? `${proposal.value}%` : proposal.value;
}
</script>

<template>
    <Head :title="`Quadro Síntese — ${schoolClass.label}`" />

    <div class="space-y-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <Heading :title="`Quadro Síntese — ${schoolClass.label}`" :description="schoolClass.subject" />
                <div class="flex gap-3 text-sm">
                    <Link :href="`/classes/${schoolClass.ulid}`" class="text-muted-foreground hover:underline">← Voltar à turma</Link>
                    <Link :href="`/classes/${schoolClass.ulid}/classifications`" class="text-primary hover:underline">
                        Gerir classificações →
                    </Link>
                    <!-- Only where the capability is there: a school that never
                         uses INOVAR never meets this. -->
                    <Link
                        v-if="canExportToInovar && periods.length"
                        :href="`/classes/${schoolClass.ulid}/exports/inovar/${periods[periods.length - 1].ulid}`"
                        class="text-primary hover:underline"
                    >
                        Exportar para INOVAR →
                    </Link>
                </div>
            </div>
            <!-- The real periods of the year, then this view. Neither their
                 names nor their number is known here (§2). -->
            <div v-if="periods.length" class="flex gap-1">
                <Link
                    v-for="period in periods"
                    :key="period.ulid"
                    :href="`/classes/${schoolClass.ulid}/results/${period.ulid}`"
                    class="rounded-md border border-border px-3 py-1.5 text-sm hover:bg-muted/40"
                >
                    {{ period.label }}
                </Link>
                <span class="rounded-md border border-primary bg-primary px-3 py-1.5 text-sm text-primary-foreground">Quadro Síntese</span>
                <Link
                    :href="`/classes/${schoolClass.ulid}/results/estatistica`"
                    class="rounded-md border border-border px-3 py-1.5 text-sm hover:bg-muted/40"
                >
                    Estatística
                </Link>
            </div>
        </div>

        <p v-if="!schoolClass.has_profile" class="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            Esta turma não tem perfil de avaliação associado, por isso não há resultados a sintetizar.
        </p>

        <EmptyState v-else-if="students.length === 0" title="Sem alunos nesta turma." />

        <!-- Wide on purpose: a year of a class does not fit a viewport, and
             shrinking it to fit would be hiding it (§4). The student stays put
             while everything else scrolls past. -->
        <div v-else class="max-h-[75vh] overflow-auto rounded-lg border border-border">
            <table class="w-max min-w-full text-sm">
                <thead class="text-left">
                    <tr>
                        <th
                            rowspan="2"
                            class="sticky top-0 left-0 z-30 border-r border-b border-border bg-muted px-3 py-2 align-bottom font-medium"
                            scope="col"
                        >
                            Aluno
                        </th>
                        <!-- The domain's name, made the most evident thing in
                             the heading: the grouping is read from the block,
                             not by tracing the columns under it (§8, §11). -->
                        <th
                            v-for="(domain, domainIndex) in domains"
                            :key="domain.id"
                            :colspan="domainColumns"
                            class="sticky top-0 z-20 border-b border-border px-3 py-1.5 text-center text-xs font-semibold tracking-wide text-foreground uppercase"
                            :class="[domainTone(domainIndex), BLOCK_EDGE]"
                            scope="colgroup"
                        >
                            {{ domain.name }}
                        </th>
                        <!-- The síntese is not a domain, and is separated more
                             firmly than the domains are from each other (§13). -->
                        <th
                            v-for="(period, index) in periods"
                            :key="`sintese-${period.id}`"
                            :colspan="synthesisColumns(index)"
                            class="sticky top-0 z-20 border-b border-border bg-primary/10 px-3 py-1.5 text-center text-xs font-semibold tracking-wide uppercase"
                            :class="index === 0 ? 'border-l-4 border-l-border' : BLOCK_EDGE"
                            scope="colgroup"
                        >
                            Síntese · {{ period.label }}
                        </th>
                    </tr>
                    <tr>
                        <template v-for="domain in domains" :key="`sub-${domain.id}`">
                            <template v-for="(period, index) in periods" :key="`sub-${domain.id}-${period.id}`">
                                <th
                                    class="sticky top-[33px] z-20 border-b border-border bg-muted/30 px-2 py-1 text-center text-xs font-medium"
                                    :class="index === 0 ? BLOCK_EDGE : ''"
                                    :title="`${period.label} — ${domain.name}`"
                                    :aria-label="`${period.label} — ${domain.name}`"
                                    scope="col"
                                >
                                    {{ shortPeriod(index) }}
                                </th>
                                <th
                                    v-if="index > 0"
                                    class="sticky top-[33px] z-20 border-b border-border bg-muted/30 px-2 py-1 text-center text-xs font-medium"
                                    :title="`Evolução face a ${periods[index - 1].label} — ${domain.name}`"
                                    :aria-label="`Evolução face a ${periods[index - 1].label} — ${domain.name}`"
                                    scope="col"
                                >
                                    Evol.
                                </th>
                            </template>
                            <th
                                class="sticky top-[33px] z-20 border-b border-border bg-muted/30 px-2 py-1 text-center text-xs font-medium"
                                :title="`Média Ponderada Acumulada — ${domain.name}`"
                                :aria-label="`Média Ponderada Acumulada — ${domain.name}`"
                                scope="col"
                            >
                                Acum.
                            </th>
                            <th
                                class="sticky top-[33px] z-20 border-b border-border bg-muted/30 px-2 py-1 text-center text-xs font-medium"
                                :title="`Menção qualitativa acumulada — ${domain.name}`"
                                :aria-label="`Menção qualitativa acumulada — ${domain.name}`"
                                scope="col"
                            >
                                Menção
                            </th>
                        </template>

                        <template v-for="(period, index) in periods" :key="`sub-sintese-${period.id}`">
                            <th
                                class="sticky top-[33px] z-20 border-b border-border bg-primary/5 px-2 py-1 text-center text-xs font-medium"
                                :class="index === 0 ? 'border-l-4 border-l-border' : BLOCK_EDGE"
                                :title="`Média Ponderada — ${period.label}`"
                                :aria-label="`Média Ponderada — ${period.label}`"
                                scope="col"
                            >
                                MP
                            </th>
                            <th
                                v-if="index > 0"
                                class="sticky top-[33px] z-20 border-b border-border bg-primary/5 px-2 py-1 text-center text-xs font-medium"
                                :title="`Evolução face a ${periods[index - 1].label}`"
                                :aria-label="`Evolução face a ${periods[index - 1].label}`"
                                scope="col"
                            >
                                Evol.
                            </th>
                            <th
                                class="sticky top-[33px] z-20 border-b border-border bg-primary/5 px-2 py-1 text-center text-xs font-medium"
                                :title="`Média Ponderada Acumulada — ${period.label}`"
                                :aria-label="`Média Ponderada Acumulada — ${period.label}`"
                                scope="col"
                            >
                                Acum.
                            </th>
                            <th
                                class="sticky top-[33px] z-20 border-b border-border bg-primary/5 px-2 py-1 text-center text-xs font-medium"
                                :title="`Proposta do Lapispro — ${period.label}`"
                                :aria-label="`Proposta do Lapispro — ${period.label}`"
                                scope="col"
                            >
                                Prop.
                            </th>
                            <th
                                class="sticky top-[33px] z-20 border-b border-border bg-primary/5 px-2 py-1 text-center text-xs font-medium"
                                :title="`Autoavaliação global do aluno — ${period.label}`"
                                :aria-label="`Autoavaliação global do aluno — ${period.label}`"
                                scope="col"
                            >
                                Autoav.
                            </th>
                            <th
                                class="sticky top-[33px] z-20 border-r border-b border-border bg-primary/5 px-2 py-1 text-center text-xs font-medium"
                                :title="`${decision.label} — ${period.label}`"
                                :aria-label="`${decision.label} — ${period.label}`"
                                scope="col"
                            >
                                {{ decision.classifies_by_level ? 'Nível' : 'Classif.' }}
                            </th>
                        </template>
                    </tr>
                </thead>

                <tbody class="divide-y divide-border">
                    <tr v-for="student in students" :key="student.enrollment_id" class="hover:bg-muted/20">
                        <th
                            scope="row"
                            class="sticky left-0 z-10 border-r border-border bg-background px-3 py-2 text-left font-medium whitespace-nowrap"
                        >
                            <span class="mr-1.5 text-muted-foreground">{{ student.class_number ?? '—' }}</span>{{ student.name }}
                        </th>

                        <!-- One block per domain of the profile version. -->
                        <template v-for="domain in domains" :key="`${student.enrollment_id}-${domain.id}`">
                            <template v-for="(period, index) in student.periods" :key="`${student.enrollment_id}-${domain.id}-${period.period_id}`">
                                <td class="px-2 py-1.5 text-center tabular-nums" :class="index === 0 ? BLOCK_EDGE : ''">
                                    <span :class="{ 'text-muted-foreground': (domainCell(period, domain.id)?.weighted_average ?? null) === null }">
                                        {{ pct(domainCell(period, domain.id)?.weighted_average ?? null) }}
                                    </span>
                                    <CircleAlert
                                        v-if="domainCell(period, domain.id)?.coverage_warning"
                                        class="ml-0.5 inline size-3 text-amber-500"
                                        title="Cobertura parcial — o resultado assenta apenas em parte dos elementos aplicáveis."
                                    />
                                    <!-- What the student said about this domain,
                                         beside what the evidence says (§7). -->
                                    <sup
                                        v-if="domainCell(period, domain.id)?.self_assessment"
                                        class="ml-0.5 rounded bg-muted px-1 text-[10px] font-normal text-muted-foreground"
                                        :title="selfAssessmentTitle(domainCell(period, domain.id)?.self_assessment ?? null)"
                                        :aria-label="selfAssessmentTitle(domainCell(period, domain.id)?.self_assessment ?? null)"
                                    >A{{ domainCell(period, domain.id)?.self_assessment?.code }}</sup>
                                </td>
                                <td
                                    v-if="index > 0"
                                    class="px-2 py-1.5 text-center text-xs tabular-nums"
                                    :title="trendTitle(domainCell(period, domain.id)?.evolution ?? null, null)"
                                >
                                    <span :class="[TREND_SHAPE, trendClasses(domainCell(period, domain.id)?.evolution ?? null)]">
                                        <span :class="{ 'text-muted-foreground': (domainCell(period, domain.id)?.evolution ?? null) === null }">
                                            {{ trendPoints(domainCell(period, domain.id)?.evolution ?? null) }}
                                        </span>
                                        <span>{{ trendArrow(domainCell(period, domain.id)?.evolution ?? null) }}</span>
                                    </span>
                                </td>
                            </template>
                            <td class="bg-muted/20 px-2 py-1.5 text-center tabular-nums">
                                <span
                                    :class="{
                                        'text-muted-foreground':
                                            (domainCell(student.periods[student.periods.length - 1], domain.id)?.accumulated_average ?? null) === null,
                                    }"
                                >
                                    {{ pct(domainCell(student.periods[student.periods.length - 1], domain.id)?.accumulated_average ?? null) }}
                                </span>
                            </td>
                            <!-- The band that accumulated figure falls in, on the
                                 profile's own scale. Never a threshold decided
                                 here, and «—» where the scale has no band for it. -->
                            <td class="bg-muted/20 px-2 py-1.5 text-center whitespace-nowrap">
                                <span
                                    v-if="domainCell(student.periods[student.periods.length - 1], domain.id)?.mention"
                                    class="rounded px-1.5 py-0.5 text-xs"
                                    :class="levelClasses(domainCell(student.periods[student.periods.length - 1], domain.id)?.mention ?? null)"
                                    :title="`Menção qualitativa acumulada — ${domain.name}`"
                                >{{ domainCell(student.periods[student.periods.length - 1], domain.id)?.mention?.label }}</span>
                                <span v-else class="text-muted-foreground">—</span>
                            </td>
                        </template>

                        <!-- …then the year read whole, period by period. -->
                        <template v-for="(period, index) in student.periods" :key="`${student.enrollment_id}-sintese-${period.period_id}`">
                            <td
                                class="px-2 py-1.5 text-center font-medium tabular-nums"
                                :class="index === 0 ? 'border-l-4 border-l-border' : BLOCK_EDGE"
                            >
                                <span :class="{ 'text-muted-foreground': period.weighted_average === null }">{{ pct(period.weighted_average) }}</span>
                                <CircleAlert
                                    v-if="period.coverage_warning"
                                    class="ml-0.5 inline size-3 text-amber-500"
                                    title="Cobertura parcial — o resultado assenta apenas em parte dos elementos aplicáveis."
                                />
                            </td>
                            <td
                                v-if="index > 0"
                                class="px-2 py-1.5 text-center text-xs tabular-nums"
                                :title="trendTitle(period.evolution, period.accumulated_average)"
                            >
                                <span :class="[TREND_SHAPE, trendClasses(period.evolution)]">
                                    <span :class="{ 'text-muted-foreground': period.evolution === null }">{{ trendPoints(period.evolution) }}</span>
                                    <span>{{ trendArrow(period.evolution) }}</span>
                                </span>
                            </td>
                            <td class="bg-muted/20 px-2 py-1.5 text-center tabular-nums">
                                <span :class="{ 'text-muted-foreground': period.accumulated_average === null }">
                                    {{ pct(period.accumulated_average) }}
                                </span>
                            </td>
                            <td class="px-2 py-1.5 text-center tabular-nums">
                                <span v-if="period.classification?.proposal.value" class="rounded bg-muted px-1.5 py-0.5">
                                    {{ proposalText(period.classification.proposal) }}
                                </span>
                                <span v-else class="text-muted-foreground">—</span>
                            </td>
                            <td class="px-2 py-1.5 text-center tabular-nums">
                                <span
                                    v-if="period.self_assessment"
                                    class="rounded px-1.5 py-0.5"
                                    :class="levelClasses(period.self_assessment)"
                                    :title="`Autoavaliação do aluno: ${period.self_assessment.code} — ${period.self_assessment.label}`"
                                >{{ period.self_assessment.code }}</span>
                                <span v-else class="text-muted-foreground" title="O aluno não respondeu à autoavaliação global deste período.">—</span>
                            </td>
                            <!-- The decision of THAT period, kept as it was: a
                                 quadro síntese that showed only the latest would
                                 be hiding the year it exists to show (§10). -->
                            <td class="px-2 py-1.5 text-center tabular-nums">
                                <span
                                    v-if="period.classification?.final"
                                    class="inline-flex items-center gap-0.5 rounded px-1.5 py-0.5 font-bold"
                                    :class="levelClasses(period.classification.final)"
                                    :title="`${period.classification.final.code} — ${period.classification.final.label}`"
                                >
                                    {{ period.classification.final.code }}
                                    <Lock v-if="period.classification.is_published" class="size-3 font-normal opacity-60" />
                                </span>
                                <span v-else class="text-muted-foreground" title="Ainda por atribuir — o Lapispro propõe, o professor decide.">—</span>
                            </td>
                        </template>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="flex items-start gap-2 text-xs text-muted-foreground">
            <CircleAlert class="mt-0.5 size-3.5 shrink-0" />
            <span>
                Cada bloco é um domínio do perfil de avaliação: <strong>P1</strong>, <strong>P2</strong>… são a
                <strong>Média Ponderada</strong> de cada período, <strong>Evol.</strong> compara esse período com o
                anterior — sempre valores do próprio período, nunca acumulados —, <strong>Acum.</strong> é a
                <strong>Média Ponderada Acumulada</strong> e a <strong>Menção</strong> é a banda dessa acumulada na
                escala do perfil<template v-if="schoolClass.scale_name"> ({{ schoolClass.scale_name }})</template>;
                fica "—" quando a escala não tem banda definida — o Lapispro não infere limiares. O <strong>A</strong> em
                expoente é a autoavaliação do aluno nesse domínio. No bloco <strong>Síntese</strong> ficam, por período, a Média Ponderada, a evolução, a
                acumulada, a <strong>Proposta</strong>, a <strong>Autoavaliação</strong> global e o
                <strong>{{ decision.label }}</strong><template v-if="schoolClass.scale_name"> na escala
                {{ schoolClass.scale_name }}</template>. Um fundo
                <span class="rounded bg-emerald-50 px-1 dark:bg-emerald-950/40">verde</span> ou
                <span class="rounded bg-rose-50 px-1 dark:bg-rose-950/40">vermelho</span> indica
                <strong>tendência</strong>, e é independente da cor do nível, que indica <strong>desempenho</strong>.
                "—" significa sem elementos, nunca zero, e um período sem termo de comparação não mostra evolução.
            </span>
        </p>
    </div>
</template>
