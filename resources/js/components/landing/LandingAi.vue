<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Check, Sparkles } from '@lucide/vue';
import LandingSection from './LandingSection.vue';
import RevealOnScroll from './RevealOnScroll.vue';

/**
 * Where the page says what its AI is, and — at least as importantly — what it
 * is not.
 *
 * THE WHOLE BAND IS BUILT AROUND ONE SENTENCE: «IA sugere. Professor
 * decide.» A teacher arriving from a search for «IA para professores» in 2026
 * has already been sold a text generator, and the useful thing this product
 * can say is that it is not one — it reads figures the application computed
 * deterministically and proposes wording and next steps a person accepts or
 * discards.
 *
 * EVERY CLAIM MAPS TO CODE. Reading results, trends, potentialities, the next
 * step and «preparar conversa» are `BuildStudentInsights` (advanced_analytics);
 * strategy proposals are `InterventionStrategySuggester` and report rewriting
 * is `ReportWritingAssistant` (both ai_assistance). Nothing here describes a
 * model touching a classification, because nothing in the application lets one
 * — see `ReportWritingAssistant`'s guard, which refuses text that comes back
 * with a number changed.
 */

const uses = [
    {
        title: 'Interpreta resultados que o Lapispro já calculou',
        body: 'Tendências, regularidade e o que mudou de um período para o seguinte — a partir dos números, nunca em vez deles.',
    },
    {
        title: 'Identifica pontos fortes e potencialidades',
        body: 'A margem de progressão de cada aluno, dita com a limitação assinalada quando os elementos não chegam para uma leitura.',
    },
    {
        title: 'Propõe estratégias e o próximo passo pedagógico',
        body: 'Uma sugestão estruturada que o professor adapta, substitui ou ignora. Nada é criado sem o seu gesto.',
    },
    {
        title: 'Aperfeiçoa a redação de um relatório',
        body: 'Reescreve o que o Lapispro já compôs. Números e datas saem como marcadores e um guarda recusa o que voltar alterado.',
    },
] as const;

/**
 * The three rules of the calculation, said next to the AI on purpose: they are
 * the same promise from two sides. The number is deterministic and the model
 * never touches it; the model reads, the teacher decides. Was its own section
 * («O cálculo») until 0.92.0.
 */
const rules = [
    {
        claim: 'Vazio nunca é zero.',
        body: 'Uma avaliação por preencher não conta como zero. O Lapispro distingue o que falta avaliar de uma classificação de zero.',
    },
    {
        claim: '«Não aplicável» fica fora do cálculo.',
        body: 'Se um critério, questão ou domínio não se aplica, sai do cálculo — o resultado usa só o que era avaliável.',
    },
    {
        claim: 'Quem chega mais tarde não é penalizado.',
        body: 'Um aluno que entra a meio do período é avaliado só com os elementos em que podia participar.',
    },
] as const;
</script>

<template>
    <LandingSection
        id="ia"
        eyebrow="Inteligência artificial"
        title="IA para professores. O professor mantém sempre a decisão final."
        lead="A IA pedagógica do Lapispro não escreve a avaliação por si. Lê a informação que já registou, ajuda a compreender o que ela pode significar e propõe um caminho — que o professor confirma, corrige ou descarta."
    >
        <div
            class="grid gap-10 lg:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)] lg:gap-16"
        >
            <dl class="grid gap-7 sm:grid-cols-2 sm:gap-x-8">
                <RevealOnScroll
                    v-for="(use, index) in uses"
                    :key="use.title"
                    :delay="index * 80"
                >
                    <div class="flex gap-3.5">
                        <span
                            aria-hidden="true"
                            class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-blue-100 text-blue-700"
                        >
                            <Check class="size-3" />
                        </span>
                        <div class="min-w-0">
                            <dt class="font-medium tracking-tight text-pretty">
                                {{ use.title }}
                            </dt>
                            <dd
                                class="mt-1 text-sm leading-relaxed text-pretty text-muted-foreground"
                            >
                                {{ use.body }}
                            </dd>
                        </div>
                    </div>
                </RevealOnScroll>
            </dl>

            <RevealOnScroll :delay="120" variant="right">
                <!-- A mock of `BuildStudentInsights` + the strategy suggester
                     as the teacher sees them: pseudonym, not a name — the AI
                     never receives one. Shows how it works instead of
                     explaining it. Demo text, fictional student. -->
                <div class="rounded-3xl bg-white p-5 card-soft sm:p-6">
                    <div
                        aria-hidden="true"
                        class="flex items-center justify-between gap-3"
                    >
                        <div class="flex items-center gap-2.5">
                            <span
                                class="flex size-8 items-center justify-center rounded-lg bg-blue-100 text-blue-700"
                            >
                                <Sparkles class="size-4" />
                            </span>
                            <div>
                                <p class="text-sm font-semibold text-slate-900">
                                    Sugestão de próximo passo
                                </p>
                                <p class="text-[11px] text-slate-500">
                                    Aluno A07 · Português · 2.º período
                                </p>
                            </div>
                        </div>
                        <span
                            class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold tracking-wide text-amber-800 uppercase"
                        >
                            Por confirmar
                        </span>
                    </div>
                    <div
                        aria-hidden="true"
                        class="mt-4 rounded-xl bg-slate-50 p-4 text-[13px] leading-relaxed text-slate-700"
                    >
                        <p>
                            Regularidade na oralidade (3,1 → 3,6) e leitura
                            estável. A escrita desceu no último instrumento
                            (2,9): dois elementos em quatro, leitura limitada.
                        </p>
                        <p class="mt-2 font-medium text-slate-900">
                            Proposta: reforço de planificação de texto antes do
                            próximo trabalho escrito.
                        </p>
                    </div>
                    <div aria-hidden="true" class="mt-4 flex flex-wrap gap-2">
                        <span
                            class="inline-flex h-8 items-center rounded-md bg-blue-600 px-3 text-[12px] font-medium text-white"
                        >
                            Aplicar sugestão
                        </span>
                        <span
                            class="inline-flex h-8 items-center rounded-md bg-white px-3 text-[12px] font-medium text-slate-700 ring-1 ring-slate-200"
                        >
                            Editar
                        </span>
                        <span
                            class="inline-flex h-8 items-center rounded-md px-3 text-[12px] font-medium text-slate-500"
                        >
                            Descartar
                        </span>
                    </div>
                    <p
                        class="mt-4 border-t border-slate-100 pt-3 text-[12px] leading-relaxed text-slate-500"
                    >
                        <span class="font-semibold text-slate-900"
                            >IA sugere. Professor decide.</span
                        >
                        Nenhuma classificação é atribuída ou alterada por um
                        modelo. A IA pedagógica faz parte do
                        <Link
                            href="/planos"
                            class="rounded font-medium text-slate-900 underline underline-offset-4 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >plano Pro</Link
                        >
                        e do Institucional.
                    </p>
                </div>
            </RevealOnScroll>
        </div>

        <RevealOnScroll>
            <h3
                class="mt-16 text-[11px] font-semibold tracking-[0.14em] text-blue-700 uppercase"
            >
                Três regras que uma folha de cálculo não trata sozinha
            </h3>
        </RevealOnScroll>
        <dl class="mt-5 grid gap-8 sm:grid-cols-3 sm:gap-6">
            <RevealOnScroll
                v-for="(rule, index) in rules"
                :key="rule.claim"
                :delay="index * 80"
            >
                <div class="border-t-2 border-blue-100 pt-5">
                    <dt
                        class="text-lg font-semibold tracking-tight text-balance"
                    >
                        {{ rule.claim }}
                    </dt>
                    <dd
                        class="mt-2 text-sm leading-relaxed text-muted-foreground"
                    >
                        {{ rule.body }}
                    </dd>
                </div>
            </RevealOnScroll>
        </dl>
    </LandingSection>
</template>
