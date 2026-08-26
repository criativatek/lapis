import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import Show from './Show.vue';

const routerPost = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
        Link: defineComponent({ inheritAttrs: false, setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()) }),
        router: { get: vi.fn(), post: routerPost },
    };
});

/**
 * Pro gating, on the FRONTEND half of the guarantee (§8.2 of CLAUDE.md: the
 * server decides, and this is the other half of proving it — an omitted
 * `pro` prop must render nothing interpretive, not merely hide it behind a style
 * rule). The server-side half — that a Base organization's Inertia payload
 * has no `pro` key with data in it at all — is covered in
 * tests/Feature/StudentProgress/StudentProgressPanelTest.php.
 */
function baseProps() {
    return {
        student: {
            ulid: 'student-1',
            name: 'Ana Marques',
            class_number: 1,
            is_late_entry: false,
            enrolled_on: '2026-09-14',
            left_on: null,
            status_label: 'Ativo',
            is_current: true,
            status_reason: null,
        },
        schoolClass: {
            ulid: 'class-1',
            label: '7.º A',
            subject: 'Português',
            academic_year: '2026/2027',
            has_profile: true,
            scale_name: 'Escala 1 a 5',
        },
        reading: {
            kind: 'period' as const,
            canonical: 'period' as const,
            has_toggle: false,
            label: 'Média Ponderada',
            caption: 'Só os elementos realizados no período analisado.',
        },
        headline: {
            value: '61.3',
            supplementary_value: null,
            band: null,
            coverage: 'complete' as const,
            classification: { status: 'confirmed', final: { code: '4', label: 'Bom' }, final_value: null },
            self_assessment: null,
            evolution: null,
            continuous_evolution: null,
        },
        moments: [],
        classifications: [
            {
                period_id: 1,
                period_label: '1.º Semestre',
                is_decided: true,
                assigned: { code: '4', label: 'Bom' },
                assigned_value: null,
                is_published: false,
                proposal: null,
                differs_from_proposal: false,
            },
        ],
        selfAssessments: [],
        domains: { rows: [], highlights: { highest: null, lowest: null, largest_rise: null, largest_fall: null } },
        sinceLast: null,
        classComparison: null,
        records: { total: 0, kinds: [], rows: [] },
        interventions: { total: 0, individual: 0, needing_review: 0, rows: [] },
        recentInstruments: [],
        narrative: null,
        factualAlerts: [],
        strengths: [],
        ai: { available: false, reason: 'plan' },
        aiPurposeOptions: [
            { value: 'recovery', label: 'Recuperação', description: 'Recuperar.' },
            { value: 'consolidation', label: 'Consolidação', description: 'Consolidar.' },
            { value: 'improvement', label: 'Melhoria', description: 'Melhorar.' },
        ],
        aiPurposeSuggestions: { recovery: null, consolidation: null, improvement: null },
        aiSuggestion: null,
        aiSuggestionError: null,
        links: {
            records: '/classes/class-1/records',
            interventions: '/classes/class-1/interventions',
            newIntervention: null,
            reports: '/reports/novo',
            statistics: '/classes/class-1/estatistica',
            suggestStrategy: '/classes/class-1/evolucao/student-1/sugestao-estrategia',
        },
    };
}

function proDimensions() {
    return {
        estado360: {
            dimensions: [
                { key: 'rendimento', label: 'Rendimento', state: 'acima_da_turma', state_label: 'Acima da turma', detail: 'x' },
                { key: 'tendencia', label: 'Tendência', state: 'subida', state_label: 'Subida', detail: 'x' },
                { key: 'regularidade', label: 'Regularidade', state: 'variavel', state_label: 'Variável', detail: 'x' },
                { key: 'empenho_academico', label: 'Empenho académico', state: 'sem_registos', state_label: 'Sem registos', detail: 'x' },
                { key: 'atitudes_comportamento', label: 'Atitudes e comportamento', state: 'estavel', state_label: 'Estável', detail: 'x' },
                { key: 'acompanhamento', label: 'Acompanhamento', state: 'sem_intervencao', state_label: 'Sem intervenção', detail: 'x' },
                { key: 'pontos_fortes', label: 'Pontos fortes', state: 'sem_elementos', state_label: 'Sem elementos', detail: 'x' },
                { key: 'margem_progressao', label: 'Margem de progressão', state: 'sem_elementos', state_label: 'Sem elementos', detail: 'x' },
            ],
        },
        analyticalAlerts: [],
        positiveSignals: [],
        potentialities: { narrative: null, strengths: [], progressing: [], next_step: null },
        whatChanged: { items: [], empty_sentence: 'Não existem alterações relevantes no período analisado.' },
        evolutionAfterStrategy: [],
        conversationPrep: {
            situation: { value: '61.3', coverage: 'complete' as const, reading_label: 'Média Ponderada' },
            difficulties: [],
            strengths: [],
            changes: [],
            potentialities: null,
            ongoingStrategies: [],
            selfAssessment: [],
            evolutionAfterStrategy: [],
            positiveSignals: [],
            topicsToDiscuss: [],
        },
    };
}

describe('student-progress/Show — Pro gating on the frontend', () => {
    it('renders no interpretive section at all when `pro` is omitted (Base)', () => {
        const wrapper = mount(Show, { props: baseProps() });

        expect(wrapper.text()).not.toContain('Estado 360º');
        expect(wrapper.text()).not.toContain('Preparar conversa');
        expect(wrapper.text()).not.toContain('Potencialidades');
    });

    it('renders Estado 360º and its eight dimensions when `pro` is present', () => {
        const wrapper = mount(Show, { props: { ...baseProps(), pro: proDimensions() } });

        expect(wrapper.text()).toContain('Estado 360º');
        expect(wrapper.text()).toContain('Rendimento');
        expect(wrapper.text()).toContain('Margem de progressão');
        expect(wrapper.text()).toContain('Preparar conversa');
    });

    /**
     * Panel review §5: the Estado 360º intro names the real eight dimensions
     * — pulled from their own `label`, so it can never drift from the cards
     * actually rendered below it — and never uses "diz-o"/"di-lo".
     */
    it('names the real eight dimensions in the Estado 360º intro and never says "diz-o"', () => {
        const dimensions = proDimensions();
        const wrapper = mount(Show, { props: { ...baseProps(), pro: dimensions } });

        const text = wrapper.text();

        for (const dimension of dimensions.estado360.dimensions) {
            const lowered = dimension.label.charAt(0).toLowerCase() + dimension.label.slice(1);
            expect(text).toContain(lowered);
        }

        expect(text).not.toContain('diz-o');
        expect(text).not.toContain('di-lo');
    });

    it('shows the Base "Atenção" section from factualAlerts regardless of plan', () => {
        const wrapper = mount(Show, {
            props: {
                ...baseProps(),
                factualAlerts: [{ key: 'unresolved_homework', sentence: 'Existem 2 TPC não realizados no 1.º Semestre.' }],
            },
        });

        expect(wrapper.text()).toContain('Existem 2 TPC não realizados no 1.º Semestre.');
        // And the Pro-only interpretive subsection stays absent.
        expect(wrapper.text()).not.toContain('Leitura analítica');
    });

    it('never predicts a numeric outcome in the potentialities narrative it renders', () => {
        const wrapper = mount(Show, {
            props: {
                ...baseProps(),
                pro: {
                    ...proDimensions(),
                    potentialities: {
                        narrative: 'O aluno demonstra desempenho elevado e consistente, com margem para tarefas de maior complexidade.',
                        strengths: [{ domain_id: 1, name: 'Oralidade', value: '71.0', detail: 'Oralidade — ponto forte atual — 71,0%' }],
                        progressing: [{ domain_id: 2, name: 'Escrita', value: '17.6', detail: 'Escrita — maior evolução recente — +17,6 p.p.' }],
                        next_step: 'Consolidar Escrita e aprofundar Oralidade.',
                    },
                },
            },
        });

        const narrative = wrapper.findAll('p').find((candidate) => candidate.text().includes('margem para tarefas de maior complexidade'))?.text() ?? '';
        expect(narrative).toContain('margem para tarefas de maior complexidade');
        expect(narrative).not.toMatch(/nível \d/i);
        expect(narrative).not.toMatch(/\d+%/);
    });

    it('renders domain highlights with their real values and signed movement text', () => {
        const wrapper = mount(Show, {
            props: {
                ...baseProps(),
                domains: {
                    rows: [{
                        domain_id: 1,
                        name: 'Oralidade',
                        weighted_average: '71.0',
                        accumulated_average: '71.0',
                        mention: null,
                        self_assessment: null,
                        evolution: null,
                        coverage: 'complete' as const,
                    }],
                    highlights: {
                        highest: { domain_id: 1, name: 'Oralidade', value: '71.0' },
                        lowest: { domain_id: 2, name: 'Gramática', value: '37.5' },
                        largest_rise: { domain_id: 3, name: 'Escrita', value: '17.6' },
                        largest_fall: { domain_id: 4, name: 'Educação Literária', value: '-42.7' },
                    },
                },
            },
        });

        expect(wrapper.text()).toMatch(/Resultado mais elevado: Oralidade\s*71,0%/);
        expect(wrapper.text()).toMatch(/Maior subida: Escrita\s*↑ Subida · \+17,6 p\.p\./);
        expect(wrapper.text()).toMatch(/Maior descida: Educação Literária\s*↓ Descida · −42,7 p\.p\./);
    });

    /**
     * Panel review §3: the date of a comparison period lives exactly once on
     * the page — inside "Base da comparação" — and is worded "terminou em",
     * never "encerrado em".
     */
    it('names the comparison dates once, inside "Base da comparação", as "terminou em"', () => {
        const wrapper = mount(Show, {
            props: {
                ...baseProps(),
                sinceLast: {
                    from_label: '1.º Semestre',
                    to_label: '2.º Semestre',
                    from_date: '2027-01-29',
                    to_date: '2027-06-16',
                    from: '61.3',
                    to: '66.7',
                    classification_from: null,
                    classification_to: null,
                    domains: [],
                },
            },
        });

        const text = wrapper.text();
        expect(text).toContain('terminou em');
        expect(text).not.toContain('encerrado em');
    });

    /**
     * Panel review §1: the class-comparison delta must show one sign, not
     * two — `formatPoints()` already prefixes "+" for a positive value, so
     * the template must never prepend a second one.
     */
    it('never double-signs the class-comparison delta', () => {
        const wrapper = mount(Show, {
            props: {
                ...baseProps(),
                classComparison: {
                    student: '66.7',
                    class: '61.9',
                    students_with_result: 24,
                    difference: '4.8',
                },
            },
        });

        expect(wrapper.text()).toContain('+4,8 p.p.');
        expect(wrapper.text()).not.toContain('++4,8');
    });

    it('renders recent-instrument movement with icon text and value, never colour alone', () => {
        const wrapper = mount(Show, {
            props: {
                ...baseProps(),
                recentInstruments: [{
                    ulid: 'instrument-1',
                    title: 'Produção escrita',
                    type: 'Texto',
                    applied_on: '2027-02-15',
                    status: 'Concluído',
                    counts_toward_classification: true,
                    result: '68.0',
                    scale_label: 'Bom',
                    evolution: { direction: 'up' as const, points: '5.0' },
                }],
            },
        });

        expect(wrapper.text()).toContain('Produção escrita');
        expect(wrapper.text()).toMatch(/68,0%\s*· Bom/);
        expect(wrapper.text()).toContain('↑ Subida · +5,0 p.p.');
    });

    it('renders potentialities as three labelled groups rather than bare domain pills', () => {
        const wrapper = mount(Show, {
            props: {
                ...baseProps(),
                pro: {
                    ...proDimensions(),
                    potentialities: {
                        narrative: 'Foco na consolidação.',
                        strengths: [{ domain_id: 1, name: 'Oralidade', value: '71.0', detail: 'Oralidade — ponto forte atual — 71,0%' }],
                        progressing: [{ domain_id: 2, name: 'Escrita', value: '17.6', detail: 'Escrita — maior evolução recente — +17,6 p.p.' }],
                        next_step: 'Consolidar Escrita e aprofundar Oralidade.',
                    },
                },
            },
        });

        const section = wrapper.find('#potencialidades').element.parentElement?.textContent ?? '';
        expect(section).toContain('Pontos fortes');
        expect(section).toContain('Em progressão');
        expect(section).toContain('Próximo passo');
        expect(section).toContain('Oralidade — ponto forte atual');
        expect(section).toContain('Escrita — maior evolução recente');
    });

    it('re-requests with the same teacher-selected purpose and domain and the edited objective', async () => {
        routerPost.mockClear();
        const props = baseProps();
        const wrapper = mount(Show, {
            props: {
                ...props,
                domains: {
                    rows: [{
                        domain_id: 2,
                        name: 'Escrita',
                        weighted_average: '62.0',
                        accumulated_average: '64.0',
                        mention: null,
                        self_assessment: null,
                        evolution: { direction: 'up' as const, points: '4.0' },
                        coverage: 'complete' as const,
                    }],
                    highlights: props.domains.highlights,
                },
                ai: { available: true, reason: null },
                aiPurposeSuggestions: {
                    recovery: null,
                    consolidation: {
                        purpose: 'consolidation',
                        purpose_label: 'Consolidação',
                        domain_id: 2,
                        domain: 'Escrita',
                        value: '4.0',
                        justification: 'Maior evolução recente: +4,0 p.p., ainda com margem para estabilização.',
                    },
                    improvement: null,
                },
                aiSuggestion: {
                    domain_id: 2,
                    domain: 'Escrita',
                    purpose: 'consolidation',
                    purpose_label: 'Consolidação',
                    enrollment_ulid: 'student-1',
                    suggestions: [{
                        name: 'Reescrita orientada',
                        purpose: 'consolidation',
                        objective: 'Consolidar organização textual.',
                        strategy: 'Produção quinzenal com segunda versão.',
                        frequency: 'Quinzenal',
                        duration: null,
                        tracking_indicator: 'Critérios de organização.',
                        review_suggestion: 'Após 3 evidências comparáveis',
                    }],
                },
            },
        });

        await wrapper.get('textarea[maxlength="1000"]').setValue('Consolidar a coesão textual.');
        const button = wrapper.findAll('button').find((candidate) => candidate.text().includes('Gerar outra sugestão'));
        await button?.trigger('click');

        expect(routerPost).toHaveBeenCalledWith(
            props.links.suggestStrategy,
            { domain_id: 2, purpose: 'consolidation', teacher_objective: 'Consolidar a coesão textual.' },
            expect.objectContaining({ preserveScroll: true }),
        );

        expect(wrapper.text()).toContain('Adicionar como estratégia');
        expect(wrapper.text()).toContain('Adaptar');
        expect(wrapper.text()).toContain('Ignorar');

        const actionLinks = wrapper.findAll('a').filter((candidate) => ['Adicionar como estratégia', 'Adaptar'].includes(candidate.text()));
        expect(actionLinks).toHaveLength(2);
        const addHref = actionLinks[0]?.attributes('href') ?? '';
        const adaptHref = actionLinks[1]?.attributes('href') ?? '';
        expect(addHref).toBe(adaptHref);
        const prefillUrl = decodeURIComponent(addHref);
        expect(prefillUrl).toContain('dominio=2');
        expect(prefillUrl).toContain('estrategia=Reescrita+orientada');
        expect(prefillUrl).toContain('aplicacao=Produção+quinzenal+com+segunda+versão.');
        expect(prefillUrl).toContain('revisao=Após+3+evidências+comparáveis');

        const ignore = wrapper.findAll('button').find((candidate) => candidate.text().includes('Ignorar'));
        await ignore?.trigger('click');
        expect(wrapper.text()).not.toContain('Reescrita orientada');
    });
});
