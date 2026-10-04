<script setup lang="ts">
/**
 * OS FILTROS DA SEMANA — as turmas sempre à vista, o resto recolhível.
 *
 * As turmas são o filtro do dia a dia, por isso ficam numa linha à vista, cada
 * uma com o seu tom e o nome escrito. Estado, «terminadas por confirmar» e
 * sumário vivem em «Mais filtros», recolhidos por omissão para o cabeçalho não
 * crescer. Recolhido não é escondido: um filtro ativo, seja qual for, aparece
 * na linha «A mostrar X de Y» como pílula que se remove com um toque, e o botão
 * diz quantos filtros secundários estão ligados.
 */
import {
    Check,
    ChevronDown,
    CircleAlert,
    SlidersHorizontal,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    FILTERABLE_STATES,
    secondaryFilterCount,
    STATE_FILTER_LABELS,
    toggled,
} from '@/lib/lessonWeekView';
import type {
    FilterableState,
    SummaryFilter,
    WeekViewState,
} from '@/lib/lessonWeekView';
import { TURMA_SWATCH } from '@/lib/turmaTones';
import type { TurmaTone } from '@/lib/turmaTones';

const props = withDefaults(
    defineProps<{
        state: WeekViewState;
        classes: {
            ulid: string;
            label: string;
            tone: TurmaTone;
            archived?: boolean;
        }[];
        showClasses?: boolean;
        visibleCount: number;
        totalCount: number;
        unitLabel: string;
    }>(),
    { showClasses: true },
);

const emit = defineEmits<{ update: [patch: Partial<WeekViewState>] }>();

const open = ref(false);
const secondaryCount = computed(() => secondaryFilterCount(props.state));

type Pill = { key: string; label: string; patch: Partial<WeekViewState> };

const pills = computed<Pill[]>(() => {
    const result: Pill[] = [];

    if (props.showClasses) {
        for (const ulid of props.state.classes) {
            const schoolClass = props.classes.find(
                (candidate) => candidate.ulid === ulid,
            );
            result.push({
                key: `class-${ulid}`,
                label: schoolClass?.label ?? 'Turma',
                patch: {
                    classes: props.state.classes.filter(
                        (value) => value !== ulid,
                    ),
                },
            });
        }
    }

    for (const state of props.state.states) {
        result.push({
            key: `state-${state}`,
            label: STATE_FILTER_LABELS[state],
            patch: {
                states: props.state.states.filter((value) => value !== state),
            },
        });
    }

    if (props.state.attention) {
        result.push({
            key: 'attention',
            label: 'Terminadas por confirmar',
            patch: { attention: false },
        });
    }

    if (props.state.summary !== 'todos') {
        result.push({
            key: 'summary',
            label:
                props.state.summary === 'com' ? 'Com sumário' : 'Sem sumário',
            patch: { summary: 'todos' },
        });
    }

    return result;
});

function clearAll(): void {
    emit('update', {
        classes: props.showClasses ? [] : props.state.classes,
        states: [],
        attention: false,
        summary: 'todos',
    });
}

function toggleState(state: FilterableState): void {
    emit('update', { states: toggled(props.state.states, state) });
}

function toggleSummary(value: SummaryFilter): void {
    emit('update', {
        summary: props.state.summary === value ? 'todos' : value,
    });
}

const chip =
    'inline-flex min-h-11 items-center gap-1.5 rounded-full border px-3 text-sm font-medium transition-colors outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 sm:min-h-8 aria-pressed:border-primary aria-pressed:bg-primary/10 aria-pressed:text-primary dark:aria-pressed:bg-primary/20 hover:bg-accent';
</script>

<template>
    <section
        aria-label="Filtros"
        class="grid gap-2.5 rounded-[14px] border bg-card px-3 py-2.5 sm:px-4"
        data-testid="lesson-filters"
    >
        <div class="flex flex-wrap items-center gap-2">
            <template v-if="showClasses && classes.length > 0">
                <!-- A etiqueta vive dentro do grupo, para correr na mesma linha
                     das primeiras turmas em vez de gastar uma linha sozinha. -->
                <div
                    role="group"
                    aria-labelledby="lesson-filter-classes"
                    class="flex flex-wrap items-center gap-1.5"
                >
                    <span
                        id="lesson-filter-classes"
                        class="mr-1 text-[13px] font-semibold text-muted-foreground"
                        >Turmas</span
                    >
                    <button
                        v-for="schoolClass in classes"
                        :key="schoolClass.ulid"
                        type="button"
                        :class="chip"
                        :aria-pressed="state.classes.includes(schoolClass.ulid)"
                        :data-testid="`filter-class-${schoolClass.ulid}`"
                        @click="
                            emit('update', {
                                classes: toggled(
                                    state.classes,
                                    schoolClass.ulid,
                                ),
                            })
                        "
                    >
                        <Check
                            v-if="state.classes.includes(schoolClass.ulid)"
                            class="size-3.5"
                            aria-hidden="true"
                        />
                        <span
                            :class="[
                                'size-2.5 rounded-[3px]',
                                TURMA_SWATCH[schoolClass.tone],
                            ]"
                            aria-hidden="true"
                        />
                        {{ schoolClass.label
                        }}<span
                            v-if="schoolClass.archived"
                            class="text-xs text-muted-foreground"
                            >(arquivada)</span
                        >
                    </button>
                </div>
            </template>
            <slot name="actions" />
            <Button
                type="button"
                variant="outline"
                size="sm"
                class="ml-auto min-h-11 sm:min-h-8"
                :aria-expanded="open"
                aria-controls="lesson-filters-more"
                data-testid="filters-more-toggle"
                @click="open = !open"
            >
                <SlidersHorizontal class="size-4" aria-hidden="true" />
                Mais filtros
                <span
                    v-if="secondaryCount > 0"
                    class="rounded-full bg-primary px-1.5 text-xs leading-5 font-semibold text-primary-foreground"
                >
                    {{ secondaryCount }}<span class="sr-only"> ativos</span>
                </span>
                <ChevronDown
                    :class="[
                        'size-4 transition-transform',
                        open ? 'rotate-180' : '',
                    ]"
                    aria-hidden="true"
                />
            </Button>
        </div>

        <div
            v-show="open"
            id="lesson-filters-more"
            class="grid gap-2 border-t border-dashed pt-2.5"
        >
            <div
                role="group"
                aria-labelledby="lesson-filter-state"
                class="flex flex-wrap items-center gap-1.5"
            >
                <span
                    id="lesson-filter-state"
                    class="w-full text-[13px] font-semibold text-muted-foreground sm:w-20"
                    >Estado</span
                >
                <button
                    v-for="value in FILTERABLE_STATES"
                    :key="value"
                    type="button"
                    :class="chip"
                    :aria-pressed="state.states.includes(value)"
                    :data-testid="`filter-state-${value}`"
                    @click="toggleState(value)"
                >
                    <Check
                        v-if="state.states.includes(value)"
                        class="size-3.5"
                        aria-hidden="true"
                    />{{ STATE_FILTER_LABELS[value] }}
                </button>
                <span
                    class="mx-1 hidden h-6 w-px bg-border sm:inline-block"
                    aria-hidden="true"
                />
                <button
                    type="button"
                    :class="chip"
                    :aria-pressed="state.attention"
                    data-testid="filter-attention"
                    @click="emit('update', { attention: !state.attention })"
                >
                    <CircleAlert
                        class="size-3.5"
                        aria-hidden="true"
                    />Terminadas por confirmar
                </button>
            </div>
            <div
                role="group"
                aria-labelledby="lesson-filter-summary"
                class="flex flex-wrap items-center gap-1.5"
            >
                <span
                    id="lesson-filter-summary"
                    class="w-full text-[13px] font-semibold text-muted-foreground sm:w-20"
                    >Sumário</span
                >
                <button
                    type="button"
                    :class="chip"
                    :aria-pressed="state.summary === 'com'"
                    data-testid="filter-summary-com"
                    @click="toggleSummary('com')"
                >
                    <Check
                        v-if="state.summary === 'com'"
                        class="size-3.5"
                        aria-hidden="true"
                    />Com sumário
                </button>
                <button
                    type="button"
                    :class="chip"
                    :aria-pressed="state.summary === 'sem'"
                    data-testid="filter-summary-sem"
                    @click="toggleSummary('sem')"
                >
                    <Check
                        v-if="state.summary === 'sem'"
                        class="size-3.5"
                        aria-hidden="true"
                    />Sem sumário
                </button>
            </div>
        </div>

        <div
            v-if="pills.length > 0"
            class="flex flex-wrap items-center gap-2 border-t border-dashed pt-2.5 text-sm"
            aria-live="polite"
            data-testid="active-filters"
        >
            <span class="font-semibold"
                >A mostrar {{ visibleCount }} de {{ totalCount }}
                {{ unitLabel }}</span
            >
            <span class="sr-only">Filtros ativos:</span>
            <button
                v-for="pill in pills"
                :key="pill.key"
                type="button"
                class="inline-flex min-h-11 items-center gap-1 rounded-full border bg-muted px-2.5 text-[13px] font-medium outline-none hover:border-foreground/30 focus-visible:ring-[3px] focus-visible:ring-ring/50 sm:min-h-7"
                :aria-label="`Remover o filtro ${pill.label}`"
                @click="emit('update', pill.patch)"
            >
                {{ pill.label }} <X class="size-3.5" aria-hidden="true" />
            </button>
            <Button
                type="button"
                variant="link"
                class="h-auto min-h-11 px-1 sm:min-h-0"
                data-testid="clear-filters"
                @click="clearAll"
                >Limpar filtros</Button
            >
        </div>
        <p v-else class="sr-only" aria-live="polite">
            A mostrar {{ visibleCount }} de {{ totalCount }} {{ unitLabel }},
            sem filtros ativos.
        </p>
    </section>
</template>
