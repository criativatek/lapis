<script setup lang="ts">
import { ArrowDownRight, ArrowUpRight, Check, Circle, Minus, TrendingDown, TrendingUp, TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import { prefersReducedMotion } from '@/lib/chartTheme';

/**
 * «Como evoluiu a turma», answered as TWO readings rather than one.
 *
 * MOVING AND CROSSING ARE DIFFERENT QUESTIONS. A student going 62% → 68%
 * progressed and stayed exactly where they were pedagogically; one going
 * 48% → 53% moved less and crossed the line the school actually acts on. The
 * old bar could only ever tell the first story, so the second — which is the
 * one a conselho de turma talks about — had to be worked out by hand.
 *
 * So the board is built in two registers. Above: the average and how many
 * moved, which is arithmetic about results. Below, under its own rule and
 * heading: how many changed SIDE of the scale, which is a statement about
 * passing and failing and is given the bigger numbers because it is the
 * heavier fact.
 *
 * THE GRADIENTS ARE PAPER, NOT DATA. Each ground is a fixed pastel wash chosen
 * for its category — mint for rising, slate-blue for holding, coral for
 * falling — and never varies with the value. Nothing here is encoded in a
 * length, an angle or a saturation: every figure is read as a numeral, a
 * percentage and a word, so the composition survives being printed in grey.
 */

export type MovementKey = 'progressed' | 'stable' | 'regressed';
export type CrossingKey = 'failure_to_success' | 'success_to_failure';
export type HeldKey = 'success_to_success' | 'failure_to_failure';

export type MovementCard = {
    key: MovementKey;
    label: string;
    count: number;
    /** Already formatted by the page — «66,7%» or «—». */
    share: string;
};

export type CrossingCard = {
    key: CrossingKey;
    label: string;
    count: number;
    share: string;
};

export type HeldCard = {
    key: HeldKey;
    label: string;
    count: number;
};

const props = withDefaults(
    defineProps<{
        /** «+8,8» — formatted by the page, so this decides no arithmetic. */
        averageDisplay: string;
        averageDirection: 'up' | 'down' | 'flat' | null;
        /** Students with two comparable standalone results. */
        comparable: number;
        movements: MovementCard[];
        crossings: CrossingCard[];
        held: HeldCard[];
        /** Students the scale placed a band on at BOTH ends. */
        crossingComparable: number;
        unclassified: number;
        noComparison: number;
        selected?: string | null;
        /**
         * Whether the cards select a group of students. Off where there is
         * nothing to highlight — a control that looks pressable and does
         * nothing is worse than a plain figure.
         */
        interactive?: boolean;
        /** «resultado isolado contra resultado isolado», or the interim's words. */
        movementCaption?: string;
    }>(),
    {
        selected: null,
        interactive: true,
        movementCaption: 'com dois períodos comparáveis, resultado isolado contra resultado isolado',
    },
);

const emit = defineEmits<{ (event: 'select', key: string): void }>();

/**
 * Ground, edge and ink per category. Fixed strings rather than computed
 * classes so Tailwind keeps them, and a dark twin for each: a pale wash over a
 * dark ground turns to mud, so dark mode gets a deep desaturated version and
 * carries the identity in the edge instead of the fill (§28).
 */
const SURFACES: Record<string, { ground: string; glow: string; ink: string; fill: string }> = {
    progressed: {
        ground: 'bg-gradient-to-br from-emerald-50 via-emerald-50/70 to-teal-100/70 border-emerald-200/70'
            + ' dark:from-emerald-950/50 dark:via-emerald-950/25 dark:to-teal-900/25 dark:border-emerald-900/50',
        glow: 'bg-emerald-300/40 dark:bg-emerald-500/20',
        ink: 'text-emerald-700 dark:text-emerald-300',
        fill: 'from-emerald-300 to-teal-400 dark:from-emerald-700 dark:to-teal-600',
    },
    stable: {
        ground: 'bg-gradient-to-br from-slate-50 via-slate-50/70 to-sky-100/60 border-slate-200/70'
            + ' dark:from-slate-900/70 dark:via-slate-900/35 dark:to-sky-950/35 dark:border-slate-700/50',
        glow: 'bg-sky-300/35 dark:bg-sky-500/15',
        ink: 'text-slate-600 dark:text-slate-300',
        fill: 'from-slate-300 to-sky-300 dark:from-slate-600 dark:to-sky-800',
    },
    regressed: {
        ground: 'bg-gradient-to-br from-rose-50 via-rose-50/70 to-orange-100/60 border-rose-200/70'
            + ' dark:from-rose-950/50 dark:via-rose-950/25 dark:to-orange-950/25 dark:border-rose-900/50',
        glow: 'bg-rose-300/40 dark:bg-rose-500/20',
        ink: 'text-rose-700 dark:text-rose-300',
        fill: 'from-rose-300 to-orange-300 dark:from-rose-800 dark:to-orange-900',
    },
    failure_to_success: {
        ground: 'bg-gradient-to-br from-emerald-50 via-teal-50 to-emerald-100/80 border-emerald-200/70'
            + ' dark:from-emerald-950/55 dark:via-teal-950/35 dark:to-emerald-900/30 dark:border-emerald-800/50',
        glow: 'bg-emerald-300/45 dark:bg-emerald-500/25',
        ink: 'text-emerald-700 dark:text-emerald-300',
        fill: 'from-emerald-300 to-teal-400 dark:from-emerald-700 dark:to-teal-600',
    },
    success_to_failure: {
        ground: 'bg-gradient-to-br from-rose-50 via-orange-50/70 to-rose-100/70 border-rose-200/70'
            + ' dark:from-rose-950/55 dark:via-orange-950/30 dark:to-rose-900/30 dark:border-rose-800/50',
        glow: 'bg-rose-300/45 dark:bg-rose-500/25',
        ink: 'text-rose-700 dark:text-rose-300',
        fill: 'from-rose-300 to-orange-300 dark:from-rose-800 dark:to-orange-900',
    },
    neutral: {
        ground: 'bg-gradient-to-br from-muted/40 to-muted/10 border-border/70',
        glow: 'bg-muted-foreground/10',
        ink: 'text-muted-foreground',
        fill: 'from-muted to-muted',
    },
};

const MOVEMENT_ICONS = { progressed: TrendingUp, stable: Minus, regressed: TrendingDown };
const CROSSING_ICONS = { failure_to_success: ArrowUpRight, success_to_failure: ArrowDownRight };
const HELD_ICONS = { success_to_success: Check, failure_to_failure: TriangleAlert };

/** The average's own ground. Flat and absent both read as neutral. */
const heroTone = computed<string>(() => {
    if (props.averageDirection === 'up') {
        return 'progressed';
    }

    return props.averageDirection === 'down' ? 'regressed' : 'stable';
});

const HeroIcon = computed(() => {
    if (props.averageDirection === 'up') {
        return TrendingUp;
    }

    return props.averageDirection === 'down' ? TrendingDown : Minus;
});

const motion = computed<string>(() => (prefersReducedMotion() ? 'duration-0' : 'duration-300'));

/**
 * ONE SCALE FOR THE THREE BARS, and it is the number of students.
 *
 * Never the largest count: scaling to the tallest would make «4 de 6» and
 * «4 de 40» draw the same bar, and the shape of the class would silently
 * redraw itself per class. A zero keeps its row and draws nothing.
 */
const barBase = computed<number>(() => Math.max(
    props.comparable + props.noComparison,
    ...props.movements.map((movement) => movement.count),
    1,
));

function barWidth(count: number): number {
    return (count / barBase.value) * 100;
}

/** Nobody to point at means nothing to select. */
function isSelectable(count: number): boolean {
    return props.interactive && count > 0;
}

function toggle(key: string, count: number): void {
    if (isSelectable(count)) {
        emit('select', key);
    }
}

const students = (count: number): string => (count === 1 ? '1 aluno' : `${count} alunos`);
</script>

<template>
    <div>
        <!-- ---------------------------------------- a média e quem se moveu -->
        <div class="grid gap-3 lg:grid-cols-5">
            <!-- THE HEADLINE, given a whole panel of its own. It answers «a
                 turma melhorou ou piorou?» before any counting starts. -->
            <div
                class="relative overflow-hidden rounded-[18px] border px-5 py-4 lg:col-span-2"
                :class="SURFACES[heroTone].ground"
            >
                <span
                    aria-hidden="true"
                    class="pointer-events-none absolute -right-10 -top-12 size-36 rounded-full blur-3xl"
                    :class="SURFACES[heroTone].glow"
                ></span>

                <p class="relative text-[10px] font-semibold uppercase tracking-[0.14em] text-muted-foreground">
                    Evolução média
                </p>

                <p class="relative mt-2 flex items-baseline gap-2">
                    <component
                        :is="HeroIcon"
                        aria-hidden="true"
                        class="size-6 shrink-0 self-center"
                        :class="SURFACES[heroTone].ink"
                    />
                    <span
                        class="text-[2.75rem] font-semibold leading-none tabular-nums tracking-tight"
                        :class="SURFACES[heroTone].ink"
                    >{{ averageDisplay }}</span>
                    <span class="text-sm font-medium text-muted-foreground">p.p.</span>
                </p>

                <p class="relative mt-2.5 text-[11px] leading-relaxed text-muted-foreground">
                    Sobre {{ students(comparable) }} {{ movementCaption }}.
                </p>
            </div>

            <!-- THREE BARS ON ONE SCALE. The counts are small and the question
                 is «quantos, comparados uns com os outros» — three lengths
                 sharing a baseline answer that in one look, which three
                 separate numerals cannot. The numeral is still at the end of
                 every bar, so nothing has to be measured by eye. -->
            <ul class="flex flex-col justify-center gap-2.5 lg:col-span-3">
                <li v-for="movement in movements" :key="movement.key">
                    <component
                        :is="isSelectable(movement.count) ? 'button' : 'div'"
                        :type="isSelectable(movement.count) ? 'button' : undefined"
                        class="flex w-full items-center gap-3 rounded-[14px] px-2.5 py-1.5 text-left transition ease-out"
                        :class="[
                            motion,
                            isSelectable(movement.count) ? 'cursor-pointer hover:bg-muted/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring' : '',
                            selected === movement.key ? 'bg-muted/50 ring-1 ring-foreground/15' : '',
                            selected !== null && selected !== movement.key ? 'opacity-55' : '',
                        ]"
                        :aria-pressed="isSelectable(movement.count) ? selected === movement.key : undefined"
                        @click="toggle(movement.key, movement.count)"
                    >
                        <span class="flex w-[7.5rem] shrink-0 items-center gap-1.5">
                            <component
                                :is="MOVEMENT_ICONS[movement.key]"
                                aria-hidden="true"
                                class="size-3.5 shrink-0"
                                :class="SURFACES[movement.key].ink"
                            />
                            <span class="truncate text-xs font-medium">{{ movement.label }}</span>
                        </span>

                        <span class="h-6 min-w-0 flex-1 overflow-hidden rounded-lg bg-muted/40">
                            <span
                                class="block h-full rounded-lg bg-gradient-to-r transition-all ease-out"
                                :class="[SURFACES[movement.key].fill, motion]"
                                :style="{ width: `${barWidth(movement.count)}%` }"
                            ></span>
                        </span>

                        <span class="w-6 shrink-0 text-right text-base font-semibold tabular-nums">{{ movement.count }}</span>
                        <span class="w-12 shrink-0 text-right text-[11px] tabular-nums text-muted-foreground">{{ movement.share }}</span>
                    </component>
                </li>
            </ul>
        </div>

        <!-- ------------------------------------------- mudanças de patamar -->
        <div class="mt-5 mb-3 flex items-center gap-3">
            <h3 class="text-[10px] font-semibold uppercase tracking-[0.14em] text-muted-foreground">
                Mudanças de patamar
            </h3>
            <span aria-hidden="true" class="h-px flex-1 bg-border/70"></span>
            <span class="text-[10px] tabular-nums text-muted-foreground">
                {{ crossingComparable }} com classificação atribuída nos dois momentos
            </span>
        </div>

        <!-- THE HEAVIER FACT, GIVEN THE BIGGER NUMBERS. Crossing the line is
             what a conselho de turma acts on, so these two are the largest
             figures on the card even when they read zero — «ninguém passou» is
             an answer and deserves to be visible as one. -->
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <component
                :is="isSelectable(crossing.count) ? 'button' : 'div'"
                v-for="crossing in crossings"
                :key="crossing.key"
                :type="isSelectable(crossing.count) ? 'button' : undefined"
                class="relative overflow-hidden rounded-[18px] border p-4 text-left transition ease-out"
                :class="[
                    crossing.count > 0 ? SURFACES[crossing.key].ground : SURFACES.neutral.ground,
                    motion,
                    isSelectable(crossing.count) ? 'cursor-pointer hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring' : '',
                    selected === crossing.key ? 'ring-2 ring-foreground/25 shadow-md' : '',
                    selected !== null && selected !== crossing.key ? 'opacity-60' : '',
                ]"
                :aria-pressed="isSelectable(crossing.count) ? selected === crossing.key : undefined"
                @click="toggle(crossing.key, crossing.count)"
            >
                <span
                    aria-hidden="true"
                    class="pointer-events-none absolute -right-8 -bottom-10 size-28 rounded-full blur-3xl"
                    :class="crossing.count > 0 ? SURFACES[crossing.key].glow : SURFACES.neutral.glow"
                ></span>

                <span
                    class="relative inline-flex size-9 items-center justify-center rounded-full bg-background/70 shadow-sm dark:bg-white/10"
                >
                    <component
                        :is="CROSSING_ICONS[crossing.key]"
                        aria-hidden="true"
                        class="size-[18px]"
                        :class="crossing.count > 0 ? SURFACES[crossing.key].ink : 'text-muted-foreground'"
                    />
                </span>

                <span class="relative mt-3 block text-[2.5rem] font-semibold leading-none tabular-nums tracking-tight">
                    {{ crossing.count }}
                </span>

                <span class="relative mt-1.5 block text-sm font-medium leading-snug">{{ crossing.label }}</span>

                <span class="relative mt-0.5 block text-[11px] tabular-nums text-muted-foreground">
                    <template v-if="crossing.count > 0">{{ crossing.share }} de quem tem classificação nos dois momentos</template>
                    <template v-else>Ninguém mudou de patamar neste sentido</template>
                </span>
            </component>
        </div>

        <!-- SECONDARY BY DESIGN: standing still on the same side is the normal
             case and does not need the weight of a card (§15). -->
        <dl class="mt-3 flex flex-wrap items-center gap-x-5 gap-y-1.5 rounded-xl bg-background/50 px-3.5 py-2.5 text-xs dark:bg-background/25">
            <div v-for="row in held" :key="row.key" class="flex items-center gap-1.5">
                <component
                    :is="HELD_ICONS[row.key]"
                    aria-hidden="true"
                    class="size-3.5 shrink-0"
                    :class="row.key === 'success_to_success'
                        ? 'text-emerald-600 dark:text-emerald-400'
                        : 'text-amber-600 dark:text-amber-400'"
                />
                <dt class="text-muted-foreground">{{ row.label }}</dt>
                <dd class="font-semibold tabular-nums">{{ row.count }}</dd>
            </div>

            <div v-if="unclassified > 0" class="flex items-center gap-1.5">
                <Circle aria-hidden="true" class="size-3.5 shrink-0 text-muted-foreground/60" />
                <dt class="text-muted-foreground">Sem menção na escala</dt>
                <dd class="font-semibold tabular-nums text-muted-foreground">{{ unclassified }}</dd>
            </div>

            <div class="flex items-center gap-1.5">
                <Circle aria-hidden="true" class="size-3.5 shrink-0 text-muted-foreground/60" />
                <dt class="text-muted-foreground">Sem classificação comparável</dt>
                <dd class="font-semibold tabular-nums" :class="noComparison > 0 ? '' : 'text-muted-foreground'">
                    {{ noComparison }}
                </dd>
            </div>
        </dl>

        <!-- WHAT THIS BLOCK READS, said plainly. The register above is about
             results and this one is about grades, and a teacher must never have
             to guess which of the two a number came from (§12). -->
        <p class="mt-2 text-[11px] leading-relaxed text-muted-foreground">
            <template v-if="noComparison > 0">
                Mudança de patamar lê a classificação que atribuiu, não a média.
                {{ students(noComparison) }} sem classificação atribuída nos dois momentos ficam fora
                destas contagens — e não são «mantiveram-se».
            </template>
            <template v-else>Mudança de patamar lê a classificação que atribuiu, não a média calculada.</template>
        </p>
    </div>
</template>
