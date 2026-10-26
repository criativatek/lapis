<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import SectionHeading from '@/components/dashboard/SectionHeading.vue';
import type { PeriodRuler, UpcomingItem, WeekDay } from '@/lib/dashboard';
import { dateLabel } from '@/lib/dashboard';

const props = defineProps<{
    ruler: NonNullable<PeriodRuler>;
    today: string;
    /** Na Base, sem horário, a régua do período é o bloco do dia: leva os acontecimentos de hoje e o que se aproxima. */
    prominent?: boolean;
    todayEvents?: WeekDay['events'];
    upcoming?: UpcomingItem[];
    summary?: string | null;
}>();

const STATE_LABEL = { corrected: 'corrigido', in_correction: 'em correção', applied: 'aplicado, sem correção', scheduled: 'marcado' } as const;
const STATE_CLASS = {
    corrected: 'bg-(--brand-amber)',
    in_correction: 'bg-primary',
    applied: 'bg-foreground',
    scheduled: 'bg-(--pencil)',
} as const;

const dayLabel = computed(() => `dia ${props.ruler.period.day} de ${props.ruler.period.days} · termina a ${props.ruler.period.ends_on.slice(8, 10)} de ${['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'][Number(props.ruler.period.ends_on.slice(5, 7)) - 1]}`);

/** Para o leitor de ecrã: quantas marcas há e de que estados, sem ler a lista inteira. */
const marksSummary = computed(() => {
    const total = props.ruler.marks.length;

    if (total === 0) {
        return '';
    }

    const open = props.ruler.marks.filter((mark) => mark.state !== 'corrected').length;

    return `. ${total} ${total === 1 ? 'elemento com data' : 'elementos com data'}, ${open} por fechar`;
});

/** Os elementos mais relevantes: os que ainda não estão corrigidos, do mais próximo ao mais distante, três no máximo. */
const relevantMarks = computed(() => props.ruler.marks.filter((mark) => mark.state !== 'corrected').sort((a, b) => a.date.localeCompare(b.date)).slice(0, 3));

const hiddenMarks = computed(() => props.ruler.marks.length - relevantMarks.value.length);

const nextItems = computed(() => (props.upcoming ?? []).slice(0, 3));
</script>

<template>
    <section class="rounded-2xl border border-border bg-card p-4 sm:px-5" aria-labelledby="dash-period" data-testid="period-ruler">
        <SectionHeading id="dash-period" :title="prominent ? 'Hoje e o período' : ruler.period.label" :accent="prominent" :note="prominent ? `${ruler.period.label} · ${dayLabel}` : dayLabel" />

        <ol v-if="prominent && (todayEvents?.length ?? 0) > 0" class="mt-2 mb-4 divide-y divide-border" aria-label="Hoje">
            <li v-for="event in todayEvents" :key="`${event.kind}-${event.title}`">
                <Link :href="event.href" class="flex items-center gap-3 py-2 hover:bg-muted/60 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
                    <span class="w-[52px] shrink-0 text-[13px] font-semibold text-muted-foreground tabular-nums">{{ event.start_time ?? 'hoje' }}</span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold">{{ event.title }}{{ event.class_label ? ` — ${event.class_label}` : '' }}</span>
                        <span class="block truncate text-[13px] text-muted-foreground">{{ event.type_label }}</span>
                    </span>
                </Link>
            </li>
        </ol>
        <p v-else-if="prominent" class="mt-2 mb-4 font-serif text-base text-muted-foreground italic">Nada marcado no calendário para hoje.</p>

        <div class="relative mt-3 h-2.5 rounded-full bg-muted" role="img" :aria-label="`${ruler.period.label}: ${dayLabel}${marksSummary}`">
            <span class="absolute inset-y-0 left-0 rounded-full bg-primary/85" :style="{ width: `${Math.round(ruler.period.progress * 100)}%` }"></span>
            <span
                v-for="mark in ruler.marks"
                :key="mark.ulid"
                class="absolute -top-1.5 h-5 w-0.5 rounded"
                :class="STATE_CLASS[mark.state]"
                :style="{ left: `calc(${mark.position * 100}% - 1px)` }"
                :title="`${mark.date} · ${mark.title} — ${mark.class.label} · ${STATE_LABEL[mark.state]}`"
            ></span>
        </div>
        <div class="mt-2 flex justify-between text-xs text-muted-foreground tabular-nums">
            <span>{{ ruler.period.starts_on.slice(8, 10) }}/{{ ruler.period.starts_on.slice(5, 7) }}</span>
            <!-- O resumo só cabe na variante larga (Base); na compacta repetiria o «Agora». -->
            <span v-if="summary && prominent" class="hidden truncate px-2 sm:inline">{{ summary }}</span>
            <span class="whitespace-nowrap">{{ ruler.period.ends_on.slice(8, 10) }}/{{ ruler.period.ends_on.slice(5, 7) }} · faltam {{ ruler.period.days_left }} dias</span>
        </div>
        <p v-if="summary && prominent" class="mt-1 text-xs text-muted-foreground sm:hidden">{{ summary }}</p>
        <!-- Só o que importa: os elementos por fechar mais próximos; o resto está nas grelhas. -->
        <ol v-if="!prominent && relevantMarks.length > 0" class="mt-2 divide-y divide-border border-t border-border" aria-label="Elementos de avaliação por fechar">
            <li v-for="mark in relevantMarks" :key="mark.ulid">
                <Link :href="mark.href" class="flex items-center gap-3 py-1.5 text-[13px] hover:bg-muted/60 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
                    <span class="w-[52px] shrink-0 font-semibold text-muted-foreground tabular-nums">{{ dateLabel(mark.date, today) }}</span>
                    <span class="min-w-0 flex-1 truncate"><span class="font-semibold">{{ mark.title }}</span><span class="text-muted-foreground"> — {{ mark.class.label }} · {{ STATE_LABEL[mark.state] }}</span></span>
                </Link>
            </li>
        </ol>
        <p v-if="!prominent && ruler.marks.length > 0" class="mt-1.5 text-xs text-muted-foreground">
            <Link href="/assessments" class="inline-flex min-h-11 items-center font-semibold text-primary hover:underline sm:min-h-0">{{ hiddenMarks > 0 ? `Ver todos os ${ruler.marks.length} elementos` : 'Ver as grelhas' }}</Link>
        </p>

        <ol v-if="prominent && nextItems.length > 0" class="mt-3 divide-y divide-border border-t border-border" aria-label="A aproximar-se">
            <li v-for="item in nextItems" :key="`${item.kind}-${item.date}-${item.title}`">
                <Link :href="item.href" class="flex items-center gap-3 py-2 hover:bg-muted/60 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
                    <span class="w-[52px] shrink-0 text-[13px] font-semibold text-muted-foreground tabular-nums">{{ dateLabel(item.date, today) }}</span>
                    <span class="min-w-0 flex-1 truncate text-sm"><span class="font-semibold">{{ item.title }}</span><span v-if="item.class" class="text-muted-foreground"> — {{ item.class.label }}</span><span class="text-muted-foreground"> · {{ item.subtitle }}</span></span>
                </Link>
            </li>
        </ol>
    </section>
</template>
