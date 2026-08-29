<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Reveals its content the first time it enters the viewport.
 *
 * IntersectionObserver rather than a scroll listener, and it disconnects after
 * the first crossing: the animation is a one-off, so keeping an observer alive
 * for the rest of the visit buys nothing.
 *
 * `prefers-reduced-motion` is honoured by never arming the observer at all —
 * the content starts visible. Guarding only the transition would still leave a
 * reader with motion sensitivity looking at an invisible section until the
 * observer happened to fire.
 *
 * `shown` is exposed as a slot prop so a child can drive its own entrance off
 * the same signal — a rule that draws itself, a bar that grows — without a
 * second observer watching the same element.
 */

type RevealVariant = 'up' | 'scale' | 'right';

withDefaults(
    defineProps<{
        delay?: number;
        /**
         * How it arrives. «up» is the page's default; «scale» is for the
         * product mocks, which read as objects being placed rather than as
         * text sliding in; «right» for a panel that belongs to the column
         * beside it.
         */
        variant?: RevealVariant;
    }>(),
    { delay: 0, variant: 'up' },
);

const HIDDEN: Record<RevealVariant, string> = {
    up: 'translate-y-5 opacity-0',
    scale: 'translate-y-4 scale-[0.985] opacity-0',
    right: 'translate-x-5 opacity-0',
};

const root = ref<HTMLElement | null>(null);
/**
 * Starts SHOWN. The server renders this component, and a crawler must get the
 * text visible — `opacity-0` in the HTML is text Google may treat as hidden.
 * On the client, the element is hidden again only when it is below the fold
 * (nobody sees that happen) and revealed by the observer as it scrolls in.
 * What is already in view on load simply stays; the first screen is faster
 * for it.
 */
const shown = ref(true);
let observer: IntersectionObserver | null = null;

onMounted(() => {
    const prefersReducedMotion = window.matchMedia(
        '(prefers-reduced-motion: reduce)',
    ).matches;

    if (
        prefersReducedMotion ||
        !('IntersectionObserver' in window) ||
        !root.value ||
        root.value.getBoundingClientRect().top < window.innerHeight
    ) {
        return;
    }

    shown.value = false;

    observer = new IntersectionObserver(
        (entries) => {
            for (const entry of entries) {
                if (entry.isIntersecting) {
                    shown.value = true;
                    observer?.disconnect();
                }
            }
        },
        { rootMargin: '0px 0px -8% 0px', threshold: 0.05 },
    );

    observer.observe(root.value);
});

onBeforeUnmount(() => observer?.disconnect());
</script>

<template>
    <div
        ref="root"
        class="motion-safe:transition-all motion-safe:duration-[900ms] motion-safe:ease-[cubic-bezier(0.16,1,0.3,1)]"
        :class="
            shown
                ? 'translate-x-0 translate-y-0 scale-100 opacity-100'
                : HIDDEN[variant]
        "
        :style="delay ? { transitionDelay: `${delay}ms` } : undefined"
    >
        <slot :shown="shown" />
    </div>
</template>
