<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight, Plus } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { register } from '@/routes';
import { LANDING_PRIMARY } from './chrome';
import {
    FOUNDER_DEADLINE,
    FOUNDER_PRICE_PER_YEAR,
    FOUNDER_SEATS,
    PRO_PRICE_PER_YEAR,
} from './commercial';
import RevealOnScroll from './RevealOnScroll.vue';

/**
 * Native <details>, not a scripted accordion: it opens without JavaScript, it
 * is keyboard-operable and screen-reader-announced for free, and the browser's
 * own in-page find can open a closed answer. A custom one would have to earn
 * all of that back.
 *
 * Every answer here is a promise about what the product does today. An answer
 * that would need a feature that does not exist says so instead.
 */
/**
 * THE FIGURES COME FROM `commercial.ts`, never from a literal typed here. An
 * answer quoting a price the plan cards no longer quote is worse than no
 * answer, and it is the kind of drift nobody notices until a reader does.
 *
 * The order is search intent, not product structure: what the thing IS, then
 * what it does, then what it costs, then the details somebody only asks once
 * they are interested. Every answer is about the product as it is today — one
 * that would need a feature that does not exist says so instead.
 */
const questions = [
    {
        question: 'O que é o Lapispro?',
        answer: 'É uma plataforma para professores que reúne a avaliação de alunos, a gestão de turmas, o acompanhamento pedagógico, as aulas, os sumários e os relatórios num único lugar — em vez de os espalhar por folhas de cálculo, documentos e cadernos.',
    },
    {
        question:
            'Posso usar os meus critérios de avaliação, com os meus nomes e os meus pesos?',
        answer: 'É o ponto de partida da aplicação: os domínios com os nomes da sua escola, a ponderação de cada um, os instrumentos de avaliação que entram e a escala em que a classificação é dada.',
    },
    {
        question: 'A IA decide as classificações?',
        answer: 'Não. Nenhuma classificação é atribuída, alterada ou decidida por um modelo. O cálculo é determinístico e explicável, o Lapispro propõe a partir do perfil e do que está registado, e a confirmação é sempre do professor. Se decidir diferente da proposta, a diferença e a razão ficam registadas.',
    },
    {
        question: 'Existe uma versão gratuita?',
        answer: 'O plano Base é gratuito no ano letivo 2026/27 e fica ativo assim que criar conta, sem cartão em passo nenhum. Dentro da aplicação pode ainda ativar, uma vez, um período experimental de 30 dias do plano Pro — no fim volta ao Base sem perder nada do que registou.',
    },
    {
        question: 'Quanto custa o plano Pro?',
        answer: `${PRO_PRICE_PER_YEAR}, em subscrição anual — não existe pagamento mensal. Os primeiros ${FOUNDER_SEATS} professores a aderirem podem beneficiar da condição Membro Fundador, ${FOUNDER_PRICE_PER_YEAR}, disponível até ${FOUNDER_DEADLINE} ou até esses lugares estarem preenchidos, consoante o que ocorrer primeiro. É o mesmo plano Pro, numa condição de adesão distinta.`,
    },
    {
        question: 'Existe uma solução para escolas e agrupamentos?',
        answer: 'Existe o plano Institucional, que acrescenta ao Pro a gestão de vários professores, os modelos e perfis de avaliação institucionais, a visão agregada e a governação — coordenação à escala da escola, sem retirar autonomia pedagógica a cada professor. Ainda não está disponível para adesão: falta fechar o enquadramento contratual com as escolas. Fale connosco e avisamos quando abrir.',
    },
    {
        question: 'Os dados dos meus alunos estão seguros?',
        answer: 'O nome e o número de processo ficam numa tabela separada e cifrada; o resto da aplicação usa um pseudónimo. Cada organização só acede ao que é seu, imposto no servidor. A conta protege-se com dois passos ou passkey.',
    },
    {
        question: 'Consigo tirar de lá o que lá pus?',
        answer: 'Relatórios de avaliação em PDF e Word, pautas e quadros-síntese, e grelhas de correção para imprimir. No plano Pro, também a exportação das menções de um período para a grelha do INOVAR. A exportação dos seus próprios dados existe em todos os planos.',
    },
];
</script>

<template>
    <section
        id="perguntas"
        class="scroll-mt-[4.5rem] border-t border-border/60 pt-12 pb-16 sm:py-24 lg:py-28"
        aria-labelledby="perguntas-title"
    >
        <div
            class="mx-auto grid w-full max-w-6xl gap-10 px-6 sm:px-8 lg:grid-cols-[minmax(0,0.72fr)_minmax(0,1.28fr)] lg:gap-16"
        >
            <RevealOnScroll class="min-w-0">
                <div class="lg:sticky lg:top-24">
                    <p
                        class="text-[12px] font-semibold tracking-[0.12em] text-blue-700 uppercase"
                    >
                        Perguntas
                    </p>
                    <h2
                        id="perguntas-title"
                        class="mt-3 text-3xl font-semibold tracking-[-0.02em] text-balance sm:text-4xl lg:text-[3rem] lg:leading-[1.08]"
                    >
                        O que perguntam primeiro.
                    </h2>
                    <p
                        class="mt-4 leading-relaxed text-pretty text-muted-foreground"
                    >
                        Respostas sobre o produto como ele está hoje — não sobre
                        o que está planeado.
                    </p>
                    <Button
                        as-child
                        class="group/cta mt-7"
                        :class="LANDING_PRIMARY"
                    >
                        <Link :href="register()">
                            Experimentar Lapispro
                            <ArrowRight
                                aria-hidden="true"
                                class="transition-transform duration-300 group-hover/cta:translate-x-0.5"
                            />
                        </Link>
                    </Button>
                </div>
            </RevealOnScroll>

            <div class="min-w-0 divide-y divide-border border-y border-border">
                <RevealOnScroll
                    v-for="(item, index) in questions"
                    :key="item.question"
                    :delay="Math.min(index, 4) * 50"
                >
                    <details class="group">
                        <summary
                            class="-mx-3 flex cursor-pointer list-none items-start gap-4 rounded-lg px-3 py-5 text-left transition-colors duration-300 hover:bg-background/70 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none dark:hover:bg-background/40 [&::-webkit-details-marker]:hidden"
                        >
                            <span
                                class="min-w-0 flex-1 font-medium tracking-tight text-pretty transition-colors group-hover:text-primary dark:group-hover:text-(--brand-amber)"
                            >
                                {{ item.question }}
                            </span>
                            <Plus
                                aria-hidden="true"
                                class="mt-0.5 size-4 shrink-0 text-muted-foreground transition-all duration-300 group-open:rotate-45 group-hover:text-primary dark:group-hover:text-(--brand-amber)"
                            />
                        </summary>
                        <p
                            class="pr-8 pb-5 text-sm leading-relaxed text-pretty text-muted-foreground"
                        >
                            {{ item.answer }}
                        </p>
                    </details>
                </RevealOnScroll>
            </div>
        </div>
    </section>
</template>
