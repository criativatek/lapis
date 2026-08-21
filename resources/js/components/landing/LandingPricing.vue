<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Check, ChevronDown } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { register } from '@/routes';
import LandingSection from './LandingSection.vue';
import RevealOnScroll from './RevealOnScroll.vue';
import type { LandingPlan } from './types';

/**
 * The plan cards, built from the entitlement tables.
 *
 * NO PRICE IS SHOWN, because none is defined anywhere in the product — the
 * commercial composition of the plans is a business decision that is explicitly
 * not ours to invent (CLAUDE.md §31). What each plan CARRIES is real and comes
 * from the database, so the cards are honest about the part that is decided.
 *
 * The Base line is not a placeholder: registering creates a personal
 * organization already subscribed to Base, and nothing asks for payment.
 */

const props = defineProps<{ plans: LandingPlan[] }>();

type PlanCopy = {
    audience: string;
    price: string;
    priceNote: string;
    recommended?: boolean;
};

const COPY: Record<string, PlanCopy> = {
    base: {
        audience: 'Todas as suas turmas num só sítio.',
        price: 'Incluído ao criar conta',
        priceNote: 'Não é pedido cartão.',
    },
    pro: {
        audience: 'Importar, exportar e escrever mais depressa.',
        price: 'Preço por anunciar',
        priceNote: 'Comece no Base. Mudar de plano não obriga a recomeçar.',
        recommended: true,
    },
    institutional: {
        audience: 'Para escolas e agrupamentos que trabalham em equipa.',
        price: 'Preço por anunciar',
        priceNote: 'Convites, membros e auditoria.',
    },
};

const FALLBACK: PlanCopy = {
    audience: 'Um plano do LÁPIS.',
    price: 'Preço por anunciar',
    priceNote: 'Ver a comparação completa abaixo.',
};

/**
 * A card lists at most this many modules.
 *
 * The Base plan carries fourteen, which made a card taller than a laptop
 * screen — and a pricing section a visitor has to scroll through twice is a
 * pricing section that gets skipped. The remainder is counted, and the full
 * table is one click below.
 */
const SHOWN_PER_CARD = 6;

/** What a card lists: its own modules for the first plan, the additions after. */
function highlighted(plan: LandingPlan, index: number): string[] {
    return (index === 0 ? plan.modules : plan.adds).slice(0, SHOWN_PER_CARD);
}

function remaining(plan: LandingPlan, index: number): number {
    return Math.max(
        0,
        (index === 0 ? plan.modules : plan.adds).length - SHOWN_PER_CARD,
    );
}

const copyFor = (plan: LandingPlan): PlanCopy => COPY[plan.key] ?? FALLBACK;

/** Every module in the catalogue, in the order the largest plan lists them. */
const allModules = computed<string[]>(() => {
    const seen: string[] = [];

    for (const plan of props.plans) {
        for (const module of plan.modules) {
            if (!seen.includes(module)) {
                seen.push(module);
            }
        }
    }

    return seen;
});
</script>

<template>
    <LandingSection
        id="planos"
        eyebrow="Planos"
        title="Três planos, e a mesma aplicação por baixo."
        lead="O que muda é o que está ligado, não a qualidade do que faz."
    >
        <div v-if="plans.length" class="grid gap-5 lg:grid-cols-3">
            <RevealOnScroll
                v-for="(plan, index) in plans"
                :key="plan.key"
                :delay="index * 90"
            >
                <article
                    class="group flex h-full flex-col rounded-2xl border bg-card p-7 shadow-sm transition-[transform,box-shadow,border-color] duration-500 ease-out hover:shadow-xl motion-safe:hover:-translate-y-1.5"
                    :class="
                        copyFor(plan).recommended
                            ? 'border-primary/60 ring-1 ring-primary/25 dark:border-(--brand-amber)/50 dark:ring-(--brand-amber)/20'
                            : 'border-border/70'
                    "
                >
                    <div class="flex items-center gap-2">
                        <h3 class="font-semibold tracking-tight">
                            {{ plan.name }}
                        </h3>
                        <span
                            v-if="copyFor(plan).recommended"
                            class="rounded-full bg-primary px-2 py-0.5 text-[10px] font-semibold tracking-[0.08em] text-primary-foreground uppercase"
                        >
                            Recomendado
                        </span>
                    </div>

                    <p
                        class="mt-2 min-h-[2.5rem] text-sm leading-relaxed text-muted-foreground"
                    >
                        {{ copyFor(plan).audience }}
                    </p>

                    <p
                        class="mt-5 text-xl font-semibold tracking-tight text-balance"
                    >
                        {{ copyFor(plan).price }}
                    </p>
                    <p
                        class="mt-1.5 text-xs leading-relaxed text-muted-foreground"
                    >
                        {{ copyFor(plan).priceNote }}
                    </p>

                    <Button
                        as-child
                        class="mt-5 w-full"
                        :variant="
                            copyFor(plan).recommended ? 'default' : 'outline'
                        "
                    >
                        <Link :href="register()">Criar conta</Link>
                    </Button>

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
                            v-for="module in highlighted(plan, index)"
                            :key="module"
                            class="flex gap-2.5"
                        >
                            <Check
                                aria-hidden="true"
                                class="mt-0.5 size-3.5 shrink-0 text-primary transition-transform duration-500 group-hover:scale-110 dark:text-(--brand-amber)"
                            />
                            <span class="text-muted-foreground">{{
                                module
                            }}</span>
                        </li>
                        <li
                            v-if="remaining(plan, index) > 0"
                            class="pt-1 pl-6 text-xs text-muted-foreground/80"
                        >
                            e mais {{ remaining(plan, index) }} módulos
                        </li>
                    </ul>
                </article>
            </RevealOnScroll>
        </div>

        <RevealOnScroll v-if="plans.length">
            <details class="group mt-8">
                <summary
                    class="inline-flex cursor-pointer list-none items-center gap-2 rounded-md px-3 py-2 text-sm font-medium transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none [&::-webkit-details-marker]:hidden"
                >
                    <span class="text-muted-foreground group-open:hidden"
                        >Comparar os três planos lado a lado</span
                    >
                    <span class="hidden text-muted-foreground group-open:inline"
                        >Fechar a comparação</span
                    >
                    <ChevronDown
                        aria-hidden="true"
                        class="size-4 text-muted-foreground transition-transform group-open:rotate-180"
                    />
                </summary>

                <div
                    class="mt-4 overflow-x-auto rounded-2xl border border-border/70"
                >
                    <table class="w-full min-w-[32rem] text-left text-sm">
                        <caption class="sr-only">
                            Módulos incluídos em cada plano
                        </caption>
                        <thead
                            class="bg-muted/50 text-[11px] font-medium tracking-[0.06em] text-muted-foreground uppercase"
                        >
                            <tr>
                                <th scope="col" class="px-4 py-2.5 font-medium">
                                    Módulo
                                </th>
                                <th
                                    v-for="plan in plans"
                                    :key="plan.key"
                                    scope="col"
                                    class="px-3 py-2.5 text-center font-medium whitespace-nowrap"
                                >
                                    {{ plan.name.replace('LÁPIS ', '') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr
                                v-for="module in allModules"
                                :key="module"
                                class="transition-colors duration-300 hover:bg-muted/40"
                            >
                                <th
                                    scope="row"
                                    class="px-4 py-2.5 text-left font-normal"
                                >
                                    {{ module }}
                                </th>
                                <td
                                    v-for="plan in plans"
                                    :key="plan.key"
                                    class="px-3 py-2.5 text-center"
                                >
                                    <Check
                                        v-if="plan.modules.includes(module)"
                                        aria-hidden="true"
                                        class="mx-auto size-4 text-emerald-600 dark:text-emerald-400"
                                    />
                                    <span
                                        v-else
                                        aria-hidden="true"
                                        class="text-muted-foreground/50"
                                        >—</span
                                    >
                                    <span class="sr-only">{{
                                        plan.modules.includes(module)
                                            ? 'incluído'
                                            : 'não incluído'
                                    }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </details>
        </RevealOnScroll>
    </LandingSection>
</template>
