<script setup lang="ts">
import { CalendarDays, Clock, NotebookPen, Route } from '@lucide/vue';
import type { Component } from 'vue';
import LandingSection from './LandingSection.vue';
import RevealOnScroll from './RevealOnScroll.vue';

/**
 * The half of the product the page never mentioned.
 *
 * «Funcionalidades» walks the assessment year — organizar, avaliar,
 * acompanhar, intervir, documentar. None of those five is the timetable, the
 * lesson, the summary or the agenda, which is most of what a teacher actually
 * opens on a Tuesday. A visitor searching for «horário do professor» or
 * «sumários» found nothing here, and neither did a crawler.
 *
 * ALL FOUR ARE REAL AND ALL FOUR ARE PRO. `lessons` carries the timetable,
 * the weekly view, the summaries and the reusable sequences; `calendar`
 * carries the agenda. Nothing on this band is aspirational, and the plan is
 * named on it rather than left for the visitor to discover at the price.
 *
 * No product mock: the page already carries six, and a seventh on a band that
 * exists to add four short claims would cost more scroll than it returns.
 */

type Capability = {
    icon: Component;
    title: string;
    body: string;
};

const capabilities: readonly Capability[] = [
    {
        icon: Clock,
        title: 'Horário do professor',
        body: 'A semana inteira num ecrã, montada à mão ou importada do horário que a escola já emitiu.',
    },
    {
        icon: NotebookPen,
        title: 'Aulas e sumários',
        body: 'O sumário como centro da aula, com notas privadas, recursos e trabalho de casa no mesmo registo.',
    },
    {
        icon: Route,
        title: 'Planeamento e sequências de aulas',
        body: 'Sequências reutilizáveis para aplicar a mesma progressão a outra turma sem a escrever outra vez.',
    },
    {
        icon: CalendarDays,
        title: 'Agenda do ano letivo',
        body: 'Períodos, interrupções e feriados no mesmo calendário onde marca o que é seu.',
    },
];
</script>

<template>
    <LandingSection
        id="dia-a-dia"
        eyebrow="O dia a dia"
        title="Planeie aulas, organize sumários e consulte o horário na mesma plataforma."
        lead="A organização do trabalho do professor não vive só na avaliação. O Lapispro Pro traz o horário, a gestão de aulas, o planeamento de aulas e a agenda para junto das turmas e das classificações."
    >
        <dl
            class="grid gap-8 sm:grid-cols-2 sm:gap-x-10 lg:grid-cols-4 lg:gap-6"
        >
            <RevealOnScroll
                v-for="(capability, index) in capabilities"
                :key="capability.title"
                :delay="index * 80"
            >
                <div class="group">
                    <span
                        aria-hidden="true"
                        class="flex size-10 items-center justify-center rounded-xl border border-border/70 bg-card text-primary shadow-sm transition-all duration-500 group-hover:border-primary/40 group-hover:shadow-md motion-safe:group-hover:-translate-y-0.5 dark:text-(--brand-amber) dark:group-hover:border-(--brand-amber)/40"
                    >
                        <component :is="capability.icon" class="size-[18px]" />
                    </span>
                    <dt class="mt-5 font-semibold tracking-tight text-balance">
                        {{ capability.title }}
                    </dt>
                    <dd
                        class="mt-2 text-sm leading-relaxed text-pretty text-muted-foreground"
                    >
                        {{ capability.body }}
                    </dd>
                </div>
            </RevealOnScroll>
        </dl>

        <RevealOnScroll>
            <p class="mt-10 text-sm text-muted-foreground">
                Estas quatro capacidades fazem parte do
                <a
                    href="#planos"
                    class="rounded font-medium text-foreground underline underline-offset-4 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >Lapispro Pro</a
                >.
            </p>
        </RevealOnScroll>
    </LandingSection>
</template>
