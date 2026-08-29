<script setup lang="ts">
/**
 * A photograph beside a statement. Used where the page needs to breathe
 * between two dense sections, and to say the one thing the product stands
 * on («o professor decide») with a person in the picture.
 */
withDefaults(
    defineProps<{
        image: { src: string; alt: string };
        eyebrow?: string;
        title: string;
        body: string;
        /** Photo on the right (default) or on the left. */
        flip?: boolean;
    }>(),
    { eyebrow: undefined, flip: false },
);
</script>

<template>
    <section class="py-16 sm:py-24">
        <div
            class="mx-auto grid w-full max-w-6xl items-center gap-10 px-6 sm:px-8 lg:grid-cols-2 lg:gap-16"
        >
            <div :class="flip ? 'lg:order-2' : undefined">
                <p
                    v-if="eyebrow"
                    class="text-[12px] font-semibold tracking-[0.12em] text-blue-700 uppercase"
                >
                    {{ eyebrow }}
                </p>
                <h2
                    class="mt-3 text-3xl font-semibold tracking-tight text-balance sm:text-4xl"
                >
                    {{ title }}
                </h2>
                <p
                    class="mt-5 text-lg leading-relaxed text-pretty text-muted-foreground"
                >
                    {{ body }}
                </p>
                <slot />
            </div>
            <img
                :src="image.src"
                :alt="image.alt"
                width="1600"
                height="1067"
                loading="lazy"
                decoding="async"
                class="aspect-[3/2] w-full rounded-[2rem] object-cover"
                :class="flip ? 'lg:order-1' : undefined"
            />
        </div>
    </section>
</template>
