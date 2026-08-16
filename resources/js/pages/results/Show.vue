<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { CircleAlert } from '@lucide/vue';
import CoverageWarning from '@/components/CoverageWarning.vue';
import Heading from '@/components/Heading.vue';
import StudentAvatar from '@/components/StudentAvatar.vue';
import { qualitativeToneClasses, qualitativeToneFor } from '@/lib/qualitativeTone';
import type { Coverage } from '@/types';

type DomainCol = { id: number; name: string };

/**
 * Movement between the STANDALONE average of this period and the one before.
 * Null when either period has nothing comparable — an absence is not a fall.
 */
type Evolution = {
    direction: 'up' | 'down' | 'flat';
    points: string;
    previous: string;
    current: string;
} | null;

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
        proposed: Level;
        final: Level;
        differs_from_proposal: boolean;
    } | null;
};

// A row whose warning has no detail still gets a tooltip, not a blank one.
const NO_COVERAGE: Coverage = { absences: [], no_elements: false, excluded_domain_ids: [] };

const props = defineProps<{
    schoolClass: { ulid: string; label: string; subject: string; has_profile: boolean; scale_name: string | null };
    periods: { ulid: string; label: string; selected: boolean }[];
    domains: DomainCol[];
    rows: Row[];
    /** «Média Ponderada» — this screen shows the period's own evidence (§4). */
    weightedAverageLabel: string;
    isFirstPeriod: boolean;
    scaleBands: { label: string; sequence: number; is_negative: boolean }[];
}>();

/**
 * TENDÊNCIA, and never performance.
 *
 * The background says whether the student moved; the badge's colour says how
 * they are doing. A student who went 25% to 40% improved and is still failing,
 * and one who went 92% to 85% fell back and is still excellent — so the two
 * must never be drawn with the same ink (§9).
 */
function trendClasses(evolution: Evolution): string {
    if (evolution === null || evolution.direction === 'flat') {
        return '';
    }

    return evolution.direction === 'up'
        ? 'bg-emerald-50 dark:bg-emerald-950/40'
        : 'bg-rose-50 dark:bg-rose-950/40';
}

function trendArrow(evolution: Evolution): string {
    if (evolution === null || evolution.direction === 'flat') {
        return '';
    }

    return evolution.direction === 'up' ? '↑' : '↓';
}

/** «Período anterior: 55,0% · atual: 75,0% · +20,0 p.p.» */
function trendTitle(evolution: Evolution, accumulated: string | null): string | undefined {
    if (evolution === null) {
        return undefined;
    }

    const signed = Number(evolution.points) > 0 ? `+${evolution.points}` : evolution.points;

    const lines = [
        `Período anterior: ${pct(evolution.previous)}`,
        `Período atual: ${pct(evolution.current)}`,
        // Percentage POINTS: the difference between two percentages is not
        // itself a percentage.
        `Evolução: ${signed.replace('.', ',')} p.p.`,
    ];

    if (accumulated !== null) {
        lines.push(`Média Ponderada Acumulada: ${pct(accumulated)}`);
    }

    return lines.join('\n');
}

/**
 * DESEMPENHO, from the canonical resolver — never a colour invented here.
 */
function levelClasses(level: Level): string {
    if (level === null) {
        return 'text-muted-foreground';
    }

    return qualitativeToneClasses[qualitativeToneFor(level, props.scaleBands)];
}

// Trim the engine's 6-decimal value to something a teacher reads. "—" for null,
// never 0, because a missing value is not a zero.
function pct(value: string | null): string {
    if (value === null) {
        return '—';
    }

    return `${Number(value).toFixed(1)}%`;
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
</script>

<template>
    <Head :title="`Resultados — ${schoolClass.label}`" />

    <div class="space-y-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <Heading :title="`Resultados — ${schoolClass.label}`" :description="schoolClass.subject" />
                <div class="flex gap-3 text-sm">
                    <Link :href="`/classes/${schoolClass.ulid}`" class="text-muted-foreground hover:underline">← Voltar à turma</Link>
                    <Link :href="`/classes/${schoolClass.ulid}/classifications`" class="text-primary hover:underline">Classificações →</Link>
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
            </div>
        </div>

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
                        <th class="px-3 py-2 text-right font-medium">Nível atribuído</th>
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
                        <!-- The background is TREND and the text is the value.
                             Never the same ink for both (§9). -->
                        <td
                            v-for="domain in domains"
                            :key="domain.id"
                            class="px-3 py-2 text-center tabular-nums"
                            :class="trendClasses(domainValue(row, domain.id)?.evolution ?? null)"
                            :title="trendTitle(domainValue(row, domain.id)?.evolution ?? null, null)"
                        >
                            <span :class="{ 'text-muted-foreground': domainValue(row, domain.id)?.value === null }">
                                {{ pct(domainValue(row, domain.id)?.value ?? null) }}
                            </span>
                            <span class="ml-0.5 text-xs">{{ trendArrow(domainValue(row, domain.id)?.evolution ?? null) }}</span>
                            <CoverageWarning
                                v-if="domainValue(row, domain.id)?.warning"
                                :coverage="domainCoverage(row, domain.id)"
                                :has-value="(domainValue(row, domain.id)?.value ?? null) !== null"
                                :domains="domains"
                            />
                        </td>
                        <td
                            class="px-3 py-2 text-right font-semibold tabular-nums"
                            :class="trendClasses(row.evolution)"
                            :title="trendTitle(row.evolution, row.accumulated)"
                        >
                            <span :class="{ 'text-muted-foreground': !row.has_value }">{{ pct(row.overall) }}</span>
                            <span class="ml-0.5 text-xs font-normal">{{ trendArrow(row.evolution) }}</span>
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
                          The decision. Bold and the strongest of the three,
                          because it is the one that is true rather than
                          proposed — and never filled in from the proposal (§6).
                        -->
                        <td class="px-3 py-2 text-right tabular-nums">
                            <!-- The decision is a value on the scale too — the
                                 same token the Proposta and the Autoavaliação
                                 are read in, so the three can be compared at a
                                 glance. The mention stays as support. -->
                            <span
                                v-if="row.classification?.final"
                                class="rounded px-2 py-0.5 font-bold"
                                :class="levelClasses(row.classification.final)"
                                :title="`${row.classification.final.code} — ${row.classification.final.label}`"
                            >
                                {{ row.classification.final.code }}
                                <span
                                    v-if="row.classification.differs_from_proposal"
                                    class="ml-0.5 text-xs font-normal"
                                    title="Nível atribuído diferente da proposta do LÁPIS."
                                >·</span>
                            </span>
                            <span
                                v-else
                                class="text-muted-foreground"
                                title="Ainda por atribuir — o LÁPIS propõe, o professor decide."
                            >—</span>
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
                O <strong>Nível atribuído</strong> é a decisão do professor e nunca é
                preenchido pela proposta. A <strong>Autoavaliação</strong> é o valor que o
                próprio aluno propôs na escala — a resposta à pergunta global, nunca a média
                do que disse sobre cada domínio.
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
