<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { CircleAlert } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InfographicMetric from '@/components/infographic/InfographicMetric.vue';
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

type Comparison = {
    interim: { ulid: string; name: string; reference_date: string; reference_date_label: string };
    period: { id: number; label: string };
    is_final_still_open: boolean;
    summary: {
        interim_average: string | null; final_average: string | null; change: string | null;
        interim_students_with_result: number; final_students_with_result: number;
    };
    movement: {
        progressed: number; stable: number; regressed: number; no_comparison: number;
        comparable: number; average_change: string | null;
    };
    transitions: Record<CrossingKey | HeldKey | 'unclassified' | 'no_comparison' | 'comparable', number> & {
        percentages: Record<CrossingKey | HeldKey, string | null>;
        share_of_class: { unclassified: string | null; no_comparison: string | null };
    };
    domains: {
        domain_id: number; label: string;
        interim_average: string | null; final_average: string | null; change: string | null;
        interim_mention: Mention; final_mention: Mention;
        interim_partial_coverage: number; final_partial_coverage: number;
        only_in_interim?: boolean; only_in_final?: boolean;
    }[];
    students: {
        enrollment_id: number; name: string; class_number: number | null;
        interim_average: string | null; final_average: string | null;
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

/** The class itself, from one moment to the other. */
const classSlope = computed<Slope[]>(() => [{
    id: 0,
    label: 'Média Ponderada da turma',
    from: props.comparison.summary.interim_average === null ? null : Number(props.comparison.summary.interim_average),
    to: props.comparison.summary.final_average === null ? null : Number(props.comparison.summary.final_average),
    colour: '#4f46e5',
}]);

const domainSlopes = computed<Slope[]>(() => props.comparison.domains.map((domain) => ({
    id: domain.domain_id,
    label: domain.label,
    from: domain.interim_average === null ? null : Number(domain.interim_average),
    to: domain.final_average === null ? null : Number(domain.final_average),
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

const crossings = computed<CrossingCard[]>(() => {
    const transitions = props.comparison.transitions;

    return [
        {
            key: 'failure_to_success',
            label: 'Passaram a resultado positivo',
            count: transitions.failure_to_success,
            share: formatShare(transitions.percentages.failure_to_success),
        },
        {
            key: 'success_to_failure',
            label: 'Passaram a resultado negativo',
            count: transitions.success_to_failure,
            share: formatShare(transitions.percentages.success_to_failure),
        },
    ];
});

const held = computed<HeldCard[]>(() => [
    { key: 'success_to_success', label: 'Mantiveram resultado positivo', count: props.comparison.transitions.success_to_success },
    { key: 'failure_to_failure', label: 'Mantiveram resultado negativo', count: props.comparison.transitions.failure_to_failure },
]);

const averageDirection = computed<'up' | 'down' | 'flat' | null>(() => {
    const change = props.comparison.movement.average_change;

    if (change === null) {
        return null;
    }

    return Number(change) > 0 ? 'up' : Number(change) < 0 ? 'down' : 'flat';
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
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <InfographicMetric
                index="01"
                :label="comparison.interim.name"
                :value="pct(comparison.summary.interim_average)"
                :context="`${comparison.interim.reference_date_label} · ${comparison.summary.interim_students_with_result} com resultado`"
            />
            <InfographicMetric
                index="02"
                :label="finalLabel"
                :value="pct(comparison.summary.final_average)"
                :context="`${comparison.summary.final_students_with_result} com resultado`"
            />
            <InfographicMetric index="03" label="Diferença" value="">
                <template #value>
                    <span
                        :class="Number(comparison.summary.change) > 0 ? 'text-emerald-600 dark:text-emerald-400'
                            : Number(comparison.summary.change) < 0 ? 'text-rose-600 dark:text-rose-400' : ''"
                    >
                        {{ formatPoints(comparison.summary.change) }}
                    </span>
                </template>
                <p class="mt-2 text-xs text-muted-foreground">pontos percentuais</p>
            </InfographicMetric>
            <InfographicMetric
                index="04"
                label="Evolução média por aluno"
                :value="formatPoints(comparison.movement.average_change)"
                :context="`Só os ${comparison.movement.comparable} alunos com resultado nos dois momentos`"
            />
        </div>

        <!-- ============================================= 02 · movimento -->
        <section class="border-t border-border/70 pt-7">
            <SectionHeading
                index="02"
                title="Quem se moveu, e para onde"
                :description="`Cada aluno de ${comparison.interim.reference_date_label} até ao estado atual do período. Quanto se moveram, e quem mudou de lado da escala.`"
            />
            <MovementBoard
                :average-display="formatPoints(comparison.movement.average_change)"
                :average-direction="averageDirection"
                :comparable="comparison.movement.comparable"
                :movements="movements"
                :crossings="crossings"
                :held="held"
                :crossing-comparable="comparison.transitions.comparable"
                :unclassified="comparison.transitions.unclassified"
                :no-comparison="comparison.transitions.no_comparison"
                :interactive="false"
                movement-caption="com resultado nos dois momentos"
            />
        </section>

        <!-- ============================================ 03 · a turma -->
        <section class="border-t border-border/70 pt-7">
            <SectionHeading index="03" title="A turma, de um momento ao outro" />
            <Slopegraph
                :slopes="classSlope"
                :from-label="comparison.interim.reference_date_label"
                :to-label="finalLabel"
                :show-labels="false"
                :summary="`Média Ponderada da turma em ${comparison.interim.name} e no ${finalLabel}.`"
            />
        </section>

        <!-- ============================================ 04 · domínios -->
        <section class="border-t border-border/70 pt-7">
            <SectionHeading
                index="04"
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

        <!-- ============================================== 05 · alunos -->
        <section class="border-t border-border/70 pt-7">
            <SectionHeading index="05" title="Aluno a aluno" description="Sem ordenação por resultado." />

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
                            <td class="px-3 py-1.5 text-center tabular-nums">
                                {{ pct(student.interim_average) }}
                                <CircleAlert v-if="student.interim_coverage_warning && student.interim_average !== null" class="ml-0.5 inline size-3 text-amber-500" />
                            </td>
                            <td class="px-3 py-1.5 text-center tabular-nums">
                                {{ pct(student.final_average) }}
                                <CircleAlert v-if="student.final_coverage_warning && student.final_average !== null" class="ml-0.5 inline size-3 text-amber-500" />
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
