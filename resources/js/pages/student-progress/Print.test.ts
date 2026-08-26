import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import Print from './Print.vue';

vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
        Link: defineComponent({
            props: { href: { type: String, default: '' } },
            setup: (props, { slots }) => () => h('a', { href: props.href }, slots.default?.()),
        }),
    };
});

/**
 * Client-side smoke test for the print document (§ print brief).
 *
 * This is the half StudentProgressPrintTest.php (Feature) cannot cover:
 * that the ACTUAL Vue template mounts and renders correctly against a
 * realistic payload, for both variants, with no runtime error — and that
 * the composition really is driven by `document.sections` alone, never by
 * a `pro` truthiness check invented independently of it.
 */
function factualDocument() {
    return {
        title: 'Ficha do Aluno',
        sections: [
            { key: 'current_situation', label: 'Situação atual', capability: 'student_progress' },
            { key: 'domains', label: 'Resultados por domínio', capability: 'student_progress' },
            { key: 'evolution', label: 'Como evoluiu', capability: 'student_progress' },
            { key: 'self_assessments', label: 'Autoavaliação', capability: 'student_progress' },
            { key: 'academic_records', label: 'Classificações atribuídas', capability: 'student_progress' },
            { key: 'records', label: 'Registos', capability: 'student_progress' },
            { key: 'attention_factual', label: 'Atenção', capability: 'student_progress' },
            { key: 'strengths_factual', label: 'Pontos fortes', capability: 'student_progress' },
            { key: 'strategies', label: 'Estratégias e Medidas', capability: 'student_progress' },
        ],
    };
}

function baseProps() {
    return {
        student: {
            ulid: 'student-1',
            name: 'Álvaro Simões',
            class_number: 1,
            is_current: true,
            status_label: 'Ativo',
            status_reason: null,
        },
        schoolClass: {
            ulid: 'class-1',
            label: '7.º A',
            subject: 'Português',
            academic_year: '2026/2027',
        },
        reading: { kind: 'period' as const, label: 'Média Ponderada', caption: 'Só os elementos realizados no período analisado.' },
        selectedPeriod: { id: 1, label: '1.º Período' },
        headline: {
            value: '61.3',
            band: null,
            coverage: 'complete' as const,
            classification: { status: 'confirmed', final: { code: '4', label: 'Bom' }, final_value: null },
            self_assessment: { code: '3', label: 'Satisfaz' },
        },
        moments: [
            { key: 'period-1', kind: 'period' as const, label: '1.º Período', date: '2026-12-15', value: '61.3', reading: 'period' as const, coverage: 'complete' as const, before_enrolment: false },
        ],
        classifications: [
            { period_id: 1, period_label: '1.º Período', is_decided: true, assigned: { code: '4', label: 'Bom' }, assigned_value: null, proposal: null, differs_from_proposal: false },
        ],
        selfAssessments: [
            { period_id: 1, period_label: '1.º Período', self_assessment: { code: '3', label: 'Satisfaz' }, assigned: { code: '4', label: 'Bom' }, comparison: { difference: -1, direction: 'below' as const } },
        ],
        domains: {
            rows: [
                { domain_id: 1, name: 'Leitura', accumulated_average: '71.4', mention: { code: '4', label: 'Bom' }, evolution: { direction: 'up' as const, points: '4.8' }, coverage: 'complete' as const },
            ],
            highlights: {
                highest: { name: 'Leitura', value: '71.4' },
                lowest: { name: 'Escrita', value: '52.1' },
                largest_rise: { name: 'Leitura', value: '4.8' },
                largest_fall: null,
            },
        },
        sinceLast: {
            from_label: '1.º Período', to_label: '2.º Período', from: '55.0', to: '61.3',
            classification_from: { code: '3', label: 'Satisfaz' }, classification_to: { code: '4', label: 'Bom' },
        },
        classComparison: { student: '61.3', class: '58.0', students_with_result: 6, difference: '3.3' },
        records: {
            total: 1,
            rows: [{ ulid: 'rec-1', kind_label: 'Trabalho de casa', occurred_at: '2026-11-05', description: 'TPC não realizado.', domain: null, severity: null, homework_status: 'Não realizado', participation_level: null }],
        },
        interventions: {
            total: 1,
            rows: [{ ulid: 'int-1', title: 'Leitura orientada', effectiveness: 'Eficaz', last_followup_on: '2026-12-01', followup_count: 1, status: 'Em curso', started_on: '2026-11-10', domain: 'Leitura', is_individual: true, purpose_label: 'Recuperação', frequency: '2x por semana', tracking_indicator: 'n.º de leituras' }],
        },
        recentInstruments: [
            { ulid: 'inst-1', title: 'Ficha de leitura', type: 'Ficha', applied_on: '2026-11-20', result: '71.4', scale_label: 'Bom', evolution: { direction: 'up' as const, points: '4.8' } },
        ],
        narrative: 'Do 1.º Período para o 2.º Período, a Média Ponderada passou de 55% para 61,3% — subida de 6,3 p.p.',
        factualAlerts: [{ key: 'unresolved_homework', sentence: 'Existe 1 TPC não realizado no 2.º Período.' }],
        strengths: [{ key: 'highest_domain', sentence: 'Leitura — ponto forte atual — 71,4% · Bom.' }],
        document: factualDocument(),
        generatedAt: '2026-08-26',
    };
}

function proExtras() {
    return {
        estado360: {
            dimensions: [
                { key: 'rendimento', label: 'Rendimento', state: 'acima_da_turma', state_label: 'Acima da turma', detail: 'x' },
                { key: 'tendencia', label: 'Tendência', state: 'subida', state_label: 'Subida', detail: 'x' },
                { key: 'regularidade', label: 'Regularidade', state: 'variavel', state_label: 'Variável', detail: 'x' },
                { key: 'empenho_academico', label: 'Empenho académico', state: 'sem_evidencia', state_label: 'Sem evidência suficiente', detail: 'x' },
                { key: 'atitudes_comportamento', label: 'Atitudes e comportamento', state: 'estavel', state_label: 'Estável', detail: 'x' },
                { key: 'acompanhamento', label: 'Acompanhamento', state: 'em_curso', state_label: 'Em curso', detail: 'x' },
                { key: 'pontos_fortes', label: 'Pontos fortes', state: 'identificados', state_label: 'Identificados', detail: 'x' },
                { key: 'margem_progressao', label: 'Margem de progressão', state: 'em_progressao', state_label: 'Em progressão', detail: 'x' },
            ],
        },
        analyticalAlerts: [{ key: 'consistent_decline', sentence: 'Observa-se uma leitura analítica de teste.' }],
        positiveSignals: [{ key: 'consistent_improvement', sentence: 'Observa-se uma melhoria consistente nos elementos mais recentes.' }],
        potentialities: {
            narrative: 'O aluno está próximo do patamar seguinte.',
            strengths: [{ domain_id: 1, name: 'Leitura', detail: 'Leitura — ponto forte atual — 71,4%' }],
            progressing: [{ domain_id: 1, name: 'Leitura', detail: 'Leitura — maior evolução recente — +4,8 p.p.' }],
            next_step: 'Continuar a aprofundar Leitura.',
        },
        whatChanged: { items: [{ key: 'result', sentence: 'A Média Ponderada subiu.' }], empty_sentence: null },
    };
}

function analyticalDocument() {
    return {
        title: 'Síntese de Acompanhamento do Aluno',
        sections: [
            ...factualDocument().sections,
            { key: 'attention_analytical', label: 'Atenção — leitura analítica', capability: 'advanced_analytics' },
            { key: 'positive_signals', label: 'Sinais positivos', capability: 'advanced_analytics' },
            { key: 'estado360', label: 'Estado 360º', capability: 'advanced_analytics' },
            { key: 'what_changed', label: 'O que mudou', capability: 'advanced_analytics' },
            { key: 'potentialities', label: 'Potencialidades', capability: 'advanced_analytics' },
        ],
    };
}

describe('student-progress/Print — composition driven by document.sections', () => {
    it('renders the factual "Ficha do Aluno" with no analytical section at all', () => {
        const wrapper = mount(Print, { props: baseProps() });
        const text = wrapper.text();

        expect(text).toContain('Ficha do Aluno');
        expect(text).toContain('Álvaro Simões');
        expect(text).toContain('Existe 1 TPC não realizado no 2.º Período.');
        expect(text).toContain('Leitura orientada');
        expect(text).not.toContain('Estado 360º');
        expect(text).not.toContain('Potencialidades');
        expect(text).not.toContain('leitura analítica');
        expect(wrapper.find('.doc-analytical').exists()).toBe(false);

        // §4: nothing locked on paper.
        for (const forbidden of ['Disponível no Pro', 'Bloqueado', 'upsell', 'upgrade']) {
            expect(text).not.toContain(forbidden);
        }
    });

    it('renders the "Síntese de Acompanhamento do Aluno" with the analytical sections when the document lists them', () => {
        const wrapper = mount(Print, {
            props: { ...baseProps(), pro: proExtras(), document: analyticalDocument() },
        });
        const text = wrapper.text();

        expect(text).toContain('Síntese de Acompanhamento do Aluno');
        expect(text).toContain('Estado 360º');
        expect(text).toContain('Margem de progressão');
        expect(text).toContain('Potencialidades');
        expect(text).toContain('leitura analítica');
        expect(wrapper.findAll('.doc-analytical').length).toBeGreaterThan(0);
    });

    it('never renders an analytical section whose key the document omits, even when `pro` is present', () => {
        // A deliberately inconsistent payload (should never happen from the
        // real controller) — proves the template asks `has(key)` and never
        // falls back to "pro is truthy" on its own.
        const documentWithoutEstado360 = {
            ...analyticalDocument(),
            sections: analyticalDocument().sections.filter((section) => section.key !== 'estado360'),
        };
        const wrapper = mount(Print, {
            props: { ...baseProps(), pro: proExtras(), document: documentWithoutEstado360 },
        });

        expect(wrapper.text()).not.toContain('Estado 360º');
    });

    it('shows the footer disclaimer and the generation date, never new PII', () => {
        const wrapper = mount(Print, { props: baseProps() });
        const text = wrapper.text();

        expect(text).toContain('Documento de apoio ao acompanhamento pedagógico');
        expect(text).toContain('26 de agosto de 2026');
    });

    it('identifies the student in the header and never repeats it as a section', () => {
        const wrapper = mount(Print, { props: baseProps() });
        const header = wrapper.find('.doc-header');

        // Everything the removed «Identificação» card used to carry is still
        // on the page — it simply lives in the header now, said once.
        expect(header.text()).toContain('Álvaro Simões');
        expect(header.text()).toContain('N.º 1');
        expect(header.text()).toContain('7.º A');
        expect(header.text()).toContain('Português');
        expect(header.text()).toContain('2026/2027');
        expect(header.text()).toContain('1.º Período');

        expect(wrapper.find('.doc-content').text()).not.toContain('Identificação');
    });
});

describe('student-progress/Print — on-screen actions', () => {
    it('offers a way back to the panel and a print action', () => {
        const wrapper = mount(Print, { props: baseProps() });
        const actions = wrapper.find('.doc-actions');

        expect(actions.exists()).toBe(true);
        expect(actions.find('a').attributes('href')).toBe('/classes/class-1/evolucao/student-1');
        expect(actions.text()).toContain('Voltar ao aluno');
        expect(actions.text()).toContain('Imprimir / Guardar PDF');
    });

    it('calls window.print() when the print action is pressed', async () => {
        const print = vi.fn();
        vi.stubGlobal('print', print);

        const wrapper = mount(Print, { props: baseProps() });
        await wrapper.find('.doc-actions button').trigger('click');

        expect(print).toHaveBeenCalledTimes(1);

        vi.unstubAllGlobals();
    });

    it('carries the browser-settings hint, and keeps it inside the print-hidden block', () => {
        const wrapper = mount(Print, { props: baseProps() });
        const actions = wrapper.find('.doc-actions');

        // Inside `.doc-actions`, which @media print removes outright — so the
        // hint reaches the person at the screen and never the paper.
        expect(actions.text()).toContain('Cabeçalhos e rodapés');
        expect(wrapper.find('.doc-content').text()).not.toContain('Cabeçalhos e rodapés');
        expect(wrapper.find('.doc-footer').text()).not.toContain('Cabeçalhos e rodapés');
    });
});
