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
    <section class="bg-sidebar py-20 text-sidebar-foreground sm:py-28">
        <div class="mx-auto w-full max-w-6xl px-6 sm:px-8">
            <RevealOnScroll v-slot="{ shown }">
                <p
                    class="flex items-center gap-2.5 text-[11px] font-semibold tracking-[0.14em] text-(--brand-amber) uppercase"
                >
                    <span
                        aria-hidden="true"
                        class="h-px w-7 origin-left bg-(--brand-amber) transition-transform delay-150 duration-700 ease-out"
                        :class="shown ? 'scale-x-100' : 'scale-x-0'"
                    />
                    O que ganha
                </p>
                <h2
                    class="mt-3 max-w-2xl text-3xl font-semibold tracking-tight text-balance text-white sm:text-4xl"
                >
                    Menos tempo a gerir avaliações.
                    <span class="text-(--brand-amber)"
                        >Mais tempo para ensinar.</span
                    >
                </h2>
                <p
                    class="mt-4 max-w-2xl leading-relaxed text-pretty text-sidebar-foreground/80"
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
                    <div class="border-t border-sidebar-border pt-5">
                        <dt class="font-semibold tracking-tight text-white">
                            {{ gain.title }}
                        </dt>
                        <dd
                            class="mt-2 text-sm leading-relaxed text-sidebar-foreground/75"
                        >
                            {{ gain.body }}
                        </dd>
                    </div>
                </RevealOnScroll>
            </dl>
        </div>
    </section>
</template>
