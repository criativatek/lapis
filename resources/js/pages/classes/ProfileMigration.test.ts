import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';
import ProfileMigration from './ProfileMigration.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    Link: defineComponent({ inheritAttrs: false, setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()) }),
    useForm: (data: Record<string, unknown>) => reactive({ ...data, errors: {}, processing: false, post: vi.fn() }),
}));

/**
 * A PRÉ-VISUALIZAÇÃO EXPLICA A MUDANÇA, em vez de só comparar números.
 *
 * O caso de produção: uma turma em «Percentagem (0 a 100)» migra para «Escala 1
 * a 5» com os mesmos domínios e pesos. O número de cada aluno não muda — muda a
 * escala em que a proposta se lê. A página dizia «0 alunos afetados» e escrevia
 * «3.0%» para um 3.
 */

const DOMAINS = [
    { name: 'Oralidade', weight: '60' },
    { name: 'Escrita', weight: '40' },
];

function previewOf(overrides: Record<string, unknown> = {}) {
    return {
        from: { version: 3, name: 'Avaliação final', scale: { name: 'Percentagem (0 a 100)', kind: 'percentage' }, domains: DOMAINS },
        to: { version: 6, name: 'Avaliação final', scale: { name: 'Escala 1 a 5', kind: 'level' }, domains: DOMAINS },
        comparison: {
            scale_changes: true,
            domains_same: true,
            weights_same: true,
            rules_same: true,
            domains: [
                { name: 'Oralidade', from_weight: '60', to_weight: '60', changed: false },
                { name: 'Escrita', from_weight: '40', to_weight: '40', changed: false },
            ],
        },
        periods: ['1.º Semestre'],
        rows: [
            {
                name: 'Eva Salgado',
                class_number: 1,
                changed: true,
                cells: [
                    {
                        period_label: '1.º Semestre',
                        state: 'refreshed' as const,
                        before: '78.000000',
                        after: '78.000000',
                        before_text: '78%',
                        after_text: '4 — Bom',
                        changed: true,
                    },
                ],
            },
            {
                name: 'Carolina Nunes',
                class_number: 2,
                changed: false,
                cells: [
                    {
                        period_label: '1.º Semestre',
                        state: 'kept' as const,
                        before: '65.000',
                        after: '65.000',
                        before_text: '65%',
                        after_text: '65%',
                        changed: false,
                    },
                ],
            },
        ],
        affected_enrollment_count: 1,
        recalculated_result_count: 1,
        kept_result_count: 1,
        ...overrides,
    };
}

function mountWith(preview = previewOf()) {
    return mount(ProfileMigration, {
        props: {
            schoolClass: { ulid: 'cls-1', label: '7.º A', subject: 'Português' },
            toVersionUlid: 'ver-6',
            preview,
        },
    });
}

describe('classes/ProfileMigration — o bloco de comparação', () => {
    it('destaca a mudança de escala, com as duas escalas', () => {
        const text = mountWith().text();

        expect(text).toContain('Escala:');
        expect(text).toContain('Percentagem (0 a 100) → Escala 1 a 5');
    });

    it('diz «sem alteração» quando a escala é a mesma', () => {
        const preview = previewOf({
            from: { version: 3, name: 'Avaliação final', scale: { name: 'Escala 1 a 5', kind: 'level' }, domains: DOMAINS },
            comparison: { ...previewOf().comparison, scale_changes: false },
        });

        const text = mountWith(preview).text();

        expect(text).toContain('sem alteração (Escala 1 a 5)');
        expect(text).not.toContain('→ Escala 1 a 5');
    });

    it('mostra os domínios com o peso de origem e de destino e o indicador «iguais»', () => {
        const wrapper = mountWith();
        const rows = wrapper.findAll('section table tbody tr');

        expect(rows).toHaveLength(2);
        expect(rows[0].text()).toContain('Oralidade');
        expect(rows[0].text()).toContain('60%');
        expect(rows[0].text()).toContain('iguais');
        expect(rows[1].text()).toContain('Escrita');
        expect(rows[1].text()).toContain('40%');
    });

    it('marca como «alterado» um domínio cujo peso mudou, e as regras como «alteradas»', () => {
        const preview = previewOf({
            comparison: {
                scale_changes: false,
                domains_same: true,
                weights_same: false,
                rules_same: false,
                domains: [
                    { name: 'Oralidade', from_weight: '60', to_weight: '50', changed: true },
                    { name: 'Escrita', from_weight: '40', to_weight: '50', changed: true },
                ],
            },
        });

        const wrapper = mountWith(preview);
        const rows = wrapper.findAll('section table tbody tr');

        expect(rows[0].text()).toContain('alterado');
        expect(rows[0].text()).toContain('50%');
        expect(wrapper.text().replace(/\s+/g, ' ')).toContain('Regras de cálculo: alteradas');
    });

    it('diz «iguais» para as regras quando não mudam', () => {
        expect(mountWith().text().replace(/\s+/g, ' ')).toContain('Regras de cálculo: iguais');
    });
});

describe('classes/ProfileMigration — a explicação da mudança de escala', () => {
    it('explica que o resultado em percentagem não muda, que a proposta é lida na nova escala e que as decisões ficam', () => {
        const explanation = mountWith().find('[data-testid="scale-change-explanation"]');
        const text = explanation.text().replace(/\s+/g, ' ');

        expect(explanation.exists()).toBe(true);
        expect(text).toContain('O resultado em percentagem de cada aluno não muda');
        expect(text).toContain('lida na nova escala');
        expect(text).toContain('ficam como foram tomadas, na escala de origem');
    });

    it('dá um exemplo tirado da própria pré-visualização', () => {
        const text = mountWith().find('[data-testid="scale-change-explanation"]').text().replace(/\s+/g, ' ');

        expect(text).toContain('a proposta de Eva Salgado (1.º Semestre), hoje lida como 78%, passa a ser lida como 4 — Bom');
    });

    it('não afirma que a percentagem não muda quando pesos ou regras também mudam', () => {
        const preview = previewOf({
            comparison: { ...previewOf().comparison, weights_same: false },
        });

        const explanation = mountWith(preview).find('[data-testid="scale-change-explanation"]');

        expect(explanation.exists()).toBe(true);
        expect(explanation.text()).not.toContain('não muda');
    });

    it('não aparece quando a escala é a mesma', () => {
        const preview = previewOf({ comparison: { ...previewOf().comparison, scale_changes: false } });

        expect(mountWith(preview).find('[data-testid="scale-change-explanation"]').exists()).toBe(false);
    });
});

describe('classes/ProfileMigration — as células e o cabeçalho', () => {
    it('mostra o que o professor lê antes e depois, e nunca «3.0%» para um nível', () => {
        const text = mountWith().text();

        expect(text).toContain('78%');
        expect(text).toContain('4 — Bom');
        expect(text).not.toMatch(/\d\.\d%/);
    });

    it('uma decisão mantida mostra-se na escala em que foi tomada', () => {
        const rows = mountWith().findAll('tbody tr');
        const carolina = rows.find((row) => row.text().includes('Carolina Nunes'));

        expect(carolina).toBeTruthy();
        expect(carolina!.text()).toContain('65%');
        expect(carolina!.text()).toContain('mantida');
    });

    it('o cabeçalho conta alunos com a proposta alterada, propostas recalculadas e decisões mantidas', () => {
        const text = mountWith().text().replace(/\s+/g, ' ');

        // Singular for one — «1 decisões mantidas» read as a typo on the real page.
        expect(text).toContain('1 aluno com a proposta alterada');
        expect(text).toContain('1 proposta recalculada');
        expect(text).toContain('1 decisão mantida');
    });

    it('o cabeçalho usa o plural a partir de dois', () => {
        const text = mountWith(previewOf({ affected_enrollment_count: 20, recalculated_result_count: 20, kept_result_count: 0 })).text().replace(/\s+/g, ' ');

        expect(text).toContain('20 alunos com a proposta alterada');
        expect(text).toContain('20 propostas recalculadas');
        expect(text).toContain('0 decisões mantidas');
    });
});
