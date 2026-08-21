<script setup lang="ts">
import RevealOnScroll from './RevealOnScroll.vue';

/**
 * One band of the landing page: an optional tinted ground, the standard
 * measure, and the eyebrow / heading / lead trio the whole page repeats.
 *
 * The heading level is a prop because the page has exactly one <h1> (the hero)
 * and every band underneath is an <h2>. A component that hardcoded <h2> would
 * be unusable for the sub-blocks that need <h3>.
 */

withDefaults(
    defineProps<{
        id?: string;
        eyebrow?: string;
        title?: string;
        lead?: string;
        /** A faint band, used to break the rhythm between white sections. */
        tinted?: boolean;
        /** Centres the heading block. Left-aligned is the default. */
        centered?: boolean;
    }>(),
    {
        id: undefined,
        eyebrow: undefined,
        title: undefined,
        lead: undefined,
        tinted: false,
        centered: false,
    },
);
</script>

<template>
    <section
        :id="id"
        class="scroll-mt-20 border-t border-border/60 py-20 sm:py-28"
        :class="tinted ? 'bg-muted/40 dark:bg-muted/10' : undefined"
        :aria-labelledby="id && title ? `${id}-title` : undefined"
    >
        <div class="mx-auto w-full max-w-6xl px-6 sm:px-8">
            <RevealOnScroll v-if="eyebrow || title || lead" v-slot="{ shown }">
                <div
                    class="max-w-2xl"
                    :class="centered ? 'mx-auto text-center' : undefined"
                >
                    <p
                        v-if="eyebrow"
                        class="flex items-center gap-2.5 text-[11px] font-semibold tracking-[0.14em] text-muted-foreground uppercase"
                        :class="centered ? 'justify-center' : undefined"
                    >
                        <span
                            aria-hidden="true"
                            class="h-px w-7 origin-left bg-primary transition-transform delay-150 duration-700 ease-out dark:bg-(--brand-amber)"
                            :class="shown ? 'scale-x-100' : 'scale-x-0'"
                        />
                        {{ eyebrow }}
                    </p>
                    <h2
                        v-if="title"
                        :id="id ? `${id}-title` : undefined"
                        class="mt-3 text-3xl font-semibold tracking-tight text-balance sm:text-4xl"
                    >
                        {{ title }}
                    </h2>
                    <p
                        v-if="lead"
                        class="mt-4 text-base leading-relaxed text-pretty text-muted-foreground sm:text-lg"
                    >
                        {{ lead }}
                    </p>
                </div>
            </RevealOnScroll>

            <div
                :class="eyebrow || title || lead ? 'mt-12 sm:mt-16' : undefined"
            >
                <slot />
            </div>
        </div>
    </section>
</template>
