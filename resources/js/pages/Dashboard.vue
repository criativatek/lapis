<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowRight, CheckCircle2, Circle, LayoutList, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import ClassCards from '@/components/dashboard/ClassCards.vue';
import DashboardHero from '@/components/dashboard/DashboardHero.vue';
import DashboardLayoutEditor from '@/components/dashboard/DashboardLayoutEditor.vue';
import DayRuler from '@/components/dashboard/DayRuler.vue';
import DoneToday from '@/components/dashboard/DoneToday.vue';
import PeriodRuler from '@/components/dashboard/PeriodRuler.vue';
import WeekAgenda from '@/components/dashboard/WeekAgenda.vue';
import { Button } from '@/components/ui/button';
import { useDashboardLayout } from '@/composables/useDashboardLayout';
import { useFreshDashboard } from '@/composables/useFreshDashboard';
import type { DashboardData } from '@/lib/dashboard';
import { allDone, buildQueue } from '@/lib/dashboard';
import type { BlockId, BlockWidth } from '@/lib/dashboardLayout';
import { BLOCK_WIDTH, DEFAULT_ORDER, layoutStorageKey } from '@/lib/dashboardLayout';

type ReadinessItem = {
    id: string;
    name: string;
    description: string;
    completed: boolean;
    is_next: boolean;
    cta: { label: string; href: string } | null;
};

// Same shape as ReadinessItem minus `is_next` — "Primeiros passos" is a
// checklist of independent facts, not a sequence, so every unfinished item
// keeps its own call to action rather than just the "next" one.
type FirstStepsItem = {
    id: string;
    name: string;
    description: string;
    completed: boolean;
    cta: { label: string; href: string } | null;
};

const props = defineProps<{
    teacherName: string;
    readiness: { is_ready: boolean; items: ReadinessItem[] };
    firstSteps: { dismissed: boolean; all_done: boolean; items: FirstStepsItem[] };
    /** O que o DashboardComposer sabe do dia: só factos, já gated por módulo. */
    dashboard: DashboardData;
}>();

const page = usePage();

/**
 * A module the organization may at least CONSULT — `modules` alone would hide
 * these links from a suspended organization, whose whole point is that the data
 * stays readable. Presentation only; each destination re-checks on the server.
 */
function canRead(module: string): boolean {
    return page.props.modules.includes(module) || page.props.readOnlyModules.includes(module);
}

const canOpenAssessments = computed(() => canRead('assessments'));
const canOpenRecords = computed(() => canRead('records'));
const canOpenOrganizationAudit = computed(() => canRead('audit_log'));
// Never a modal, never blocking: an old, fully-adopted account already shows
// nothing (all_done), the same way readiness() shows nothing once configured.
const showFirstSteps = computed(() => !props.firstSteps.dismissed && !props.firstSteps.all_done);

function dismissFirstSteps(): void {
    router.post('/dashboard/onboarding-dismissal', {}, { preserveScroll: true });
}

/*
 * A HOME DE FECHO (DESIGN.md, variante C2 — e agora a Proposta C refinada): a
 * página não mostra a ferramenta, mostra o dia — Hoje → Pendências →
 * Arrumado → «podes fechar». Prosa antes de números; âmbar só no FEITO; nada
 * inventado: cada número vem do DashboardComposer.
 */
const data = computed(() => props.dashboard);
const today = computed(() => data.value.context.date);
const queue = computed(() => buildQueue(data.value.pending, today.value));
const done = computed(() => allDone(data.value, queue.value));
const hasLessons = computed(() => data.value.context.modules.lessons);

// Ao voltar de uma tarefa o painel não pode mostrar números de há horas.
useFreshDashboard(() => props.dashboard.context.now);

/** Os acontecimentos de hoje na agenda da semana — a Base mostra-os no lugar do horário. */
const todayEvents = computed(() => data.value.week.days.find((day) => day.is_today)?.events ?? []);

const periodSummary = computed<string | null>(() => {
    const counts = data.value.pending.counts;
    const parts: string[] = [];

    if (counts.corrections > 0) {
        parts.push(`${counts.corrections} ${counts.corrections === 1 ? 'elemento' : 'elementos'} por corrigir`);
    }

    if (counts.classifications_proposed > 0) {
        parts.push(`${counts.classifications_proposed} ${counts.classifications_proposed === 1 ? 'proposta' : 'propostas'} por confirmar`);
    }

    return parts.length > 0 ? parts.join(' · ') : null;
});

/*
 * A DISPOSIÇÃO: o cabeçalho e a régua «Hoje» são fixos; os quatro blocos de
 * baixo seguem a ordem do professor (guardada só no browser, por utilizador e
 * organização). Os que não existem para esta conta (período sem horário, turmas
 * sem turmas) não aparecem nem se ordenam. Ver `lib/dashboardLayout.ts`.
 */
const availableBlocks = computed<BlockId[]>(() =>
    DEFAULT_ORDER.filter((id) => (id === 'period' ? data.value.day.available && data.value.period !== null : id === 'classes' ? data.value.classes.length > 0 : true)),
);
// Um «Arrumado hoje» vazio é uma linha curta: ocupa a sua própria linha em vez de ficar ao lado de um bloco alto.
const blockWidths = computed<Record<BlockId, BlockWidth>>(() => ({ ...BLOCK_WIDTH, done: data.value.done.length === 0 ? 'wide' : 'half' }));
const storageKey = computed(() => layoutStorageKey(page.props.auth.user?.id, page.props.auth.organization?.ulid));
const layout = useDashboardLayout({ storageKey, available: availableBlocks, widths: blockWidths });
const organiseButton = ref<HTMLButtonElement | null>(null);
</script>

<template>
    <Head title="Painel do Professor" />

    <div class="flex flex-col gap-6 p-4 sm:p-5 lg:px-6">
        <DashboardHero
            :teacher-name="teacherName"
            :data="data"
            :queue="queue"
            :can-open-assessments="canOpenAssessments"
            :can-open-records="canOpenRecords"
        />

        <!-- "Primeiros passos" (A1a) — a separate, dismissible adoption
             checklist. Never a modal, never blocks navigation, and shown
             independently of the readiness checklist below. -->
        <div v-if="showFirstSteps" class="mx-auto w-full max-w-3xl rounded-xl border border-border p-4 sm:p-5">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold">Primeiros passos</h2>
                    <p class="mt-1 text-sm text-muted-foreground">Continue quando quiser — nada aqui bloqueia o resto da aplicação.</p>
                </div>
                <Button variant="ghost" size="icon" aria-label="Dispensar primeiros passos" @click="dismissFirstSteps">
                    <X class="size-4" aria-hidden="true" />
                </Button>
            </div>

            <ul class="mt-4 space-y-2" aria-label="Primeiros passos">
                <li v-for="item in firstSteps.items" :key="item.id" class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border p-3">
                    <div class="flex min-w-0 items-center gap-3">
                        <CheckCircle2 v-if="item.completed" class="size-5 shrink-0 text-emerald-600 dark:text-emerald-400" aria-hidden="true" />
                        <Circle v-else class="size-5 shrink-0 text-muted-foreground" aria-hidden="true" />
                        <div class="min-w-0">
                            <p class="text-sm font-medium">{{ item.name }}</p>
                            <p class="text-xs text-muted-foreground">{{ item.description }}</p>
                        </div>
                        <span class="sr-only">{{ item.completed ? 'Concluído' : 'Por fazer' }}</span>
                    </div>

                    <Link v-if="item.cta" :href="item.cta.href" class="shrink-0 text-sm font-medium text-primary hover:underline">
                        {{ item.cta.label }}
                    </Link>
                </li>
            </ul>
        </div>

        <div v-if="!readiness.is_ready" class="mx-auto w-full max-w-3xl">
            <div class="mb-5">
                <h2 class="text-lg font-semibold">Prepare o ano letivo</h2>
                <p class="mt-1 text-sm text-muted-foreground">Complete estes passos para começar a trabalhar com as suas turmas.</p>
            </div>

            <ol class="space-y-3" aria-label="Preparação do ano letivo">
                <li
                    v-for="item in readiness.items"
                    :key="item.id"
                    class="rounded-xl border p-4 sm:p-5"
                    :class="item.is_next ? 'border-primary bg-primary/5 shadow-sm' : 'border-border'"
                >
                    <div class="flex min-w-0 items-start gap-3 sm:gap-4">
                        <CheckCircle2 v-if="item.completed" class="mt-0.5 size-6 shrink-0 text-emerald-600 dark:text-emerald-400" aria-hidden="true" />
                        <Circle v-else class="mt-0.5 size-6 shrink-0 text-muted-foreground" aria-hidden="true" />

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="font-medium">{{ item.name }}</h3>
                                <span v-if="item.is_next" class="rounded-full bg-primary px-2 py-0.5 text-xs font-medium text-primary-foreground">Próximo passo</span>
                                <span class="sr-only">{{ item.completed ? 'Concluído' : 'Por fazer' }}</span>
                            </div>
                            <p class="mt-1 text-sm text-muted-foreground">{{ item.description }}</p>

                            <Link
                                v-if="item.cta"
                                :href="item.cta.href"
                                class="mt-3 inline-flex items-center gap-1.5 rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground hover:opacity-90"
                            >
                                {{ item.cta.label }}
                                <ArrowRight class="size-4" aria-hidden="true" />
                            </Link>
                        </div>
                    </div>
                </li>
            </ol>
        </div>

        <template v-else>
            <!-- HOJE — a régua do dia (Pro, com horário) ou, na Base, o lugar é da
                 régua do período com os acontecimentos de hoje: nada fica vazio. -->
            <DayRuler v-if="data.day.available" :day="data.day" :now="data.context.now" :lessons-href="data.week.href" />
            <PeriodRuler
                v-else-if="data.period"
                :ruler="data.period"
                :today="today"
                prominent
                :today-events="todayEvents"
                :upcoming="data.upcoming"
                :summary="periodSummary"
            />

            <!-- OS BLOCOS, pela ordem do professor. Grelha por linhas (sem `dense`): a
                 ordem do DOM é a ordem visual e a do teclado. Os largos ocupam a
                 linha; os meios emparelham e, sem par, passam a largos — nunca
                 metade de linha vazia. Abaixo de 56 rem, uma coluna. -->
            <div class="@container" data-testid="dashboard-blocks">
                <div class="grid grid-cols-1 gap-6 @min-[56rem]:grid-cols-2 @min-[56rem]:items-start">
                    <div v-for="block in layout.blocks.value" :key="block.id" class="min-w-0" :class="block.span === 'wide' ? '@min-[56rem]:col-span-2' : ''" :data-block="block.id">
                        <WeekAgenda v-if="block.id === 'week'" :week="data.week" :upcoming="data.upcoming" :today="today" :has-lessons-module="hasLessons" />

                        <div v-else-if="block.id === 'done'" class="flex flex-col gap-6">
                            <DoneToday :items="data.done" />

                            <!-- O FECHO — a frase-assinatura, só quando é verdade. -->
                            <p v-if="done" class="border-t-2 border-emerald-700/60 pt-3 font-serif text-lg text-foreground italic dark:border-emerald-400/50" data-testid="closing-line">Tudo guardado — podes fechar.</p>
                        </div>

                        <PeriodRuler v-else-if="block.id === 'period' && data.period" :ruler="data.period" :today="today" :summary="periodSummary" :wide="block.span === 'wide'" />

                        <ClassCards v-else-if="block.id === 'classes'" :classes="data.classes" :today="today" :has-lessons-module="hasLessons" />
                    </div>
                </div>
            </div>

            <!-- «Organizar painel»: diálogo; a pré-visualização é a própria página, por trás. -->
            <DashboardLayoutEditor
                :open="layout.editing.value"
                :order="layout.order.value"
                :announcement="layout.announcement.value"
                @move="layout.move"
                @save="layout.save"
                @reset="layout.reset"
                @cancel="layout.cancel"
                @closed="organiseButton?.focus()"
            />
        </template>

        <!-- «Minha atividade» é de todos os planos. A auditoria transversal
             (audit_log) é do Institucional: sem ela o link não aparece, como
             no menu, e o servidor volta a verificar. -->
        <p class="flex flex-wrap gap-x-4 text-sm text-muted-foreground">
            <Link href="/activity" class="inline-flex min-h-11 items-center hover:underline sm:min-h-9">Minha atividade</Link>
            <Link v-if="canOpenOrganizationAudit" href="/activity/organization" class="inline-flex min-h-11 items-center hover:underline sm:min-h-9">Auditoria da organização</Link>
            <Link href="/classes" class="inline-flex min-h-11 items-center text-primary hover:underline sm:min-h-9">Ver todas as turmas</Link>
            <button
                v-if="readiness.is_ready && layout.canOrganise.value"
                ref="organiseButton"
                type="button"
                class="inline-flex min-h-11 items-center gap-1.5 rounded-md px-1 hover:text-foreground hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:ml-auto sm:min-h-9"
                aria-haspopup="dialog"
                data-testid="layout-open"
                @click="layout.start"
            >
                <LayoutList class="size-4" aria-hidden="true" />Organizar painel
            </button>
        </p>
    </div>
</template>
