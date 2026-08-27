<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight, Check } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { register } from '@/routes';
import { edit as planSettings } from '@/routes/settings/plan';
import {
    FOUNDER,
    FOUNDER_MONTHLY_EQUIVALENT,
    FOUNDER_PRICE,
    PRO_PRICE_PER_YEAR,
} from './commercial';
import RevealOnScroll from './RevealOnScroll.vue';

/**
 * The launch condition on LÁPIS Pro — NOT a fourth plan, and shaped so it
 * cannot be mistaken for one.
 *
 * It is a band, not a card: it sits inside the Planos section, directly under
 * the three cards, and it never repeats the Pro feature list. What it shows is
 * one price crossed out, one price in force, and the two limits that end it.
 * Reading it should leave a visitor with «same Pro, cheaper for the first
 * 250», which is exactly what it is.
 *
 * THERE IS NO COUNTER. Nothing in the product records how many Fundador
 * places have been taken, so nothing here claims a number: no «restam 37», no
 * progress bar, no countdown. An invented scarcity number is a lie that
 * happens to be easy to write, and this page has spent its whole existence
 * refusing to invent figures (see LandingBenefits on hours saved). If a real
 * count ever exists server-side, it can be added here — and only then.
 *
 * THE REGISTER IS ALSO WHERE THIS GOES. There is no checkout; creating an
 * account is the real first step, and the condition is settled from there.
 */

const props = defineProps<{ authenticated: boolean }>();

const href = () => (props.authenticated ? planSettings() : register());
</script>

<template>
    <RevealOnScroll :delay="120">
        <aside
            id="fundadores"
            class="mt-6 scroll-mt-[4.5rem] overflow-hidden rounded-2xl border border-primary/40 bg-accent/60 dark:border-(--brand-amber)/35 dark:bg-(--brand-amber)/8"
            aria-labelledby="fundadores-title"
        >
            <div
                class="grid gap-8 p-7 sm:p-9 lg:grid-cols-[minmax(0,1.25fr)_minmax(0,1fr)] lg:items-start lg:gap-12"
            >
                <div class="min-w-0">
                    <p
                        class="inline-flex rounded-full bg-primary px-3 py-1 text-[11px] font-semibold tracking-[0.08em] text-primary-foreground uppercase dark:bg-(--brand-amber) dark:text-[#33200f]"
                    >
                        {{ FOUNDER.badge }}
                    </p>

                    <h3
                        id="fundadores-title"
                        class="mt-4 text-2xl font-semibold tracking-tight text-balance sm:text-3xl"
                    >
                        {{ FOUNDER.title }}
                    </h3>

                    <p class="mt-3 leading-relaxed text-pretty">
                        {{ FOUNDER.body }}
                    </p>

                    <p
                        class="mt-2 text-sm leading-relaxed text-pretty text-muted-foreground"
                    >
                        {{ FOUNDER.clarification }}
                    </p>

                    <ul class="mt-6 space-y-2 text-sm">
                        <li
                            v-for="benefit in FOUNDER.benefits"
                            :key="benefit"
                            class="flex gap-2.5"
                        >
                            <Check
                                aria-hidden="true"
                                class="mt-0.5 size-3.5 shrink-0 text-primary dark:text-(--brand-amber)"
                            />
                            <span class="text-pretty">{{ benefit }}</span>
                        </li>
                    </ul>

                    <p
                        class="mt-6 text-base font-semibold tracking-tight text-balance"
                    >
                        {{ FOUNDER.closing }}
                    </p>
                </div>

                <div
                    class="min-w-0 rounded-xl border border-border/70 bg-card p-6 shadow-sm"
                >
                    <p
                        class="text-[11px] font-semibold tracking-[0.1em] text-muted-foreground uppercase"
                    >
                        Condição Fundador
                    </p>

                    <p
                        class="mt-2 flex flex-wrap items-baseline gap-x-2 gap-y-1"
                    >
                        <span
                            class="text-4xl font-semibold tracking-tight text-primary dark:text-(--brand-amber)"
                            >{{ FOUNDER_PRICE }}</span
                        >
                        <span class="text-sm text-muted-foreground">/ ano</span>
                    </p>

                    <p class="mt-1.5 text-sm text-muted-foreground">
                        em vez de
                        <s class="decoration-muted-foreground/60">{{
                            PRO_PRICE_PER_YEAR
                        }}</s>
                    </p>

                    <p class="mt-1 text-xs text-muted-foreground italic">
                        {{ FOUNDER_MONTHLY_EQUIVALENT }}
                    </p>

                    <p
                        class="mt-4 border-t border-border/70 pt-4 text-xs leading-relaxed text-pretty text-muted-foreground"
                    >
                        {{ FOUNDER.eligibility }}
                    </p>

                    <Button as-child class="group/cta mt-5 w-full">
                        <Link :href="href()">
                            {{ FOUNDER.cta }}
                            <ArrowRight
                                aria-hidden="true"
                                class="transition-transform duration-300 group-hover/cta:translate-x-0.5"
                            />
                        </Link>
                    </Button>

                    <p class="mt-3 text-xs text-muted-foreground">
                        Subscrição anual. Não existe pagamento mensal.
                    </p>
                </div>
            </div>
        </aside>
    </RevealOnScroll>
</template>
