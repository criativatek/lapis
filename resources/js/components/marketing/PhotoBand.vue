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
        /** The page's one statement: tinted ground, bigger type, bigger photo. */
        prominent?: boolean;
    }>(),
    { eyebrow: undefined, flip: false, prominent: false },
);
</script>

<template>
    <section
        class="py-14 sm:py-20"
        :class="prominent ? 'bg-slate-50 sm:py-24' : undefined"
    >
        <div
            class="mx-auto grid w-full max-w-6xl items-center gap-10 px-6 sm:px-8 lg:gap-16"
            :class="
                prominent
                    ? 'lg:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]'
                    : 'lg:grid-cols-2'
            "
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
                    :class="prominent ? 'lg:text-[3rem] lg:leading-[1.08]' : undefined"
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
                class="w-full rounded-[2rem] object-cover"
                :class="[
                    flip ? 'lg:order-1' : undefined,
                    prominent ? 'aspect-[4/3] shadow-xl' : 'aspect-[3/2]',
                ]"
            />
        </div>
    </section>
</template>
