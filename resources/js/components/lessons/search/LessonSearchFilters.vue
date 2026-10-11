<script setup lang="ts">
/**
 * OS FILTROS DA PESQUISA — turmas e estado à vista; período e outras
 * ocorrências em «Mais filtros».
 *
 * O estado está sempre à vista porque é a pergunta que muda a resposta:
 * «lecionadas» diz o que foi dado; «preparadas», o que estava previsto. Por
 * omissão (na primeira utilização), só as lecionadas.
 *
 * Os filtros são da PESQUISA (`q_classes`, `q_states`…), e não os do
 * calendário: mudar aqui as turmas não muda o que o calendário filtra quando
 * se volta a ele.
 */
import { Check, ChevronDown, SlidersHorizontal } from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    searchClassLabel,
    SUMMARY_SEARCH_STATE_LABELS,
} from '@/lib/lessonSummarySearch';
import type {
    SummarySearchState,
    SummarySearchStates,
} from '@/lib/lessonSummarySearch';
import { toggled } from '@/lib/lessonWeekView';
import { TURMA_SWATCH } from '@/lib/turmaTones';
import type { TurmaTone } from '@/lib/turmaTones';

const props = defineProps<{
    state: SummarySearchState;
    classes: {
        ulid: string;
        label: string;
        subject: string;
        tone: TurmaTone;
        archived: boolean;
    }[];
    showSubject: boolean;
    /** Os limites do ano letivo, para as datas. */
    bounds: { start: string; end: string } | null;
}>();

const emit = defineEmits<{
    update: [patch: Partial<SummarySearchState>];
}>();

const STATES: SummarySearchStates[] = ['taught', 'prepared', 'both'];

const open = ref(
    props.state.from !== null || props.state.to !== null || props.state.other,
);
const moreCount = computed(
    () =>
        (props.state.from !== null || props.state.to !== null ? 1 : 0) +
        (props.state.other ? 1 : 0),
);

function setStates(value: SummarySearchStates): void {
    if (value !== props.state.states) {
        emit('update', { states: value });
    }
}

function onStatesKeydown(event: KeyboardEvent, index: number): void {
    if (!['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End'].includes(event.key)) {
        return;
    }

    event.preventDefault();
    const forward = event.key === 'ArrowRight' || event.key === 'ArrowDown';
    const next =
        event.key === 'Home'
            ? 0
            : event.key === 'End'
              ? STATES.length - 1
              : (index + (forward ? 1 : -1) + STATES.length) % STATES.length;
    const target = STATES[next];

    if (target) {
        setStates(target);
        nextTick(() =>
            document.getElementById(`search-state-${target}`)?.focus(),
        );
    }
}

function setDate(key: 'from' | 'to', value: string | number): void {
    const text = String(value);
    emit('update', { [key]: /^\d{4}-\d{2}-\d{2}$/.test(text) ? text : null });
}

const chip =
    'inline-flex min-h-11 items-center gap-1.5 rounded-full border px-3 text-sm font-medium transition-colors outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 sm:min-h-8 aria-pressed:border-primary aria-pressed:bg-primary/10 aria-pressed:text-primary dark:aria-pressed:bg-primary/20 hover:bg-accent';
</script>

<template>
    <section
        aria-label="Filtros da pesquisa"
        class="grid gap-2.5 rounded-[14px] border bg-card px-3 py-2.5 sm:px-4"
        data-testid="search-filters"
    >
        <div
            role="group"
            aria-labelledby="search-filter-classes"
            class="flex flex-wrap items-center gap-1.5"
        >
            <span
                id="search-filter-classes"
                class="mr-1 text-[13px] font-semibold text-muted-foreground"
                >Turmas</span
            >
            <button
                type="button"
                :class="chip"
                :aria-pressed="state.classes.length === 0"
                data-testid="search-class-all"
                @click="emit('update', { classes: [] })"
            >
                <Check
                    v-if="state.classes.length === 0"
                    class="size-3.5"
                    aria-hidden="true"
                />Todas
            </button>
            <button
                v-for="schoolClass in classes"
                :key="schoolClass.ulid"
                type="button"
                :class="chip"
                :aria-pressed="state.classes.includes(schoolClass.ulid)"
                :data-testid="`search-class-${schoolClass.ulid}`"
                @click="
                    emit('update', {
                        classes: toggled(state.classes, schoolClass.ulid),
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
                {{ searchClassLabel(schoolClass, showSubject)
                }}<span
                    v-if="schoolClass.archived"
                    class="text-xs text-muted-foreground"
                    >(arquivada)</span
                >
            </button>
        </div>

        <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
            <div class="flex w-full flex-wrap items-center gap-2 sm:w-auto">
                <span
                    id="search-filter-states"
                    class="text-[13px] font-semibold text-muted-foreground"
                    >Estado</span
                >
                <div
                    role="radiogroup"
                    aria-labelledby="search-filter-states"
                    class="grid flex-1 grid-cols-3 gap-0.5 rounded-lg border bg-background p-0.5 sm:flex-none"
                >
                    <button
                        v-for="(value, index) in STATES"
                        :id="`search-state-${value}`"
                        :key="value"
                        type="button"
                        role="radio"
                        :aria-checked="state.states === value"
                        :tabindex="state.states === value ? 0 : -1"
                        :data-testid="`search-state-${value}`"
                        :class="[
                            'inline-flex min-h-11 items-center justify-center rounded-md px-3 text-sm font-medium outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 sm:min-h-8',
                            state.states === value
                                ? 'bg-primary text-primary-foreground'
                                : 'hover:bg-accent',
                        ]"
                        @click="setStates(value)"
                        @keydown="onStatesKeydown($event, index)"
                    >
                        {{ SUMMARY_SEARCH_STATE_LABELS[value] }}
                    </button>
                </div>
            </div>
            <Button
                type="button"
                variant="outline"
                size="sm"
                class="min-h-11 w-full sm:ml-auto sm:min-h-8 sm:w-auto"
                :aria-expanded="open"
                aria-controls="search-filters-more"
                data-testid="search-filters-more-toggle"
                @click="open = !open"
            >
                <SlidersHorizontal class="size-4" aria-hidden="true" />
                Mais filtros
                <span
                    v-if="moreCount > 0"
                    class="rounded-full bg-primary px-1.5 text-xs leading-5 font-semibold text-primary-foreground"
                >
                    {{ moreCount }}<span class="sr-only"> ativos</span>
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
            id="search-filters-more"
            class="grid gap-3 border-t border-dashed pt-2.5"
        >
            <fieldset class="flex flex-wrap items-end gap-x-3 gap-y-2">
                <legend
                    class="mb-1.5 w-full text-[13px] font-semibold text-muted-foreground"
                >
                    Período
                </legend>
                <label class="grid gap-1 text-sm">
                    De
                    <Input
                        type="date"
                        class="h-11 w-auto sm:h-9"
                        :model-value="state.from ?? ''"
                        :min="bounds?.start"
                        :max="state.to ?? bounds?.end"
                        data-testid="search-from"
                        @change="
                            (event: Event) =>
                                setDate(
                                    'from',
                                    (event.target as HTMLInputElement).value,
                                )
                        "
                    />
                </label>
                <label class="grid gap-1 text-sm">
                    Até
                    <Input
                        type="date"
                        class="h-11 w-auto sm:h-9"
                        :model-value="state.to ?? ''"
                        :min="state.from ?? bounds?.start"
                        :max="bounds?.end"
                        data-testid="search-to"
                        @change="
                            (event: Event) =>
                                setDate(
                                    'to',
                                    (event.target as HTMLInputElement).value,
                                )
                        "
                    />
                </label>
                <Button
                    v-if="state.from !== null || state.to !== null"
                    type="button"
                    variant="link"
                    class="h-auto min-h-11 px-1 sm:min-h-9"
                    data-testid="search-dates-clear"
                    @click="emit('update', { from: null, to: null })"
                    >Todo o ano letivo</Button
                >
            </fieldset>
            <div>
                <button
                    type="button"
                    :class="chip"
                    :aria-pressed="state.other"
                    data-testid="search-other"
                    @click="emit('update', { other: !state.other })"
                >
                    <Check
                        v-if="state.other"
                        class="size-3.5"
                        aria-hidden="true"
                    />Incluir professor ausente e outras atividades
                </button>
            </div>
        </div>
    </section>
</template>
