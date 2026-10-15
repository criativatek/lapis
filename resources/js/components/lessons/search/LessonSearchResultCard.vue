<script setup lang="ts">
/**
 * UM RESULTADO DA PESQUISA — uma aula, com o sumário inteiro.
 *
 * Quem e quando à cabeça (a turma com o tom guardado dela, o grupo por
 * extenso, a data com o ano, a hora e o número da lição), o estado à direita
 * na mesma pílula da semana, e o sumário COMPLETO com as ocorrências
 * destacadas — o que se procurou pode estar no último parágrafo, que é
 * exatamente o que o cartão compacto não mostra.
 *
 * O estado nunca sai do cartão: uma expressão encontrada numa aula preparada
 * diz o que estava previsto, não que foi dado.
 */
import { Link } from '@inertiajs/vue3';
import { ArrowRight } from '@lucide/vue';
import { computed } from 'vue';
import HighlightedSummary from '@/components/lessons/search/HighlightedSummary';
import LessonIdentity from '@/components/lessons/week/LessonIdentity.vue';
import LessonStatePill from '@/components/lessons/week/LessonStatePill.vue';
import { Button } from '@/components/ui/button';
import { lessonNumberLabel, lessonScope, lessonTimeRange } from '@/lib/lessons';
import { resultDateLabel } from '@/lib/lessonSummarySearch';
import type { SummarySearchResult } from '@/lib/lessonSummarySearch';
import { TURMA_BAND, TURMA_BAND_STRIPED } from '@/lib/turmaTones';
import type { TurmaTone } from '@/lib/turmaTones';

const props = withDefaults(
    defineProps<{
        result: SummarySearchResult;
        tone: TurmaTone;
        showSubject?: boolean;
        highlighted?: boolean;
    }>(),
    { showSubject: false, highlighted: false },
);

const emit = defineEmits<{ open: [] }>();

const scope = computed(() => lessonScope(props.result));
const date = computed(() => resultDateLabel(props.result));
const time = computed(() => lessonTimeRange(props.result));
const numberLabel = computed(
    () =>
        lessonNumberLabel(
            props.result.lesson_number,
            props.result.lesson_number_end,
        ) ?? 'Sem número',
);
const band = computed(() =>
    scope.value.kind === 'group'
        ? TURMA_BAND_STRIPED[props.tone]
        : TURMA_BAND[props.tone],
);
const context = computed(
    () => `${props.result.context_label}, ${date.value}, ${time.value}`,
);
</script>

<template>
    <article
        :id="`resultado-${result.ulid}`"
        :aria-labelledby="`resultado-${result.ulid}-titulo`"
        data-testid="search-result"
        :data-lesson="result.ulid"
        :class="[
            'relative grid gap-3 rounded-[10px] border bg-card py-4 pr-4 pl-[22px] shadow-xs scroll-mt-24 @3xl:pr-5 @3xl:pl-7',
            highlighted ? 'ring-[3px] ring-amber-400/70' : '',
        ]"
    >
        <span
            :class="['absolute inset-y-0 left-0 w-1.5 rounded-l-[10px]', band]"
            aria-hidden="true"
        />

        <header class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
            <div class="grid min-w-0 gap-1.5">
                <h3
                    :id="`resultado-${result.ulid}-titulo`"
                    class="text-base font-normal"
                >
                    <LessonIdentity
                        :class-label="result.school_class.label"
                        :tone="tone"
                        :scope="scope"
                    />
                    <span class="sr-only">, {{ date }}, {{ time }}</span>
                </h3>
                <p
                    class="flex flex-wrap gap-x-1.5 text-[13px] leading-[18px] text-muted-foreground"
                >
                    <time
                        :datetime="result.starts_at"
                        class="font-semibold text-foreground"
                        >{{ date }}</time
                    >
                    <span aria-hidden="true">·</span>
                    <span class="tabular-nums">{{ time }}</span>
                    <span aria-hidden="true">·</span>
                    <span
                        class="font-semibold text-foreground tabular-nums"
                        data-testid="search-result-number"
                        >{{ numberLabel }}</span
                    >
                    <template v-if="showSubject">
                        <span aria-hidden="true">·</span>
                        <span data-testid="search-result-subject">{{
                            result.subject
                        }}</span>
                    </template>
                </p>
            </div>
            <span class="flex items-center gap-2">
                <span class="sr-only">Estado da aula:</span>
                <LessonStatePill :lesson="result" />
            </span>
        </header>

        <HighlightedSummary :segments="result.segments" />

        <footer class="flex flex-wrap items-center justify-between gap-2">
            <p
                class="text-[13px] text-muted-foreground"
                data-testid="search-result-occurrences"
            >
                {{ result.occurrences }}
                {{ result.occurrences === 1 ? 'ocorrência' : 'ocorrências' }}
                neste sumário
            </p>
            <Button
                as-child
                variant="outline"
                class="min-h-11 w-full sm:min-h-9 sm:w-auto"
            >
                <Link
                    :href="`/lessons/${result.ulid}`"
                    :aria-label="`Abrir aula — ${context}`"
                    data-testid="open-lesson"
                    @click="emit('open')"
                >
                    Abrir aula
                    <ArrowRight class="size-4" aria-hidden="true" />
                </Link>
            </Button>
        </footer>
    </article>
</template>
