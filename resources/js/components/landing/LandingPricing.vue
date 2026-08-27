<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight, Check } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { dashboard, register } from '@/routes';
import { edit as planSettings } from '@/routes/settings/plan';
import {
    FALLBACK_PLAN,
    FOUNDER,
    FOUNDER_PRICE_PER_YEAR,
    PLAN_COPY,
} from './commercial';
import type { PlanCommercial } from './commercial';
import LandingFounder from './LandingFounder.vue';
import LandingSection from './LandingSection.vue';
import RevealOnScroll from './RevealOnScroll.vue';
import type { LandingPlan } from './types';

/**
 * The three plans, and nothing else. Base, Pro, Institucional — there is no
 * fourth card, and the Fundador condition below is deliberately NOT one: it
 * is a launch price on the same Pro plan, so it is attached to that card
 * (the strip at its foot) and expanded in a band underneath, never presented
 * as a plan a visitor could choose instead of Pro.
 *
 * THE ORDER AND THE NAMES COME FROM THE DATABASE, the prices and the copy
 * from `commercial.ts`. `plan_module` is data on purpose (§4.3), so which
 * capabilities a plan carries is never typed into a component — see
 * LandingCompare, which derives every ✓ from `moduleKeys`.
 *
 * PRO CARRIES A LIGHT VISUAL LEAD, and no invented label. There is no «mais
 * popular» badge: nobody has counted, and a badge that claims a fact nobody
 * measured is the kind of thing a teacher who has been sold software before
 * notices immediately. The lead is a border, a ring and one line of copy.
 *
 * WHERE THE BUTTONS GO. Nothing on this page sells: there is no checkout, and
 * payments are on the ask-first list (CLAUDE.md §31). «Começar gratuitamente»
 * and «Escolher Pro» both open the registration form — which really does
 * create an organization on the Base plan, with a voluntary 30-day Pro trial
 * available inside — and a signed-in visitor is sent to their own plan screen
 * instead of registering twice. «Falar connosco» is a mailto to the address
 * the operator configured; with no address configured the button is not
 * rendered at all, rather than pointing somewhere nobody reads.
 */

const props = defineProps<{
    plans: LandingPlan[];
    authenticated: boolean;
    contactEmail: string | null;
}>();

const copyFor = (plan: LandingPlan): PlanCommercial =>
    PLAN_COPY[plan.key] ?? FALLBACK_PLAN;

const isPro = (plan: LandingPlan): boolean => plan.key === 'pro';

/** True when the card's action leaves the app, so it is an <a>, not a <Link>. */
function isExternal(plan: LandingPlan): boolean {
    return plan.key === 'institutional';
}

/** Whether this card shows a button at all. */
function hasAction(plan: LandingPlan): boolean {
    return !isExternal(plan) || props.contactEmail !== null;
}

/** «Falar connosco» — only ever called once `hasAction` has said there is one. */
function mailtoHref(plan: LandingPlan): string {
    const subject = `${plan.name} — pedido de informação`;

    return `mailto:${props.contactEmail}?subject=${encodeURIComponent(subject)}`;
}

/** Where the two self-service calls to action go. There is no checkout. */
function visitHref(plan: LandingPlan) {
    if (!props.authenticated) {
        return register();
    }

    return plan.key === 'pro' ? planSettings() : dashboard();
}
</script>

<template>
    <LandingSection
        id="planos"
        eyebrow="Planos"
        title="Um Lapispro para cada forma de trabalhar."
        lead="Menos peso administrativo. Mais espaço para ser professor. O que muda entre os planos não é a qualidade do que faz — é até onde o Lapispro o acompanha."
    >
        <div v-if="plans.length" class="grid gap-5 lg:grid-cols-3">
            <RevealOnScroll
                v-for="(plan, index) in plans"
                :key="plan.key"
                :delay="index * 90"
                class="h-full"
            >
                <article
                    class="group flex h-full flex-col rounded-2xl border bg-card p-7 shadow-sm transition-[transform,box-shadow,border-color] duration-500 ease-out hover:shadow-xl motion-safe:hover:-translate-y-1.5"
                    :class="
                        isPro(plan)
                            ? 'border-primary/60 ring-1 ring-primary/25 dark:border-(--brand-amber)/50 dark:ring-(--brand-amber)/20'
                            : 'border-border/70'
                    "
                >
                    <h3 class="font-semibold tracking-tight">
                        {{ plan.name }}
                    </h3>

                    <p
                        class="mt-3 text-[17px] leading-snug font-semibold tracking-tight text-balance"
                    >
                        {{ copyFor(plan).headline }}
                    </p>

                    <p
                        class="mt-2.5 text-sm leading-relaxed text-pretty text-muted-foreground"
                    >
                        {{ copyFor(plan).body }}
                    </p>

                    <!-- The price block. Fixed order everywhere: the lead line
                         when the figure needs context, the figure, the note. -->
                    <div class="mt-6 border-t border-border/70 pt-5">
                        <p
                            v-if="copyFor(plan).priceLead"
                            class="text-[11px] font-semibold tracking-[0.1em] text-primary uppercase dark:text-(--brand-amber)"
                        >
                            {{ copyFor(plan).priceLead }}
                        </p>
                        <p
                            class="mt-1.5 flex flex-wrap items-baseline gap-x-1.5 text-3xl font-semibold tracking-tight text-balance"
                        >
                            {{ copyFor(plan).price }}
                            <span
                                v-if="copyFor(plan).priceUnit"
                                class="text-sm font-normal text-muted-foreground"
                                >{{ copyFor(plan).priceUnit }}</span
                            >
                        </p>
                        <p
                            class="mt-1.5 text-xs leading-relaxed text-pretty text-muted-foreground"
                            :class="isPro(plan) ? 'italic' : undefined"
                        >
                            {{ copyFor(plan).priceNote }}
                        </p>
                        <p
                            v-if="copyFor(plan).priceFootnote"
                            class="mt-1 text-xs leading-relaxed text-pretty text-muted-foreground/80"
                        >
                            {{ copyFor(plan).priceFootnote }}
                        </p>
                    </div>

                    <Button
                        v-if="hasAction(plan)"
                        as-child
                        class="group/cta mt-5 w-full"
                        :variant="isPro(plan) ? 'default' : 'outline'"
                    >
                        <a v-if="isExternal(plan)" :href="mailtoHref(plan)">
                            {{ copyFor(plan).cta }}
                            <ArrowRight
                                aria-hidden="true"
                                class="transition-transform duration-300 group-hover/cta:translate-x-0.5"
                            />
                        </a>
                        <Link v-else :href="visitHref(plan)">
                            {{ copyFor(plan).cta }}
                            <ArrowRight
                                aria-hidden="true"
                                class="transition-transform duration-300 group-hover/cta:translate-x-0.5"
                            />
                        </Link>
                    </Button>

                    <!-- The Fundador condition, said on the Pro card itself so
                         nobody has to scroll to learn that 44,90 € is not the
                         only figure. The band below carries the detail. -->
                    <a
                        v-if="isPro(plan)"
                        href="#fundadores"
                        class="mt-3 block rounded-lg border border-primary/30 bg-accent/70 px-3.5 py-2.5 text-xs leading-relaxed transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none dark:border-(--brand-amber)/30 dark:bg-(--brand-amber)/10 dark:hover:bg-(--brand-amber)/15"
                    >
                        <span
                            class="font-semibold tracking-tight text-accent-foreground dark:text-(--brand-amber)"
                        >
                            {{ FOUNDER.badge }}
                        </span>
                        <span class="mt-0.5 block text-muted-foreground">
                            {{ FOUNDER_PRICE_PER_YEAR }} para os primeiros 250
                            professores. Mesmo plano, mesma aplicação.
                        </span>
                    </a>

                    <p
                        class="mt-6 border-t border-border/70 pt-4 text-[11px] font-semibold tracking-[0.1em] text-muted-foreground uppercase"
                    >
                        {{
                            index === 0
                                ? 'Inclui'
                                : `Tudo do ${plans[index - 1].name}, mais`
                        }}
                    </p>

                    <ul class="mt-3 space-y-1.5 text-sm">
                        <li
                            v-for="feature in copyFor(plan).features"
                            :key="feature"
                            class="flex gap-2.5"
                        >
                            <Check
                                aria-hidden="true"
                                class="mt-0.5 size-3.5 shrink-0 text-primary transition-transform duration-500 group-hover:scale-110 dark:text-(--brand-amber)"
                            />
                            <span class="text-pretty text-muted-foreground">{{
                                feature
                            }}</span>
                        </li>
                    </ul>

                    <p
                        class="mt-6 border-t border-border/70 pt-4 text-sm font-medium tracking-tight text-balance"
                    >
                        {{ copyFor(plan).boundary }}
                    </p>
                </article>
            </RevealOnScroll>
        </div>

        <LandingFounder :authenticated="authenticated" />
    </LandingSection>
</template>
