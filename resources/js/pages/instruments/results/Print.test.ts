import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import type { ResultsAnalysisProps } from '@/types/resultsAnalysis';
import ResultsReport from './Print.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
}));

function analysis() {
    return {
        universe: 20, classified: 18, partial: 0, out_of_scope: 1,
        missing: { total: 2, pending: 1, under_review: 0, absent: 1, absent_justified: 0, exempt: 0, not_applicable: 0, annulled: 0 },
        mean: '68.4', median: '70.0', min: '20.0', max: '98.0',
        threshold: { value: '49.5', available: true, below: { count: 4, percent: '22.2' }, at_or_above: { count: 14, percent: '77.8' } },
        quantitative: { total: 18, classes: [{ key: 'c4', label: '[40, 50[', count: 4, percent: '22.2', below_threshold: true }] },
        qualitative: {
            available: true, total: 18, unplaced: 0,
            categories: [{ key: 'suf', code: 'Suf', label: 'Suficiente', sequence: 2, is_negative: false, count: 8, percent: '44.4' }],
        },
    };
}

function baseProps(overrides: Partial<ResultsAnalysisProps> = {}): ResultsAnalysisProps {
    return {
        availability: { official: true, status: 'official', status_label: 'Oficial', message: null },
        context: {
            kind: 'instrument', is_diagnostic: false, classificatory: true, counts_toward_classification: true,
            instrument: {
                ulid: 'instrument-a', title: 'Teste de Frações', applied_on: '2026-09-13',
                status: 'completed', status_label: 'Concluído', type: 'Teste', purpose: 'summative', purpose_label: 'Sumativa',
            },
            class: { ulid: 'class-a', label: '7.º C' },
            period: { label: '1.º Período' },
            absence_mode: 'zero_all', absence_mode_label: 'Ausências contam zero',
            threshold: { value: '49.5', label: '49,5 %', explanation: 'A escala 1-5 define 49,5 % como o início de «Suficiente».' },
            scale: {
                name: 'Escala 1-5', has_bands: true,
                bands: [{ key: 'suf', code: 'Suf', label: 'Suficiente', sequence: 2, is_negative: false }],
            },
            domains: [], items_without_domain: 0, notes: [],
        },
        students: [],
        dimensions: [{ key: 'global', label: 'Global', analysis: analysis() }],
        report: {
            title: 'Relatório — Teste de Frações', generated_at: '2026-09-25T10:00:00Z',
            sections: [{ key: 'identification', title: 'Identificação', paragraphs: ['Teste de Frações, 7.º C.'], table: null }],
        },
        note: { body: 'Observação.', lock_version: 1, updated_at: null, updated_by: null },
        can_edit: false,
        include_individual: false,
        links: {
            grid: '/instruments/instrument-a/grelha',
            results: '/instruments/instrument-a/resultados',
            report: '/instruments/instrument-a/resultados/relatorio',
            report_with_individual: '/instruments/instrument-a/resultados/relatorio?individual=1',
            note: '/instruments/instrument-a/resultados/nota',
        },
        ...overrides,
    };
}

const NAMED_STUDENT = {
    enrollment_id: 1, class_number: 1, name: 'Ana Martins', status: 'classified', status_label: 'Classificado',
    global: { value: '72.4', value_precise: null, exact: '72.399123', band: null, below_threshold: false, is_partial: false },
    domains: {},
};

describe('instruments/results/Print.vue', () => {
    it('não mostra nenhum nome de aluno quando include_individual é falso, mesmo com students preenchido por engano', () => {
        const wrapper = mount(ResultsReport, {
            props: baseProps({ include_individual: false, students: [NAMED_STUDENT] }),
        });

        expect(wrapper.html()).not.toContain('Ana Martins');
        expect(wrapper.text()).toContain('Relatório agregado: não inclui nomes nem classificações individuais.');
    });

    it('mostra os nomes dos alunos quando include_individual é verdadeiro', () => {
        const wrapper = mount(ResultsReport, {
            props: baseProps({ include_individual: true, students: [NAMED_STUDENT] }),
        });

        expect(wrapper.text()).toContain('Ana Martins');
        expect(wrapper.text()).not.toContain('Relatório agregado: não inclui nomes');
    });

    it('mostra o título do relatório e a secção do relatório', () => {
        const wrapper = mount(ResultsReport, { props: baseProps() });

        expect(wrapper.text()).toContain('Relatório — Teste de Frações');
        expect(wrapper.text()).toContain('Identificação');
    });

    it('quando não há resultados oficiais, mostra o estado sem números e mantém as observações do professor', () => {
        const wrapper = mount(ResultsReport, {
            props: baseProps({
                availability: { official: false, status: 'under_correction', status_label: 'Em correção', message: 'Ainda em correção.' },
                dimensions: [], students: [], report: null,
            }),
        });

        expect(wrapper.text()).toContain('Estado: Em correção');
        expect(wrapper.text()).toContain('Ainda em correção.');
        expect(wrapper.text()).toContain('Observações do professor');
        expect(wrapper.text()).not.toContain('Identificação');
        expect(wrapper.text()).not.toContain('68,4');
    });

    it('esconde as observações do professor na impressão quando a caixa é desmarcada', async () => {
        const wrapper = mount(ResultsReport, { props: baseProps() });

        expect(wrapper.text()).toContain('Observação.');

        const checkbox = wrapper.find('input[type="checkbox"]');
        await checkbox.setValue(false);

        expect(wrapper.text()).not.toContain('Observação.');
    });
});
