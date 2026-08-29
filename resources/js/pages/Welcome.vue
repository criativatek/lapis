<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    BarChart3,
    CalendarDays,
    Database,
    EyeOff,
    FileText,
    KeyRound,
    Scale,
    Users,
} from '@lucide/vue';
import LandingAi from '@/components/landing/LandingAi.vue';
import LandingFinalCta from '@/components/landing/LandingFinalCta.vue';
import LandingHowItWorks from '@/components/landing/LandingHowItWorks.vue';
import type { LandingPlan } from '@/components/landing/types';
import ColorBand from '@/components/marketing/ColorBand.vue';
import HeroCard from '@/components/marketing/HeroCard.vue';
import MarketingShell from '@/components/marketing/MarketingShell.vue';
import PageHero from '@/components/marketing/PageHero.vue';
import PhotoBand from '@/components/marketing/PhotoBand.vue';
import ScreenFrame from '@/components/marketing/ScreenFrame.vue';

/**
 * The home page of the marketing site.
 *
 * Since 0.94.0 it is a front door, not the whole house: each area of the
 * product has its own page (see App\Support\Seo\PublicPages), and this one
 * says what the product is, shows it once, and points at the rest. That is
 * also why it no longer carries the plans: /planos does, with the same
 * component, and a visitor who wants prices is one click away.
 *
 * The SEO tags are NOT here: they live in resources/views/app.blade.php, which
 * is rendered even when the SSR process (resources/js/ssr.ts) is down. This
 * <Head> only sets the tab title.
 */

defineProps<{
    plans: LandingPlan[];
    contactEmail: string | null;
}>();

const areas = [
    {
        icon: Scale,
        title: 'Avaliação de alunos',
        body: 'Os seus critérios, pesos e escala. Média ponderada, proposta na escala, e o professor a decidir.',
        href: '/funcionalidades/avaliacao',
        tone: 'bg-blue-100 text-blue-700',
    },
    {
        icon: Users,
        title: 'Turmas e alunos',
        body: 'Importe a pauta da escola com nomes, números e fotografias. Um ano letivo de cada vez.',
        href: '/funcionalidades/turmas',
        tone: 'bg-amber-100 text-amber-700',
    },
    {
        icon: BarChart3,
        title: 'Acompanhamento',
        body: 'Quadro síntese, evolução por aluno, estratégias e registos — fora do cálculo.',
        href: '/funcionalidades/acompanhamento',
        tone: 'bg-emerald-100 text-emerald-700',
    },
    {
        icon: CalendarDays,
        title: 'Aulas e sumários',
        body: 'Horário, aulas com sumário, sequências reutilizáveis e a agenda do ano letivo.',
        href: '/funcionalidades/aulas-e-sumarios',
        tone: 'bg-blue-100 text-blue-700',
    },
    {
        icon: FileText,
        title: 'Relatórios e pautas',
        body: 'Relatórios que partem do que já registou. Pautas e quadro síntese exportáveis.',
        href: '/funcionalidades/relatorios',
        tone: 'bg-amber-100 text-amber-700',
    },
] as const;

const facts = [
    {
        icon: Scale,
        value: '3 regras',
        label: 'de cálculo que uma folha não trata: vazio, não aplicável, entrada tardia',
        tone: 'bg-blue-100 text-blue-700',
    },
    {
        icon: EyeOff,
        value: 'Pseudónimos',
        label: 'no dia a dia e na IA — o nome fica cifrado e separado',
        tone: 'bg-emerald-100 text-emerald-700',
    },
    {
        icon: KeyRound,
        value: '2FA + passkeys',
        label: 'na conta de cada professor, com códigos de recuperação',
        tone: 'bg-amber-100 text-amber-700',
    },
    {
        icon: Database,
        value: '0 €',
        label: 'no plano Base, sem cartão e sem prazo',
        tone: 'bg-blue-100 text-blue-700',
    },
] as const;

const measures = [
    {
        icon: EyeOff,
        title: 'Identidade separada',
        body: 'Nome cifrado à parte; o pseudónimo é o que circula.',
    },
    {
        icon: Database,
        title: 'Isolamento no servidor',
        body: 'Cada organização só vê o seu contexto, em todas as consultas.',
    },
    {
        icon: KeyRound,
        title: 'Registo de operações',
        body: 'As ações relevantes ficam ligadas a quem as realizou.',
    },
] as const;
</script>

<template>
    <!--
        MUST MATCH App\Support\Seo\LandingSeo::TITLE, which is what the server
        renders and what a crawler reads. The formatter in inertia.ts leaves a
        title that already names the brand alone, so this one survives intact
        instead of coming out as «… - Lapispro».
    -->
    <Head title="Lapispro | Plataforma para Professores — Avaliação e Turmas" />

    <MarketingShell v-slot="{ authenticated }" :contact-email="contactEmail">
        <PageHero
            eyebrow="Básico · Secundário · Profissional · Universitário"
            title="Menos peso administrativo."
            title-accent="Mais espaço para ser professor."
            lead="O Lapispro é uma plataforma para professores que reúne avaliação de alunos, gestão de turmas, acompanhamento pedagógico, aulas, sumários e relatórios num único lugar — com IA que interpreta e um professor que decide sempre."
            :image="{
                src: '/images/marketing/hero.webp',
                alt: 'Mãos de uma professora a escrever num caderno ao lado de um portátil aberto.',
            }"
            :authenticated="authenticated"
        >
            <template #secondary>
                <Link
                    href="/funcionalidades/avaliacao"
                    class="inline-flex h-10 items-center rounded-md px-4 text-sm font-medium underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    Ver como funciona
                </Link>
            </template>
            <template #figure>
                <HeroCard />
            </template>
        </PageHero>

        <!-- Facts, not figures. The reference mockup had «+120 escolas» and
             «99,9% disponibilidade» here; the product has no such numbers and
             the site invents none. These four are true and checkable. -->
        <section class="px-4 pt-4 sm:px-6 sm:pt-6">
            <dl
                class="mx-auto grid w-full max-w-6xl gap-3 sm:grid-cols-2 lg:grid-cols-4"
            >
                <div
                    v-for="fact in facts"
                    :key="fact.label"
                    class="flex items-center gap-4 rounded-2xl bg-white px-5 py-4 ring-1 ring-black/5"
                >
                    <span
                        aria-hidden="true"
                        class="flex size-11 shrink-0 items-center justify-center rounded-xl"
                        :class="fact.tone"
                    >
                        <component :is="fact.icon" class="size-5" />
                    </span>
                    <div class="min-w-0">
                        <dt
                            class="text-xl font-semibold tracking-tight tabular-nums"
                        >
                            {{ fact.value }}
                        </dt>
                        <dd
                            class="text-[13px] leading-snug text-muted-foreground"
                        >
                            {{ fact.label }}
                        </dd>
                    </div>
                </div>
            </dl>
        </section>

        <ColorBand
            tone="blue"
            eyebrow="O produto"
            title="Uma grelha que sabe o que aconteceu — e nunca inventa um zero."
            lead="Média ponderada por domínio, proposta na escala da escola e o nível que o professor atribui. Vazio não é zero, «não aplicável» sai do denominador, quem chega tarde não é penalizado."
        >
            <ScreenFrame
                src="/images/landing/grid.webp"
                alt="Grelha de resultados de uma turma no Lapispro: média ponderada por domínio, proposta na escala e nível atribuído pelo professor."
                :width="1600"
                :height="854"
                eager
            />
        </ColorBand>

        <ColorBand
            id="funcionalidades"
            eyebrow="Funcionalidades"
            title="Um ano letivo de ponta a ponta."
            lead="Organizar, avaliar, acompanhar, planear e documentar — pela ordem por que o trabalho do professor acontece. Cada área tem a sua página."
        >
            <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                <Link
                    v-for="area in areas"
                    :key="area.href"
                    :href="area.href"
                    class="group rounded-3xl bg-white p-6 shadow-sm ring-1 ring-black/5 transition-[box-shadow,transform] duration-300 hover:shadow-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none motion-safe:hover:-translate-y-0.5 sm:p-7"
                >
                    <span
                        aria-hidden="true"
                        class="flex size-11 items-center justify-center rounded-2xl"
                        :class="area.tone"
                    >
                        <component :is="area.icon" class="size-5" />
                    </span>
                    <h3 class="mt-5 text-lg font-semibold tracking-tight">
                        {{ area.title }}
                    </h3>
                    <p
                        class="mt-2 text-sm leading-relaxed text-muted-foreground"
                    >
                        {{ area.body }}
                    </p>
                    <span
                        class="mt-5 inline-flex items-center gap-1.5 text-sm font-medium text-blue-700"
                    >
                        Saber mais
                        <ArrowRight
                            aria-hidden="true"
                            class="size-4 transition-transform duration-300 group-hover:translate-x-0.5"
                        />
                    </span>
                </Link>
                <Link
                    href="/planos"
                    class="group flex flex-col justify-between rounded-3xl bg-amber-100 p-6 transition-[box-shadow,transform] duration-300 hover:shadow-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none motion-safe:hover:-translate-y-0.5 sm:p-7"
                >
                    <div>
                        <p
                            class="text-[12px] font-semibold tracking-[0.12em] text-amber-800 uppercase"
                        >
                            Planos
                        </p>
                        <h3
                            class="mt-3 text-2xl font-semibold tracking-tight text-balance"
                        >
                            Base gratuito. Pro por 44,90 € por ano.
                        </h3>
                    </div>
                    <span
                        class="mt-6 inline-flex items-center gap-1.5 text-sm font-medium text-amber-900"
                    >
                        Ver os planos
                        <ArrowRight
                            aria-hidden="true"
                            class="size-4 transition-transform duration-300 group-hover:translate-x-0.5"
                        />
                    </span>
                </Link>
            </div>
        </ColorBand>

        <LandingHowItWorks />

        <PhotoBand
            eyebrow="A regra da casa"
            title="O sistema propõe. O professor decide."
            body="Nenhuma classificação é atribuída, alterada ou decidida por um modelo. O cálculo é determinístico e explicável, a proposta é do Lapispro, e a confirmação é sempre do professor — com o que entrou no resultado disponível para consulta."
            :image="{
                src: '/images/marketing/teacher-laptop.webp',
                alt: 'Professora numa sala de professores a consultar o portátil, com um caderno ao lado.',
            }"
            flip
            prominent
        />

        <LandingAi />

        <ColorBand
            tone="emerald"
            eyebrow="Segurança e dados"
            title="Dados de alunos exigem proteção desde a origem."
            lead="Nomes cifrados e separados, isolamento por organização no servidor, dois fatores e passkeys, registo de operações, IA sem dados identificáveis."
        >
            <div class="grid gap-4 sm:grid-cols-3">
                <div
                    v-for="measure in measures"
                    :key="measure.title"
                    class="rounded-2xl bg-white p-5 ring-1 ring-emerald-900/5"
                >
                    <component
                        :is="measure.icon"
                        aria-hidden="true"
                        class="size-5 text-emerald-700"
                    />
                    <h3 class="mt-3 font-semibold tracking-tight">
                        {{ measure.title }}
                    </h3>
                    <p
                        class="mt-1.5 text-sm leading-relaxed text-muted-foreground"
                    >
                        {{ measure.body }}
                    </p>
                </div>
            </div>
            <Link
                href="/seguranca"
                class="group mt-6 inline-flex items-center gap-2 rounded-full bg-emerald-700 px-5 py-2.5 text-sm font-medium text-white transition-colors hover:bg-emerald-800 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                Como protegemos os dados
                <ArrowRight
                    aria-hidden="true"
                    class="size-4 transition-transform duration-300 group-hover:translate-x-0.5"
                />
            </Link>
        </ColorBand>

        <LandingFinalCta
            :authenticated="authenticated"
            :photo="{
                src: '/images/marketing/teacher-students.webp',
                alt: 'Professora de pé junto a uma mesa, inclinada sobre o trabalho de três alunos.',
            }"
        />
    </MarketingShell>
</template>
