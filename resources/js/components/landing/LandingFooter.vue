<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { dashboard, login, register } from '@/routes';
import {
    CHROME_BORDER,
    CHROME_LINK,
    CHROME_MUTED,
    CHROME_SURFACE,
} from './chrome';
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
                    <!-- Decorative: the word LÁPIS is right beside it, so a
                         screen reader announcing the mark too would just read
                         the brand twice. -->
                    <span
                        aria-hidden="true"
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
                            class="mt-0.5 block text-[11px] leading-none"
                            :class="CHROME_MUTED"
                            >Mais simples. Mais tempo.</span
                        >
                    </span>
                </span>
                <p
                    class="mt-5 max-w-sm text-sm leading-relaxed"
                    :class="CHROME_MUTED"
                >
                    Laboratório de Apoio ao Professor, Informação e
                    Simplificação. Uma plataforma criada para apoiar professores
                    na avaliação, organização e acompanhamento pedagógico.
                </p>
            </div>

            <nav aria-labelledby="footer-product">
                <h2
                    id="footer-product"
                    class="text-[11px] font-semibold tracking-[0.14em] uppercase"
                    :class="CHROME_MUTED"
                >
                    Produto
                </h2>
                <ul class="mt-4 space-y-2.5 text-sm">
                    <li v-for="item in LANDING_NAV" :key="item.href">
                        <a
                            :href="item.href"
                            class="inline-block rounded transition-all duration-300 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none motion-safe:hover:translate-x-0.5"
                            :class="CHROME_LINK"
                            >{{ item.label }}</a
                        >
                    </li>
                </ul>
            </nav>

            <nav aria-labelledby="footer-account">
                <h2
                    id="footer-account"
                    class="text-[11px] font-semibold tracking-[0.14em] uppercase"
                    :class="CHROME_MUTED"
                >
                    Conta
                </h2>
                <ul class="mt-4 space-y-2.5 text-sm">
                    <li v-if="!authenticated">
                        <Link
                            :href="login()"
                            class="inline-block rounded transition-all duration-300 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none motion-safe:hover:translate-x-0.5"
                            :class="CHROME_LINK"
                            >Entrar</Link
                        >
                    </li>
                    <li v-if="!authenticated">
                        <Link
                            :href="register()"
                            class="inline-block rounded transition-all duration-300 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none motion-safe:hover:translate-x-0.5"
                            :class="CHROME_LINK"
                            >Criar conta</Link
                        >
                    </li>
                    <li v-if="authenticated">
                        <Link
                            :href="dashboard()"
                            class="inline-block rounded transition-all duration-300 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none motion-safe:hover:translate-x-0.5"
                            :class="CHROME_LINK"
                            >Painel do Professor</Link
                        >
                    </li>
                </ul>
            </nav>
        </div>

        <div class="border-t" :class="CHROME_BORDER">
            <div
                class="mx-auto flex w-full max-w-6xl flex-wrap items-center justify-between gap-2 px-6 py-5 text-xs sm:px-8"
                :class="CHROME_MUTED"
            >
                <span>LÁPIS v{{ version }}</span>
                <span>Mais simples. Mais tempo.</span>
            </div>
        </div>
    </footer>
</template>
