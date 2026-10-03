import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';
import Preview from './Preview.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({
        setup:
            (_, { slots }) =>
            () =>
                h('div', slots.default?.()),
    }),
    Link: defineComponent({
        props: { href: { type: String, default: '' } },
        setup:
            (props, { slots }) =>
            () =>
                h('a', { 'data-href': props.href }, slots.default?.()),
    }),
    useForm: (data: Record<string, unknown>) =>
        reactive({
            ...data,
            processing: false,
            post: vi.fn(),
            delete: vi.fn(),
        }),
}));

const wrappers: VueWrapper[] = [];

const SENTENCE = 'Campos obrigatórios em falta ou inválidos.';

function tally(partial: Partial<Record<string, number>> = {}) {
    return {
        new: 0,
        existing: 0,
        conflict: 0,
        invalid: 0,
        unsupported: 0,
        ...partial,
    };
}

function mountPreview() {
    const wrapper = mount(Preview, {
        props: {
            dataImport: {
                ulid: '01J0000000000000000000000A',
                status: 'pending',
                status_label: 'Por confirmar',
                original_filename: 'backup.zip',
                source_schema_version: 13,
                source_app_version: '0.156.4',
                source_generated_at: null,
                source_organization: null,
                summary: null,
                failure_reason: null,
            },
            destination: { name: 'Org fictícia', type: 'personal' },
            plan: {
                rows: {
                    student_item_scores: [
                        { classification: 'new', reason: null },
                        { classification: 'invalid', reason: SENTENCE },
                    ],
                    item_domain_allocations: [
                        { classification: 'invalid', reason: SENTENCE },
                    ],
                    self_assessment_responses: [
                        { classification: 'invalid', reason: SENTENCE },
                    ],
                    profile_version_domains: [
                        { classification: 'invalid', reason: SENTENCE },
                    ],
                    cancelled_lesson_occurrences: [
                        { classification: 'invalid', reason: SENTENCE },
                    ],
                },
                counts: {
                    student_item_scores: tally({ new: 1, invalid: 1 }),
                    item_domain_allocations: tally({ invalid: 1 }),
                    self_assessment_responses: tally({ invalid: 1 }),
                    profile_version_domains: tally({ invalid: 1 }),
                    cancelled_lesson_occurrences: tally({ invalid: 1 }),
                },
                can_confirm: true,
            },
        },
    });

    wrappers.push(wrapper);

    return wrapper;
}

afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
});

describe('imports/data/Preview — recusas de linhas filhas', () => {
    it('lista cada recusa em «Pontos a rever» com o nome do domínio e a frase do validador', () => {
        const text = mountPreview().text();

        expect(text).toContain('Pontos a rever');
        expect(text).toContain('Pontuações');
        expect(text).toContain('Alocações de itens a domínios');
        expect(text).toContain('Respostas de autoavaliação');
        expect(text).toContain('Pesos dos domínios nos perfis');
        expect(text).toContain('Aulas canceladas');
        expect(text.split(SENTENCE)).toHaveLength(6);
    });

    it('nunca mostra um traço solto no lugar do nome de um domínio escondido da tabela', () => {
        const items = mountPreview().findAll('li');
        const issues = items.filter((li) => li.text().includes(SENTENCE));

        expect(issues).toHaveLength(5);
        issues.forEach((li) => expect(li.text().startsWith('—')).toBe(false));
    });
});
