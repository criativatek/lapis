<script setup lang="ts">
import RevealOnScroll from './RevealOnScroll.vue';

/**
 * The one dark band on the page.
 *
 * It uses the sidebar tokens rather than a colour of its own, so it is the same
 * navy the application wears every day — and it turns near-black in dark mode
 * for the same reason the sidebar does.
 *
 * No hours-saved figure appears here on purpose. Nobody has measured one, and
 * an invented number is the fastest way to lose a teacher who has been sold
 * software before.
 */
const gains = [
    {
        title: 'A configuração faz-se uma vez',
        body: 'O perfil monta-se no início do ano. Em fevereiro já não se mexe nele.',
    },
    {
        title: 'Mudar um peso não obriga a refazer contas',
        body: 'Altere a ponderação e os resultados são recalculados.',
    },
    {
        title: 'O relatório parte do que já registou',
        body: 'As secções partem dos dados que existem. Não se começa em branco.',
    },
] as const;
</script>

<template>
    <section
        class="bg-blue-50/60 py-16 sm:py-24 lg:py-28 dark:bg-sidebar dark:text-sidebar-foreground"
    >
        <div class="mx-auto w-full max-w-6xl px-6 sm:px-8">
            <RevealOnScroll v-slot="{ shown }">
                <p
                    class="flex items-center gap-2.5 text-[11px] font-semibold tracking-[0.14em] text-blue-700 uppercase dark:text-(--brand-amber)"
                >
                    <span
                        aria-hidden="true"
                        class="h-px w-7 origin-left bg-blue-600 transition-transform delay-150 duration-700 ease-out dark:bg-(--brand-amber)"
                        :class="shown ? 'scale-x-100' : 'scale-x-0'"
                    />
                    O que ganha
                </p>
                <h2
                    class="mt-3 max-w-2xl text-3xl font-semibold tracking-tight text-balance sm:text-4xl dark:text-white"
                >
                    Mais simples.
                    <span class="text-blue-600 dark:text-(--brand-amber)"
                        >Mais tempo.</span
                    >
                </h2>
                <p
                    class="mt-4 max-w-2xl leading-relaxed text-pretty text-muted-foreground dark:text-sidebar-foreground/80"
                >
                    O trabalho administrativo não desaparece. Deixa é de ser
                    feito duas vezes.
                </p>
            </RevealOnScroll>

            <dl class="mt-12 grid gap-8 sm:mt-16 sm:grid-cols-3 sm:gap-6">
                <RevealOnScroll
                    v-for="(gain, index) in gains"
                    :key="gain.title"
                    :delay="index * 90"
                >
                    <div
                        class="border-t border-blue-200 pt-5 dark:border-sidebar-border"
                    >
                        <dt
                            class="font-semibold tracking-tight dark:text-white"
                        >
                            {{ gain.title }}
                        </dt>
                        <dd
                            class="mt-2 text-sm leading-relaxed text-muted-foreground dark:text-sidebar-foreground/75"
                        >
                            {{ gain.body }}
                        </dd>
                    </div>
                </RevealOnScroll>
            </dl>
        </div>
    </section>
</template>
