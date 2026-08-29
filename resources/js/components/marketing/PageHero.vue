<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight } from '@lucide/vue';
import { LANDING_PRIMARY } from '@/components/landing/chrome';
import { Button } from '@/components/ui/button';
import { dashboard, register } from '@/routes';

/**
 * The first screen of a public page: a big claim, a photograph, one action.
 *
 * THE PHOTOGRAPH IS THE POINT. The old landing had no image at all and read
 * as a document; a person's hands over a notebook says «this is for you»
 * before a single line is read. The photographs are generated, editorial,
 * and never show a recognisable face — the audience teaches minors, and a
 * stock face on a page about student data reads as exactly the wrong thing.
 */

withDefaults(
    defineProps<{
        eyebrow?: string;
        title: string;
        lead: string;
        image: { src: string; alt: string };
        authenticated: boolean;
        cta?: string;
        /** Which way the photo's rounded corner leans, for variety across pages. */
        tone?: 'amber' | 'blue' | 'emerald';
    }>(),
    { eyebrow: undefined, cta: 'Experimentar Lapispro', tone: 'amber' },
);

const BLOB: Record<'amber' | 'blue' | 'emerald', string> = {
    amber: 'bg-amber-200/70',
    blue: 'bg-blue-200/70',
    emerald: 'bg-emerald-200/70',
};
</script>

<template>
    <section class="relative overflow-hidden">
        <div
            class="mx-auto grid w-full max-w-6xl items-center gap-12 px-6 pt-12 pb-16 sm:px-8 sm:pt-16 sm:pb-24 lg:grid-cols-[minmax(0,1.05fr)_minmax(0,1fr)] lg:gap-16"
        >
            <div class="min-w-0">
                <p
                    v-if="eyebrow"
                    class="inline-flex rounded-full bg-blue-50 px-3.5 py-1.5 text-[12px] font-semibold tracking-[0.08em] text-blue-700 uppercase"
                >
                    {{ eyebrow }}
                </p>
                <h1
                    class="mt-6 text-4xl font-semibold tracking-tight text-balance sm:text-5xl lg:text-[3.6rem] lg:leading-[1.04]"
                >
                    {{ title }}
                </h1>
                <p
                    class="mt-6 max-w-xl text-lg leading-relaxed text-pretty text-muted-foreground sm:text-xl"
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
                <p class="mt-5 text-sm text-muted-foreground">
                    Sem cartão. O plano
                    <span class="font-medium text-foreground">Base</span> fica
                    ativo de imediato.
                </p>
            </div>

            <div class="relative min-w-0">
                <span
                    aria-hidden="true"
                    class="absolute -top-10 -right-10 -z-10 size-64 rounded-full blur-3xl sm:size-80"
                    :class="BLOB[tone]"
                />
                <img
                    :src="image.src"
                    :alt="image.alt"
                    width="1600"
                    height="1067"
                    fetchpriority="high"
                    decoding="async"
                    class="aspect-[3/2] w-full rounded-[2rem] object-cover shadow-[0_30px_80px_-40px_rgba(15,23,42,0.45)]"
                />
                <slot name="figure" />
            </div>
        </div>
    </section>
</template>
