<script setup lang="ts">
/**
 * OS RESULTADOS DA PESQUISA, na área principal da página.
 *
 * À cabeça, sempre: o que se procurou, ONDE (o âmbito inteiro — ano letivo,
 * turmas, estados, período — para ninguém julgar que é só a semana aberta) e
 * quanto se encontrou, com as aulas separadas das ocorrências.
 *
 * Nenhum resultado diz isso mesmo — que não há sumários com aqueles critérios
 * — e nunca que um conteúdo «não foi lecionado»: a pesquisa lê o que está
 * escrito, não o que aconteceu na sala.
 *
 * As contagens são sempre as da resposta DESTA pesquisa (`current`): enquanto
 * a nova não chega, a lista anterior fica esbatida e sem números. E se o
 * servidor pesquisou noutro ano letivo (mudado noutro separador), nada se
 * mostra com um âmbito que não foi o usado — pede-se para recarregar.
 */
import { ChevronLeft, ChevronRight, Info, SearchX } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import LessonSearchResultCard from '@/components/lessons/search/LessonSearchResultCard.vue';
import { Button } from '@/components/ui/button';
import type {
    SummarySearchErrorKind,
    SummarySearchStatus,
} from '@/composables/useSummarySearch';
import type {
    SummarySearchResponse,
    SummarySearchResult,
    SummarySearchState,
} from '@/lib/lessonSummarySearch';
import type { TurmaTone } from '@/lib/turmaTones';

const props = withDefaults(
    defineProps<{
        state: SummarySearchState;
        scope: string[];
        response: SummarySearchResponse | null;
        status: SummarySearchStatus;
        /** A resposta à vista é a desta pesquisa. */
        current: boolean;
        error: string | null;
        errorKind: SummarySearchErrorKind | null;
        /** O ano letivo que a página mostra — para confirmar que foi o pesquisado. */
        academicYear: string;
        tones: Map<string, TurmaTone>;
        showSubject?: boolean;
        highlighted?: string | null;
    }>(),
    { showSubject: false, highlighted: null },
);

const emit = defineEmits<{
    page: [page: number];
    retry: [];
    open: [result: SummarySearchResult];
    'include-prepared': [];
    'reload-page': [];
}>();

const heading = ref<HTMLElement | null>(null);
const loading = computed(() => props.status === 'loading');
const firstLoad = computed(() => loading.value && props.response === null);
/** Os resultados à vista são DESTA pesquisa e do ano letivo que a página mostra. */
const shown = computed(() =>
    props.response !== null && props.current && !yearChanged.value
        ? props.response
        : null,
);
const yearChanged = computed(
    () =>
        props.current &&
        props.response !== null &&
        props.response.academic_year !== null &&
        props.response.academic_year !== props.academicYear,
);
/** A lista anterior, esbatida, enquanto a desta pesquisa não chega. */
const previous = computed(() =>
    !props.current && loading.value && !yearChanged.value ? props.response : null,
);
const listed = computed(() => shown.value ?? previous.value);
const includesPrepared = computed(() => props.state.states !== 'taught');

function toneOf(result: SummarySearchResult): TurmaTone {
    return props.tones.get(result.school_class.ulid) ?? 'blue';
}

function plural(count: number, singular: string, pluralForm: string): string {
    return `${count} ${count === 1 ? singular : pluralForm}`;
}

/** Ao mudar de página, o foco e a leitura voltam ao início da lista. */
let focusOnNextPage = false;

function goToPage(page: number): void {
    focusOnNextPage = true;
    emit('page', page);
}

watch(
    () => props.response?.page,
    () => {
        if (!focusOnNextPage) {
            return;
        }

        focusOnNextPage = false;
        nextTick(() => {
            heading.value?.scrollIntoView({ block: 'start' });
            heading.value?.focus({ preventScroll: true });
        });
    },
);

defineExpose({ focusHeading: () => heading.value?.focus() });
</script>

<template>
    <section
        aria-labelledby="summary-search-heading"
        class="mt-4 grid gap-3"
        data-testid="search-results"
    >
        <header class="grid gap-1">
            <h2
                id="summary-search-heading"
                ref="heading"
                tabindex="-1"
                class="scroll-mt-24 text-[17px] leading-6 font-semibold outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
            >
                Resultados para «{{ state.term.trim() }}»
            </h2>
            <p
                class="text-sm text-muted-foreground"
                data-testid="search-scope"
            >
                <span class="sr-only">Âmbito da pesquisa: </span
                >{{ scope.join(' · ') }}
            </p>
            <p class="text-sm" role="status" data-testid="search-count">
                <template v-if="shown">
                    <strong class="font-semibold">{{
                        plural(
                            shown.total,
                            'aula encontrada',
                            'aulas encontradas',
                        )
                    }}</strong>
                    <template v-if="shown.total > 0">
                        ·
                        {{
                            plural(
                                shown.occurrences,
                                'ocorrência da expressão',
                                'ocorrências da expressão',
                            )
                        }}
                        · mais recentes primeiro</template
                    ><span v-if="loading" class="text-muted-foreground">
                        · a atualizar…</span
                    >
                </template>
                <template v-else-if="loading">A pesquisar nos sumários…</template>
            </p>
            <p
                v-if="includesPrepared"
                class="mt-1 flex max-w-[72ch] items-start gap-1.5 text-[13px] leading-[18px] text-muted-foreground"
                data-testid="search-prepared-note"
            >
                <Info class="mt-px size-3.5 shrink-0" aria-hidden="true" />
                Numa aula preparada, o sumário diz o que estava previsto:
                encontrar a expressão não confirma que o conteúdo foi lecionado.
                O estado de cada aula está no cartão.
            </p>
        </header>

        <div
            v-if="yearChanged"
            role="alert"
            class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-[14px] border bg-card p-4 text-sm"
            data-testid="search-year-changed"
        >
            <span
                >O ano letivo selecionado mudou noutra janela
                ({{ response?.academic_year }}). Recarrega a página para
                pesquisar no ano letivo certo.</span
            >
            <Button
                type="button"
                variant="outline"
                size="sm"
                class="min-h-11 sm:min-h-9"
                @click="emit('reload-page')"
                >Recarregar a página</Button
            >
        </div>

        <div
            v-else-if="status === 'error' && error"
            role="alert"
            class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-[14px] border border-destructive/40 bg-card p-4 text-sm"
            data-testid="search-error"
        >
            <span>{{ error }}</span>
            <Button
                v-if="errorKind === 'failed'"
                type="button"
                variant="outline"
                size="sm"
                class="min-h-11 sm:min-h-9"
                @click="emit('retry')"
                >Tentar outra vez</Button
            >
            <Button
                v-else-if="errorKind === 'session'"
                type="button"
                variant="outline"
                size="sm"
                class="min-h-11 sm:min-h-9"
                @click="emit('reload-page')"
                >Recarregar a página</Button
            >
        </div>

        <p
            v-else-if="firstLoad"
            class="rounded-[14px] border border-dashed bg-card p-6 text-center text-sm text-muted-foreground"
            aria-busy="true"
            data-testid="search-loading"
        >
            A pesquisar nos sumários de todo o ano letivo…
        </p>

        <div
            v-else-if="shown && shown.total === 0"
            class="grid justify-items-center gap-2 rounded-[14px] border border-dashed bg-card p-6 text-center"
            data-testid="search-empty"
        >
            <SearchX class="size-6 text-muted-foreground" aria-hidden="true" />
            <p class="font-semibold">
                Não foram encontrados sumários correspondentes aos critérios
                selecionados.
            </p>
            <p class="max-w-[60ch] text-sm text-muted-foreground">
                Experimenta outra palavra, outras turmas ou outro
                período<template v-if="!includesPrepared"
                    >, ou inclui as aulas preparadas</template
                >.
            </p>
            <Button
                v-if="!includesPrepared"
                type="button"
                variant="outline"
                class="mt-1 min-h-11 sm:min-h-9"
                data-testid="search-include-prepared"
                @click="emit('include-prepared')"
                >Incluir as preparadas</Button
            >
        </div>

        <template
            v-if="listed && listed.total > 0 && status !== 'error' && !yearChanged"
        >
            <ol
                class="@container grid gap-3 transition-opacity"
                :class="loading || !shown ? 'opacity-60' : ''"
                :aria-busy="loading"
                data-testid="search-result-list"
            >
                <li v-for="result in listed.results" :key="result.ulid">
                    <LessonSearchResultCard
                        :result="result"
                        :tone="toneOf(result)"
                        :show-subject="showSubject"
                        :highlighted="highlighted === result.ulid"
                        @open="emit('open', result)"
                    />
                </li>
            </ol>

            <nav
                v-if="shown && shown.last_page > 1"
                aria-label="Páginas de resultados"
                class="mt-1 flex items-center justify-between gap-2"
                data-testid="search-pagination"
            >
                <Button
                    type="button"
                    variant="outline"
                    class="min-h-11 sm:min-h-9"
                    :disabled="shown.page <= 1 || loading"
                    data-testid="search-page-previous"
                    @click="goToPage(shown.page - 1)"
                >
                    <ChevronLeft class="size-4" aria-hidden="true" />
                    Anterior
                </Button>
                <span class="text-sm text-muted-foreground tabular-nums"
                    >Página {{ shown.page }} de {{ shown.last_page }}</span
                >
                <Button
                    type="button"
                    variant="outline"
                    class="min-h-11 sm:min-h-9"
                    :disabled="shown.page >= shown.last_page || loading"
                    data-testid="search-page-next"
                    @click="goToPage(shown.page + 1)"
                >
                    Seguinte
                    <ChevronRight class="size-4" aria-hidden="true" />
                </Button>
            </nav>
        </template>
    </section>
</template>
