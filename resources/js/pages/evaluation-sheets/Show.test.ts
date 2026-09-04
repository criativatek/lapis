import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import type { EvaluationSheet } from '@/types';
import Show from './Show.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    Link: defineComponent({ inheritAttrs: false, setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()) }),
    router: { get: vi.fn() },
}));

/**
 * Pautas de Avaliação (Fatia 2) — UMA ÚNICA VISTA, nunca três.
 *
 * Estes testes cobrem a regra central: os toggles de apresentação só
 * escondem colunas, nunca tocam nas props recebidas — e a coluna de nível
 * atribuído mostra "—" quando não há classificação, sem inventar um valor.
 */
function baseSheet(): EvaluationSheet {
    return {
        class_id: 1,
        academic_period_id: 1,
        scope: 'period',
        domains: [
            { domain_id: 1, name: 'Oralidade', sequence: 0, weight_percent: '25.0000', color: '#DCEAFB' },
            { domain_id: 2, name: 'Leitura', sequence: 1, weight_percent: '25.0000', color: '#E1F0E1' },
        ],
        students: [
            {
                enrollment_id: 10,
                class_number: 1,
                name: 'Carolina Nunes',
                overall: {
                    normalized_value: '91.000000',
                    scale_value: '5.000',
                    scale_level_id: 5,
                    scale_level_label: 'Muito Bom',
                    result_state: 'ok',
                    has_coverage_warning: false,
                },
                domains: [
                    {
                        domain_id: 1,
                        name: 'Oralidade',
                        sequence: 0,
                        normalized_value: '90.000000',
                        weight_percent_applied: '25.0000',
                        scale_level_id: 5,
                        scale_level_label: 'Muito Bom',
                        has_coverage_warning: false,
                        coverage: { absences: [], no_elements: false, excluded_domain_ids: [] },
                    },
                    {
                        domain_id: 2,
                        name: 'Leitura',
                        sequence: 1,
                        normalized_value: '93.125000',
                        weight_percent_applied: '25.0000',
                        scale_level_id: 5,
                        scale_level_label: 'Muito Bom',
                        has_coverage_warning: false,
                        coverage: { absences: [], no_elements: false, excluded_domain_ids: [] },
                    },
                ],
                classification: {
                    status: 'confirmed',
                    proposed_value: '91.000',
                    proposed_scale_level_id: 5,
                    proposed_scale_level_label: 'Muito Bom',
                    final_value: '4.000',
                    final_scale_level_id: 4,
                    final_scale_level_label: 'Bom',
                    override_reason: null,
                },
                coverage: { absences: [], no_elements: false, excluded_domain_ids: [] },
            },
            {
                enrollment_id: 11,
                class_number: 2,
                name: 'Diogo Ferreira',
                overall: {
                    normalized_value: null,
                    scale_value: null,
                    scale_level_id: null,
                    scale_level_label: null,
                    result_state: 'no_value',
                    has_coverage_warning: true,
                },
                domains: [],
                classification: null,
                coverage: { absences: [], no_elements: true, excluded_domain_ids: [] },
            },
        ],
    };
}

function baseProps() {
    return {
        schoolClass: { ulid: 'class-1', label: '7.º A', subject: 'Português', academic_year: '2026/2027', has_profile: true },
        periods: [{ ulid: 'period-1', label: '1.º Semestre', kind_label: 'Semestre', selected: true }],
        sheet: baseSheet(),
    };
}

describe('evaluation-sheets/Show — a única vista', () => {
    it('renders every domain name from the payload', () => {
        const wrapper = mount(Show, { props: baseProps() });

        expect(wrapper.text()).toContain('Oralidade');
        expect(wrapper.text()).toContain('Leitura');
    });

    it('shows "—" for the assigned level when the student has no classification', () => {
        const wrapper = mount(Show, { props: baseProps() });
        const rows = wrapper.findAll('tbody tr');
        const diogoRow = rows.find((row) => row.text().includes('Diogo Ferreira'));

        expect(diogoRow).toBeDefined();
        expect(diogoRow!.text()).toContain('—');
    });

    it('shows the decided level in bold and never the proposal once a decision exists', () => {
        const wrapper = mount(Show, { props: baseProps() });

        // Carolina's final decision ("Bom") is what shows, not the proposal
        // ("Muito Bom") — a decision is never overwritten by the proposal.
        const rows = wrapper.findAll('tbody tr');
        const carolinaRow = rows.find((row) => row.text().includes('Carolina Nunes'));

        expect(carolinaRow).toBeDefined();
        expect(carolinaRow!.text()).toContain('Bom');
    });

    it('hiding the quantitative toggle changes only what is rendered, never the props the component received', async () => {
        const props = baseProps();
        const wrapper = mount(Show, { props });

        const before = JSON.stringify(wrapper.props('sheet'));

        const quantitativeCheckbox = wrapper.findAll('input[type="checkbox"]')[0];
        await quantitativeCheckbox.setValue(false);

        const after = JSON.stringify(wrapper.props('sheet'));

        expect(after).toBe(before);
        // The percentage figure that only shows in quantitative mode is gone.
        expect(wrapper.text()).not.toContain('91,0%');
    });

    it('hiding domain detail collapses to the global column without touching the payload', async () => {
        const wrapper = mount(Show, { props: baseProps() });
        const before = JSON.stringify(wrapper.props('sheet'));

        const domainDetailCheckbox = wrapper.findAll('input[type="checkbox"]')[1];
        await domainDetailCheckbox.setValue(false);

        expect(JSON.stringify(wrapper.props('sheet'))).toBe(before);
        expect(wrapper.find('th').text()).not.toBe('Oralidade');
    });
});
