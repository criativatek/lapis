<script setup lang="ts">
import { Maximize2 } from '@lucide/vue';
import { ref } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import AppTour from './AppTour.vue';

/**
 * A neutral application frame around a mock of a real LÁPIS screen.
 *
 * The chrome is deliberately generic — three dots and a path pill, no browser
 * branding — so the frame reads as "this is the product" without pretending to
 * be a screenshot of any particular browser.
 *
 * IT OPENS — ON A POINTER DEVICE ONLY. Every mock is a door into the same
 * guided view of the shell, because a visitor interested enough to point at the
 * picture is exactly the one worth showing the application to. The trigger is a
 * real button laid over the frame rather than the frame itself: the mocks
 * contain tables, and a button that wraps a table is neither valid HTML nor
 * usable.
 *
 * Below `lg` the door is not there at all. The tour is a wide two-column view
 * whose whole point is that pointing at a region lights its explanation — on a
 * phone it collapses into a long scroll with no hover to drive it, which is a
 * worse answer than the mock the visitor already had. `display: none` also
 * takes the button out of the tab order, so nothing invisible is reachable.
 *
 * The frame lifts under the pointer and the traffic lights take colour, which
 * is the affordance — the frame answers before it is clicked.
 */

/**
 * `label` is the alt text these illustrations never had. They are not images —
 * they are DOM, so a screen reader walks the mock's own table and reads a
 * column of numbers with no idea what screen it is looking at. One sr-only
 * sentence before the mock says which part of the application this is, and it
 * is the same sentence a sighted reader gets from the surrounding heading.
 *
 * Optional, because a mock in a section whose heading already names it does
 * not need it repeated.
 */
withDefaults(defineProps<{ path: string; label?: string }>(), {
    label: undefined,
});

const open = ref(false);
</script>

<template>
    <Dialog v-model:open="open">
        <div
            class="group/window relative overflow-hidden rounded-2xl border border-border/70 bg-card shadow-[0_24px_60px_-30px_rgba(15,23,42,0.45)] hover:border-border hover:shadow-[0_36px_80px_-32px_rgba(15,23,42,0.5)] motion-safe:transition-[transform,box-shadow,border-color] motion-safe:duration-500 motion-safe:ease-out motion-safe:hover:-translate-y-1 dark:shadow-[0_24px_60px_-30px_rgba(0,0,0,0.9)] dark:hover:shadow-[0_36px_80px_-32px_rgba(0,0,0,1)]"
        >
            <div
                aria-hidden="true"
                class="flex items-center gap-2 border-b border-border/70 bg-muted/60 px-3 py-2.5 dark:bg-muted/20"
            >
                <span class="flex gap-1.5">
                    <span
                        class="size-2.5 rounded-full bg-border transition-colors duration-500 group-hover/window:bg-rose-400/70"
                    />
                    <span
                        class="size-2.5 rounded-full bg-border transition-colors delay-75 duration-500 group-hover/window:bg-amber-400/70"
                    />
                    <span
                        class="size-2.5 rounded-full bg-border transition-colors delay-150 duration-500 group-hover/window:bg-emerald-400/70"
                    />
                </span>
                <span
                    class="ml-1 min-w-0 flex-1 truncate rounded-md bg-background/80 px-2.5 py-1 text-[11px] text-muted-foreground tabular-nums transition-colors duration-500 group-hover/window:text-foreground dark:bg-background/40"
                >
                    {{ path }}
                </span>
            </div>

            <p v-if="label" class="sr-only">{{ label }}</p>

            <slot />

            <!-- The whole frame is the target. Named, so a screen reader gets a
                 door and not an unlabelled box. -->
            <DialogTrigger as-child>
                <button
                    type="button"
                    class="absolute inset-0 z-10 hidden cursor-pointer rounded-2xl focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none lg:block"
                >
                    <span class="sr-only">Ver o LÁPIS por dentro</span>
                </button>
            </DialogTrigger>

            <span
                aria-hidden="true"
                class="pointer-events-none absolute right-3 bottom-3 z-20 hidden translate-y-1 items-center gap-1.5 rounded-full bg-primary px-3 py-1.5 text-[11px] font-medium text-primary-foreground opacity-0 shadow-lg transition-all duration-300 group-focus-within/window:translate-y-0 group-focus-within/window:opacity-100 group-hover/window:translate-y-0 group-hover/window:opacity-100 lg:inline-flex"
            >
                <Maximize2 class="size-3" />
                Ver o LÁPIS por dentro
            </span>
        </div>

        <DialogContent class="max-h-[92vh] overflow-y-auto sm:max-w-[76rem]">
            <DialogHeader class="text-left">
                <DialogTitle class="text-xl tracking-tight"
                    >O LÁPIS por dentro</DialogTitle
                >
                <DialogDescription>
                    O interface como ele é: o menu, o contexto no cabeçalho e
                    uma turma aberta.
                </DialogDescription>
            </DialogHeader>

            <AppTour />
        </DialogContent>
    </Dialog>
</template>
