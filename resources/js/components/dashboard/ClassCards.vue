<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import SectionHeading from '@/components/dashboard/SectionHeading.vue';
import type { ClassCard } from '@/lib/dashboard';
import { whenLabel } from '@/lib/dashboard';
import { TURMA_BAND, isTurmaTone } from '@/lib/turmaTones';

const props = defineProps<{
    classes: ClassCard[];
    today: string;
    hasLessonsModule: boolean;
}>();

function stripe(card: ClassCard): string {
    return TURMA_BAND[isTurmaTone(card.class.tone) ? card.class.tone : 'stone'];
}

/** As células: três factos, pelos módulos que a conta tem. */
function stats(card: ClassCard): { value: string; label: string }[] {
    const cells: { value: string; label: string }[] = [];

    if (props.hasLessonsModule) {
        cells.push({ value: card.absences_this_week === null ? '—' : String(card.absences_this_week), label: 'faltas' });
    } else {
        cells.push({ value: card.records_in_period === null ? '—' : String(card.records_in_period), label: 'registos' });
    }

    cells.push({ value: card.open_interventions === null ? '—' : String(card.open_interventions), label: 'medidas' });

    if (props.hasLessonsModule) {
        cells.push({ value: card.instruments ? String(card.instruments.in_correction) : '—', label: 'a corrigir' });
    } else {
        cells.push({ value: card.self_assessments ? `${card.self_assessments.submitted}/${card.self_assessments.total}` : '—', label: 'autoaval.' });
    }

    return cells;
}

/** As barras: uma por elemento do período, na ordem corrigido · em correção · aplicado · por aplicar. */
function bars(card: ClassCard): ('on' | 'pend' | 'applied' | 'off')[] {
    const instruments = card.instruments;

    if (!instruments) {
        return [];
    }

    return [
        ...Array.from({ length: instruments.corrected }, () => 'on' as const),
        ...Array.from({ length: instruments.in_correction }, () => 'pend' as const),
        ...Array.from({ length: instruments.applied }, () => 'applied' as const),
        ...Array.from({ length: instruments.to_apply }, () => 'off' as const),
    ];
}

const BAR_CLASS = { on: '', pend: 'bg-(--brand-amber)', applied: 'bg-(--pencil)', off: 'bg-muted' } as const;

/** A pílula de pendências — âmbar só quando há mesmo algo; esmeralda quando está em dia. */
function pill(card: ClassCard): { text: string; tone: 'amber' | 'green' | 'outline' }[] {
    const pending = card.pending;
    const pills: { text: string; tone: 'amber' | 'green' | 'outline' }[] = [];

    if (!card.has_profile) {
        pills.push({ text: 'sem perfil de avaliação', tone: 'amber' });
    }

    if (pending) {
        if (pending.classifications_proposed > 0) {
            pills.push({ text: `${pending.classifications_proposed} por confirmar`, tone: 'amber' });
        }

        if (pending.summaries_missing > 0) {
            pills.push({ text: pending.summaries_missing === 1 ? 'sumário em falta' : `${pending.summaries_missing} sumários em falta`, tone: 'amber' });
        }

        if (pending.alerts > 0) {
            pills.push({ text: pending.alerts === 1 ? '1 alerta' : `${pending.alerts} alertas`, tone: 'amber' });
        }

        if (pending.reviews > 0) {
            pills.push({ text: pending.reviews === 1 ? 'medida a rever' : `${pending.reviews} medidas a rever`, tone: 'amber' });
        }

        if (pending.corrections > 0 && !props.hasLessonsModule) {
            pills.push({ text: pending.corrections === 1 ? '1 por corrigir' : `${pending.corrections} por corrigir`, tone: 'amber' });
        }
    }

    if (pills.length === 0) {
        pills.push({ text: 'em dia', tone: 'green' });
    }

    if (pending && pending.classifications_confirmed > 0) {
        pills.push({ text: `${pending.classifications_confirmed} por publicar`, tone: 'outline' });
    }

    return pills.slice(0, 2);
}

const PILL_CLASS = {
    amber: 'bg-amber-100 text-amber-900 dark:bg-amber-900/50 dark:text-amber-200',
    green: 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200',
    outline: 'border border-border text-muted-foreground',
} as const;

const columns = computed(() => Math.min(4, Math.max(1, props.classes.length)));

function link(card: ClassCard): { label: string; href: string } {
    if (card.pending && card.pending.classifications_proposed > 0 && card.hrefs.classifications) {
        return { label: 'Classificações', href: card.hrefs.classifications };
    }

    return card.hrefs.assessments ? { label: 'Grelhas', href: card.hrefs.assessments } : { label: 'Turma', href: card.hrefs.class };
}
</script>

<template>
    <section class="flex flex-col gap-2.5" aria-labelledby="dash-classes" data-testid="class-cards">
        <SectionHeading id="dash-classes" title="As minhas turmas" accent>
            <template #aside>
                <Link href="/classes" class="inline-flex min-h-11 items-center text-sm font-semibold text-primary hover:underline sm:min-h-0">Ver todas</Link>
            </template>
        </SectionHeading>

        <ul class="grid gap-3" :style="{ gridTemplateColumns: `repeat(auto-fit, minmax(min(100%, 220px), 1fr))` }" :data-columns="columns">
            <li v-for="card in classes" :key="card.class.ulid">
                <Link :href="card.hrefs.class" class="relative flex h-full flex-col gap-2 overflow-hidden rounded-xl border border-border bg-card px-4 py-3 pl-5 transition-shadow hover:card-soft focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
                    <span class="absolute inset-y-0 left-0 w-1" :class="stripe(card)" aria-hidden="true"></span>

                    <span class="flex flex-col">
                        <span class="text-[19px] leading-[22px] font-semibold tracking-tight">{{ card.label }}</span>
                        <span class="text-[12.5px] text-muted-foreground">{{ card.subject }}{{ card.subject ? ' · ' : '' }}{{ card.students }} alunos</span>
                    </span>

                    <span class="flex justify-between gap-2 text-[13px] text-muted-foreground">
                        <span>{{ hasLessonsModule ? 'Próxima aula' : 'Último registo' }}</span>
                        <span class="font-semibold text-foreground tabular-nums">
                            {{ hasLessonsModule ? (card.next_lesson ? whenLabel(card.next_lesson.starts_at, today) : '—') : (card.last_record_at ? whenLabel(card.last_record_at, today) : '—') }}
                        </span>
                    </span>

                    <span class="grid grid-cols-3 gap-1.5">
                        <span v-for="cell in stats(card)" :key="cell.label" class="min-w-0 rounded-lg bg-muted px-2 py-1.5">
                            <span class="block text-base leading-5 font-semibold tabular-nums">{{ cell.value }}</span>
                            <span class="block truncate text-[10.5px] leading-[13px] text-muted-foreground">{{ cell.label }}</span>
                        </span>
                    </span>

                    <span v-if="card.instruments" class="flex flex-col gap-1.5">
                        <span class="flex justify-between text-xs text-muted-foreground">
                            <span>{{ card.instruments.period_label }} · corrigidos</span>
                            <span class="font-semibold text-foreground tabular-nums">{{ card.instruments.corrected }}/{{ card.instruments.total }}</span>
                        </span>
                        <span v-if="bars(card).length > 0" class="flex h-2 gap-[3px]" aria-hidden="true">
                            <span v-for="(bar, index) in bars(card)" :key="index" class="flex-1 rounded-[4px]" :class="bar === 'on' ? stripe(card) : BAR_CLASS[bar]"></span>
                        </span>
                        <span v-else class="h-2 rounded-[4px] bg-muted" aria-hidden="true"></span>
                    </span>

                    <span class="mt-auto flex flex-wrap items-center gap-x-2 gap-y-1 border-t border-border pt-2 text-[12.5px]">
                        <span v-for="entry in pill(card)" :key="entry.text" class="rounded-full px-2 py-0.5 font-semibold whitespace-nowrap" :class="PILL_CLASS[entry.tone]">{{ entry.text }}</span>
                        <span class="ml-auto font-semibold text-primary">{{ link(card).label }}</span>
                    </span>
                </Link>
            </li>
        </ul>

        <p class="text-xs text-muted-foreground">Barras: elementos do período corrigidos (cor da turma), em correção (âmbar), aplicados sem correção (lápis) e por aplicar (cinza). Sem médias: o acompanhamento vive em «Turma» e «Aluno».</p>
    </section>
</template>
