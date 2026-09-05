<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Menu } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import AppLogoWordmark from '@/components/AppLogoWordmark.vue';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { dashboard, login, register } from '@/routes';
import {
    CHROME_BORDER,
    CHROME_GHOST_HOVER,
    CHROME_LINK,
    CHROME_SURFACE_STICKY,
    LANDING_PRIMARY,
} from './chrome';
import type { LandingNavItem } from './navigation';
import { COMPANY_NAV, FEATURE_NAV, LANDING_NAV } from './navigation';

/**
 * The public header. Transparent over the hero and only grows its border and
 * blur once the page has moved — the hero is the first impression and a hard
 * rule across it costs more than it gives.
 */

defineProps<{ authenticated: boolean }>();

const scrolled = ref(false);
const mobileOpen = ref(false);

/**
 * How far down the page the visitor is, 0–1.
 *
 * Drawn as a hairline under the header. On a page this long it is the cheapest
 * honest answer to «how much more of this is there?», and it costs one read of
 * two numbers inside a scroll handler that already existed.
 */
const progress = ref(0);

const onScroll = (): void => {
    scrolled.value = window.scrollY > 8;

    const scrollable =
        document.documentElement.scrollHeight - window.innerHeight;

    progress.value = scrollable <= 0 ? 0 : window.scrollY / scrollable;
};

onMounted(() => {
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
});

onBeforeUnmount(() => window.removeEventListener('scroll', onScroll));

const items: readonly LandingNavItem[] = LANDING_NAV;

/** The sheet has room for every page; the bar shows the short list. */
const sheetItems: readonly LandingNavItem[] = [...FEATURE_NAV, ...COMPANY_NAV];

const page = usePage();
const current = computed(() => new URL(page.url, 'http://x').pathname);
</script>

<template>
    <header
        class="sticky top-0 z-50 border-b transition-shadow duration-300"
        :class="[
            CHROME_SURFACE_STICKY,
            CHROME_BORDER,
            scrolled ? 'shadow-[0_1px_24px_-12px_rgba(15,23,42,0.5)]' : '',
        ]"
    >
        <div
            class="mx-auto flex w-full max-w-6xl items-center gap-2 px-4 py-3.5 sm:gap-3 sm:px-8"
        >
            <Link
                href="/"
                class="flex items-center gap-2.5 rounded-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                aria-label="Lapispro — página inicial"
            >
                <!--
                    O logótipo da identidade, não uma marca dentro de um
                    quadrado com o nome escrito ao lado. As cores exactas dos
                    ficheiros: #11223d no tema claro, branco no escuro — que é
                    a diferença entre as duas versões da identidade, feita aqui
                    com uma classe em vez de duas imagens.

                    O nome deixa de ser escrito à parte porque o logótipo já o
                    diz. A linha descritiva que vivia ao lado saiu na 0.91.0:
                    quatro linhas de 12px coladas ao logótipo eram ruído, e o
                    hero diz o mesmo duas linhas abaixo.
                -->
                <AppLogoWordmark
                    class="h-7 w-auto shrink-0 text-[#11223d] sm:h-8 dark:text-white"
                />
            </Link>

            <nav
                class="mx-auto hidden items-center gap-1 lg:flex"
                aria-label="Secções da página"
            >
                <Link
                    v-for="item in items"
                    :key="item.href"
                    :href="item.href"
                    class="group relative rounded-md px-3 py-2 text-sm font-medium transition-colors duration-300 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :class="[
                        CHROME_LINK,
                        current === item.href ? 'text-foreground' : '',
                    ]"
                    :aria-current="current === item.href ? 'page' : undefined"
                >
                    {{ item.label }}
                    <span
                        aria-hidden="true"
                        class="absolute inset-x-3 -bottom-0.5 h-px origin-left bg-blue-600 transition-transform duration-300 ease-out group-hover:scale-x-100"
                        :class="
                            current === item.href ? 'scale-x-100' : 'scale-x-0'
                        "
                    />
                </Link>
            </nav>

            <div class="ml-auto flex items-center gap-2 lg:ml-0">
                <template v-if="authenticated">
                    <Button as-child size="sm">
                        <Link :href="dashboard()">Ir para o painel</Link>
                    </Button>
                </template>
                <template v-else>
                    <Button
                        as-child
                        variant="ghost"
                        size="sm"
                        class="hidden sm:inline-flex"
                        :class="[CHROME_LINK, CHROME_GHOST_HOVER]"
                    >
                        <Link :href="login()">Entrar</Link>
                    </Button>
                    <Button as-child size="sm" :class="LANDING_PRIMARY">
                        <!-- At 375px the full label plus the logo and the menu
                             button ran 49px past the viewport. -->
                        <Link :href="register()">
                            <span class="sm:hidden">Experimentar</span>
                            <span class="hidden sm:inline"
                                >Experimentar Lapispro</span
                            >
                        </Link>
                    </Button>
                </template>

                <Sheet v-model:open="mobileOpen">
                    <SheetTrigger :as-child="true">
                        <Button
                            variant="ghost"
                            size="icon"
                            class="size-10 sm:size-11 lg:hidden"
                            :class="[CHROME_LINK, CHROME_GHOST_HOVER]"
                            aria-label="Abrir menu"
                        >
                            <Menu class="size-5" />
                        </Button>
                    </SheetTrigger>
                    <SheetContent side="right" class="w-[280px] p-6">
                        <SheetHeader class="p-0 text-left">
                            <SheetTitle class="text-base">Navegação</SheetTitle>
                        </SheetHeader>
                        <nav class="mt-6 flex flex-col gap-1">
                            <Link
                                v-for="item in sheetItems"
                                :key="item.href"
                                :href="item.href"
                                class="rounded-md px-3 py-2.5 text-sm font-medium transition-colors hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                :class="
                                    current === item.href
                                        ? 'bg-blue-50 text-blue-700'
                                        : ''
                                "
                                @click="mobileOpen = false"
                            >
                                {{ item.label }}
                            </Link>
                            <Link
                                v-if="!authenticated"
                                :href="login()"
                                class="mt-3 rounded-md border border-border px-3 py-2.5 text-center text-sm font-medium transition-colors hover:bg-muted"
                            >
                                Entrar
                            </Link>
                        </nav>
                    </SheetContent>
                </Sheet>
            </div>
        </div>

        <!-- The reading progress hairline. Decorative: the number it encodes is
             the scrollbar's, which assistive technology already exposes. -->
        <div
            aria-hidden="true"
            class="pointer-events-none absolute inset-x-0 bottom-0 h-px"
        >
            <div
                class="h-full origin-left bg-primary/70 transition-opacity duration-300 dark:bg-(--brand-amber)/70"
                :class="scrolled ? 'opacity-100' : 'opacity-0'"
                :style="{ transform: `scaleX(${progress})` }"
            />
        </div>
    </header>
</template>
