<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { CircleAlert, Minus, TrendingDown, TrendingUp } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import MovementBoard from '@/components/infographic/MovementBoard.vue';
import type { CrossingCard, CrossingKey, HeldCard, HeldKey, MovementCard } from '@/components/infographic/MovementBoard.vue';
import SectionHeading from '@/components/infographic/SectionHeading.vue';
import Slopegraph from '@/components/infographic/Slopegraph.vue';
import type { Slope } from '@/components/infographic/Slopegraph.vue';
import { domainColours, formatPoints, formatShare, pct } from '@/lib/chartTheme';
import { qualitativeToneClasses, qualitativeToneFor } from '@/lib/qualitativeTone';

/**
 * Two moments, side by side — and a slopegraph is exactly the shape for two.
 *
 * Nothing here is computed: the service already produced both ends and the
 * difference between them. This only chooses how to draw it, in the language
 * Estatística already speaks.
 */

type Mention = { label?: string; label_snapshot?: string; sequence: number; is_negative: boolean } | null;

/**
 * One reading at both ends. `kind` says WHICH figure it is, so the page never
 * has to guess whether a number is an accumulated result or a period's own.
 */
type Reading = {
    kind: 'period' | 'accumulated';
    label: string;
    caption: string;
    interim_value: string | null;
    final_value: string | null;
    change: string | null;
    direction: 'up' | 'down' | 'flat' | null;
    /** False for a photograph that never stored this figure. */
    is_available: boolean;
};

type Comparison = {
    interim: { ulid: string; name: string; reference_date: string; reference_date_label: string };
    period: { id: number; label: string };
    is_final_still_open: boolean;
    summary: {
        /** The reading this period is about — accumulated where the profile says so. */
        primary: Reading;
        /** The period's own work. Null at the first moment: the two would be one number. */
        supplementary: Reading | null;
        interim_students_with_result: number; final_students_with_result: number;
    };
    movement: {
        progressed: number; stable: number; regressed: number; no_comparison: number;
        comparable: number; average_change: string | null;
    };
    transitions: Record<CrossingKey | HeldKey | 'unclassified' | 'no_assigned_classification' | 'comparable', number> & {
        percentages: Record<CrossingKey | HeldKey, string | null>;
        share_of_class: { unclassified: string | null; no_assigned_classification: string | null };
    };
    /** Where the scale puts its passing line, for the words on the cards. */
    threshold: { noun: string; at_or_above: string; below: string } | null;
    assigned_distribution: {
        /** False for a photograph taken before this block was recorded. */
        interim_is_available: boolean;
        bands: {
            scale_level_id: number; code: string; label: string; sequence: number; is_negative: boolean;
            interim_count: number | null; final_count: number | null; change: number | null;
            only_in_interim?: boolean; only_in_final?: boolean;
        }[];
        interim_classified: number | null; final_classified: number | null;
        interim_without_classification: number | null; final_without_classification: number | null;
    };
    domains: {
        domain_id: number; label: string;
        primary: Reading; supplementary: Reading | null;
        interim_mention: Mention; final_mention: Mention;
        interim_partial_coverage: number; final_partial_coverage: number;
        only_in_interim?: boolean; only_in_final?: boolean;
    }[];
    students: {
        enrollment_id: number; name: string; class_number: number | null;
        primary: Reading; supplementary: Reading | null;
        change: string | null; direction: 'up' | 'down' | 'flat' | null;
        interim_band: Mention; final_band: Mention;
        interim_coverage_warning: boolean; final_coverage_warning: boolean;
    }[];
};

const props = defineProps<{
    schoolClass: { ulid: string; label: string; subject: string };
    comparison: Comparison;
}>();

const bands = computed(() => {
    const seen = new Map<number, { sequence: number; is_negative: boolean }>();

    for (const student of props.comparison.students) {
        for (const band of [student.interim_band, student.final_band]) {
            if (band !== null) {
                seen.set(band.sequence, { sequence: band.sequence, is_negative: band.is_negative });
            }
        }
    }

    return [...seen.values()];
});

function toneClass(band: Mention): string {
    return band === null ? 'bg-muted text-muted-foreground' : qualitativeToneClasses[qualitativeToneFor(band, bands.value)];
}

function mentionLabel(band: Mention): string {
    return band?.label ?? band?.label_snapshot ?? '—';
}

const inks = computed(() => domainColours(props.comparison.domains.map((domain) => domain.domain_id)));

const value = (raw: string | null): number | null => (raw === null ? null : Number(raw));

/**
 * The class itself, from one moment to the other — ON THE PRIMARY READING.
 *
 * There is one slopegraph on this page and it draws the comparison the period
 * is actually about. The other reading appears as numbers, not as a second
 * chart of the same size: two equal charts would put two answers on the same
 * footing and leave the teacher to guess which is which (§11).
 */
const classSlope = computed<Slope[]>(() => [{
    id: 0,
    label: props.comparison.summary.primary.label,
    from: value(props.comparison.summary.primary.interim_value),
    to: value(props.comparison.summary.primary.final_value),
    colour: '#4f46e5',
}]);

const domainSlopes = computed<Slope[]>(() => props.comparison.domains.map((domain) => ({
    id: domain.domain_id,
    label: domain.label,
    from: value(domain.primary.interim_value),
    to: value(domain.primary.final_value),
    colour: inks.value[domain.domain_id],
    badge: mentionLabel(domain.final_mention) === '—' ? undefined : mentionLabel(domain.final_mention),
    badgeClass: toneClass(domain.final_mention),
})));

const total = computed(() => props.comparison.students.length);

/**
 * The same two readings the class page shows, in the same board.
 *
 * A teacher who learnt «passaram a resultado positivo» on Estatística must
 * find the same words, the same icons and the same grounds here — a second
 * vocabulary for the same fact is a second thing to learn (§23).
 */
const movements = computed<MovementCard[]>(() => {
    const movement = props.comparison.movement;
    const percentage = (count: number): string => (
        total.value === 0 ? '—' : formatShare((count / total.value) * 100)
    );

    return [
        { key: 'progressed', label: 'Progrediram', count: movement.progressed, share: percentage(movement.progressed) },
        { key: 'stable', label: 'Mantiveram-se', count: movement.stable, share: percentage(movement.stable) },
        { key: 'regressed', label: 'Regrediram', count: movement.regressed, share: percentage(movement.regressed) },
    ];
});

/**
 * The scale's own line, in words. Same phrasing as Estatística, from the same
 * source — a second vocabulary for the same fact is a second thing to learn.
 */
const threshold = computed(() => props.comparison.threshold ?? {
    noun: 'classificação',
    at_or_above: 'classificação não negativa',
    below: 'classificação negativa',
});

const crossed = (count: number): string => (count === 1 ? 'Passou' : 'Passaram');

const crossings = computed<CrossingCard[]>(() => {
    const transitions = props.comparison.transitions;

    return [
        {
            key: 'failure_to_success',
            label: `${crossed(transitions.failure_to_success)} para ${threshold.value.at_or_above}`,
            count: transitions.failure_to_success,
            share: formatShare(transitions.percentages.failure_to_success),
        },
        {
            key: 'success_to_failure',
            label: `${crossed(transitions.success_to_failure)} para ${threshold.value.below}`,
            count: transitions.success_to_failure,
            share: formatShare(transitions.percentages.success_to_failure),
        },
    ];
});

const held = computed<HeldCard[]>(() => [
    {
        key: 'success_to_success',
        label: `Mantiveram ${threshold.value.at_or_above}`,
        count: props.comparison.transitions.success_to_success,
    },
    {
        key: 'failure_to_failure',
        label: `Mantiveram ${threshold.value.below}`,
        count: props.comparison.transitions.failure_to_failure,
    },
]);

const crossingTitle = computed<string>(() => (
    threshold.value.noun === 'nível'
        ? 'Evolução dos níveis atribuídos'
        : 'Evolução das classificações atribuídas'
));

const crossingCaption = computed<string>(() => {
    const plural = threshold.value.noun === 'nível' ? 'os níveis' : 'as classificações';

    return `Esta leitura compara ${plural} que atribuiu nos dois momentos, tendo em conta o limiar`
        + ' definido pela escala.';
});

const finalLabel = computed(() => `Final do ${props.comparison.period.label}`);
</script>

<template>
    <Head :title="`${comparison.interim.name} vs ${finalLabel}`" />

    <div class="space-y-6 p-4">
        <div>
            <Heading
                :title="`${comparison.interim.name} → ${finalLabel}`"
                :description="`${schoolClass.label} · ${schoolClass.subject}`"
            />
            <div class="flex flex-wrap gap-3 text-sm">
                <Link
                    :href="`/classes/${schoolClass.ulid}/avaliacoes-intercalares/${comparison.interim.ulid}`"
                    class="text-muted-foreground hover:underline"
                >
                    ← Voltar à avaliação intercalar
                </Link>
            </div>
        </div>

        <!--
          THE ASYMMETRY, SAID OUT LOUD. One side is frozen and one is live, and
          a teacher comparing them should know which of the two numbers can move
          under their feet (§7).
        -->
        <p class="rounded-xl border border-border bg-muted/25 px-4 py-3 text-sm">
            <strong>{{ comparison.interim.name }}</strong> é uma fotografia de
            {{ comparison.interim.reference_date_label }} e não muda.
            <strong>{{ finalLabel }}</strong> é o estado atual do período — se ainda houver avaliações
            por registar, este lado ainda se move.
        </p>

        <!-- ================================================ 01 · a turma -->
        <!-- THE READING THE PERIOD IS ABOUT, at full size. Where the profile
             defines continuity that is the accumulated result at both ends;
             the period's own work sits underneath, deliberately smaller. -->
        <section
            class="relative overflow-hidden rounded-[22px] border border-emerald-200/60 bg-gradient-to-r from-emerald-50 via-emerald-50/50 to-teal-50/30 p-5 shadow-sm sm:p-6 dark:border-emerald-900/50 dark:from-emerald-950/40 dark:via-emerald-950/20 dark:to-teal-950/15"
        >
            <p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-muted-foreground">
                {{ comparison.summary.primary.label }}
            </p>
            <p class="mt-0.5 text-xs text-muted-foreground">{{ comparison.summary.primary.caption }}</p>

            <div v-if="comparison.summary.primary.is_available" class="mt-4 flex flex-wrap items-end gap-x-6 gap-y-3">
                <div>
                    <p class="text-[10px] uppercase tracking-wider text-muted-foreground">
                        {{ comparison.interim.reference_date_label }}
                    </p>
                    <p class="text-2xl font-semibold tabular-nums text-muted-foreground">
                        {{ pct(comparison.summary.primary.interim_value) }}
                    </p>
                </div>

                <span aria-hidden="true" class="pb-2 text-lg text-muted-foreground/60">→</span>

                <div>
                    <p class="text-[10px] uppercase tracking-wider text-muted-foreground">{{ finalLabel }}</p>
                    <p class="text-[2.75rem] font-semibold leading-none tabular-nums tracking-tight">
                        {{ pct(comparison.summary.primary.final_value) }}
                    </p>
                </div>

                <p
                    class="mb-1 inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-sm font-medium tabular-nums"
                    :class="comparison.summary.primary.direction === 'up'
                        ? 'bg-emerald-100/70 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-300'
                        : comparison.summary.primary.direction === 'down'
                            ? 'bg-rose-100/70 text-rose-800 dark:bg-rose-950/50 dark:text-rose-300'
                            : 'bg-muted text-muted-foreground'"
                >
                    <component
                        :is="comparison.summary.primary.direction === 'up' ? TrendingUp
                            : comparison.summary.primary.direction === 'down' ? TrendingDown : Minus"
                        aria-hidden="true"
                        class="size-3.5 shrink-0"
                    />
                    {{ formatPoints(comparison.summary.primary.change) }} p.p.
                </p>

                <p class="ml-auto text-[11px] text-muted-foreground">
                    {{ comparison.summary.interim_students_with_result }} com resultado então ·
                    {{ comparison.summary.final_students_with_result }} agora
                </p>
            </div>

            <p v-else class="mt-4 text-sm text-muted-foreground">
                Esta fotografia não guardou este resultado, por isso não há comparação a fazer.
                O que ela guardou não se reescreve.
            </p>

            <!-- The other reading, as numbers only: a second slopegraph of the
                 same size would put two answers on the same footing (§2, §11). -->
            <div
                v-if="comparison.summary.supplementary"
                class="mt-4 flex flex-wrap items-baseline gap-x-4 gap-y-1 border-t border-emerald-200/50 pt-3 text-sm dark:border-emerald-900/40"
            >
                <span class="text-[10px] font-semibold uppercase tracking-[0.12em] text-muted-foreground">
                    Só neste período
                </span>
                <span class="tabular-nums text-muted-foreground">
                    {{ pct(comparison.summary.supplementary.interim_value) }}
                </span>
                <span aria-hidden="true" class="text-muted-foreground/60">→</span>
                <span class="font-semibold tabular-nums">{{ pct(comparison.summary.supplementary.final_value) }}</span>
                <span
                    class="text-xs tabular-nums"
                    :class="comparison.summary.supplementary.direction === 'up' ? 'text-emerald-700 dark:text-emerald-400'
                        : comparison.summary.supplementary.direction === 'down' ? 'text-rose-700 dark:text-rose-400' : 'text-muted-foreground'"
                >
                    {{ formatPoints(comparison.summary.supplementary.change) }} p.p.
                </span>
                <span class="text-[11px] text-muted-foreground">{{ comparison.summary.supplementary.caption }}</span>
            </div>
        </section>

        <!-- ============================================= 02 · movimento -->
        <section class="border-t border-border/70 pt-7">
            <SectionHeading
                index="02"
                title="Quem se moveu, e para onde"
                :description="`Cada aluno de ${comparison.interim.reference_date_label} até ao estado atual do período. Quanto se moveram, e quem mudou de lado da escala.`"
            />
            <MovementBoard
                :comparable="comparison.movement.comparable"
                :movements="movements"
                :crossings="crossings"
                :held="held"
                :crossing-title="crossingTitle"
                :crossing-caption="crossingCaption"
                :crossing-comparable="comparison.transitions.comparable"
                :unclassified="comparison.transitions.unclassified"
                :no-comparison="comparison.transitions.no_assigned_classification"
                :interactive="false"
                movement-caption="com resultado nos dois momentos"
            />
        </section>

        <!-- ================================== 03 · as classificações -->
        <section class="border-t border-border/70 pt-7">
            <SectionHeading
                index="03"
                title="Quantos alunos em cada nível"
                description="Os níveis atribuídos num momento e no outro. Sem ordenação por resultado."
            />

            <div v-if="comparison.assigned_distribution.interim_is_available">
                <ul class="space-y-1.5">
                    <li
                        v-for="band in comparison.assigned_distribution.bands"
                        :key="band.scale_level_id"
                        class="flex items-baseline gap-3 rounded-lg px-2.5 py-2 text-sm odd:bg-muted/25"
                    >
                        <span class="min-w-0 font-medium">{{ band.code }} · {{ band.label }}</span>
                        <span v-if="band.only_in_interim" class="text-[11px] text-muted-foreground">já não existe na escala</span>
                        <span v-else-if="band.only_in_final" class="text-[11px] text-muted-foreground">não existia então</span>

                        <span class="ml-auto flex items-baseline gap-2 tabular-nums">
                            <span class="w-8 text-right text-muted-foreground">{{ band.interim_count ?? '—' }}</span>
                            <span aria-hidden="true" class="text-muted-foreground/60">→</span>
                            <span class="w-8 text-right font-semibold">{{ band.final_count ?? '—' }}</span>
                            <span
                                class="w-10 text-right text-xs"
                                :class="(band.change ?? 0) > 0 ? 'text-emerald-700 dark:text-emerald-400'
                                    : (band.change ?? 0) < 0 ? 'text-rose-700 dark:text-rose-400' : 'text-muted-foreground'"
                            >{{ band.change === null ? '' : band.change > 0 ? `+${band.change}` : band.change }}</span>
                        </span>
                    </li>
                </ul>

                <p class="mt-2.5 text-[11px] leading-relaxed text-muted-foreground">
                    Classificações atribuídas, não médias calculadas.
                    {{ comparison.assigned_distribution.interim_without_classification }} sem classificação em
                    {{ comparison.interim.name }} · {{ comparison.assigned_distribution.final_without_classification }} sem
                    classificação agora.
                </p>
            </div>

            <p v-else class="rounded-xl bg-muted/25 px-4 py-6 text-center text-sm text-muted-foreground">
                Esta fotografia foi tirada antes de as classificações atribuídas passarem a ser registadas nela,
                por isso não há distribuição para comparar. O que ela guardou não se reescreve.
            </p>
        </section>

        <!-- ============================================ 04 · a turma -->
        <section class="border-t border-border/70 pt-7">
            <SectionHeading index="04" title="A turma, de um momento ao outro" />
            <Slopegraph
                :slopes="classSlope"
                :from-label="comparison.interim.reference_date_label"
                :to-label="finalLabel"
                :show-labels="false"
                :summary="`Média Ponderada da turma em ${comparison.interim.name} e no ${finalLabel}.`"
            />
        </section>

        <!-- ============================================ 05 · domínios -->
        <section class="border-t border-border/70 pt-7">
            <SectionHeading
                index="05"
                title="Cada domínio"
                description="Os nomes são os que existiam quando a fotografia foi tirada."
            />
            <Slopegraph
                :slopes="domainSlopes"
                :from-label="comparison.interim.reference_date_label"
                :to-label="finalLabel"
                :summary="`Média de cada domínio em ${comparison.interim.name} e no ${finalLabel}.`"
            />

            <ul class="mt-4 space-y-1 text-xs text-muted-foreground">
                <li v-for="domain in comparison.domains.filter((row) => row.only_in_interim || row.only_in_final)" :key="domain.domain_id">
                    <template v-if="domain.only_in_interim">
                        «{{ domain.label }}» existia nesta fotografia e já não faz parte do perfil.
                    </template>
                    <template v-else>
                        «{{ domain.label }}» entrou no perfil depois desta fotografia.
                    </template>
                </li>
            </ul>
        </section>

        <!-- ============================================== 06 · alunos -->
        <section class="border-t border-border/70 pt-7">
            <SectionHeading
                index="06"
                title="Aluno a aluno"
                :description="comparison.summary.supplementary
                    ? ` em cima; só neste período, em baixo e mais pequeno. Sem ordenação por resultado.`
                    : 'Sem ordenação por resultado.'"
            />

            <div class="-mx-2 overflow-x-auto px-2">
                <table class="w-max min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-border text-left">
                            <th scope="col" class="px-3 py-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Aluno</th>
                            <th scope="col" class="px-3 py-2 text-center text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                                {{ comparison.interim.reference_date_label }}
                            </th>
                            <th scope="col" class="px-3 py-2 text-center text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                                {{ finalLabel }}
                            </th>
                            <th scope="col" class="px-3 py-2 text-center text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Diferença</th>
                            <th scope="col" class="px-3 py-2 text-center text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Menção</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="student in comparison.students" :key="student.enrollment_id" class="border-b border-border/60 last:border-0">
                            <th scope="row" class="px-3 py-1.5 text-left font-normal">
                                <span class="mr-1.5 tabular-nums text-xs text-muted-foreground">{{ student.class_number ?? '—' }}</span>
                                {{ student.name }}
                            </th>
                            <!-- The primary reading at full weight, and the
                                 period's own underneath it in small type — so
                                 the difference between the two is visible per
                                 student without a second table (§9). -->
                            <td class="px-3 py-1.5 text-center tabular-nums">
                                {{ pct(student.primary.interim_value) }}
                                <CircleAlert v-if="student.interim_coverage_warning && student.primary.interim_value !== null" class="ml-0.5 inline size-3 text-amber-500" />
                                <span v-if="student.supplementary" class="block text-[10px] text-muted-foreground">
                                    {{ pct(student.supplementary.interim_value) }}
                                </span>
                            </td>
                            <td class="px-3 py-1.5 text-center tabular-nums">
                                {{ pct(student.primary.final_value) }}
                                <CircleAlert v-if="student.final_coverage_warning && student.primary.final_value !== null" class="ml-0.5 inline size-3 text-amber-500" />
                                <span v-if="student.supplementary" class="block text-[10px] text-muted-foreground">
                                    {{ pct(student.supplementary.final_value) }}
                                </span>
                            </td>
                            <td
                                class="px-3 py-1.5 text-center tabular-nums"
                                :class="student.direction === 'up' ? 'text-emerald-600 dark:text-emerald-400'
                                    : student.direction === 'down' ? 'text-rose-600 dark:text-rose-400' : 'text-muted-foreground'"
                            >
                                <template v-if="student.change !== null">
                                    {{ student.direction === 'up' ? '↑' : student.direction === 'down' ? '↓' : '→' }}
                                    {{ formatPoints(student.change) }}
                                </template>
                                <span v-else class="text-xs">sem comparação</span>

                                <span
                                    v-if="student.supplementary && student.supplementary.change !== null"
                                    class="block text-[10px] text-muted-foreground"
                                >{{ formatPoints(student.supplementary.change) }}</span>
                            </td>
                            <td class="px-3 py-1.5 text-center">
                                <span class="text-xs text-muted-foreground">{{ mentionLabel(student.interim_band) }}</span>
                                <span aria-hidden="true" class="mx-1 text-muted-foreground/50">→</span>
                                <span
                                    v-if="student.final_band"
                                    class="rounded-md px-2 py-0.5 text-xs font-medium"
                                    :class="toneClass(student.final_band)"
                                >{{ mentionLabel(student.final_band) }}</span>
                                <span v-else class="text-xs text-muted-foreground">—</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="mt-3 text-xs text-muted-foreground">
                Os alunos são os que constavam da fotografia. Quem entrou na turma depois não tem
                lado esquerdo para comparar.
            </p>
        </section>
    </div>
</template>
