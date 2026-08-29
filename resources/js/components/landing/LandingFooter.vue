<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { dashboard, login, register } from '@/routes';
import { privacy, processing, terms } from '@/routes/legal';
import {
    CHROME_BORDER,
    CHROME_LINK,
    CHROME_MUTED,
    CHROME_SURFACE,
} from './chrome';
import { LANDING_NAV } from './navigation';

/**
 * Produto, conta e os documentos legais.
 *
 * A coluna legal esteve deliberadamente ausente enquanto as páginas não
 * existiam — um rodapé cheio de links que não vão a lado nenhum custa mais
 * credibilidade do que uma coluna vazia poupa. Agora existem, e são o primeiro
 * sítio onde alguém as procura.
 *
 * Ainda não há «Contacto»: o endereço público é uma definição de plataforma que
 * pode não estar preenchida, e o mesmo raciocínio de então continua a aplicar-se.
 */

withDefaults(
    defineProps<{ authenticated: boolean; contactEmail?: string | null }>(),
    { contactEmail: null },
);

const version = computed(() => usePage().props.appVersion);
</script>

<template>
    <footer class="border-t" :class="[CHROME_SURFACE, CHROME_BORDER]">
        <div
            class="mx-auto grid w-full max-w-6xl gap-10 px-6 py-14 sm:px-8 md:grid-cols-[minmax(0,1.4fr)_repeat(3,minmax(0,1fr))]"
        >
            <div>
                <span class="flex items-center gap-2.5">
                    <!-- Decorative: the word Lapispro is right beside it, so a
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
                            >Lapispro</span
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
                    Uma plataforma criada para apoiar professores na avaliação,
                    organização e acompanhamento pedagógico.
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
            <!-- Contact in the footer, not hidden behind a form: a school
                 deciding whether to trust student data to a platform looks
                 for who is behind it. The address comes from the platform
                 settings; the column disappears when none is set. -->
            <nav v-if="contactEmail" aria-labelledby="footer-contact">
                <h2
                    id="footer-contact"
                    class="text-[11px] font-semibold tracking-[0.14em] uppercase"
                    :class="CHROME_MUTED"
                >
                    Contacto
                </h2>
                <ul class="mt-4 space-y-2.5 text-sm">
                    <li>
                        <a
                            :href="`mailto:${contactEmail}`"
                            class="inline-block rounded break-all transition-all duration-300 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none motion-safe:hover:translate-x-0.5"
                            :class="CHROME_LINK"
                            >{{ contactEmail }}</a
                        >
                    </li>
                </ul>
            </nav>
        </div>

        <div class="border-t" :class="CHROME_BORDER">
            <div
                class="mx-auto flex w-full max-w-6xl flex-wrap items-center justify-between gap-x-6 gap-y-3 px-6 py-5 text-xs sm:px-8"
                :class="CHROME_MUTED"
            >
                <span>Lapispro v{{ version }}</span>

                <nav
                    aria-label="Documentos legais"
                    class="flex flex-wrap items-center gap-x-5 gap-y-2"
                >
                    <Link
                        :href="terms()"
                        class="rounded transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        :class="CHROME_LINK"
                        >Termos de Utilização</Link
                    >
                    <Link
                        :href="privacy()"
                        class="rounded transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        :class="CHROME_LINK"
                        >Política de Privacidade</Link
                    >
                    <Link
                        :href="processing()"
                        class="rounded transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        :class="CHROME_LINK"
                        >Acordo de Tratamento de Dados</Link
                    >
                </nav>
            </div>
        </div>
    </footer>
</template>
