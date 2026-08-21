<script setup lang="ts">
import { ref } from 'vue';
import AnalysisPreview from './AnalysisPreview.vue';
import GridPreview from './GridPreview.vue';
import InterventionsPreview from './InterventionsPreview.vue';
import LandingSection from './LandingSection.vue';
import ProductWindow from './ProductWindow.vue';
import ReportPreview from './ReportPreview.vue';
import RevealOnScroll from './RevealOnScroll.vue';
import RosterPreview from './RosterPreview.vue';

/**
 * The five stages of the teacher's year, and one real screen for each.
 *
 * THE ORDER IS THE APPLICATION'S OWN — organizar, avaliar, acompanhar,
 * intervir, documentar — the same sequence config/navigation.php builds the
 * side menu from. A landing page that invented a different grouping would be
 * teaching visitors a shape the product does not have.
 *
 * A grid of one card per module was the obvious way to show this and the wrong
 * one: fifteen equal cards say «here is a list» and nobody reads a list. Five
 * stages with a screen each say «here is a year».
 */

type Stage = {
    key: string;
    tab: string;
    title: string;
    line: string;
    path: string;
    modules: readonly string[];
};

const stages: readonly Stage[] = [
    {
        key: 'organizar',
        tab: 'Organizar',
        title: 'As suas turmas, sem as escrever outra vez',
        line: 'Importe a pauta que a escola já lhe deu — nomes, números e fotografias no mesmo passo.',
        path: 'lapis.pt/classes/9b',
        modules: ['Turmas', 'Alunos', 'Importações'],
    },
    {
        key: 'avaliar',
        tab: 'Avaliar',
        title: 'Uma grelha que sabe o que aconteceu',
        line: 'Faltou, foi dispensado, não se aplica, ainda está por corrigir. Oito estados, e o cálculo trata cada um como deve.',
        path: 'lapis.pt/instruments/teste-1/grelha',
        modules: [
            'Elementos de Avaliação',
            'Registo de Avaliações',
            'Autoavaliações',
        ],
    },
    {
        key: 'acompanhar',
        tab: 'Acompanhar',
        title: 'A turma inteira num ecrã',
        line: 'Distribuição pela escala, desempenho por domínio e o movimento de um período para o seguinte.',
        path: 'lapis.pt/classes/9b/results/estatistica',
        modules: [
            'Análise da Turma',
            'Evolução do Aluno',
            'Avaliações Intercalares',
        ],
    },
    {
        key: 'intervir',
        tab: 'Intervir',
        title: 'O que fez fica registado',
        line: 'Estratégias, medidas e observações do dia a dia — no processo do aluno, fora do cálculo.',
        path: 'lapis.pt/classes/9b/interventions',
        modules: ['Estratégias e Medidas', 'Registos'],
    },
    {
        key: 'documentar',
        tab: 'Documentar',
        title: 'O relatório já vem meio escrito',
        line: 'As secções partem do que já registou. Finalizar fixa o documento; corrigir depois deriva outro.',
        path: 'lapis.pt/reports/2o-periodo',
        modules: ['Relatórios', 'Pautas e Quadro-síntese', 'Exportações'],
    },
];

/** The capabilities a plan above Base unlocks — said once, compactly. */
const beyondBase = [
    { name: 'Importação de grelhas de correção', plan: 'Pro' },
    { name: 'Exportação para o INOVAR', plan: 'Pro' },
    { name: 'Ligações de autoavaliação', plan: 'Pro' },
    { name: 'Apoio à redação', plan: 'Pro' },
    { name: 'Equipa e convites', plan: 'Institucional' },
] as const;

const active = ref(0);
const tabs = ref<HTMLButtonElement[]>([]);

/**
 * Arrow keys move between tabs and take focus with them, which is what a
 * tablist is expected to do — without it the widget is reachable by keyboard
 * but not usable by one.
 */
function onKeydown(event: KeyboardEvent): void {
    const step =
        event.key === 'ArrowRight' ? 1 : event.key === 'ArrowLeft' ? -1 : 0;

    if (step === 0) {
        return;
    }

    event.preventDefault();
    active.value = (active.value + step + stages.length) % stages.length;
    tabs.value[active.value]?.focus();
}
</script>

<template>
    <LandingSection
        id="funcionalidades"
        eyebrow="Funcionalidades"
        title="Um ano letivo, de ponta a ponta."
        lead="Organizar, avaliar, acompanhar, intervir e documentar — pela ordem por que o trabalho acontece."
    >
        <RevealOnScroll>
            <div
                role="tablist"
                aria-label="Etapas do ano letivo"
                class="-mx-6 flex gap-1.5 overflow-x-auto px-6 pb-1 sm:mx-0 sm:px-0"
                @keydown="onKeydown"
            >
                <button
                    v-for="(stage, index) in stages"
                    :key="stage.key"
                    ref="tabs"
                    type="button"
                    role="tab"
                    :aria-selected="active === index"
                    :aria-controls="`etapa-${stage.key}`"
                    :tabindex="active === index ? 0 : -1"
                    class="flex shrink-0 items-center gap-2 rounded-full border px-4 py-2 text-sm font-medium transition-[background-color,color,border-color,transform,box-shadow] duration-300 ease-out focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :class="
                        active === index
                            ? 'border-primary bg-primary text-primary-foreground shadow-md motion-safe:-translate-y-0.5'
                            : 'border-border text-muted-foreground hover:border-primary/40 hover:bg-muted hover:text-foreground motion-safe:hover:-translate-y-0.5 dark:hover:border-(--brand-amber)/40'
                    "
                    @click="active = index"
                >
                    <span
                        aria-hidden="true"
                        class="text-[11px] tabular-nums opacity-60"
                        >{{ index + 1 }}</span
                    >
                    {{ stage.tab }}
                </button>
            </div>
        </RevealOnScroll>

        <div
            v-for="(stage, index) in stages"
            v-show="active === index"
            :id="`etapa-${stage.key}`"
            :key="stage.key"
            role="tabpanel"
            :aria-label="stage.tab"
            class="mt-8 grid gap-8 motion-safe:animate-in motion-safe:duration-500 motion-safe:fade-in motion-safe:slide-in-from-bottom-2 lg:min-h-[26rem] lg:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)] lg:items-center lg:gap-12"
        >
            <div class="min-w-0">
                <h3
                    class="text-2xl font-semibold tracking-tight text-balance sm:text-[1.75rem]"
                >
                    {{ stage.title }}
                </h3>
                <p
                    class="mt-3 leading-relaxed text-pretty text-muted-foreground"
                >
                    {{ stage.line }}
                </p>
                <ul class="mt-6 flex flex-wrap gap-1.5">
                    <li
                        v-for="module in stage.modules"
                        :key="module"
                        class="rounded-md border border-border bg-card px-2.5 py-1 text-xs font-medium text-muted-foreground transition-colors duration-300 hover:border-primary/40 hover:text-foreground dark:hover:border-(--brand-amber)/40"
                    >
                        {{ module }}
                    </li>
                </ul>
            </div>

            <div class="min-w-0">
                <ProductWindow :key="stage.key" :path="stage.path">
                    <RosterPreview v-if="stage.key === 'organizar'" />
                    <GridPreview v-else-if="stage.key === 'avaliar'" />
                    <AnalysisPreview v-else-if="stage.key === 'acompanhar'" />
                    <InterventionsPreview
                        v-else-if="stage.key === 'intervir'"
                    />
                    <ReportPreview v-else />
                </ProductWindow>
            </div>
        </div>

        <RevealOnScroll>
            <div
                class="mt-14 flex flex-wrap items-center gap-x-6 gap-y-3 border-t border-border/70 pt-6"
            >
                <p
                    class="text-[11px] font-semibold tracking-[0.14em] text-muted-foreground uppercase"
                >
                    Nos planos superiores
                </p>
                <ul class="flex flex-wrap gap-1.5">
                    <li
                        v-for="capability in beyondBase"
                        :key="capability.name"
                        class="inline-flex items-center gap-1.5 rounded-full border border-border px-2.5 py-1 text-xs transition-all duration-300 hover:border-primary/40 hover:bg-card motion-safe:hover:-translate-y-0.5 dark:hover:border-(--brand-amber)/40"
                    >
                        {{ capability.name }}
                        <span
                            class="text-[10px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
                            >{{ capability.plan }}</span
                        >
                    </li>
                </ul>
            </div>
        </RevealOnScroll>
    </LandingSection>
</template>
