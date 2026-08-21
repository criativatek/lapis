<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { dashboard, login, register } from '@/routes';
import RevealOnScroll from './RevealOnScroll.vue';

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
                    O próximo período pode correr melhor do que o último.
                </h2>
                <p
                    class="mx-auto mt-5 max-w-xl text-lg leading-relaxed text-pretty text-muted-foreground"
                >
                    Monte um perfil, traga uma turma. Meia hora chega para
                    perceber se o LÁPIS trabalha à sua maneira.
                </p>

                <div class="mt-9 flex flex-wrap justify-center gap-3">
                    <Button as-child size="lg" class="group/cta">
                        <Link :href="authenticated ? dashboard() : register()">
                            {{
                                authenticated
                                    ? 'Ir para o painel'
                                    : 'Criar conta'
                            }}
                            <ArrowRight
                                aria-hidden="true"
                                class="transition-transform duration-300 group-hover/cta:translate-x-0.5"
                            />
                        </Link>
                    </Button>
                    <Button
                        v-if="!authenticated"
                        as-child
                        variant="outline"
                        size="lg"
                    >
                        <Link :href="login()">Já tenho conta</Link>
                    </Button>
                </div>

                <p class="mt-5 text-sm text-muted-foreground">
                    Não é pedido cartão. O plano LÁPIS Base fica ativo de
                    imediato.
                </p>
            </RevealOnScroll>
        </div>
    </section>
</template>
