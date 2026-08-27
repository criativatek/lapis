<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowRight, BarChart3, CheckCircle2, Circle, ClipboardList, Send, Users, X } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';

type ClassCard = {
    ulid: string;
    label: string;
    subject: string;
    academic_year: string;
    has_profile: boolean;
    pending_confirmation: number;
    pending_publication: number;
};

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
    classes: ClassCard[];
    readiness: { is_ready: boolean; items: ReadinessItem[] };
    firstSteps: { dismissed: boolean; all_done: boolean; items: FirstStepsItem[] };
    totals: { classes: number; pending_confirmation: number; pending_publication: number };
}>();

// First name only — a warmer greeting than the full registered name.
const firstName = computed(() => props.teacherName.split(' ')[0]);

// Never a modal, never blocking: an old, fully-adopted account already shows
// nothing (all_done), the same way readiness() shows nothing once configured.
const showFirstSteps = computed(() => !props.firstSteps.dismissed && !props.firstSteps.all_done);

function dismissFirstSteps(): void {
    // Never touches progress — there is none stored to touch. Only hides the
    // card; findable again later from A2's Help Center (not built yet).
    router.post('/dashboard/onboarding-dismissal', {}, { preserveScroll: true });
}

const summary = computed(() => [
    { label: 'Turmas', value: props.totals.classes, icon: Users },
    { label: 'Por confirmar', value: props.totals.pending_confirmation, icon: ClipboardList },
    { label: 'Por publicar', value: props.totals.pending_publication, icon: Send },
]);
</script>

<template>
    <Head title="Painel do Professor" />

    <div class="space-y-6 p-4">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Olá, {{ firstName }}</h1>
                <p class="text-sm text-muted-foreground">Aqui está o que precisa da sua atenção.</p>
            </div>
            <Link href="/activity" class="text-sm text-muted-foreground hover:underline">Registo de atividade</Link>
        </div>

        <!-- "Primeiros passos" (A1a) — a separate, dismissible adoption
             checklist. Never a modal, never blocks navigation, and shown
             independently of the readiness checklist below: an account can be
             fully configured and still not have used the app yet, or vice
             versa. -->
        <div v-if="showFirstSteps" class="mx-auto max-w-3xl rounded-xl border border-border p-4 sm:p-5">
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
                <li
                    v-for="item in firstSteps.items"
                    :key="item.id"
                    class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border p-3"
                >
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

        <div v-if="!readiness.is_ready" class="mx-auto max-w-3xl">
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
                                <span v-if="item.is_next" class="rounded-full bg-primary px-2 py-0.5 text-xs font-medium text-primary-foreground">
                                    Próximo passo
                                </span>
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
            <div class="grid gap-4 sm:grid-cols-3">
                <div v-for="item in summary" :key="item.label" class="flex items-center gap-4 rounded-xl border border-border p-4">
                    <span class="flex size-11 items-center justify-center rounded-lg bg-accent text-accent-foreground">
                        <component :is="item.icon" class="size-5" />
                    </span>
                    <div>
                        <div class="text-2xl font-semibold tabular-nums">{{ item.value }}</div>
                        <div class="text-sm text-muted-foreground">{{ item.label }}</div>
                    </div>
                </div>
            </div>

            <div>
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="font-semibold">As minhas turmas</h2>
                    <Link href="/classes" class="text-sm text-primary hover:underline">Ver todas</Link>
                </div>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div v-for="schoolClass in classes" :key="schoolClass.ulid" class="flex flex-col rounded-xl border border-border p-4">
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <div class="font-semibold">{{ schoolClass.label }}</div>
                                <div class="text-sm text-muted-foreground">{{ schoolClass.subject }} · {{ schoolClass.academic_year }}</div>
                            </div>
                            <span v-if="!schoolClass.has_profile" class="rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-800" title="Sem perfil de avaliação">sem perfil</span>
                        </div>

                        <div class="mt-4 flex flex-wrap gap-2 text-xs">
                            <span
                                v-if="schoolClass.pending_confirmation > 0"
                                class="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-1 text-muted-foreground"
                            >
                                <ClipboardList class="size-3" /> {{ schoolClass.pending_confirmation }} por confirmar
                            </span>
                            <span
                                v-if="schoolClass.pending_publication > 0"
                                class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-1 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300"
                            >
                                <Send class="size-3" /> {{ schoolClass.pending_publication }} por publicar
                            </span>
                            <span
                                v-if="schoolClass.pending_confirmation === 0 && schoolClass.pending_publication === 0"
                                class="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-1 text-muted-foreground"
                            >
                                <CheckCircle2 class="size-3" /> sem pendências
                            </span>
                        </div>

                        <div class="mt-4 flex gap-3 border-t border-border pt-3 text-sm">
                            <Link :href="`/classes/${schoolClass.ulid}/classifications`" class="inline-flex items-center gap-1 text-primary hover:underline">
                                <ClipboardList class="size-3.5" /> Classificações
                            </Link>
                            <!-- The class read as a whole. «Resultados» is no longer
                                 a place a teacher goes to; «como está esta turma?»
                                 is the question, and this is where it is answered. -->
                            <Link :href="`/classes/${schoolClass.ulid}/results/estatistica`" class="inline-flex items-center gap-1 text-muted-foreground hover:underline">
                                <BarChart3 class="size-3.5" /> Acompanhamento
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
