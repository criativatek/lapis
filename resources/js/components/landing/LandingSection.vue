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
        /**
         * The header/footer's warm paper tone, for a section that wants its
         * own identity rather than a neutral pause. Takes priority over
         * `tinted` if both are set.
         */
        warm?: boolean;
        /** Centres the heading block. Left-aligned is the default. */
        centered?: boolean;
    }>(),
    {
        id: undefined,
        eyebrow: undefined,
        title: undefined,
        lead: undefined,
        tinted: false,
        warm: false,
        centered: false,
    },
);
</script>

<template>
    <section
        :id="id"
        class="scroll-mt-[4.5rem] border-t border-border/60 pt-12 pb-14 sm:py-20 lg:py-24"
        :class="warm ? 'bg-blue-50/60 dark:bg-muted/10' : undefined"
        :aria-labelledby="id && title ? `${id}-title` : undefined"
    >
        <div class="mx-auto w-full max-w-6xl px-6 sm:px-8">
            <RevealOnScroll v-if="eyebrow || title || lead">
                <div
                    class="max-w-2xl"
                    :class="centered ? 'mx-auto text-center' : undefined"
                >
                    <p
                        v-if="eyebrow"
                        class="text-[12px] font-semibold tracking-[0.12em] text-blue-700 uppercase"
                    >
                        {{ eyebrow }}
                    </p>
                    <h2
                        v-if="title"
                        :id="id ? `${id}-title` : undefined"
                        class="mt-3 text-3xl font-semibold tracking-[-0.02em] text-balance sm:text-4xl lg:text-[3rem] lg:leading-[1.08]"
                    >
                        {{ title }}
                    </h2>
                    <p
                        v-if="lead"
                        class="mt-4 text-lg leading-relaxed text-pretty text-muted-foreground"
                    >
                        {{ lead }}
                    </p>
                </div>
            </RevealOnScroll>

            <div
                :class="eyebrow || title || lead ? 'mt-10 sm:mt-12' : undefined"
            >
                <slot />
            </div>
        </div>
    </section>
</template>
