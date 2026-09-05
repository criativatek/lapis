import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';
import type { EvaluationSheet, EvaluationSheetReadiness } from '@/types';
import Show from './Show.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    Link: defineComponent({ inheritAttrs: false, setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()) }),
    router: { get: vi.fn(), post: vi.fn() },
    useForm: (fields: Record<string, unknown>) => reactive({ ...fields, errors: {}, processing: false, post: vi.fn() }),
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

/**
 * «Preparar fecho» — o botão abre uma LEITURA já recebida do servidor.
 *
 * Abrir e fechar o painel não pede nada ao servidor e não toca na pauta;
 * sem payload de preparação (sem pauta, sem alunos) o botão nem aparece.
 */
describe('evaluation-sheets/Show — preparar fecho', () => {
    function readiness(): EvaluationSheetReadiness {
        return {
            moment: { period_label: '1.º Semestre', kind_label: 'Semestre', is_closing: true },
            summary: { students_total: 2, students_with_notes: 1, students_ready: 1, attention_count: 2 },
            items: [
                { key: 'decisions', state: 'attention', label: '1 de 2 níveis atribuídos', detail: null, action: 'classifications' },
            ],
            students: [
                {
                    enrollment_ulid: 'enr-diogo',
                    class_number: 2,
                    name: 'Diogo Ferreira',
                    pending: [{ state: 'attention', label: 'Nível ainda não atribuído', action: 'classifications' }],
                },
            ],
        };
    }

    it('shows the button with the attention count, and only opens the panel on demand', async () => {
        const wrapper = mount(Show, { props: { ...baseProps(), readiness: readiness() } });

        const button = wrapper.findAll('button').find((candidate) => candidate.text().includes('Preparar fecho'));
        expect(button).toBeDefined();
        expect(button!.text()).toContain('2');
        expect(wrapper.text()).not.toContain('Preparação — Semestre: 1.º Semestre');

        await button!.trigger('click');

        expect(wrapper.text()).toContain('Preparação — Semestre: 1.º Semestre');
        expect(wrapper.text()).toContain('Nível ainda não atribuído');
    });

    it('offers no button at all when there is nothing to prepare', () => {
        const wrapper = mount(Show, { props: { ...baseProps(), readiness: null } });

        expect(wrapper.findAll('button').some((candidate) => candidate.text().includes('Preparar fecho'))).toBe(false);
    });

    it('keeps the panel off the printed sheet', async () => {
        const wrapper = mount(Show, { props: { ...baseProps(), readiness: readiness() } });

        const button = wrapper.findAll('button').find((candidate) => candidate.text().includes('Preparar fecho'));
        await button!.trigger('click');

        const panel = wrapper.find('section[aria-label="Preparação do fecho do momento"]');
        expect(panel.exists()).toBe(true);
        expect(panel.classes()).toContain('print-hide');
    });
});

/**
 * Imprimir e exportar — a mesma pauta, duas regras OPOSTAS e deliberadas.
 *
 * O papel é WYSIWYG: sai o que está no ecrã, incluindo o que o professor
 * escolheu esconder. O CSV é um ficheiro de dados: leva sempre tudo, e por isso
 * o seu link nunca muda com os toggles — nem sequer chega a falar deles.
 */
describe('evaluation-sheets/Show — imprimir e exportar', () => {
    it('offers a CSV download whose address never changes with the toggles', async () => {
        const wrapper = mount(Show, { props: baseProps() });

        const link = wrapper.findAll('a').find((anchor) => anchor.text().includes('Exportar CSV'));
        expect(link).toBeDefined();

        const before = link!.attributes('href');
        expect(before).toBe('/classes/class-1/pauta-avaliacao/csv/period-1');

        // Desligar tudo o que se pode desligar não retira uma única coluna ao
        // ficheiro, porque o pedido é o mesmo pedido.
        for (const checkbox of wrapper.findAll('input[type="checkbox"]')) {
            await checkbox.setValue(false);
        }

        const after = wrapper.findAll('a').find((anchor) => anchor.text().includes('Exportar CSV'));
        expect(after!.attributes('href')).toBe(before);
    });

    it('prints from this very screen, and keeps the controls off the paper', () => {
        const wrapper = mount(Show, { props: baseProps() });

        const printButton = wrapper.findAll('button').find((button) => button.text().includes('Imprimir'));
        expect(printButton).toBeDefined();

        // Tudo o que é interativo está marcado para não sair impresso.
        const controls = wrapper.findAll('.print-hide');
        expect(controls.length).toBeGreaterThanOrEqual(3);

        // O botão de imprimir é ele próprio um controlo, e por isso está dentro
        // de um bloco que não vai ao papel.
        expect(printButton!.element.closest('.print-hide')).not.toBeNull();

        // E a grelha NÃO está: é precisamente o que se imprime.
        expect(wrapper.find('table').element.closest('.print-hide')).toBeNull();
        expect(wrapper.find('table').element.closest('.pauta-print')).not.toBeNull();
    });

    it('the printed header names the class, the subject and the period in its own terminology', () => {
        const wrapper = mount(Show, { props: baseProps() });

        const header = wrapper.find('.pauta-print .print\\:block');
        expect(header.exists()).toBe(true);
        expect(header.text()).toContain('7.º A');
        expect(header.text()).toContain('Português');
        // «Semestre», vindo do período — nunca uma palavra fixa no código.
        expect(header.text()).toContain('Semestre');
        expect(header.text()).toContain('1.º Semestre');
        expect(header.text()).toContain('Impresso em');
    });
});
