<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { BellRing, Check, HeartHandshake, NotebookPen, PenLine, Presentation } from '@lucide/vue';
import { computed } from 'vue';
import SectionHeading from '@/components/dashboard/SectionHeading.vue';
import { Button } from '@/components/ui/button';
import type { QueueItem } from '@/lib/dashboard';

const props = defineProps<{
    queue: QueueItem[];
    /** A fila está vazia porque não há trabalho — ou porque não há turmas. */
    hasClasses: boolean;
    /** Amanhã, em prosa, para o vazio não ser um beco. */
    tomorrowHint: string | null;
}>();

const agora = computed(() => props.queue[0] ?? null);
const aSeguir = computed(() => props.queue.slice(1));

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
    <div class="flex flex-col gap-6">
        <!-- AGORA — a única coisa que não deve esperar, com a ação à vista. -->
        <section class="flex flex-col gap-2.5" aria-labelledby="dash-agora">
            <SectionHeading id="dash-agora" title="Agora" accent :note="agora ? 'a única coisa que não deve esperar' : null" />

            <div v-if="agora" class="card-soft relative overflow-hidden rounded-2xl border border-border bg-card p-4 pl-6 sm:p-5 sm:pl-7" data-testid="agora">
                <span class="absolute inset-y-0 left-0 w-1 bg-primary" aria-hidden="true"></span>
                <p class="text-[17px] leading-6 font-semibold">{{ agora.title }}</p>
                <p class="mt-0.5 text-sm text-muted-foreground">{{ agora.detail }}</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <Button as-child><Link :href="agora.href">{{ agora.action }}</Link></Button>
                </div>
            </div>

            <div v-else class="rounded-2xl border border-dashed border-border px-5 py-7 text-center" data-testid="agora-empty">
                <Check class="mx-auto size-7 text-(--pencil)" aria-hidden="true" />
                <p class="mt-2 font-semibold">{{ hasClasses ? 'Nada por fazer.' : 'Ainda sem turmas neste ano letivo.' }}</p>
                <p class="mx-auto mt-1 max-w-md text-sm text-muted-foreground">
                    {{ hasClasses ? (tomorrowHint ?? 'Quando houver trabalho novo, aparece aqui com a ação à vista.') : 'Crie a primeira turma para o painel começar a trabalhar por si.' }}
                </p>
            </div>
        </section>

        <!-- A SEGUIR — lista pautada, ícones neutros: espera em silêncio. -->
        <section v-if="aSeguir.length > 0" class="flex flex-col gap-2" aria-labelledby="dash-seguir" data-testid="a-seguir">
            <SectionHeading id="dash-seguir" title="A seguir" :note="`quando tiveres um momento · ${aSeguir.length}`" />
            <ol class="divide-y divide-border border-y border-border">
                <li v-for="item in aSeguir" :key="item.key">
                    <Link :href="item.href" class="grid grid-cols-[28px_1fr] items-center gap-2.5 py-2.5 pr-1 sm:grid-cols-[28px_1fr_auto] hover:bg-muted/60 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
                        <span class="grid size-7 place-items-center rounded-md" :class="item.emphasis === 'amber' ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-200' : 'bg-muted text-muted-foreground'" aria-hidden="true">
                            <component :is="ICONS[item.kind]" class="size-[15px]" />
                        </span>
                        <span class="min-w-0">
                            <span class="block text-[14.5px] leading-5 font-semibold">{{ item.title }}</span>
                            <span class="block text-[12.5px] text-muted-foreground">{{ item.detail }}</span>
                        </span>
                        <span class="col-start-2 text-sm font-semibold text-primary sm:col-start-3">{{ item.action }}</span>
                    </Link>
                </li>
            </ol>
        </section>
    </div>
</template>
