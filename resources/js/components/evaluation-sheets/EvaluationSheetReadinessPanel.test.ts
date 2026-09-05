import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import type { EvaluationSheetReadiness } from '@/types';
import EvaluationSheetReadinessPanel from './EvaluationSheetReadinessPanel.vue';

vi.mock('@inertiajs/vue3', () => ({
    Link: defineComponent({ inheritAttrs: false, setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()) }),
}));

/**
 * «Preparar fecho» — uma leitura sobre a pauta, nunca uma segunda pauta.
 *
 * Estes testes cobrem as regras do painel: terminologia do momento sempre
 * dinâmica, três estados no máximo, texto e ícone à frente da cor, links de
 * resolução para o contexto certo — e a frase que diz ao professor que nada
 * disto o impede de fechar.
 */
function baseReadiness(): EvaluationSheetReadiness {
    return {
        moment: { period_label: '1.º Semestre', kind_label: 'Semestre', is_closing: true },
        summary: { students_total: 6, students_with_notes: 2, students_ready: 4, attention_count: 3 },
        items: [
            { key: 'decisions', state: 'attention', label: '4 de 6 níveis atribuídos', detail: '2 propostas por decidir', action: 'classifications' },
            { key: 'coverage', state: 'ok', label: 'Sem lacunas de cobertura detetadas', detail: null, action: 'results' },
            { key: 'self-assessments', state: 'neutral', label: 'Autoavaliações não utilizadas neste momento', detail: null, action: null },
            { key: 'inovar', state: 'neutral', label: 'Exportação Inovar ainda não realizada', detail: null, action: 'inovar' },
        ],
        students: [
            {
                enrollment_ulid: 'enr-ana',
                class_number: 1,
                name: 'Ana Marques',
                pending: [
                    { state: 'attention', label: 'Proposta do Lapispro ainda não decidida', action: 'classifications' },
                    { state: 'attention', label: 'Autoavaliação em falta', action: 'self-assessment' },
                ],
            },
            {
                enrollment_ulid: 'enr-diogo',
                class_number: 4,
                name: 'Diogo Ferreira',
                pending: [{ state: 'attention', label: 'Sem elementos avaliados neste momento', action: 'results' }],
            },
        ],
    };
}

function mountPanel(readiness: EvaluationSheetReadiness = baseReadiness()) {
    return mount(EvaluationSheetReadinessPanel, {
        props: { readiness, classUlid: 'class-1', periodUlid: 'period-1' },
    });
}

describe('EvaluationSheetReadinessPanel', () => {
    it('names the moment in the period’s own terminology, never a hardcoded word', () => {
        const wrapper = mountPanel();

        expect(wrapper.text()).toContain('Preparação — Semestre: 1.º Semestre');
    });

    it('counts the points to look at without ever declaring the sheet closed', () => {
        const wrapper = mountPanel();

        expect(wrapper.text()).toContain('Há 3 pontos a verificar.');
        expect(wrapper.text()).toContain('4 de 6 alunos sem pendências.');
        expect(wrapper.text()).not.toContain('Pronto para fechar');
    });

    it('says «sem pendências detetadas» — support language, not a verdict', () => {
        const readiness = baseReadiness();
        readiness.summary = { students_total: 6, students_with_notes: 0, students_ready: 6, attention_count: 0 };
        readiness.students = [];

        const wrapper = mountPanel(readiness);

        expect(wrapper.text()).toContain('Sem pendências detetadas.');
    });

    it('flags an interim moment as informative, in the period’s own kind label', () => {
        const readiness = baseReadiness();
        readiness.moment = { period_label: '2.º Período', kind_label: 'Período', is_closing: false };

        const wrapper = mountPanel(readiness);

        expect(wrapper.text()).toContain('Período ainda a decorrer');
    });

    it('every state carries a textual label for screen readers — colour is never the only signal', () => {
        const wrapper = mountPanel();
        const srLabels = wrapper.findAll('.sr-only').map((node) => node.text());

        expect(srLabels).toContain('Ponto a verificar:');
        expect(srLabels).toContain('Sem pendências:');
        expect(srLabels).toContain('Informativo / não aplicável:');
    });

    it('links each class item to its own screen in the right class and period', () => {
        const wrapper = mountPanel();
        const hrefs = wrapper.findAll('a').map((anchor) => anchor.attributes('href'));

        expect(hrefs).toContain('/classes/class-1/classifications/period-1');
        expect(hrefs).toContain('/classes/class-1/pauta-avaliacao/inovar/period-1');
    });

    it('resolves a student’s self-assessment straight to that student’s form', () => {
        const wrapper = mountPanel();
        const hrefs = wrapper.findAll('a').map((anchor) => anchor.attributes('href'));

        expect(hrefs).toContain('/classes/class-1/self-assessments/period-1/enr-ana');
    });

    it('lists only students with pending points, by name and number', () => {
        const wrapper = mountPanel();

        expect(wrapper.text()).toContain('Ana Marques');
        expect(wrapper.text()).toContain('Diogo Ferreira');
        expect(wrapper.text()).toContain('Sem elementos avaliados neste momento');
    });

    it('tells the teacher that nothing here blocks the close — the decision stays theirs', () => {
        const wrapper = mountPanel();

        expect(wrapper.text()).toContain('nada nesta lista impede o fecho');
        expect(wrapper.text()).toContain('A decisão é sempre do professor.');
    });
});
