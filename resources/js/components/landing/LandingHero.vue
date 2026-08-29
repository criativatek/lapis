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
            class="pointer-events-none absolute inset-0 -z-10 bg-[radial-gradient(60rem_32rem_at_78%_-12%,#dbeafe,transparent_65%)] opacity-90 dark:opacity-25"
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
                    Menos peso administrativo. Mais espaço para ser professor.
                </h1>

                <!--
                    THE SENTENCE UNDER THE H1 IS THE ONE THAT HAS TO SAY WHAT
                    THIS IS. The headline is a position, and a position alone
                    leaves both a visitor and a crawler guessing for another
                    two screens. This names the product category and the five
                    things it does, in the words somebody would have typed to
                    arrive here.
                -->
                <p
                    class="mt-6 max-w-xl text-lg leading-relaxed text-pretty text-foreground"
                >
                    O Lapispro é uma plataforma para professores que reúne
                    avaliação de alunos, gestão de turmas, acompanhamento
                    pedagógico, aulas, sumários e relatórios num único lugar.
                </p>

                <p
                    class="mt-3 max-w-xl leading-relaxed text-pretty text-muted-foreground"
                >
                    Software de avaliação pensado para a forma como os
                    professores realmente trabalham — com IA que interpreta a
                    informação e um professor que decide sempre.
                </p>

                <div class="mt-9 flex flex-wrap items-center gap-3">
                    <Button
                        as-child
                        size="lg"
                        class="group/cta bg-blue-600 text-white hover:bg-blue-700"
                    >
                        <Link :href="authenticated ? dashboard() : register()">
                            {{
                                authenticated
                                    ? 'Ir para o painel'
                                    : 'Experimentar Lapispro'
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
                    <span class="font-medium text-foreground">Base</span>
                    fica ativo de imediato.
                </p>
            </RevealOnScroll>

            <RevealOnScroll :delay="150" variant="scale" class="min-w-0">
                <ProductWindow
                    path="lapispro.com/classes/9b/classifications"
                    label="Ecrã de classificações de uma turma no Lapispro: a proposta calculada para cada aluno, a menção na escala da escola e a confirmação do professor."
                >
                    <ClassificationPreview />
                </ProductWindow>
            </RevealOnScroll>
        </div>
    </section>
</template>
