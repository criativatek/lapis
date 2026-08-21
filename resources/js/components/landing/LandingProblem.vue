<script setup lang="ts">
import LandingSection from './LandingSection.vue';
import RevealOnScroll from './RevealOnScroll.vue';

const problems = [
    {
        title: 'Um ficheiro por turma, um separador por período',
        body: 'E uma fórmula que só o autor entende — até ao ano em que deixa de entender.',
    },
    {
        title: 'Mudar uma ponderação obriga a refazer tudo',
        body: 'Um peso alterado em novembro custa uma revisão coluna a coluna.',
    },
    {
        title: 'Faltas e dispensas acabam em zeros',
        body: 'Quem faltou a um teste não teve zero. Teve outra coisa.',
    },
    {
        title: 'No fim, falta a justificação',
        body: '«Porquê aquele 4?» — a resposta está numa célula que ninguém encontra.',
    },
] as const;
</script>

<template>
    <LandingSection
        tinted
        eyebrow="O problema"
        title="Hoje, a avaliação vive espalhada por ficheiros."
        lead="Nada disto é falta de método. É o que acontece quando o método é seu e a ferramenta é uma folha de cálculo."
    >
        <div
            class="grid gap-px overflow-hidden rounded-2xl border border-border/70 bg-border/70 sm:grid-cols-2"
        >
            <RevealOnScroll
                v-for="(problem, index) in problems"
                :key="problem.title"
                :delay="index * 70"
                class="bg-card"
            >
                <div
                    class="group flex h-full gap-4 p-6 transition-colors duration-500 hover:bg-muted/50 sm:p-7 dark:hover:bg-muted/20"
                >
                    <span
                        aria-hidden="true"
                        class="mt-0.5 text-sm font-semibold text-muted-foreground/60 tabular-nums transition-colors duration-500 group-hover:text-primary dark:group-hover:text-(--brand-amber)"
                    >
                        {{ String(index + 1).padStart(2, '0') }}
                    </span>
                    <span class="min-w-0">
                        <span class="block font-medium tracking-tight">{{
                            problem.title
                        }}</span>
                        <span
                            class="mt-1.5 block text-sm leading-relaxed text-muted-foreground"
                            >{{ problem.body }}</span
                        >
                    </span>
                </div>
            </RevealOnScroll>
        </div>

        <RevealOnScroll>
            <p
                class="mt-10 max-w-2xl text-lg leading-relaxed font-medium tracking-tight text-balance sm:text-xl"
            >
                O LÁPIS junta este trabalho num só sítio — e guarda a razão de
                cada número.
            </p>
        </RevealOnScroll>
    </LandingSection>
</template>
