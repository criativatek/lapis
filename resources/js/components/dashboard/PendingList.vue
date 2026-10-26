<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { BellRing, HeartHandshake, NotebookPen, PenLine, Presentation } from '@lucide/vue';
import type { QueueItem } from '@/lib/dashboard';

/**
 * Todas as pendências, numa lista simples e plana: sem prioridades nem grupos,
 * na ordem em que `buildQueue` as dá. A linha inteira é a ligação.
 */
defineProps<{ items: QueueItem[] }>();

/** O título já traz «— 7.º A»: a turma passa a ser um rótulo próprio, sem repetição. */
function titleOf(item: QueueItem): string {
    const suffix = item.class ? ` — ${item.class.label}` : '';

    return suffix && item.title.endsWith(suffix) ? item.title.slice(0, -suffix.length) : item.title;
}

const ICONS: Record<QueueItem['kind'], typeof PenLine> = {
    summary: Presentation,
    summaries_older: Presentation,
    classifications: PenLine,
    alerts: BellRing,
    review: HeartHandshake,
    correction: NotebookPen,
};
</script>

<template>
    <ul class="divide-y divide-border" data-testid="pending-list">
        <li v-for="item in items" :key="item.key">
            <Link
                :href="item.href"
                class="grid min-h-11 grid-cols-[28px_1fr] items-center gap-x-2.5 py-2 pr-1 hover:bg-muted/60 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:min-h-9 sm:grid-cols-[28px_1fr_auto]"
            >
                <span class="grid size-7 place-items-center rounded-md bg-muted text-muted-foreground" aria-hidden="true">
                    <component :is="ICONS[item.kind]" class="size-[15px]" />
                </span>
                <span class="min-w-0">
                    <span class="block text-[14.5px] leading-5 font-semibold">
                        {{ titleOf(item) }}
                        <span v-if="item.class" class="ml-1.5 inline-block rounded-md bg-muted px-1.5 py-px align-middle text-xs font-medium text-muted-foreground" data-testid="pending-class">{{ item.class.label }}</span>
                    </span>
                    <span class="block text-[12.5px] text-muted-foreground">{{ item.detail }}</span>
                </span>
                <span class="col-start-2 text-sm font-semibold text-primary sm:col-start-3" data-testid="pending-action">{{ item.action }}</span>
            </Link>
        </li>
    </ul>
</template>
