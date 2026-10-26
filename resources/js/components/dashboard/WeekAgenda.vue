<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Check, Circle, PenLine } from '@lucide/vue';
import { computed } from 'vue';
import SectionHeading from '@/components/dashboard/SectionHeading.vue';
import type { UpcomingItem, WeekAgenda, WeekLesson } from '@/lib/dashboard';
import { dateLabel } from '@/lib/dashboard';
import { TURMA_BADGE, isTurmaTone } from '@/lib/turmaTones';

const props = defineProps<{
    week: WeekAgenda;
    upcoming: UpcomingItem[];
    today: string;
    hasLessonsModule: boolean;
}>();

function toneClasses(lesson: WeekLesson): string {
    if (lesson.state === 'done') {
        return 'bg-amber-50 text-amber-950 dark:bg-amber-950/40 dark:text-amber-100';
    }

    return TURMA_BADGE[isTurmaTone(lesson.class.tone) ? lesson.class.tone : 'stone'];
}

/** Os próximos acontecimentos, só os que não estão já na semana à vista. */
const beyond = computed(() => {
    const lastDay = props.week.days.at(-1)?.date ?? props.today;

    return props.upcoming.filter((item) => item.date > lastDay).slice(0, 4);
});
</script>

<template>
    <section class="flex flex-col gap-2.5" aria-labelledby="dash-week" data-testid="week-agenda">
        <SectionHeading id="dash-week" title="Esta semana">
            <template #aside>
                <Link :href="week.href" class="inline-flex min-h-11 items-center text-sm font-semibold text-primary hover:underline sm:min-h-0">{{ hasLessonsModule ? 'Horário' : 'Calendário' }}</Link>
            </template>
        </SectionHeading>

        <ol class="grid gap-1.5" :style="{ gridTemplateColumns: `repeat(${week.days.length}, minmax(0, 1fr))` }" aria-label="Dias da semana">
            <!-- A CÉLULA DO DIA É A LIGAÇÃO (um alvo táctil por dia, nunca barras de
                 14 px): leva à semana das aulas, ou ao mês do calendário na Base. -->
            <li v-for="day in week.days" :key="day.date" class="min-w-0">
                <Link
                    :href="week.href"
                    class="flex min-h-[116px] flex-col gap-1 rounded-xl border bg-card p-1.5 transition-shadow hover:card-soft focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:p-2"
                    :class="[day.is_today ? 'border-primary ring-1 ring-primary ring-inset' : 'border-border', day.non_teaching ? 'border-dashed bg-transparent' : '']"
                    :aria-current="day.is_today ? 'date' : undefined"
                    :aria-label="`${day.weekday} ${day.day}: ${day.lessons.length} aulas, ${day.events.length} acontecimentos`"
                >
                    <span class="mb-0.5 flex items-baseline justify-between text-xs font-semibold">
                        <span>{{ day.weekday }}</span>
                        <span class="font-medium text-muted-foreground tabular-nums">{{ day.day }}</span>
                    </span>
                    <span v-if="day.non_teaching" class="text-[11px] leading-[14px] text-muted-foreground italic">{{ day.non_teaching }}</span>
                    <span
                        v-for="lesson in day.lessons"
                        :key="`${lesson.start_time}-${lesson.class.ulid}`"
                        class="flex min-w-0 items-center gap-1 overflow-hidden rounded-[5px] px-1 py-[3px] text-[10.5px] leading-[14px] whitespace-nowrap"
                        :class="toneClasses(lesson)"
                        :title="`${lesson.start_time} ${lesson.label}`"
                    >
                        <span class="hidden shrink-0 tabular-nums 2xl:inline">{{ lesson.start_time }}</span>
                        <span class="truncate"><span class="sm:hidden">{{ lesson.start_time }}</span><span class="hidden sm:inline">{{ lesson.class.label }}</span></span>
                        <Check v-if="lesson.state === 'done'" class="ml-auto size-2.5 shrink-0" aria-hidden="true" />
                    </span>
                    <span
                        v-for="event in day.events"
                        :key="`${event.kind}-${event.title}`"
                        class="flex min-w-0 items-center gap-1 overflow-hidden py-0.5 text-[11px] leading-[14px] whitespace-nowrap"
                        :class="event.kind === 'instrument' ? 'font-semibold text-foreground' : 'text-muted-foreground'"
                        :title="`${event.type_label}: ${event.title}${event.class_label ? ' · ' + event.class_label : ''}`"
                    >
                        <PenLine v-if="event.kind === 'instrument'" class="size-[11px] shrink-0" aria-hidden="true" />
                        <Circle v-else class="size-[11px] shrink-0" aria-hidden="true" />
                        <span class="hidden truncate sm:inline">{{ event.title }}</span>
                    </span>
                </Link>
            </li>
        </ol>

        <ol v-if="beyond.length > 0" class="divide-y divide-border border-y border-border" aria-label="A aproximar-se">
            <li v-for="item in beyond" :key="`${item.kind}-${item.date}-${item.title}`">
                <Link :href="item.href" class="flex items-center gap-3 py-2.5 hover:bg-muted/60 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
                    <span class="w-[52px] shrink-0 text-[13px] font-semibold text-muted-foreground tabular-nums">{{ dateLabel(item.date, today) }}</span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold">{{ item.title }}{{ item.class ? ` — ${item.class.label}` : '' }}</span>
                        <span class="block truncate text-[13px] text-muted-foreground">{{ item.subtitle }}{{ item.kind === 'period_end' ? ` · ${item.days_away} dias` : '' }}</span>
                    </span>
                </Link>
            </li>
        </ol>

        <p v-if="!hasLessonsModule" class="text-[13px] text-muted-foreground italic">A semana mostra acontecimentos e datas de avaliação. Com o horário do Lapispro Pro, as aulas de cada dia aparecem aqui.</p>
    </section>
</template>
