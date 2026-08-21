<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { dashboard, login, register } from '@/routes';
import { CHROME_BORDER, CHROME_SURFACE } from './chrome';
import { LANDING_NAV } from './navigation';

/**
 * Product and account links only.
 *
 * There is deliberately no «Termos», «Privacidade» or «Contacto» column: those
 * pages do not exist yet, and a footer full of links that go nowhere costs more
 * credibility than an empty column saves.
 */

defineProps<{ authenticated: boolean }>();

const version = computed(() => usePage().props.appVersion);
</script>

<template>
    <footer class="border-t" :class="[CHROME_SURFACE, CHROME_BORDER]">
        <div
            class="mx-auto grid w-full max-w-6xl gap-10 px-6 py-14 sm:px-8 md:grid-cols-[minmax(0,1.4fr)_repeat(2,minmax(0,1fr))]"
        >
            <div>
                <span class="flex items-center gap-2.5">
                    <span
                        class="flex size-9 items-center justify-center rounded-lg bg-primary text-primary-foreground"
                    >
                        <AppLogoIcon class="size-5" />
                    </span>
                    <span>
                        <span
                            class="block text-[15px] leading-none font-semibold tracking-tight"
                            >LÁPIS</span
                        >
                        <span
                            class="mt-0.5 block text-[11px] leading-none text-muted-foreground"
                            >Mais tempo para ensinar</span
                        >
                    </span>
                </span>
                <p
                    class="mt-5 max-w-sm text-sm leading-relaxed text-muted-foreground"
                >
                    Laboratório de Apoio ao Professor, Informação e
                    Simplificação. Feito para professores portugueses.
                </p>
            </div>

            <nav aria-labelledby="footer-product">
                <h2
                    id="footer-product"
                    class="text-[11px] font-semibold tracking-[0.14em] text-muted-foreground uppercase"
                >
                    Produto
                </h2>
                <ul class="mt-4 space-y-2.5 text-sm">
                    <li v-for="item in LANDING_NAV" :key="item.href">
                        <a
                            :href="item.href"
                            class="inline-block rounded text-muted-foreground transition-all duration-300 hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none motion-safe:hover:translate-x-0.5"
                            >{{ item.label }}</a
                        >
                    </li>
                </ul>
            </nav>

            <nav aria-labelledby="footer-account">
                <h2
                    id="footer-account"
                    class="text-[11px] font-semibold tracking-[0.14em] text-muted-foreground uppercase"
                >
                    Conta
                </h2>
                <ul class="mt-4 space-y-2.5 text-sm">
                    <li v-if="!authenticated">
                        <Link
                            :href="login()"
                            class="inline-block rounded text-muted-foreground transition-all duration-300 hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none motion-safe:hover:translate-x-0.5"
                            >Entrar</Link
                        >
                    </li>
                    <li v-if="!authenticated">
                        <Link
                            :href="register()"
                            class="inline-block rounded text-muted-foreground transition-all duration-300 hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none motion-safe:hover:translate-x-0.5"
                            >Criar conta</Link
                        >
                    </li>
                    <li v-if="authenticated">
                        <Link
                            :href="dashboard()"
                            class="inline-block rounded text-muted-foreground transition-all duration-300 hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none motion-safe:hover:translate-x-0.5"
                            >Painel do Professor</Link
                        >
                    </li>
                </ul>
            </nav>
        </div>

        <div class="border-t" :class="CHROME_BORDER">
            <div
                class="mx-auto flex w-full max-w-6xl flex-wrap items-center justify-between gap-2 px-6 py-5 text-xs text-muted-foreground sm:px-8"
            >
                <span>LÁPIS v{{ version }}</span>
                <span>Mais tempo para ensinar.</span>
            </div>
        </div>
    </footer>
</template>
