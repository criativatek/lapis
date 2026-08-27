<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Menu } from '@lucide/vue';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
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
    CHROME_MUTED,
    CHROME_SURFACE_STICKY,
} from './chrome';
import type { LandingNavItem } from './navigation';
import { LANDING_NAV } from './navigation';

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

/**
 * Navigating from the mobile sheet: close first, scroll second.
 *
 * The sheet is a dialog, and while it is open reka-ui sets `overflow: hidden`
 * on the body — a scroll requested at that moment is simply dropped, which is
 * how every link in this menu became a dead end. Waiting a fixed number of
 * milliseconds was guesswork; the close animation is not the same length on
 * every device. This waits for the panel to actually leave the DOM and gives
 * up after about a second and a half, so a link never stays dead.
 *
 * `scrollIntoView` rather than a computed offset, because it honours the
 * section's own `scroll-margin-top` — the header height stays in one place.
 * No `behavior`, so the jump is instant: see the note in Welcome.vue.
 */
function goToSection(event: MouseEvent, href: string): void {
    const target = document.querySelector(href);

    if (!target) {
        return;
    }

    event.preventDefault();
    mobileOpen.value = false;
    scrollWhenSheetIsGone(target);
}

function scrollWhenSheetIsGone(target: Element, framesLeft = 90): void {
    const stillMounted =
        document.querySelector('[data-slot="sheet-content"]') !== null;

    if (stillMounted && framesLeft > 0) {
        window.requestAnimationFrame(() =>
            scrollWhenSheetIsGone(target, framesLeft - 1),
        );

        return;
    }

    // One frame after the panel unmounts: the body style is restored during
    // that unmount, and asking a still-locked document to scroll does nothing.
    window.requestAnimationFrame(() =>
        target.scrollIntoView({ block: 'start' }),
    );
}

const items: readonly LandingNavItem[] = LANDING_NAV;
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
            class="mx-auto flex w-full max-w-6xl items-center gap-3 px-6 py-3.5 sm:px-8"
        >
            <Link
                href="/"
                class="flex items-center gap-2.5 rounded-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                aria-label="LÁPIS — página inicial"
            >
                <span
                    class="flex size-9 items-center justify-center rounded-lg bg-primary text-primary-foreground"
                >
                    <AppLogoIcon class="size-5" />
                </span>
                <span class="hidden sm:block">
                    <span
                        class="block text-[17px] leading-none font-semibold tracking-tight"
                        >LÁPIS</span
                    >
                    <span
                        class="mt-1 block text-[12px] leading-tight"
                        :class="CHROME_MUTED"
                        >Plataforma para professores. Avaliação, organização e
                        acompanhamento num só lugar.</span
                    >
                </span>
            </Link>

            <nav
                class="mx-auto hidden items-center gap-1 lg:flex"
                aria-label="Secções da página"
            >
                <a
                    v-for="item in items"
                    :key="item.href"
                    :href="item.href"
                    class="group relative rounded-md px-3 py-2 text-sm font-medium transition-colors duration-300 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :class="CHROME_LINK"
                >
                    {{ item.label }}
                    <span
                        aria-hidden="true"
                        class="absolute inset-x-3 -bottom-0.5 h-px origin-left scale-x-0 bg-primary transition-transform duration-300 ease-out group-hover:scale-x-100 dark:bg-(--brand-amber)"
                    />
                </a>
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
                    <Button as-child size="sm">
                        <Link :href="register()">Experimentar LÁPIS</Link>
                    </Button>
                </template>

                <Sheet v-model:open="mobileOpen">
                    <SheetTrigger :as-child="true">
                        <Button
                            variant="ghost"
                            size="icon"
                            class="lg:hidden"
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
                            <a
                                v-for="item in items"
                                :key="item.href"
                                :href="item.href"
                                class="rounded-md px-3 py-2.5 text-sm font-medium transition-colors hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                @click="goToSection($event, item.href)"
                            >
                                {{ item.label }}
                            </a>
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
