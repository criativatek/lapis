import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';
import type { ResultsAnalysisProps } from '@/types/resultsAnalysis';
import Results from './Results.vue';

type MockForm = Record<string, unknown> & { errors: Record<string, string> };

const mocks = vi.hoisted(() => ({ forms: [] as MockForm[] }));

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    Link: defineComponent({ inheritAttrs: false, setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()) }),
    router: { get: vi.fn(), post: vi.fn(), put: vi.fn() },
    useForm: (data: Record<string, unknown>) => {
        const form = reactive({
            ...data,
            errors: {},
            processing: false,
            put: vi.fn(),
            reset: vi.fn(),
            clearErrors: vi.fn(),
        }) as unknown as MockForm;

        mocks.forms.push(form);

        return form;
    },
}));

function analysis(overrides: Partial<ResultsAnalysisProps['dimensions'][number]['analysis']> = {}) {
    return {
        universe: 20,
        classified: 18,
        partial: 0,
        out_of_scope: 1,
        missing: {
            total: 2, pending: 1, under_review: 0, absent: 1, absent_justified: 0,
            exempt: 0, not_applicable: 0, annulled: 0,
        },
        mean: '68.4',
        median: '70.0',
        min: '20.0',
        max: '98.0',
        threshold: { value: '49.5', below: { count: 4, percent: '22.2' }, at_or_above: { count: 14, percent: '77.8' } },
        quantitative: {
            total: 18,
            classes: [
                { key: 'c0', label: '[0, 10[', count: 0, percent: '0.0', below_threshold: true },
                { key: 'c4', label: '[40, 50[', count: 4, percent: '22.2', below_threshold: true },
                { key: 'c9', label: '[90, 100]', count: 2, percent: '11.1', below_threshold: false },
            ],
        },
        qualitative: {
            available: true,
            total: 18,
            unplaced: 0,
            categories: [
                { key: 'insuf', code: 'Insuf', label: 'Insuficiente', sequence: 1, is_negative: true, count: 4, percent: '22.2' },
                { key: 'suf', code: 'Suf', label: 'Suficiente', sequence: 2, is_negative: false, count: 8, percent: '44.4' },
                { key: 'bom', code: 'Bom', label: 'Bom', sequence: 3, is_negative: false, count: 6, percent: '33.3' },
            ],
        },
        ...overrides,
    };
}

function baseProps(overrides: Partial<ResultsAnalysisProps> = {}): ResultsAnalysisProps {
    return {
        context: {
            kind: 'instrument',
            is_diagnostic: false,
            classificatory: true,
            counts_toward_classification: true,
            diagnostic_counts_warning: false,
            instrument: {
                ulid: 'instrument-a', title: 'Teste de Frações', applied_on: '2026-09-13',
                status: 'completed', status_label: 'Concluído', type: 'Teste', purpose: 'summative',
                purpose_label: 'Sumativa',
            },
            class: { ulid: 'class-a', label: '7.º C' },
            period: { label: '1.º Período' },
            absence_mode: 'zero_all', absence_mode_label: 'Ausências contam zero',
            threshold: { value: '49.5', label: '49,5 %' },
            scale: {
                name: 'Escala 1-5', has_bands: true,
                bands: [
                    { key: 'insuf', code: 'Insuf', label: 'Insuficiente', sequence: 1, is_negative: true },
                    { key: 'suf', code: 'Suf', label: 'Suficiente', sequence: 2, is_negative: false },
                    { key: 'bom', code: 'Bom', label: 'Bom', sequence: 3, is_negative: false },
                ],
            },
            domains: [{ key: 'd1', id: 1, name: 'Conhecimento', weight_percent: '60.0' }],
            items_without_domain: 0,
            notes: ['Nota metodológica de exemplo.'],
        },
        students: [
            {
                enrollment_id: 1, class_number: 1, name: 'Ana Martins', status: 'classified', status_label: 'Classificado',
                global: { value: '72.4', exact: '72.399123', band: { key: 'bom', code: 'Bom', label: 'Bom', sequence: 3, is_negative: false }, below_threshold: false, is_partial: false },
                domains: { d1: { value: '72.4', exact: '72.399123', band: null, below_threshold: false, is_partial: false } },
            },
            {
                enrollment_id: 2, class_number: 2, name: 'Rui Santos', status: 'out_of_scope', status_label: 'Não abrangido',
                global: { value: null, exact: null, band: null, below_threshold: null, is_partial: false },
                domains: {},
            },
        ],
        dimensions: [
            { key: 'global', label: 'Global', analysis: analysis() },
            { key: 'd1', label: 'Conhecimento', analysis: analysis({ mean: '50.0' }) },
        ],
        report: {
            title: 'Relatório — Teste de Frações', generated_at: '2026-09-25T10:00:00Z',
            sections: [
                { key: 'identification', title: 'Identificação', paragraphs: ['Teste de Frações, 7.º C.'], table: null },
                { key: 'differences', title: 'Principais diferenças estatísticas', paragraphs: ['Nada de relevante a assinalar.'], table: null },
            ],
        },
        note: { body: 'Observação inicial.', lock_version: 1, updated_at: '2026-09-24T18:00:00Z', updated_by: 'Prof. Ana' },
        can_edit: true,
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

describe('Results.vue', () => {
    it('mostra as secções pela ordem exigida, com o relatório depois dos indicadores/gráficos/tabelas', () => {
        const wrapper = mount(Results, { props: baseProps() });
        const headings = wrapper.findAll('h2').map((node) => node.text());

        const order = ['Resultados por aluno', 'Indicadores da turma', 'Distribuição', 'Resultados por domínio', 'Relatório descritivo'];
        const positions = order.map((title) => headings.indexOf(title));

        expect(positions.every((position) => position !== -1)).toBe(true);
        expect(positions).toEqual([...positions].sort((a, b) => a - b));
    });

    it('muda a média mostrada ao trocar de dimensão', async () => {
        const wrapper = mount(Results, { props: baseProps() });

        expect(wrapper.text()).toContain('68,4 %');

        const buttons = wrapper.findAll('button[aria-pressed]');
        const domainButton = buttons.find((button) => button.text() === 'Conhecimento');
        await domainButton?.trigger('click');

        expect(wrapper.text()).toContain('50,0 %');
    });

    it('mostra só as categorias de «sem classificação» com contagem diferente de zero', () => {
        const wrapper = mount(Results, { props: baseProps() });

        expect(wrapper.text()).toContain('Por classificar');
        expect(wrapper.text()).toContain('Ausentes');
        expect(wrapper.text()).not.toContain('Em revisão');
        expect(wrapper.text()).not.toContain('Ausências justificadas');
        expect(wrapper.text()).not.toContain('Dispensados');
    });

    it('mostra o aviso de diagnóstico quando is_diagnostic é verdadeiro', () => {
        const wrapper = mount(Results, {
            props: baseProps({ context: { ...baseProps().context, is_diagnostic: true } }),
        });

        expect(wrapper.text()).toContain('Avaliação diagnóstica');
        expect(wrapper.text()).toContain('não contribui para médias classificativas');
    });

    it('mostra o aviso de diagnóstico a contar para a classificação quando diagnostic_counts_warning é verdadeiro', () => {
        const wrapper = mount(Results, {
            props: baseProps({
                context: { ...baseProps().context, is_diagnostic: true, diagnostic_counts_warning: true },
            }),
        });

        expect(wrapper.text()).toContain('contrário à regra do produto');
    });

    it('não mostra avisos de diagnóstico quando não é diagnóstico', () => {
        const wrapper = mount(Results, { props: baseProps() });

        expect(wrapper.text()).not.toContain('Avaliação diagnóstica');
    });

    it('marca com asterisco e nota o caso em que o arredondamento esconde estar abaixo do limiar', () => {
        const props = baseProps();
        props.students[0].global = {
            value: '49.5', exact: '49.449999', band: null, below_threshold: true, is_partial: false,
        };

        const wrapper = mount(Results, { props });

        expect(wrapper.find('sup').exists()).toBe(true);
        expect(wrapper.text()).toContain('Valor exato abaixo de 49,5 %; o limiar aplica-se antes do arredondamento.');
    });

    it('mostra o textarea só de leitura quando can_edit é falso', () => {
        const wrapper = mount(Results, { props: baseProps({ can_edit: false }) });
        const textarea = wrapper.find('textarea');

        expect(textarea.attributes('disabled')).toBeDefined();
        expect(wrapper.find('button').exists()).toBeDefined();
        expect(wrapper.text()).not.toContain('Guardar observações');
    });

    it('mostra o botão de guardar quando can_edit é verdadeiro', () => {
        const wrapper = mount(Results, { props: baseProps({ can_edit: true }) });

        expect(wrapper.text()).toContain('Guardar observações');
        expect(wrapper.find('textarea').attributes('disabled')).toBeUndefined();
    });
});
