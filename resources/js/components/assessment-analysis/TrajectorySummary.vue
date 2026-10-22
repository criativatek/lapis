<script setup lang="ts">
import { computed } from 'vue';
import { percent } from '@/lib/assessmentComparison';
import {
    gapSentence,
    persistentSentence,
    stepsSentence,
    stoppedSentence,
    TREND_ARROW,
    TREND_CLASS,
    TREND_WORD,
} from '@/lib/trajectories';
import type { Reading, SeriesPoint } from '@/lib/trajectories';

/**
 * Uma trajetória lida, em frases curtas e com os números que a sustentam: os valores ao longo dos
 * momentos, o nome da tendência (e os passos em que assenta) e — à parte, porque é outra pergunta —
 * se está abaixo do nível positivo em momentos seguidos, incluindo o mais recente.
 *
 * LÊ-SE, NÃO SE CALCULA. Nada é diagnóstico, previsão ou recomendação: são notas e momentos.
 */
const props = defineProps<{
    reading: Reading;
    points: SeriesPoint[];
}>();

const trend = computed(() => props.reading.trend);
const persistent = computed(() => persistentSentence(props.reading.persistent));
const stopped = computed(() => stoppedSentence(props.reading.persistent));
const gaps = computed(() => gapSentence(props.reading.trend));
</script>

<template>
    <div class="space-y-1 text-sm" data-test="trajectory">
        <p class="tabular-nums" data-test="trajectory-values">
            <template v-for="(value, index) in reading.values" :key="index">
                <span v-if="index > 0" class="mx-1 text-muted-foreground" aria-hidden="true">→</span>
                <span
                    :class="reading.negative[index] === true ? 'font-medium text-rose-700 dark:text-rose-400' : ''"
                    :title="points[index]?.label"
                >{{ percent(value) }}</span>
            </template>
        </p>
        <p class="flex flex-wrap items-baseline gap-x-2 text-xs" data-test="trajectory-trend">
            <span class="font-medium" :class="TREND_CLASS[trend.state]">{{ TREND_ARROW[trend.state] }} {{ TREND_WORD[trend.state] }}</span>
            <span class="text-muted-foreground">{{ stepsSentence(trend) }}</span>
        </p>
        <p v-if="gaps" class="text-xs text-muted-foreground" data-test="trajectory-gaps">{{ gaps }}</p>
        <div v-if="persistent" class="rounded-md border border-border/70 bg-muted/30 px-2.5 py-1.5 text-xs" data-test="trajectory-persistent">
            <p :class="reading.persistent.state === 'yes' ? 'font-medium' : 'text-muted-foreground'">{{ persistent }}</p>
            <ul v-if="reading.persistent.points.length" class="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 tabular-nums text-muted-foreground">
                <li v-for="point in reading.persistent.points" :key="point.label">
                    {{ point.label }}: {{ percent(point.value) }}<template v-if="point.band.label"> · {{ point.band.label }}</template>
                </li>
            </ul>
        </div>
        <p v-if="stopped" class="text-xs text-muted-foreground" data-test="trajectory-stopped">{{ stopped }}</p>
    </div>
</template>
