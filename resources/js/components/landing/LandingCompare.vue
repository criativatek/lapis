<script setup lang="ts">
import { Check, ChevronDown, Minus } from '@lucide/vue';
import {
    availability,
    BASE_ACTIVE_CLASSES,
    BASE_ACTIVE_STUDENTS,
    BOUNDARIES,
    COMPARE_ROWS,
} from './commercial';
import type { CompareRow, RowAvailability } from './commercial';
import LandingSection from './LandingSection.vue';
import RevealOnScroll from './RevealOnScroll.vue';
import type { LandingPlan } from './types';

/**
 * «Compare os planos» — a synthesis, not the whole matrix.
 *
 * EVERY MARK IS DERIVED, NOT TYPED. A row names the entitlement keys it
 * needs; the plan says whether it carries them (`moduleKeys`, straight from
 * `plan_module`). Move `advanced_analytics` from Pro to Base in the seeder
 * and this table follows on the next request, with nobody remembering to edit
 * it. That is the whole reason HomeController sends keys.
 *
 * THREE STATES, NOT TWO. A row the commercial offer places in a plan that the
 * product does not implement yet reads «Em preparação», never ✓ — listing it
 * would misrepresent the offer, and ticking it would misrepresent the
 * product. See COMPARE_ROWS for which ones those are and why.
 *
 * TWO RENDERINGS, ONE DATA SOURCE. A 27-row × 3-column grid is a table on a
 * laptop and an unusable smear on a phone, so the small screen gets one
 * native <details> per plan instead — same rows, same marks, read down
 * instead of across. Native <details> for the same reasons the FAQ uses it:
 * it opens without JavaScript, it is keyboard-operable and announced for
 * free, and the browser's own find-in-page can open a closed one.
 */

const props = defineProps<{ plans: LandingPlan[] }>();

const shortName = (plan: LandingPlan): string =>
    plan.name.replace('Lapispro ', '');

function markFor(row: CompareRow, plan: LandingPlan): RowAvailability {
    return availability(row, plan.key, plan.moduleKeys);
}

const LABELS: Record<RowAvailability, string> = {
    included: 'incluído',
    planned: 'em preparação',
    absent: 'não incluído',
};

/** Whether any row anywhere is «em preparação», so the legend earns its space. */
const hasPlanned = () =>
    props.plans.some((plan) =>
        COMPARE_ROWS.some((row) => markFor(row, plan) === 'planned'),
    );
</script>

<template>
    <LandingSection
        v-if="plans.length"
        id="comparar"
        tinted
        eyebrow="Comparação"
        title="Compare os planos."
        lead="Uma síntese do que muda de plano para plano. Dentro da aplicação, cada funcionalidade é verificada no servidor — esconder um botão nunca é o controlo de acesso."
    >
        <!-- Desktop: the table. Its own horizontal scroll container, so a
             narrow laptop scrolls the table and never the page body. -->
        <RevealOnScroll class="hidden md:block">
            <div
                class="overflow-x-auto rounded-2xl border border-border/70 bg-card"
            >
                <table class="w-full min-w-[40rem] text-left text-sm">
                    <caption class="sr-only">
                        Funcionalidades incluídas em cada plano do Lapispro
                    </caption>
                    <thead
                        class="bg-muted/50 text-[11px] font-medium tracking-[0.06em] text-muted-foreground uppercase"
                    >
                        <tr>
                            <th scope="col" class="px-4 py-3 font-medium">
                                Funcionalidade
                            </th>
                            <th
                                v-for="plan in plans"
                                :key="plan.key"
                                scope="col"
                                class="w-[7.5rem] px-3 py-3 text-center font-medium whitespace-nowrap"
                                :class="
                                    plan.key === 'pro'
                                        ? 'text-primary dark:text-(--brand-amber)'
                                        : undefined
                                "
                            >
                                {{ shortName(plan) }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr
                            v-for="row in COMPARE_ROWS"
                            :key="row.label"
                            class="transition-colors duration-300 hover:bg-muted/40"
                        >
                            <th
                                scope="row"
                                class="px-4 py-2.5 text-left font-normal text-pretty"
                            >
                                {{ row.label }}
                            </th>
                            <td
                                v-for="plan in plans"
                                :key="plan.key"
                                class="px-3 py-2.5 text-center align-middle"
                            >
                                <Check
                                    v-if="markFor(row, plan) === 'included'"
                                    aria-hidden="true"
                                    class="mx-auto size-4 text-emerald-600 dark:text-emerald-400"
                                />
                                <span
                                    v-else-if="markFor(row, plan) === 'planned'"
                                    aria-hidden="true"
                                    class="inline-block rounded-full bg-muted px-2 py-0.5 text-[10px] font-medium tracking-wide text-muted-foreground"
                                    >Em preparação</span
                                >
                                <Minus
                                    v-else
                                    aria-hidden="true"
                                    class="mx-auto size-3.5 text-muted-foreground/50"
                                />
                                <span class="sr-only">{{
                                    LABELS[markFor(row, plan)]
                                }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </RevealOnScroll>

        <!-- Mobile: one collapsible list per plan. -->
        <div class="space-y-3 md:hidden">
            <RevealOnScroll
                v-for="(plan, index) in plans"
                :key="plan.key"
                :delay="index * 70"
            >
                <details
                    class="group rounded-2xl border bg-card"
                    :class="
                        plan.key === 'pro'
                            ? 'border-primary/50 dark:border-(--brand-amber)/40'
                            : 'border-border/70'
                    "
                    :open="plan.key === 'pro'"
                >
                    <summary
                        class="flex cursor-pointer list-none items-center justify-between gap-3 rounded-2xl px-5 py-4 font-semibold tracking-tight focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none [&::-webkit-details-marker]:hidden"
                    >
                        {{ plan.name }}
                        <ChevronDown
                            aria-hidden="true"
                            class="size-4 shrink-0 text-muted-foreground transition-transform group-open:rotate-180"
                        />
                    </summary>

                    <ul
                        class="space-y-2 border-t border-border/70 px-5 py-4 text-sm"
                    >
                        <li
                            v-for="row in COMPARE_ROWS"
                            :key="row.label"
                            class="flex items-start gap-2.5"
                            :class="
                                markFor(row, plan) === 'absent'
                                    ? 'text-muted-foreground/60'
                                    : undefined
                            "
                        >
                            <Check
                                v-if="markFor(row, plan) === 'included'"
                                aria-hidden="true"
                                class="mt-0.5 size-3.5 shrink-0 text-emerald-600 dark:text-emerald-400"
                            />
                            <Minus
                                v-else
                                aria-hidden="true"
                                class="mt-0.5 size-3.5 shrink-0 text-muted-foreground/50"
                            />
                            <span class="min-w-0 text-pretty">
                                {{ row.label }}
                                <span
                                    v-if="markFor(row, plan) === 'planned'"
                                    class="ml-1 rounded-full bg-muted px-1.5 py-0.5 text-[10px] font-medium tracking-wide text-muted-foreground"
                                    >Em preparação</span
                                >
                            </span>
                            <span class="sr-only">{{
                                LABELS[markFor(row, plan)]
                            }}</span>
                        </li>
                    </ul>
                </details>
            </RevealOnScroll>
        </div>

        <RevealOnScroll>
            <div
                class="mt-6 space-y-2 text-xs leading-relaxed text-pretty text-muted-foreground"
            >
                <p v-if="hasPlanned()">
                    <strong class="font-medium text-foreground"
                        >Em preparação</strong
                    >
                    assinala o que está previsto no plano e ainda não está
                    disponível na aplicação. Nada aqui é apresentado como pronto
                    antes de o estar.
                </p>
                <p>
                    A exportação dos seus próprios dados existe em todos os
                    planos e não tem linha nesta tabela porque não depende do
                    plano. O que a tabela assinala é a outra metade: repor uma
                    cópia de segurança completa e guardar o histórico dos
                    backups.
                </p>
                <p>
                    O plano Base inclui até {{ BASE_ACTIVE_CLASSES }} turmas e
                    {{ BASE_ACTIVE_STUDENTS }} alunos ativos. Turmas e alunos
                    arquivados não contam para estes limites.
                </p>
                <p>
                    A IA do Lapispro sugere e reescreve texto — propõe
                    estratégias e ajuda a aperfeiçoar a redação de um relatório.
                    Não atribui nem decide classificações. O Lapispro organiza,
                    calcula e acompanha. O professor observa, decide e ensina.
                </p>
            </div>
        </RevealOnScroll>

        <!-- The three sentences. They are the argument the whole table exists
             to support, so they get the hierarchy, not a footnote. -->
        <RevealOnScroll>
            <div class="mt-12 sm:mt-16">
                <h3
                    class="text-[11px] font-semibold tracking-[0.14em] text-muted-foreground uppercase"
                >
                    A diferença em três frases
                </h3>
                <dl class="mt-5 grid gap-6 sm:grid-cols-3 sm:gap-5">
                    <RevealOnScroll
                        v-for="(boundary, index) in BOUNDARIES"
                        :key="boundary.plan"
                        v-slot="{ shown }"
                        :delay="index * 90"
                    >
                        <div class="relative pt-5">
                            <span
                                aria-hidden="true"
                                class="absolute inset-x-0 top-0 block h-0.5 origin-left bg-border"
                            />
                            <span
                                aria-hidden="true"
                                class="absolute inset-x-0 top-0 block h-0.5 origin-left bg-primary/70 transition-transform delay-200 duration-700 ease-out dark:bg-(--brand-amber)/60"
                                :class="shown ? 'scale-x-100' : 'scale-x-0'"
                            />
                            <dt class="sr-only">{{ boundary.plan }}</dt>
                            <dd
                                class="text-xl font-semibold tracking-tight text-balance sm:text-2xl"
                            >
                                {{ boundary.sentence }}
                            </dd>
                        </div>
                    </RevealOnScroll>
                </dl>
            </div>
        </RevealOnScroll>
    </LandingSection>
</template>
