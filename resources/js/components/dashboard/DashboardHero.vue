<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { CalendarDays, ClipboardCheck, ListChecks, NotebookPen, PenLine } from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref, watch } from 'vue';
import HeroStat from '@/components/dashboard/HeroStat.vue';
import PendingList from '@/components/dashboard/PendingList.vue';
import { Button } from '@/components/ui/button';
import type { DashboardData, HeroCard, QueueItem } from '@/lib/dashboard';
import { eyebrowFor, greetingFor, heroCards } from '@/lib/dashboard';

const props = defineProps<{
    teacherName: string;
    data: DashboardData;
    queue: QueueItem[];
    canOpenAssessments: boolean;
    canOpenRecords: boolean;
}>();

const STORAGE_KEY = 'lapis.dashboard.pendencias';
const PANEL_ID = 'dashboard-pendencias';

// Só o primeiro nome: a saudação é humana, não um registo.
const firstName = computed(() => props.teacherName.split(' ')[0]);
const greeting = computed(() => greetingFor(props.data.context.now));
const eyebrow = computed(() => eyebrowFor(props.data.context));
const cards = computed(() => heroCards(props.data, props.queue));

const ICONS: Record<HeroCard['key'], Component> = { today: CalendarDays, summaries: ClipboardCheck, pending: ListChecks };

// O estado aberto sobrevive à ida a uma tarefa e ao «Atrás». Nunca é essencial:
// sem sessionStorage (janela privada, bloqueado) a página funciona igual.
function readOpen(): boolean {
    try {
        return window.sessionStorage.getItem(STORAGE_KEY) === '1';
    } catch {
        return false;
    }
}

const open = ref(readOpen());

watch(open, (value) => {
    try {
        window.sessionStorage.setItem(STORAGE_KEY, value ? '1' : '0');
    } catch {
        // sem armazenamento: o painel só não se lembra
    }
});

// Sem pendências não há painel (nem beco): nada de aberto, nada de botão.
const showPanel = computed(() => open.value && props.queue.length > 0);

// Só para leitores de ecrã: o contador muda depois de uma revalidação.
const announcement = computed(() => `${props.queue.length} ${props.queue.length === 1 ? 'pendência' : 'pendências'}`);
</script>

<template>
    <section class="relative" aria-labelledby="dash-greeting">
        <!-- A lavagem âmbar rara do DESIGN.md: uma só, no canto, a dizer «manhã de
             trabalho arrumado». Decorativa, invisível ao leitor de ecrã. -->
        <div class="pointer-events-none absolute -inset-x-4 -top-4 -z-0 h-40 bg-[radial-gradient(640px_200px_at_88%_-10%,hsl(38_92%_88%/.55),transparent_70%)] dark:bg-[radial-gradient(640px_200px_at_88%_-10%,hsl(38_60%_30%/.35),transparent_70%)]" aria-hidden="true"></div>

        <div class="relative flex flex-col gap-5">
            <div class="flex flex-wrap items-end justify-between gap-x-6 gap-y-4">
                <div class="min-w-0">
                    <p class="mb-1 text-xs font-semibold tracking-[.08em] text-muted-foreground uppercase">{{ eyebrow }}</p>
                    <h1 id="dash-greeting" class="font-serif text-[32px] leading-[38px] font-medium tracking-tight">{{ greeting }}, {{ firstName }}.</h1>
                </div>

                <!-- Atalhos fixos, não recomendações: ficam discretos ao lado da saudação. -->
                <div v-if="canOpenAssessments || canOpenRecords" class="flex w-full flex-wrap gap-2 sm:w-auto" aria-label="Ações rápidas">
                    <Button v-if="canOpenAssessments" as-child variant="outline" class="flex-1 sm:flex-none">
                        <Link href="/assessments"><PenLine class="size-4" aria-hidden="true" />Nova grelha</Link>
                    </Button>
                    <Button v-if="canOpenRecords" as-child variant="outline" class="flex-1 sm:flex-none">
                        <Link href="/records"><NotebookPen class="size-4" aria-hidden="true" />Novo registo</Link>
                    </Button>
                </div>
            </div>

            <!-- Sem turmas não há cartões (nada para contar), mas a orientação que o
                 painel já dava não se perde: uma linha, sem cartão. -->
            <p v-if="!data.context.has_classes" class="text-sm text-muted-foreground" data-testid="no-classes">
                Ainda sem turmas neste ano letivo. <Link href="/classes" class="font-medium text-primary hover:underline">Crie a primeira turma</Link> para o painel começar a trabalhar por si.
            </p>

            <div v-if="cards" :class="['grid grid-cols-1 gap-3', cards.length === 2 ? 'sm:grid-cols-2' : 'sm:grid-cols-3']" data-testid="hero-cards">
                <HeroStat
                    v-for="item in cards"
                    :key="item.key"
                    :title="item.title"
                    :value="item.value"
                    :label="item.label"
                    :hint="item.hint"
                    :warning="item.warning"
                    :tone="item.tone"
                    :icon="ICONS[item.key]"
                    :clickable="item.key === 'pending' && queue.length > 0"
                    :expanded="open"
                    :controls="PANEL_ID"
                    :data-testid="`hero-${item.key}`"
                    @click="open = !open"
                />
            </div>

            <p v-if="cards" class="sr-only" aria-live="polite" data-testid="pending-live">{{ announcement }}</p>

            <section v-if="cards && showPanel" :id="PANEL_ID" role="region" aria-label="Pendências" class="rounded-2xl border border-border bg-card px-3 py-1 sm:px-4" data-testid="pending-panel">
                <PendingList :items="queue" />
            </section>
        </div>
    </section>
</template>
