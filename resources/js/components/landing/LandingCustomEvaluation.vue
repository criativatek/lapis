<script setup lang="ts">
import { Check } from '@lucide/vue';
import LandingSection from './LandingSection.vue';
import ProductWindow from './ProductWindow.vue';
import ProfilePreview from './ProfilePreview.vue';
import RevealOnScroll from './RevealOnScroll.vue';

const claims = [
    {
        title: 'Os domínios são seus',
        body: 'Nomes, número e ordem. Sem grelha oficial a que se tenha de traduzir.',
    },
    {
        title: 'Os pesos são seus',
        body: 'Por domínio, e por elemento dentro de cada domínio.',
    },
    {
        title: 'A escala é sua',
        body: '1 a 5, 0 a 20, percentagem — ou uma que crie para a sua disciplina.',
    },
    {
        title: 'O calendário é seu',
        body: 'Períodos, semestres, trimestres ou módulos. Quantos precisar.',
    },
    {
        title: 'E fica versionado',
        body: 'Alterar um perfil ativo cria uma nova versão. O histórico fica intacto.',
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
                    O seu modelo
                </p>
                <h2
                    class="mt-3 text-3xl font-semibold tracking-tight text-balance sm:text-4xl"
                >
                    O LÁPIS adapta-se à sua forma de avaliar.
                </h2>
                <p
                    class="mt-4 text-base leading-relaxed text-pretty text-muted-foreground sm:text-lg"
                >
                    Cada escola, cada disciplina e cada professor avaliam de
                    maneira diferente. Um software não devia obrigar toda a
                    gente a avaliar da mesma forma.
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
