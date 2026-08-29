<script setup lang="ts">
/**
 * A full-bleed band of colour with the standard measure inside.
 *
 * The blue band is the strong one and there should be one per page; amber
 * and emerald are pale grounds that break the white without shouting. The
 * eyebrow / title / lead trio is the same the whole site uses.
 */

type Tone = 'blue' | 'amber' | 'emerald' | 'white';

withDefaults(
    defineProps<{
        id?: string;
        tone?: Tone;
        eyebrow?: string;
        title?: string;
        lead?: string;
        centered?: boolean;
    }>(),
    {
        id: undefined,
        tone: 'white',
        eyebrow: undefined,
        title: undefined,
        lead: undefined,
        centered: false,
    },
);

const GROUND: Record<Tone, string> = {
    // Institutional navy, not royal blue: #1E4AB0 → #102A56 reads as school,
    // not as an ad. Buttons keep blue-600 — action colour stays bright.
    blue: 'bg-gradient-to-br from-[#1E4AB0] via-[#183B8F] to-[#102A56] text-white',
    amber: 'bg-amber-50',
    emerald: 'bg-emerald-50',
    white: '',
};

const EYEBROW: Record<Tone, string> = {
    blue: 'text-blue-200',
    amber: 'text-amber-700',
    emerald: 'text-emerald-700',
    white: 'text-blue-700',
};

const LEAD: Record<Tone, string> = {
    blue: 'text-blue-100/90',
    amber: 'text-muted-foreground',
    emerald: 'text-muted-foreground',
    white: 'text-muted-foreground',
};
</script>

<template>
    <section
        :id="id"
        class="scroll-mt-[4.5rem] py-14 sm:py-20"
        :class="GROUND[tone]"
        :aria-labelledby="id && title ? `${id}-title` : undefined"
    >
        <div class="mx-auto w-full max-w-6xl px-6 sm:px-8">
            <div
                v-if="eyebrow || title || lead"
                class="max-w-2xl"
                :class="centered ? 'mx-auto text-center' : undefined"
            >
                <p
                    v-if="eyebrow"
                    class="text-[12px] font-semibold tracking-[0.12em] uppercase"
                    :class="EYEBROW[tone]"
                >
                    {{ eyebrow }}
                </p>
                <h2
                    v-if="title"
                    :id="id ? `${id}-title` : undefined"
                    class="mt-3 text-3xl font-semibold tracking-tight text-balance sm:text-4xl lg:text-[2.75rem] lg:leading-[1.1]"
                >
                    {{ title }}
                </h2>
                <p
                    v-if="lead"
                    class="mt-4 text-lg leading-relaxed text-pretty"
                    :class="LEAD[tone]"
                >
                    {{ lead }}
                </p>
            </div>
            <div
                :class="eyebrow || title || lead ? 'mt-10 sm:mt-12' : undefined"
            >
                <slot />
            </div>
        </div>
    </section>
</template>
