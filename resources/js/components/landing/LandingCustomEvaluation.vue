<script setup lang="ts">
import { Check } from '@lucide/vue';
import LandingSection from './LandingSection.vue';
import ProductWindow from './ProductWindow.vue';
import ProfilePreview from './ProfilePreview.vue';
import RevealOnScroll from './RevealOnScroll.vue';

const claims = [
    {
        title: 'Os domínios refletem o modelo da escola',
        body: 'Designações, organização e critérios de avaliação configurados de acordo com o modelo adotado.',
    },
    {
        title: 'As ponderações seguem os critérios definidos',
        body: 'Pesos por domínio e por elemento de avaliação, de acordo com as regras estabelecidas.',
    },
    {
        title: 'A escala é a que a escola utiliza',
        body: 'Níveis, valores, percentagens ou outras escalas podem ser configurados de acordo com o modelo de avaliação.',
    },
    {
        title: 'O ano letivo acompanha a organização da escola',
        body: 'Períodos, semestres, trimestres ou módulos, conforme a estrutura adotada.',
    },
    {
        title: 'As alterações não apagam o passado',
        body: 'Quando a configuração muda, os resultados anteriores mantêm as regras que estavam em vigor quando foram avaliados.',
    },
] as const;
</script>

<template>
    <LandingSection tinted>
        <div
            class="grid gap-12 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] lg:items-center lg:gap-16"
        >
            <RevealOnScroll v-slot="{ shown }" class="min-w-0">
                <p
                    class="flex items-center gap-2.5 text-[11px] font-semibold tracking-[0.14em] text-muted-foreground uppercase"
                >
                    <span
                        aria-hidden="true"
                        class="h-px w-7 origin-left bg-primary transition-transform delay-150 duration-700 ease-out dark:bg-(--brand-amber)"
                        :class="shown ? 'scale-x-100' : 'scale-x-0'"
                    />
                    O modelo da sua escola
                </p>
                <h2
                    class="mt-3 text-3xl font-semibold tracking-tight text-balance sm:text-4xl"
                >
                    O LÁPIS adapta-se ao modelo de avaliação da sua escola.
                </h2>
                <p
                    class="mt-4 text-base leading-relaxed text-pretty text-muted-foreground sm:text-lg"
                >
                    Cada escola pode organizar a avaliação de forma
                    diferente. O LÁPIS permite configurar essa estrutura e
                    aplicá-la de forma consistente ao trabalho do professor.
                </p>

                <dl class="mt-9 space-y-5">
                    <div
                        v-for="claim in claims"
                        :key="claim.title"
                        class="flex gap-3.5"
                    >
                        <span
                            aria-hidden="true"
                            class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary dark:bg-(--brand-amber)/15 dark:text-(--brand-amber)"
                        >
                            <Check class="size-3" />
                        </span>
                        <div class="min-w-0">
                            <dt class="font-medium tracking-tight">
                                {{ claim.title }}
                            </dt>
                            <dd
                                class="mt-1 text-sm leading-relaxed text-muted-foreground"
                            >
                                {{ claim.body }}
                            </dd>
                        </div>
                    </div>
                </dl>
            </RevealOnScroll>

            <RevealOnScroll :delay="150" variant="scale" class="min-w-0">
                <ProductWindow path="lapis.pt/assessment-profiles/matematica-9">
                    <ProfilePreview />
                </ProductWindow>
            </RevealOnScroll>
        </div>
    </LandingSection>
</template>
