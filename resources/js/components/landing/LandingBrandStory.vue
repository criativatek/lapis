<script setup lang="ts">
import LandingSection from './LandingSection.vue';
import RevealOnScroll from './RevealOnScroll.vue';
</script>

<template>
    <LandingSection eyebrow="Porquê Lapispro?" warm centered>
        <RevealOnScroll>
            <div class="mx-auto max-w-2xl text-center">
                <p class="text-4xl font-semibold tracking-tight sm:text-5xl">
                    Lapispro
                </p>
                <p
                    class="mt-5 text-base leading-relaxed text-pretty text-muted-foreground sm:text-lg"
                >
                    O lápis é a ferramenta mais simples da sala de aula:
                    escreve, corrige, apaga e volta a escrever. É essa a ideia —
                    uma ferramenta que acompanha o trabalho do professor em vez
                    de lhe impor um formato.
                </p>
                <p
                    class="mx-auto mt-7 max-w-xl text-lg font-medium tracking-tight text-balance sm:text-xl"
                >
                    Software para professores, pensado para simplificar o
                    trabalho docente sem minimizar o que realmente importa.
                </p>
            </div>
        </RevealOnScroll>
    </LandingSection>
</template>
