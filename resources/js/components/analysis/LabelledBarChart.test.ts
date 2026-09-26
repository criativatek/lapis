import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import LabelledBarChart from './LabelledBarChart.vue';

function mountChart(overrides: Partial<InstanceType<typeof LabelledBarChart>['$props']> = {}) {
    return mount(LabelledBarChart, {
        props: {
            title: 'Distribuição quantitativa — Classificação global',
            total: 23,
            categories: [
                { key: 'a', label: '[0, 10[', count: 0, percent: '0.0', tone: 'neutral', emphasis: null },
                { key: 'b', label: '[40, 50[', count: 5, percent: '21.7', tone: 'red', emphasis: 'below' },
                { key: 'c', label: '[90, 100]', count: 1, percent: '4.3', tone: 'green', emphasis: null },
            ],
            ...overrides,
        },
    });
}

describe('LabelledBarChart', () => {
    it('mostra o título e o N total visivelmente', () => {
        const wrapper = mountChart();

        expect(wrapper.text()).toContain('Distribuição quantitativa — Classificação global');
        expect(wrapper.text()).toContain('N = 23 classificações consideradas');
    });

    it('escreve rótulo, contagem e percentagem de cada categoria como texto', () => {
        const wrapper = mountChart();

        expect(wrapper.text()).toContain('[40, 50[');
        expect(wrapper.text()).toContain('5 alunos');
        expect(wrapper.text()).toContain('21,7 %');
        expect(wrapper.text()).toContain('1 aluno');
    });

    it('mantém as linhas com contagem zero, com «0» e barra vazia', () => {
        const wrapper = mountChart();

        expect(wrapper.text()).toContain('[0, 10[');
        expect(wrapper.text()).toContain('0,0 %');
    });

    it('marca as categorias abaixo do limiar com padrão e rótulo textual', () => {
        const wrapper = mountChart();

        expect(wrapper.text()).toContain('abaixo do limiar');
        expect(wrapper.find('.bar-stripe').exists()).toBe(true);
    });

    it('não aplica o padrão às categorias sem emphasis', () => {
        const wrapper = mountChart({
            categories: [{ key: 'x', label: 'Muito Bom', count: 3, percent: '13.0', tone: 'green', emphasis: null }],
        });

        expect(wrapper.find('.bar-stripe').exists()).toBe(false);
    });

    it('singular correto para 1 aluno e N = 1', () => {
        const wrapper = mountChart({
            total: 1,
            categories: [{ key: 'a', label: 'Suficiente', count: 1, percent: '100.0', tone: 'amber', emphasis: null }],
        });

        expect(wrapper.text()).toContain('N = 1 classificação considerada');
        expect(wrapper.text()).toContain('1 aluno');
    });
});
