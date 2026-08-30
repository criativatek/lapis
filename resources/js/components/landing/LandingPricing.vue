<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight, Check } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { dashboard, register } from '@/routes';
import { create as checkout } from '@/routes/settings/checkout';
import { LANDING_PRIMARY } from './chrome';
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
 * WHERE THE BUTTONS GO, and none of them takes money on this page. There is a
 * checkout since 0.82.0, but it lives behind the account: it collects billing
 * details and hands out an IBAN and a reference, and a person confirms the
 * transfer afterwards.
 *
 * A VISITOR WITHOUT AN ACCOUNT goes to the registration form — which really
 * does create an organization on the Base plan, with a voluntary 30-day Pro
 * trial inside. Not out of dogma: the subscription attaches to an organization
 * and the invoice needs a name, a NIF and an address, none of which exists
 * before there is an account.
 *
 * A SIGNED-IN VISITOR who presses «Escolher Pro» has already decided, and goes
 * straight to the checkout. Sending them to the plan screen would make them
 * find the same button a second time.
 *
 * «Falar connosco» is a mailto to the address the operator configured; with no
 * address configured the button is not rendered at all, rather than pointing
 * somewhere nobody reads.
 */

const props = defineProps<{
    plans: LandingPlan[];
    authenticated: boolean;
    contactEmail: string | null;
    /** On /planos the page hero already says it; no second heading. */
    headless?: boolean;
    /**
     * Enquanto a condição de lançamento estiver aberta. Fechada — por lugares
     * ou por prazo — o distintivo e a banda desaparecem: continuar a oferecer
     * 29,90 € a quem já não os pode ter era a única frase materialmente falsa
     * que esta página estava programada para dizer.
     */
    founderOpen: boolean;
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

/** Where the two self-service calls to action go. */
function visitHref(plan: LandingPlan) {
    if (!props.authenticated) {
        // Conta primeiro, e não por dogma: a subscrição prende-se a uma
        // organização e a fatura precisa de nome, NIF e morada — nada disso
        // existe antes de haver conta. E o Base é gratuito, com 30 dias de Pro
        // à experiência: o caminho natural é experimentar e depois subir.
        //
        // MAS O DESTINO É O CHECKOUT, não o registo. Apontar para o destino
        // verdadeiro faz o `Authenticate` guardá-lo em sessão, e a pessoa é
        // devolvida a ele depois de entrar ou de confirmar o email (ver
        // App\Http\Responses\LoginResponse). Mandá-la para o registo perdia a
        // razão que a trouxe: entrava, aterrava no painel, e ficava a
        // procurar onde é que se subscreve.
        return plan.key === 'pro' ? checkout() : register();
    }

    // Quem já tem sessão e carrega em «Escolher Pro» vem decidido. Levá-lo à
    // página do plano obrigava-o a encontrar lá o botão outra vez; vai direito
    // ao checkout, que é o que ele pediu ao clicar.
    return plan.key === 'pro' ? checkout() : dashboard();
}
</script>

<template>
    <LandingSection
        id="planos"
        :eyebrow="headless ? undefined : 'Planos'"
        :title="
            headless ? undefined : 'Um Lapispro para cada forma de trabalhar.'
        "
        :lead="
            headless
                ? undefined
                : 'Menos peso administrativo. Mais espaço para ser professor. O que muda entre os planos não é a qualidade do que faz — é até onde o Lapispro o acompanha.'
        "
    >
        <h2 v-if="headless" class="sr-only">Os planos</h2>
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
                            class="mt-1.5 text-[13px] leading-relaxed text-pretty text-muted-foreground"
                            :class="isPro(plan) ? 'italic' : undefined"
                        >
                            {{ copyFor(plan).priceNote }}
                        </p>
                        <p
                            v-if="copyFor(plan).priceFootnote"
                            class="mt-1 text-[13px] leading-relaxed text-pretty text-muted-foreground/80"
                        >
                            {{ copyFor(plan).priceFootnote }}
                        </p>
                    </div>

                    <Button
                        v-if="hasAction(plan)"
                        as-child
                        class="group/cta mt-5 w-full"
                        :class="isPro(plan) ? LANDING_PRIMARY : undefined"
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
                        v-if="isPro(plan) && founderOpen"
                        href="#fundadores"
                        class="mt-3 block rounded-lg border border-blue-200 bg-blue-50 px-3.5 py-2.5 text-[13px] leading-relaxed transition-colors hover:bg-blue-100 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none dark:border-(--brand-amber)/30 dark:bg-(--brand-amber)/10 dark:hover:bg-(--brand-amber)/15"
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

        <LandingFounder v-if="founderOpen" :authenticated="authenticated" />
    </LandingSection>
</template>
