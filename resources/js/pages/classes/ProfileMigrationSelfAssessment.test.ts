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
 * The «Autoavaliação» section of the migration preview: what the change does to
 * the self-assessment, said before it is confirmed. Nothing is converted.
 */
const DOMAINS = [{ name: 'Oralidade', weight: '100' }];

function mountWith(selfAssessment: Record<string, unknown> | undefined, comparison: Record<string, unknown> = {}) {
    return mount(ProfileMigration, {
        props: {
            schoolClass: { ulid: 'class-1', label: '8.º A', subject: 'Português' },
            toVersionUlid: 'version-2',
            preview: {
                from: { version: 3, name: 'Avaliação final', scale: { name: 'Percentagem (0 a 100)', kind: 'percentage' }, domains: DOMAINS },
                to: { version: 6, name: 'Avaliação final', scale: { name: 'Escala 1 a 5', kind: 'level' }, domains: DOMAINS },
                comparison: {
                    scale_changes: false,
                    domains_same: true,
                    weights_same: true,
                    rules_same: true,
                    domains: [{ name: 'Oralidade', from_weight: '100', to_weight: '100', changed: false }],
                    ...comparison,
                },
                periods: [],
                rows: [],
                affected_enrollment_count: 0,
                recalculated_result_count: 0,
                kept_result_count: 0,
                self_assessment: selfAssessment as never,
            },
        },
    });
}

function section(wrapper: ReturnType<typeof mountWith>): string {
    return wrapper.get('[data-testid="self-assessment-impact"]').text();
}

describe('ProfileMigration — Autoavaliação', () => {
    it('not_started', () => {
        const text = section(mountWith({ state: 'not_started', saved_count: 0, student_count: 0, periods: [] }));

        expect(text).toContain('Autoavaliação');
        expect(text).toContain('A autoavaliação ainda não foi aberta nesta turma. Quando for, usa os critérios desta versão.');
    });

    it('follows', () => {
        expect(section(mountWith({ state: 'follows', saved_count: 0, student_count: 0, periods: [] }))).toContain(
            'A autoavaliação ainda não tem respostas: passa a usar os domínios, as ponderações e a escala desta versão.',
        );
    });

    it('kept, with the scale the answers stay on and the history of removed domains', () => {
        const wrapper = mountWith({ state: 'kept', saved_count: 3, student_count: 3, periods: ['1.º Período', '2.º Período'] }, { scale_changes: true, domains_same: false });
        const text = section(wrapper);

        expect(text).toContain('Já há 3 autoavaliações guardadas (1.º Período, 2.º Período).');
        expect(text).toContain('Ficam ligadas aos critérios em que foram preenchidas: nada é apagado, convertido nem redistribuído.');
        expect(text).toContain('Depois de aplicar, pode iniciar uma nova autoavaliação com os critérios atuais na página Autoavaliação da turma.');
        expect(text).toContain('As respostas já dadas continuam na escala Percentagem (0 a 100).');
        expect(text).toContain('Os domínios acrescentados só aparecem na nova autoavaliação; as respostas a domínios retirados ficam no histórico.');
    });

    it('kept without scale or domain changes leaves out the two extra sentences', () => {
        const text = section(mountWith({ state: 'kept', saved_count: 1, student_count: 1, periods: ['1.º Período'] }));

        expect(text).toContain('Já há 1 autoavaliação guardada (1.º Período).');
        expect(text).not.toContain('As respostas já dadas continuam na escala');
        expect(text).not.toContain('Os domínios acrescentados');
    });

    it('unchanged', () => {
        expect(section(mountWith({ state: 'unchanged', saved_count: 0, student_count: 0, periods: [] }))).toContain('A autoavaliação já usa os critérios desta versão.');
    });

    it('has no section when the server sends none', () => {
        expect(mountWith(undefined).find('[data-testid="self-assessment-impact"]').exists()).toBe(false);
    });
});
