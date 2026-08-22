<script setup lang="ts">
import LandingSection from './LandingSection.vue';
import RevealOnScroll from './RevealOnScroll.vue';

const problems = [
    {
        title: 'Um ficheiro por turma, um separador por período',
        body: 'Fórmulas, versões e ficheiros acumulam-se. Meses depois, até pode ser difícil perceber como um resultado foi construído.',
    },
    {
        title: 'Uma alteração pode obrigar a rever muita coisa',
        body: 'Mudar uma ponderação ou um critério pode significar regressar às fórmulas e conferir resultados, coluna a coluna.',
    },
    {
        title: 'Nem tudo o que está vazio é zero',
        body: 'Faltas, dispensas e situações não aplicáveis precisam de contexto. Uma folha de cálculo só sabe tratá-las corretamente se alguém lhe ensinar essa lógica.',
    },
    {
        title: 'No fim, é preciso explicar o resultado',
        body: 'Quando surge a pergunta «Porque é que este aluno teve 4?», a resposta deve estar acessível, não escondida entre células e fórmulas.',
    },
] as const;
</script>

<template>
    <LandingSection
        tinted
        eyebrow="O problema"
        title="Durante anos, a avaliação viveu espalhada por ficheiros."
        lead="Foi assim que muitos professores organizaram o seu trabalho durante muito tempo. Funcionou, mas hoje já existem formas mais simples de fazer o mesmo com mais clareza."
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
                O que antes exigia vários ficheiros pode agora ser feito no
                LÁPIS, de forma mais simples, organizada e transparente.
            </p>
        </RevealOnScroll>
    </LandingSection>
</template>
