<script setup lang="ts">
import { ClipboardList, Scale, SlidersHorizontal, Users } from '@lucide/vue';
import type { Component } from 'vue';
import LandingSection from './LandingSection.vue';
import RevealOnScroll from './RevealOnScroll.vue';

type Step = {
    icon: Component;
    title: string;
    body: string;
};

const steps: readonly Step[] = [
    {
        icon: SlidersHorizontal,
        title: 'Defina o seu perfil de avaliação',
        body: 'Domínios, ponderações e escala — com os nomes que a sua escola usa.',
    },
    {
        icon: Users,
        title: 'Traga as suas turmas',
        body: 'Importe a pauta que já tem. As fotografias entram no mesmo passo.',
    },
    {
        icon: ClipboardList,
        title: 'Registe os elementos de avaliação',
        body: 'Testes, trabalhos, apresentações. A grelha preenche-se pelo teclado.',
    },
    {
        icon: Scale,
        title: 'O Lapispro propõe, o professor decide',
        body: 'Média ponderada, proposta na escala, e o rasto do que entrou.',
    },
];
</script>

<template>
    <LandingSection
        id="como-funciona"
        eyebrow="Como funciona"
        title="Quatro passos, e o ano letivo fica montado."
        lead="Critérios de avaliação, turmas e instrumentos configuram-se uma vez. Depois é só registar o que já faria."
    >
        <ol class="grid gap-8 sm:grid-cols-2 lg:grid-cols-4 lg:gap-6">
            <li
                v-for="(step, index) in steps"
                :key="step.title"
                class="group relative"
            >
                <RevealOnScroll v-slot="{ shown }" :delay="index * 90">
                    <!-- The rule that turns four cards into a sequence. Stops at
                         the last step, and never draws on a wrapped row. -->
                    <span
                        v-if="index < steps.length - 1"
                        aria-hidden="true"
                        class="absolute top-5 left-[calc(2.5rem+0.75rem)] hidden h-px w-[calc(100%-2.5rem)] origin-left border-t border-dashed border-blue-300 transition-transform delay-300 duration-700 ease-out lg:block"
                        :class="shown ? 'scale-x-100' : 'scale-x-0'"
                    />

                    <span
                        aria-hidden="true"
                        class="relative flex size-10 items-center justify-center rounded-full bg-blue-600 text-white shadow-sm transition-all duration-500 group-hover:shadow-md motion-safe:group-hover:-translate-y-0.5"
                    >
                        <component :is="step.icon" class="size-[18px]" />
                    </span>

                    <p
                        class="mt-5 text-[11px] font-semibold tracking-[0.14em] text-muted-foreground uppercase tabular-nums"
                    >
                        Passo {{ index + 1 }}
                    </p>
                    <h3
                        class="mt-1.5 font-semibold tracking-tight text-balance"
                    >
                        {{ step.title }}
                    </h3>
                    <p
                        class="mt-2 text-sm leading-relaxed text-muted-foreground"
                    >
                        {{ step.body }}
                    </p>
                </RevealOnScroll>
            </li>
        </ol>
    </LandingSection>
</template>
