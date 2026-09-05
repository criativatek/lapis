<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowRight, CalendarDays, CheckCircle2, Circle, FileText, NotebookPen, PenLine, X } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { qualitativeToneClasses } from '@/lib/qualitativeTone';
import { card } from '@/lib/surfaces';

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

/*
 * A HOME DE FECHO (DESIGN.md, variante C2 aprovada): a página não mostra a
 * ferramenta, mostra o dia — Agora → A seguir → Arrumado → «podes fechar».
 * Prosa antes de números; âmbar só no FEITO; nenhuma estimativa inventada
 * («nada inventado» — os minutos do mock ficaram no mock).
 */
const pendingOf = (schoolClass: ClassCard): number =>
    schoolClass.pending_confirmation + schoolClass.pending_publication;

/** A tarefa mais pesada é o «Agora»; o resto espera em fila. */
const byWeight = computed(() => [...props.classes].sort((a, b) => pendingOf(b) - pendingOf(a)));

const agora = computed(() => (byWeight.value.length > 0 && pendingOf(byWeight.value[0]) > 0 ? byWeight.value[0] : null));

const aSeguir = computed(() => byWeight.value.slice(1).filter((schoolClass) => pendingOf(schoolClass) > 0));

const arrumado = computed(() => props.classes.filter((schoolClass) => pendingOf(schoolClass) === 0));

// Só uma turma pendente: o cartão «Agora» fica sozinho e o resto do ecrã
// esvazia-se. Dá-lhe corpo com ligações reais da própria turma — nenhum
// número novo, só navegação que os cartões «Arrumado» já oferecem.
const agoraSozinho = computed(() => agora.value !== null && aSeguir.value.length === 0);

// «Atalhos»: só em contas pequenas (poucas turmas), onde a home cheia (Agora +
// A seguir + Arrumado) nunca ocupa o ecrã. Navegação pura — sem estatística.
const showAtalhos = computed(() => props.classes.length > 0 && props.classes.length <= 2);

/** Tudo em dia — a única condição em que a frase de fecho aparece. */
const allDone = computed(
    () => props.classes.length > 0 && props.totals.pending_confirmation === 0 && props.totals.pending_publication === 0,
);

/** Saudação pela hora de Lisboa — a app vive em Europe/Lisbon. */
const greeting = computed(() => {
    const hour = Number(
        new Intl.DateTimeFormat('pt-PT', { hour: 'numeric', hour12: false, timeZone: 'Europe/Lisbon' }).format(new Date()),
    );

    if (hour < 6) {
        return 'Boa noite';
    }

    if (hour < 13) {
        return 'Bom dia';
    }

    if (hour < 20) {
        return 'Boa tarde';
    }

    return 'Boa noite';
});

/** O estado do dia em PROSA, só com factos que a base sabe. */
const statusPhrase = computed(() => {
    const confirm = props.totals.pending_confirmation;
    const publish = props.totals.pending_publication;

    if (props.classes.length === 0) {
        return 'Ainda sem turmas neste ano letivo.';
    }

    if (confirm === 0 && publish === 0) {
        return 'Tudo em dia.';
    }

    const parts: string[] = [];

    if (confirm > 0) {
        parts.push(`${confirm} ${confirm === 1 ? 'classificação por confirmar' : 'classificações por confirmar'}`);
    }

    if (publish > 0) {
        parts.push(`${publish} por publicar`);
    }

    return `Falta ${parts.join(' e ')}.`;
});

function pendingLabel(schoolClass: ClassCard): string {
    const parts: string[] = [];

    if (schoolClass.pending_confirmation > 0) {
        parts.push(`${schoolClass.pending_confirmation} por confirmar`);
    }

    if (schoolClass.pending_publication > 0) {
        parts.push(`${schoolClass.pending_publication} por publicar`);
    }

    return parts.join(' · ');
}
</script>

<template>
    <Head title="Painel do Professor" />

    <div class="space-y-6 p-4">
        <div class="flex items-start justify-between gap-3">
            <div>
                <!-- A VOZ HUMANA (DESIGN.md): saudação em serifa; o estado do
                     dia em prosa, só com factos reais. -->
                <h1 class="font-serif text-[1.75rem] leading-snug text-foreground">{{ greeting }}, {{ firstName }}.</h1>
                <p class="mt-0.5 font-serif text-lg text-muted-foreground">{{ statusPhrase }}</p>
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
            <!-- AGORA — a tarefa mais pesada, uma só, com a acção à vista. -->
            <section v-if="agora">
                <h2 class="text-base font-semibold">Agora</h2>
                <div class="mt-1 mb-3 h-0.5 w-12 rounded bg-(--brand-amber)" aria-hidden="true"></div>
                <div :class="[card('plain'), 'card-soft p-5']">
                    <div class="flex flex-wrap items-end justify-between gap-4">
                        <div class="min-w-0">
                            <p class="text-lg font-semibold">{{ agora.label }} — {{ agora.subject }}</p>
                            <p class="mt-0.5 text-sm text-muted-foreground">{{ pendingLabel(agora) }}</p>
                            <span v-if="!agora.has_profile" :class="['mt-2 inline-block rounded-full px-2 py-0.5 text-xs', qualitativeToneClasses.amber]">sem perfil de avaliação</span>
                        </div>
                        <Button as-child>
                            <Link :href="`/classes/${agora.ulid}/classifications`">Continuar correção</Link>
                        </Button>
                    </div>

                    <!-- Turma sozinha no ecrã: ligações rápidas, mesmas que os
                         cartões «Arrumado» oferecem — navegação, não números novos. -->
                    <div v-if="agoraSozinho" class="mt-4 flex flex-wrap gap-4 border-t border-border pt-3 text-sm">
                        <Link :href="`/classes/${agora.ulid}/classifications`" class="text-primary hover:underline">Classificações</Link>
                        <Link :href="`/classes/${agora.ulid}/results/estatistica`" class="text-muted-foreground hover:underline">Acompanhamento</Link>
                    </div>
                </div>
            </section>

            <!-- A SEGUIR — fila pautada, sem cartões: espera em silêncio. -->
            <section v-if="aSeguir.length > 0">
                <h2 class="text-base font-semibold">A seguir</h2>
                <ul class="mt-2 divide-y divide-border border-y border-border">
                    <li v-for="schoolClass in aSeguir" :key="schoolClass.ulid" class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <div class="min-w-0">
                            <span class="font-semibold">{{ schoolClass.label }}</span>
                            <span class="text-muted-foreground"> — {{ schoolClass.subject }}</span>
                            <span class="ml-2 text-sm text-muted-foreground">{{ pendingLabel(schoolClass) }}</span>
                        </div>
                        <Link :href="`/classes/${schoolClass.ulid}/classifications`" class="shrink-0 text-sm font-medium text-primary hover:underline">Continuar</Link>
                    </li>
                </ul>
            </section>

            <!-- ARRUMADO — o âmbar é do FEITO. Sem horas inventadas: a base
                 não sabe «guardado às» por turma, e nada se inventa. -->
            <section v-if="arrumado.length > 0">
                <h2 class="text-base font-semibold">Arrumado</h2>
                <div class="mt-2 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div v-for="schoolClass in arrumado" :key="schoolClass.ulid" :class="[card('plain'), 'p-4']">
                        <div class="flex items-start gap-2">
                            <CheckCircle2 class="mt-0.5 size-4 shrink-0 text-(--brand-amber)" aria-hidden="true" />
                            <div class="min-w-0">
                                <p class="truncate font-semibold">{{ schoolClass.label }} — {{ schoolClass.subject }}</p>
                                <p class="text-sm text-muted-foreground">sem pendências</p>
                            </div>
                        </div>
                        <div class="mt-3 flex gap-3 border-t border-border pt-2.5 text-sm">
                            <Link :href="`/classes/${schoolClass.ulid}/classifications`" class="text-primary hover:underline">Classificações</Link>
                            <Link :href="`/classes/${schoolClass.ulid}/results/estatistica`" class="text-muted-foreground hover:underline">Acompanhamento</Link>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ATALHOS — só em contas pequenas, onde a home cheia (Agora / A
                 seguir / Arrumado) não chega a ocupar o ecrã. Navegação pura. -->
            <section v-if="showAtalhos">
                <h2 class="text-base font-semibold">Atalhos</h2>
                <div class="mt-2 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Link href="/assessments" :class="[card('plain'), 'flex items-center gap-3 p-4 hover:bg-muted/50']">
                        <PenLine class="size-5 shrink-0 text-muted-foreground" aria-hidden="true" />
                        <span class="font-medium">Grelhas de correção</span>
                    </Link>
                    <Link href="/reports" :class="[card('plain'), 'flex items-center gap-3 p-4 hover:bg-muted/50']">
                        <FileText class="size-5 shrink-0 text-muted-foreground" aria-hidden="true" />
                        <span class="font-medium">Relatórios</span>
                    </Link>
                    <Link href="/records" :class="[card('plain'), 'flex items-center gap-3 p-4 hover:bg-muted/50']">
                        <NotebookPen class="size-5 shrink-0 text-muted-foreground" aria-hidden="true" />
                        <span class="font-medium">Registos</span>
                    </Link>
                    <Link href="/calendar" :class="[card('plain'), 'flex items-center gap-3 p-4 hover:bg-muted/50']">
                        <CalendarDays class="size-5 shrink-0 text-muted-foreground" aria-hidden="true" />
                        <span class="font-medium">Calendário</span>
                    </Link>
                </div>
            </section>

            <!-- O FECHO — a frase-assinatura, só quando é verdade. -->
            <div v-if="allDone" class="border-t-2 border-emerald-700/60 pt-3 dark:border-emerald-400/50">
                <p class="font-serif text-lg italic text-foreground">Tudo guardado — podes fechar.</p>
            </div>

            <p class="text-sm text-muted-foreground">
                <Link href="/classes" class="text-primary hover:underline">Ver todas as turmas</Link>
            </p>
        </template>
    </div>
</template>
