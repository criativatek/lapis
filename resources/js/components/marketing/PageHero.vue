<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight, Check } from '@lucide/vue';
import { LANDING_PRIMARY } from '@/components/landing/chrome';
import { Button } from '@/components/ui/button';
import { dashboard, register } from '@/routes';

/**
 * The first screen of a public page: a big claim, a photograph, one action —
 * inside one rounded card, with the photograph bleeding to the card's edge.
 *
 * THE PHOTOGRAPH IS THE POINT. The old landing had no image at all and read
 * as a document; a person's hands over a notebook says «this is for you»
 * before a single line is read. The photographs are generated, editorial,
 * and never show a recognisable face — the audience teaches minors, and a
 * stock face on a page about student data reads as exactly the wrong thing.
 *
 * `titleAccent` is the second sentence of the headline, set in blue: the
 * position («menos peso administrativo») in ink, the promise («mais espaço
 * para ser professor») in the action colour.
 */

withDefaults(
    defineProps<{
        eyebrow?: string;
        title: string;
        titleAccent?: string;
        lead: string;
        image: { src: string; alt: string };
        authenticated: boolean;
        cta?: string;
        /** Short, true facts under the buttons. Three at most. */
        chips?: readonly string[];
    }>(),
    {
        eyebrow: undefined,
        titleAccent: undefined,
        cta: 'Experimentar Lapispro',
        chips: () => [
            'Sem cartão',
            'RGPD desde a arquitetura',
            'O professor decide',
        ],
    },
);
</script>

<template>
    <section class="px-4 pt-4 pb-12 sm:px-6 sm:pt-6 sm:pb-16">
        <div
            class="relative mx-auto grid w-full max-w-6xl overflow-hidden rounded-[2rem] bg-slate-50 card-soft sm:rounded-[2.5rem] lg:grid-cols-[minmax(0,0.95fr)_minmax(0,1.05fr)]"
        >
            <div class="min-w-0 px-6 py-12 sm:px-10 sm:py-16 lg:py-20">
                <p
                    v-if="eyebrow"
                    class="inline-flex rounded-full bg-blue-100 px-3.5 py-1.5 text-[11px] font-semibold tracking-[0.08em] text-blue-800 uppercase"
                >
                    {{ eyebrow }}
                </p>
                <h1
                    class="mt-6 text-4xl font-semibold tracking-tight text-balance text-slate-900 sm:text-5xl lg:text-[3.4rem] lg:leading-[1.05]"
                >
                    {{ title }}
                    <template v-if="titleAccent">
                        <span class="block text-blue-600">{{
                            titleAccent
                        }}</span>
                    </template>
                </h1>
                <p
                    class="mt-6 max-w-xl text-lg leading-relaxed text-pretty text-slate-600"
                >
                    {{ lead }}
                </p>
                <div class="mt-9 flex flex-wrap items-center gap-3">
                    <Button
                        as-child
                        size="lg"
                        class="group/cta"
                        :class="LANDING_PRIMARY"
                    >
                        <Link :href="authenticated ? dashboard() : register()">
                            {{ authenticated ? 'Ir para o painel' : cta }}
                            <ArrowRight
                                aria-hidden="true"
                                class="transition-transform duration-300 group-hover/cta:translate-x-0.5"
                            />
                        </Link>
                    </Button>
                    <slot name="secondary" />
                </div>
                <ul
                    v-if="chips.length"
                    class="mt-7 flex flex-wrap gap-x-5 gap-y-2 text-sm text-slate-600"
                >
                    <li
                        v-for="chip in chips"
                        :key="chip"
                        class="inline-flex items-center gap-1.5"
                    >
                        <Check
                            aria-hidden="true"
                            class="size-4 text-emerald-600"
                        />
                        {{ chip }}
                    </li>
                </ul>
            </div>

            <div class="relative min-w-0 lg:min-h-0">
                <img
                    :src="image.src"
                    :alt="image.alt"
                    width="1600"
                    height="1067"
                    fetchpriority="high"
                    decoding="async"
                    class="h-64 w-full object-cover sm:h-80 lg:absolute lg:inset-0 lg:h-full"
                />
                <!-- Whatever floats over the photo: a card with the product,
                     or nothing. Kept out of this component so each page can
                     say something different, or say nothing. -->
                <slot name="figure" />
            </div>
        </div>
    </section>
</template>
