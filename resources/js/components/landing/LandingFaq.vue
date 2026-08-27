<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight, Plus } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { register } from '@/routes';
import {
    BASE_ACTIVE_CLASSES,
    BASE_ACTIVE_STUDENTS,
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
        question: 'O que é o LÁPIS?',
        answer: 'É uma plataforma para professores que reúne a avaliação de alunos, a gestão de turmas, o acompanhamento pedagógico, as aulas, os sumários e os relatórios num único lugar — em vez de os espalhar por folhas de cálculo, documentos e cadernos.',
    },
    {
        question: 'Posso gerir várias turmas e vários alunos?',
        answer: `Sim. A gestão de turmas e de alunos é o ponto de partida, e as turmas podem ser importadas da pauta que a escola já lhe deu. O plano Base inclui até ${BASE_ACTIVE_CLASSES} turmas e ${BASE_ACTIVE_STUDENTS} alunos ativos; o Pro e o Institucional não têm esse limite.`,
    },
    {
        question:
            'Posso usar os meus critérios de avaliação, com os meus nomes e os meus pesos?',
        answer: 'É o ponto de partida da aplicação: os domínios com os nomes da sua escola, a ponderação de cada um, os instrumentos de avaliação que entram e a escala em que a classificação é dada.',
    },
    {
        question: 'O LÁPIS acompanha a evolução dos alunos?',
        answer: 'Sim. O acompanhamento do progresso de cada aluno reúne resultados, domínios, classificações, autoavaliações, registos e estratégias na mesma vista. No plano Pro acrescenta a leitura interpretativa: tendências, regularidade, pontos fortes, margem de progressão e o próximo passo pedagógico.',
    },
    {
        question: 'O LÁPIS inclui horário, aulas e sumários?',
        answer: 'Inclui, no plano Pro: o horário do professor, a semana de aulas, o sumário como centro de cada aula, o planeamento em sequências reutilizáveis e a agenda do ano letivo, com períodos, interrupções e feriados.',
    },
    {
        question: 'O LÁPIS utiliza inteligência artificial?',
        answer: 'Utiliza, no plano Pro, e sempre como apoio. A IA pedagógica ajuda a interpretar resultados já calculados, a identificar potencialidades, a propor estratégias e a aperfeiçoar a redação de um relatório. Não gera a avaliação nem substitui o julgamento do professor.',
    },
    {
        question: 'A IA decide as classificações?',
        answer: 'Não. Nenhuma classificação é atribuída, alterada ou decidida por um modelo. O cálculo é determinístico e explicável, o LÁPIS propõe a partir do perfil e do que está registado, e a confirmação é sempre do professor. Se decidir diferente da proposta, a diferença e a razão ficam registadas.',
    },
    {
        question: 'Existe uma versão gratuita?',
        answer: 'O LÁPIS Base é gratuito no ano letivo 2026/27 e fica ativo assim que criar conta, sem cartão em passo nenhum. Dentro da aplicação pode ainda ativar, uma vez, um período experimental de 30 dias do LÁPIS Pro — no fim volta ao Base sem perder nada do que registou.',
    },
    {
        question: 'Quanto custa o LÁPIS Pro?',
        answer: `${PRO_PRICE_PER_YEAR}, em subscrição anual — não existe pagamento mensal. Os primeiros ${FOUNDER_SEATS} professores a aderirem podem beneficiar da condição Membro Fundador, ${FOUNDER_PRICE_PER_YEAR}, disponível até ${FOUNDER_DEADLINE} ou até esses lugares estarem preenchidos, consoante o que ocorrer primeiro. É o mesmo plano Pro, numa condição de adesão distinta.`,
    },
    {
        question: 'Existe uma solução para escolas e agrupamentos?',
        answer: 'Existe: o LÁPIS Institucional, com preço sob consulta. Acrescenta ao Pro a gestão de vários professores, os modelos e perfis de avaliação institucionais, a visão agregada e a governação — coordenação à escala da escola, sem retirar autonomia pedagógica a cada professor.',
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
        answer: 'Relatórios de avaliação em PDF e Word, pautas e quadros-síntese, e grelhas de correção para imprimir. No plano Pro, também a exportação das menções de um período para a grelha do INOVAR. A exportação dos seus próprios dados existe em todos os planos.',
    },
    {
        question: 'Há limite de turmas, de alunos ou de testes?',
        answer: `O plano Base inclui até ${BASE_ACTIVE_CLASSES} turmas e ${BASE_ACTIVE_STUDENTS} alunos ativos; turmas e alunos arquivados não contam. O Pro e o Institucional não têm esse limite. Nenhum plano limita instrumentos de avaliação. Chegar a um limite impede criar mais — nunca apaga o que já lá está.`,
    },
    {
        question: 'Funciona no telemóvel?',
        answer: 'Funciona no browser do telemóvel e do tablet, sem instalar nada. As grelhas com muitas colunas continuam a pedir um ecrã maior — como aconteceria numa folha de cálculo.',
    },
    {
        question: 'E se eu precisar de mudar o perfil a meio do ano?',
        answer: 'Alterar um perfil ativo cria uma nova versão. Uma turma com resultados só muda depois de lhe ser mostrado o impacto e de o confirmar — e o que já foi avaliado continua a apontar para a versão com que foi avaliado.',
    },
];
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
