<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    CalculatorIcon,
    ClipboardCheck,
    LockKeyhole,
    ScrollText,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { dashboard, login, register } from '@/routes';

const page = usePage();
const authed = computed(() => page.props.auth.user !== null);

const pillars = [
    {
        icon: ClipboardCheck,
        title: 'Uma grelha feita para o professor',
        body: 'Lançamento por teclado, colar de folha de cálculo, estados especiais por célula. Rápido de preencher, sem perder um valor.',
    },
    {
        icon: CalculatorIcon,
        title: 'Cálculo transparente, sempre explicável',
        body: 'A classificação é proposta pelo sistema e confirmada por si. Vê que perfil, que pesos e que elementos entraram — e o que foi excluído e porquê.',
    },
    {
        icon: LockKeyhole,
        title: 'Dados dos alunos protegidos à partida',
        body: 'A identidade do aluno é separada e cifrada. O que atravessa a inteligência artificial é um pseudónimo, nunca o nome.',
    },
    {
        icon: ScrollText,
        title: 'Regras suas, versionadas',
        body: 'Domínios, ponderações e escalas configuráveis. Alterar um perfil ativo cria uma nova versão; o histórico mantém-se intacto.',
    },
];
</script>

<template>
    <Head title="LÁPIS — Mais tempo para ensinar" />

    <div class="min-h-screen bg-background text-foreground">
        <header
            class="mx-auto flex w-full max-w-5xl items-center justify-between gap-4 px-6 py-5"
        >
            <div class="flex items-center gap-2.5">
                <span
                    class="flex size-9 items-center justify-center rounded-md bg-primary text-primary-foreground"
                >
                    <AppLogoIcon class="size-5" />
                </span>
                <span>
                    <span
                        class="block text-base leading-none font-semibold tracking-tight"
                        >LÁPIS</span
                    >
                    <span class="block text-xs text-muted-foreground"
                        >Mais tempo para ensinar</span
                    >
                </span>
            </div>
            <nav class="flex items-center gap-2 text-sm">
                <Link
                    v-if="authed"
                    :href="dashboard()"
                    class="rounded-md bg-primary px-4 py-2 font-medium text-primary-foreground transition-opacity hover:opacity-90"
                >
                    Entrar no painel
                </Link>
                <template v-else>
                    <Link
                        :href="login()"
                        class="rounded-md px-4 py-2 font-medium text-foreground transition-colors hover:bg-muted"
                    >
                        Entrar
                    </Link>
                    <Link
                        :href="register()"
                        class="rounded-md bg-primary px-4 py-2 font-medium text-primary-foreground transition-opacity hover:opacity-90"
                    >
                        Criar conta
                    </Link>
                </template>
            </nav>
        </header>

        <main class="mx-auto w-full max-w-5xl px-6">
            <section class="py-16 sm:py-24">
                <p
                    class="mb-4 inline-flex rounded-full border border-border px-3 py-1 text-xs font-medium text-muted-foreground"
                >
                    Para professores do básico e secundário
                </p>
                <h1
                    class="max-w-3xl text-4xl font-semibold tracking-tight text-balance sm:text-5xl"
                >
                    <span class="text-primary dark:text-(--brand-amber)"
                        >LAP-IS</span
                    >
                </h1>
                <p class="mt-4 max-w-2xl text-lg font-medium text-foreground">
                    Laboratório de Apoio ao Professor — Informação e
                    Simplificação
                </p>
                <p class="mt-2 max-w-2xl text-lg text-muted-foreground">
                    O seu LÁPIS digital para avaliar, organizar e ensinar.
                </p>
                <p class="mt-6 max-w-2xl text-lg text-muted-foreground">
                    O LÁPIS reúne perfis de avaliação, turmas, instrumentos e
                    grelhas de correção, e propõe classificações de forma
                    determinística e explicável — mantendo a decisão pedagógica
                    sempre consigo.
                </p>
                <div class="mt-8 flex flex-wrap items-center gap-3">
                    <Link
                        :href="authed ? dashboard() : register()"
                        class="rounded-md bg-primary px-5 py-2.5 font-medium text-primary-foreground transition-opacity hover:opacity-90"
                    >
                        {{ authed ? 'Ir para o painel' : 'Começar' }}
                    </Link>
                    <Link
                        v-if="!authed"
                        :href="login()"
                        class="rounded-md border border-border px-5 py-2.5 font-medium transition-colors hover:bg-muted"
                    >
                        Já tenho conta
                    </Link>
                </div>
            </section>

            <section class="grid gap-4 pb-20 sm:grid-cols-2">
                <div
                    v-for="pillar in pillars"
                    :key="pillar.title"
                    class="rounded-xl border border-border p-6"
                >
                    <span
                        class="flex size-10 items-center justify-center rounded-lg bg-accent text-accent-foreground"
                    >
                        <component :is="pillar.icon" class="size-5" />
                    </span>
                    <h2 class="mt-4 font-semibold">{{ pillar.title }}</h2>
                    <p class="mt-1.5 text-sm text-muted-foreground">
                        {{ pillar.body }}
                    </p>
                </div>
            </section>
        </main>

        <footer class="border-t border-border">
            <div
                class="mx-auto flex w-full max-w-5xl flex-wrap items-center justify-between gap-2 px-6 py-6 text-sm text-muted-foreground"
            >
                <span
                    >LÁPIS — Laboratório de Apoio ao Professor, Informação e
                    Simplificação</span
                >
                <span
                    >Os dados dos alunos são tratados com proteção
                    reforçada.</span
                >
            </div>
        </footer>
    </div>
</template>
