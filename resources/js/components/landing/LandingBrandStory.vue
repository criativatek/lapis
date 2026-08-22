<script setup lang="ts">
import LandingSection from './LandingSection.vue';
import RevealOnScroll from './RevealOnScroll.vue';
</script>

<template>
    <LandingSection eyebrow="Porquê LÁPIS?" warm centered>
        <RevealOnScroll>
            <div class="mx-auto max-w-2xl text-center">
                <p
                    class="text-4xl font-semibold tracking-tight sm:text-5xl"
                >
                    LÁPIS
                </p>
                <p
                    class="mt-5 text-base leading-relaxed text-pretty text-muted-foreground sm:text-lg"
                >
                    <span
                        class="font-semibold text-primary dark:text-(--brand-amber)"
                        >L</span
                    >aboratório de
                    <span
                        class="font-semibold text-primary dark:text-(--brand-amber)"
                        >A</span
                    >poio ao
                    <span
                        class="font-semibold text-primary dark:text-(--brand-amber)"
                        >P</span
                    >rofessor,
                    <span
                        class="font-semibold text-primary dark:text-(--brand-amber)"
                        >I</span
                    >nformação e
                    <span
                        class="font-semibold text-primary dark:text-(--brand-amber)"
                        >S</span
                    >implificação.
                </p>
                <p
                    class="mx-auto mt-7 max-w-xl text-lg font-medium tracking-tight text-balance sm:text-xl"
                >
                    Uma ferramenta pensada para simplificar o trabalho
                    docente sem minimizar o que realmente importa.
                </p>
            </div>
        </RevealOnScroll>
    </LandingSection>
</template>
