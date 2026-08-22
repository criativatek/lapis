<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { dashboard, register } from '@/routes';
import ClassificationPreview from './ClassificationPreview.vue';
import ProductWindow from './ProductWindow.vue';
import RevealOnScroll from './RevealOnScroll.vue';

defineProps<{ authenticated: boolean }>();
</script>

<template>
    <section class="relative overflow-hidden">
        <!-- One warm wash behind the headline, and nothing else. The page has a
             lot to say further down; the first screen should not compete. -->
        <div
            aria-hidden="true"
            class="pointer-events-none absolute inset-0 -z-10 bg-[radial-gradient(60rem_32rem_at_78%_-12%,var(--color-accent),transparent_65%)] opacity-90 dark:opacity-25"
        />

        <div
            class="mx-auto grid w-full max-w-6xl gap-14 px-6 pt-14 pb-20 sm:px-8 sm:pt-20 sm:pb-28 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.05fr)] lg:items-center lg:gap-16"
        >
            <RevealOnScroll class="min-w-0">
                <p
                    class="inline-flex rounded-full border border-border bg-background/70 px-3 py-1 text-[11px] font-medium tracking-[0.06em] text-muted-foreground uppercase"
                >
                    Básico · Secundário · Profissional · Universitário
                </p>

                <h1
                    class="mt-6 text-4xl font-semibold tracking-tight text-balance sm:text-5xl lg:text-[3.4rem] lg:leading-[1.05]"
                >
                    Mais simples. Mais tempo para o que realmente importa.
                </h1>

                <p
                    class="mt-6 max-w-xl text-lg leading-relaxed text-pretty text-foreground"
                >
                    Avaliação, acompanhamento e organização do trabalho
                    docente de forma simples e integrada.
                </p>

                <p
                    class="mt-3 max-w-xl leading-relaxed text-pretty text-muted-foreground"
                >
                    Turmas, critérios e elementos de avaliação reunidos num
                    só lugar. Informação clara para classificar, acompanhar
                    e criar relatórios.
                </p>

                <div class="mt-9 flex flex-wrap items-center gap-3">
                    <Button as-child size="lg" class="group/cta">
                        <Link :href="authenticated ? dashboard() : register()">
                            {{
                                authenticated
                                    ? 'Ir para o painel'
                                    : 'Experimentar LÁPIS'
                            }}
                            <ArrowRight
                                aria-hidden="true"
                                class="transition-transform duration-300 group-hover/cta:translate-x-0.5"
                            />
                        </Link>
                    </Button>
                    <Button
                        as-child
                        variant="outline"
                        size="lg"
                        class="transition-transform duration-300 motion-safe:hover:-translate-y-0.5"
                    >
                        <a href="#como-funciona">Ver como funciona</a>
                    </Button>
                </div>

                <p class="mt-5 text-sm leading-relaxed text-muted-foreground">
                    Sem cartão. O plano
                    <span class="font-medium text-foreground">LÁPIS Base</span>
                    fica ativo de imediato.
                </p>
            </RevealOnScroll>

            <RevealOnScroll :delay="150" variant="scale" class="min-w-0">
                <ProductWindow path="lapis.pt/classes/9b/classifications">
                    <ClassificationPreview />
                </ProductWindow>
            </RevealOnScroll>
        </div>
    </section>
</template>
