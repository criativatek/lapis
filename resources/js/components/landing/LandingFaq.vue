<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight, Plus } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { register } from '@/routes';
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
const questions = [
    {
        question: 'Posso experimentar sem pagar?',
        answer: 'Pode. Criar conta dá-lhe uma organização própria já com o plano LÁPIS Base ativo, e não é pedido cartão em passo nenhum. Os preços dos planos Pro e Institucional ainda não foram anunciados.',
    },
    {
        question:
            'Posso usar os meus critérios, com os meus nomes e os meus pesos?',
        answer: 'É o ponto de partida da aplicação: os domínios com os nomes da sua escola, a ponderação de cada um, os elementos que entram e a escala em que a classificação é dada.',
    },
    {
        question: 'Funciona com períodos e com semestres?',
        answer: 'Sim. Um ano letivo pode ter períodos, semestres, trimestres ou módulos, e quantos precisar. Um ano de dois semestres não tem de ser convertido em três períodos.',
    },
    {
        question: 'Serve para o ensino superior?',
        answer: 'A estrutura serve — unidades curriculares, semestres, ponderações por componente. Mas o vocabulário e algumas exportações são do sistema português: é uma ferramenta de avaliação flexível, não uma plataforma académica.',
    },
    {
        question: 'Os dados dos meus alunos estão seguros?',
        answer: 'O nome e o número de processo ficam numa tabela separada e cifrada; o resto da aplicação usa um pseudónimo. Cada organização só acede ao que é seu, imposto no servidor. A conta protege-se com dois passos ou passkey.',
    },
    {
        question: 'Consigo tirar de lá o que lá pus?',
        answer: 'Relatórios em PDF e Word, pautas e quadros-síntese, e grelhas de correção para imprimir. No plano Pro, também a exportação das menções de um período para a grelha do INOVAR.',
    },
    {
        question: 'Há limite de turmas, de alunos ou de testes?',
        answer: 'Os planos atuais não definem limites de turmas, de alunos ou de elementos de avaliação. O que distingue os planos é quais os módulos que estão ligados.',
    },
    {
        question: 'Funciona no telemóvel?',
        answer: 'Funciona no browser do telemóvel e do tablet, sem instalar nada. As grelhas com muitas colunas continuam a pedir um ecrã maior — como aconteceria numa folha de cálculo.',
    },
    {
        question: 'Quem decide a classificação final?',
        answer: 'O professor, sempre. O LÁPIS calcula e propõe a partir do perfil e do que está registado; a confirmação é sua. Se decidir diferente da proposta, a diferença e a razão ficam registadas.',
    },
    {
        question: 'E se eu precisar de mudar o perfil a meio do ano?',
        answer: 'Alterar um perfil ativo cria uma nova versão. Uma turma com resultados só muda depois de lhe ser mostrado o impacto e de o confirmar — e o que já foi avaliado continua a apontar para a versão com que foi avaliado.',
    },
] as const;
</script>

<template>
    <section
        id="perguntas"
        class="scroll-mt-[4.5rem] border-t border-border/60 bg-muted/40 pt-12 pb-16 sm:py-24 lg:py-28 dark:bg-muted/10"
        aria-labelledby="perguntas-title"
    >
        <div
            class="mx-auto grid w-full max-w-6xl gap-10 px-6 sm:px-8 lg:grid-cols-[minmax(0,0.72fr)_minmax(0,1.28fr)] lg:gap-16"
        >
            <RevealOnScroll v-slot="{ shown }" class="min-w-0">
                <div class="lg:sticky lg:top-24">
                    <p
                        class="flex items-center gap-2.5 text-[11px] font-semibold tracking-[0.14em] text-muted-foreground uppercase"
                    >
                        <span
                            aria-hidden="true"
                            class="h-px w-7 origin-left bg-primary transition-transform delay-150 duration-700 ease-out dark:bg-(--brand-amber)"
                            :class="shown ? 'scale-x-100' : 'scale-x-0'"
                        />
                        Perguntas
                    </p>
                    <h2
                        id="perguntas-title"
                        class="mt-3 text-3xl font-semibold tracking-tight text-balance sm:text-4xl"
                    >
                        O que perguntam primeiro.
                    </h2>
                    <p
                        class="mt-4 leading-relaxed text-pretty text-muted-foreground"
                    >
                        Respostas sobre o produto como ele está hoje — não sobre
                        o que está planeado.
                    </p>
                    <Button as-child class="group/cta mt-7">
                        <Link :href="register()">
                            Experimentar LÁPIS
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
