<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { dashboard, login, register } from '@/routes';
import RevealOnScroll from './RevealOnScroll.vue';

/**
 * The last thing on the page, and the one place the whole argument is stated
 * as a position rather than as a feature.
 *
 * «O professor decide. O LÁPIS simplifica o caminho.» is the pillar sentence
 * of the product, and it is deliberately said HERE and nowhere else on the
 * page: repeated in three sections it would read as a slogan, said once at
 * the end it reads as a commitment.
 */

defineProps<{ authenticated: boolean }>();
</script>

<template>
    <section class="relative overflow-hidden border-t border-border/60">
        <div
            aria-hidden="true"
            class="pointer-events-none absolute inset-0 -z-10 bg-[radial-gradient(48rem_26rem_at_50%_120%,var(--color-accent),transparent_70%)] opacity-90 dark:opacity-25"
        />

        <div
            class="mx-auto w-full max-w-3xl px-6 py-20 text-center sm:px-8 sm:py-28"
        >
            <RevealOnScroll>
                <h2
                    class="text-3xl font-semibold tracking-tight text-balance sm:text-4xl"
                >
                    Menos trabalho sobre os dados. Mais tempo para trabalhar com
                    os alunos.
                </h2>
                <p
                    class="mx-auto mt-5 max-w-xl text-lg leading-relaxed text-pretty text-muted-foreground"
                >
                    O LÁPIS não pretende substituir o professor. Pretende
                    dar-lhe melhor informação, melhor organização e mais tempo
                    para tomar decisões pedagógicas com confiança.
                </p>

                <p
                    class="mx-auto mt-8 max-w-xl text-xl font-semibold tracking-tight text-balance sm:text-2xl"
                >
                    O professor decide. O LÁPIS simplifica o caminho.
                </p>

                <div class="mt-9 flex flex-wrap justify-center gap-3">
                    <Button as-child size="lg" class="group/cta">
                        <Link :href="authenticated ? dashboard() : register()">
                            {{
                                authenticated
                                    ? 'Ir para o painel'
                                    : 'Começar gratuitamente'
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
                        <a href="#planos">Conhecer o Pro</a>
                    </Button>
                </div>

                <p class="mt-5 text-sm text-muted-foreground">
                    Não é necessário cartão de crédito. O plano LÁPIS Base fica
                    ativo de imediato.
                    <!-- «Já tenho conta» was a button here before the two CTAs
                         above were fixed by the commercial brief. It stays, as
                         a link: the header and the footer both carry it, but
                         the foot of the page is where somebody who scrolled
                         the whole thing looks for it. -->
                    <template v-if="!authenticated">
                        <Link
                            :href="login()"
                            class="rounded font-medium text-foreground underline underline-offset-4 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >Já tenho conta</Link
                        >.
                    </template>
                </p>
            </RevealOnScroll>
        </div>
    </section>
</template>
