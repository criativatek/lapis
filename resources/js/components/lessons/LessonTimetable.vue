<script setup lang="ts">
/**
 * A Vista Horário — a mesma semana da Vista Lista, desenhada como grelha.
 *
 * MESMA FONTE DE DADOS, SEM EXCEÇÃO. Recebe exatamente o array `lessons` que a
 * lista recebe, do mesmo `WeeklyLessonsQuery`: não há aqui uma segunda consulta,
 * um segundo formato nem um estado próprio da vista. Alternar entre as duas
 * vistas não vai ao servidor, e editar uma aula numa delas mostra-se na outra
 * porque é literalmente a mesma linha.
 *
 * A GRELHA É POR DIA, E NÃO POR HORA ABSOLUTA. Um horário escolar português tem
 * blocos de 45 ou 50 minutos em grelhas que variam de escola para escola;
 * desenhar uma régua de horas fixas obrigaria a inventar a grelha da escola, que
 * é precisamente o tipo de coisa que esta aplicação não inventa. Cada dia é uma
 * coluna, e dentro dela as aulas seguem por ordem de início, com a hora escrita
 * em cada bloco. Lê-se igualmente bem numa escola com tempos de 45 e noutra com
 * tempos de 90.
 *
 * NO TELEMÓVEL É UM DIA DE CADA VEZ (§27). Cinco colunas em 390px seriam cinco
 * colunas ilegíveis, e o scroll horizontal da página inteira — que é o que
 * acontece se se tentar encolher a grelha — é justamente o que esta aplicação
 * não faz. O seletor de dia troca a coluna; tudo o resto é igual.
 */
import { Link } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import type { WeekLesson } from '@/lib/lessons';

const props = defineProps<{
    lessons: WeekLesson[];
    weekStart: string;
    today: string;
    selectable: boolean;
    selected: string[];
}>();

const emit = defineEmits<{ (event: 'update:selected', value: string[]): void }>();

const timeFormatter = new Intl.DateTimeFormat('pt-PT', {
    hour: '2-digit',
    minute: '2-digit',
    timeZone: 'Europe/Lisbon',
});
const weekdayFormatter = new Intl.DateTimeFormat('pt-PT', {
    weekday: 'short',
    timeZone: 'UTC',
});
const dayNumberFormatter = new Intl.DateTimeFormat('pt-PT', {
    day: 'numeric',
    month: 'short',
    timeZone: 'UTC',
});

/**
 * Os sete dias da semana apresentada, e não só aqueles em que há aula: uma
 * grelha que salta a quarta-feira porque ela está vazia mente sobre a forma da
 * semana. O sábado e o domingo só aparecem quando têm mesmo alguma coisa —
 * a esmagadora maioria das semanas não tem, e duas colunas permanentemente
 * vazias roubariam um quinto da largura às que contam.
 */
const days = computed(() => {
    const start = new Date(`${props.weekStart}T12:00:00Z`);
    const all = [];

    for (let offset = 0; offset < 7; offset++) {
        const date = new Date(start);
        date.setUTCDate(date.getUTCDate() + offset);
        const key = date.toISOString().slice(0, 10);
        const lessons = props.lessons
            .filter((lesson) => lesson.starts_at.slice(0, 10) === key)
            .sort((a, b) => a.starts_at.localeCompare(b.starts_at));

        if (offset >= 5 && lessons.length === 0) {
            continue;
        }

        all.push({ key, date, lessons });
    }

    return all;
});

/** No telemóvel arranca no dia de hoje quando ele está nesta semana; fora dela,
 *  no primeiro dia que tenha aulas — e nunca numa coluna vazia por omissão. */
const selectedDay = ref<string>('');

watch(
    days,
    (value) => {
        if (value.some((day) => day.key === selectedDay.value)) {
            return;
        }

        selectedDay.value =
            value.find((day) => day.key === props.today)?.key ??
            value.find((day) => day.lessons.length > 0)?.key ??
            value[0]?.key ??
            '';
    },
    { immediate: true },
);

const mobileDay = computed(() => days.value.find((day) => day.key === selectedDay.value) ?? null);

function lessonTime(lesson: WeekLesson): string {
    const start = timeFormatter.format(new Date(lesson.starts_at));

    return lesson.ends_at ? `${start}–${timeFormatter.format(new Date(lesson.ends_at))}` : start;
}

/**
 * `Checkbox` admite um terceiro estado («indeterminate») que esta lista nunca
 * produz — uma aula está selecionada ou não está. Normalizado aqui, e não
 * ignorado, para que um estado inesperado signifique «não selecionada» em vez de
 * entrar na lista como verdadeiro por ser uma string não vazia.
 */
function toggle(ulid: string, checked: boolean | 'indeterminate'): void {
    emit(
        'update:selected',
        checked === true
            ? [...new Set([...props.selected, ulid])]
            : props.selected.filter((value) => value !== ulid),
    );
}
</script>

<template>
    <div>
        <!-- Telemóvel: um dia de cada vez. -->
        <div class="sm:hidden">
            <div
                class="-mx-1 flex gap-1 overflow-x-auto px-1 pb-2"
                role="tablist"
                aria-label="Escolher dia da semana"
            >
                <Button
                    v-for="day in days"
                    :key="day.key"
                    type="button"
                    role="tab"
                    :aria-selected="day.key === selectedDay"
                    :variant="day.key === selectedDay ? 'default' : 'outline'"
                    size="sm"
                    class="min-h-11 shrink-0 flex-col gap-0 px-3 py-1"
                    @click="selectedDay = day.key"
                >
                    <span class="text-xs capitalize">{{ weekdayFormatter.format(day.date) }}</span>
                    <span class="text-xs tabular-nums opacity-80">{{
                        dayNumberFormatter.format(day.date)
                    }}</span>
                </Button>
            </div>

            <div v-if="mobileDay" class="space-y-2">
                <p v-if="mobileDay.lessons.length === 0" class="rounded-xl border bg-card p-4 text-sm text-muted-foreground">
                    Sem aulas neste dia.
                </p>
                <div
                    v-for="lesson in mobileDay.lessons"
                    :key="lesson.ulid"
                    class="flex items-start gap-3 rounded-xl border bg-card p-3"
                >
                    <Checkbox
                        v-if="selectable"
                        :model-value="selected.includes(lesson.ulid)"
                        :aria-label="`Selecionar a aula de ${lesson.context_label}`"
                        class="mt-1"
                        @update:model-value="(value: boolean | 'indeterminate') => toggle(lesson.ulid, value)"
                    />
                    <Link
                        :href="`/lessons/${lesson.ulid}`"
                        class="min-h-11 min-w-0 flex-1 outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        <slot name="block" :lesson="lesson" :time="lessonTime(lesson)" />
                    </Link>
                </div>
            </div>
        </div>

        <!-- Ecrã largo: a grelha da semana. `overflow-x-auto` na GRELHA e nunca
             na página: numa semana com muitas colunas o que desliza é a grelha,
             dentro do seu próprio contentor. -->
        <div class="hidden overflow-x-auto sm:block">
            <div
                class="grid min-w-[44rem] gap-3"
                :style="{ gridTemplateColumns: `repeat(${days.length}, minmax(0, 1fr))` }"
            >
                <section v-for="day in days" :key="day.key" class="min-w-0 space-y-2">
                    <h3
                        class="sticky top-0 rounded-lg border bg-card px-2 py-1.5 text-center text-xs font-semibold"
                        :class="day.key === today ? 'border-primary text-primary' : ''"
                    >
                        <span class="capitalize">{{ weekdayFormatter.format(day.date) }}</span>
                        <span class="ml-1 font-normal text-muted-foreground tabular-nums">{{
                            dayNumberFormatter.format(day.date)
                        }}</span>
                    </h3>

                    <p
                        v-if="day.lessons.length === 0"
                        class="rounded-lg border border-dashed p-2 text-center text-xs text-muted-foreground"
                    >
                        —
                    </p>

                    <div
                        v-for="lesson in day.lessons"
                        :key="lesson.ulid"
                        class="flex items-start gap-2 rounded-lg border bg-card p-2"
                    >
                        <Checkbox
                            v-if="selectable"
                            :model-value="selected.includes(lesson.ulid)"
                            :aria-label="`Selecionar a aula de ${lesson.context_label}`"
                            class="mt-0.5"
                            @update:model-value="(value: boolean | 'indeterminate') => toggle(lesson.ulid, value)"
                        />
                        <Link
                            :href="`/lessons/${lesson.ulid}`"
                            class="min-w-0 flex-1 outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            <slot name="block" :lesson="lesson" :time="lessonTime(lesson)" />
                        </Link>
                    </div>
                </section>
            </div>
        </div>
    </div>
</template>
