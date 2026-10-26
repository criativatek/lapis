<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { NotebookPen, PenLine, Presentation } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import type { DashboardData, QueueItem } from '@/lib/dashboard';
import { eyebrowFor, greetingFor } from '@/lib/dashboard';

const props = defineProps<{
    teacherName: string;
    data: DashboardData;
    queue: QueueItem[];
    phrase: string;
    canOpenAssessments: boolean;
    canOpenRecords: boolean;
    canRegisterLessons: boolean;
}>();

// Só o primeiro nome: a saudação é humana, não um registo.
const firstName = computed(() => props.teacherName.split(' ')[0]);
const greeting = computed(() => greetingFor(props.data.context.now));
const eyebrow = computed(() => eyebrowFor(props.data.context));

/**
 * A AÇÃO PRIMÁRIA DO DIA, uma só: a aula a decorrer ou a seguir (Pro), senão
 * o primeiro item da fila, senão nada — um botão que não aponta para trabalho
 * real seria ruído.
 */
const primary = computed<{ label: string; href: string } | null>(() => {
    const day = props.data.day;

    if (props.canRegisterLessons && day.available) {
        const current = day.items.find((item) => item.kind === 'lesson' && item.state === 'current');

        if (current) {
            return { label: current.lesson?.has_summary ? `Abrir a aula das ${current.start_time}` : 'Registar o sumário da aula a decorrer', href: current.href };
        }

        const pending = day.items.find((item) => item.kind === 'lesson' && item.state === 'pending');

        if (pending) {
            return { label: `Registar o sumário das ${pending.start_time}`, href: pending.href };
        }

        const next = day.items.find((item) => item.kind === 'lesson' && item.state === 'upcoming');

        if (next) {
            return { label: `Abrir a aula das ${next.start_time}`, href: next.href };
        }
    }

    const first = props.queue[0];

    return first ? { label: `${first.action}: ${first.title.split(' — ')[0]?.toLowerCase()}`, href: first.href } : null;
});
</script>

<template>
    <section class="relative" aria-labelledby="dash-greeting">
        <!-- A lavagem âmbar rara do DESIGN.md: uma só, no canto, a dizer «manhã de
             trabalho arrumado». Decorativa, invisível ao leitor de ecrã. -->
        <div class="pointer-events-none absolute -inset-x-4 -top-4 -z-0 h-40 bg-[radial-gradient(640px_200px_at_88%_-10%,hsl(38_92%_88%/.55),transparent_70%)] dark:bg-[radial-gradient(640px_200px_at_88%_-10%,hsl(38_60%_30%/.35),transparent_70%)]" aria-hidden="true"></div>

        <div class="relative flex flex-wrap items-end justify-between gap-x-6 gap-y-4">
            <div class="min-w-0">
                <p class="mb-1 text-xs font-semibold tracking-[.08em] text-muted-foreground uppercase">{{ eyebrow }}</p>
                <h1 id="dash-greeting" class="font-serif text-[32px] leading-[38px] font-medium tracking-tight">{{ greeting }}, {{ firstName }}.</h1>
                <p class="mt-1 max-w-[62ch] font-serif text-lg leading-[25px] text-muted-foreground" data-testid="status-phrase">{{ phrase }}</p>
            </div>

            <div class="flex w-full flex-wrap gap-2 sm:w-auto" aria-label="Ações rápidas">
                <Button v-if="primary" as-child class="basis-full sm:basis-auto">
                    <Link :href="primary.href" data-testid="primary-action"><Presentation class="size-4" aria-hidden="true" />{{ primary.label }}</Link>
                </Button>
                <Button v-if="canOpenAssessments" as-child variant="outline" class="flex-1 sm:flex-none">
                    <Link href="/assessments"><PenLine class="size-4" aria-hidden="true" />Nova grelha</Link>
                </Button>
                <Button v-if="canOpenRecords" as-child variant="outline" class="flex-1 sm:flex-none">
                    <Link href="/records"><NotebookPen class="size-4" aria-hidden="true" />Novo registo</Link>
                </Button>
            </div>
        </div>
    </section>
</template>
