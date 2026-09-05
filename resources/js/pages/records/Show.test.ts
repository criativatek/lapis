import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';
import Show from './Show.vue';

const { routerPost } = vi.hoisted(() => ({ routerPost: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    Link: defineComponent({
        inheritAttrs: false,
        setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()),
    }),
    router: { post: routerPost, put: vi.fn(), delete: vi.fn(), get: vi.fn() },
    useForm: (data: Record<string, unknown>) =>
        reactive({
            ...data,
            errors: {},
            processing: false,
            post: vi.fn(),
            put: vi.fn(),
            reset: vi.fn(),
            clearErrors: vi.fn(),
        }),
}));

const wrappers: VueWrapper[] = [];

function baseProps(overrides: Record<string, unknown> = {}) {
    return {
        schoolClass: { ulid: 'class-1', label: '7.º A', subject: 'Português' },
        enrollments: [{ id: 1, name: 'Maria Silva' }],
        activeEnrollmentIds: [1],
        domains: [],
        kinds: [{ value: 'incident', label: 'Ocorrência disciplinar', group: 'behaviour', group_label: 'Comportamento' }],
        severities: [{ value: 'minor', label: 'Ligeira' }],
        periods: [],
        filters: { enrollment_id: null, kind: null, period_id: null },
        records: [],
        ai: { available: true, reason: null },
        incidentRewrite: null,
        incidentRewriteError: null,
        ...overrides,
    };
}

function mountShow(props: Record<string, unknown> = {}) {
    const wrapper = mount(Show, { props: baseProps(props) });
    wrappers.push(wrapper);

    return wrapper;
}

afterEach(() => {
    routerPost.mockClear();
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
});

/**
 * «Aperfeiçoar redação» on the disciplinary occurrence description (SUP-U8FMAE).
 *
 * ONLY ON `incident`, AND ONLY WHEN THE SERVER SAYS THE ENGINE IS AVAILABLE —
 * the button never draws itself as a control that can only fail (CLAUDE.md
 * §8.2). Accepting a suggestion only ever changes the text inside this
 * still-open form; nothing here posts to the record-saving endpoints.
 */
describe('records Show — aperfeiçoar redação', () => {
    it('is absent for a kind other than incident', () => {
        const wrapper = mountShow();

        expect(wrapper.text()).not.toContain('Aperfeiçoar redação');
    });

    it('offers the button once the teacher picks "Ocorrência disciplinar"', async () => {
        const wrapper = mountShow();

        await wrapper.find('select').setValue('incident');
        await wrapper.find('textarea').setValue('O aluno interrompeu a aula.');

        expect(wrapper.text()).toContain('Aperfeiçoar redação');
    });

    it('is replaced by the plan sentence when the server says the engine is unavailable', async () => {
        const wrapper = mountShow({ ai: { available: false, reason: 'plan' } });

        await wrapper.find('select').setValue('incident');

        expect(wrapper.text()).not.toContain('Aperfeiçoar redação');
        expect(wrapper.text()).toContain('Pro e Institucional');
    });

    it('posts the class-scoped endpoint with the current draft, never a save route', async () => {
        const wrapper = mountShow();

        await wrapper.find('select').setValue('incident');
        await wrapper.find('textarea').setValue('O aluno interrompeu a aula.');

        const trigger = wrapper.findAll('button').find((button) => button.text().includes('Aperfeiçoar redação'));
        await trigger?.trigger('click');

        expect(routerPost).toHaveBeenCalledWith(
            '/classes/class-1/records/aperfeicoar-descricao',
            { kind: 'incident', description: 'O aluno interrompeu a aula.' },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('shows a returned suggestion beside the current text and never writes until accepted', async () => {
        const wrapper = mountShow({
            incidentRewrite: {
                current: 'O aluno interrompeu a aula.',
                text: 'O aluno interrompeu a aula por diversas vezes.',
                provider: 'fake',
                model: 'modelo-de-teste',
                pseudonymised: false,
                message: null,
            },
        });

        await wrapper.find('select').setValue('incident');

        expect(wrapper.text()).toContain('O aluno interrompeu a aula por diversas vezes.');
        expect(wrapper.text()).toContain('Usar sugestão');
    });

    it('accepting the suggestion replaces the form text and clears the preview', async () => {
        const wrapper = mountShow({
            incidentRewrite: {
                current: 'O aluno interrompeu a aula.',
                text: 'O aluno interrompeu a aula por diversas vezes.',
                provider: 'fake',
                model: 'modelo-de-teste',
                pseudonymised: false,
                message: null,
            },
        });

        await wrapper.find('select').setValue('incident');

        const acceptButton = wrapper.findAll('button').find((button) => button.text().includes('Usar sugestão'));
        await acceptButton?.trigger('click');

        expect((wrapper.find('textarea').element as HTMLTextAreaElement).value).toBe(
            'O aluno interrompeu a aula por diversas vezes.',
        );
        expect(wrapper.text()).not.toContain('Usar sugestão');
    });

    it('shows the error sentence and preserves the draft when the engine fails', async () => {
        const wrapper = mountShow({
            incidentRewriteError: { message: 'Não foi possível pedir a reformulação.' },
        });

        await wrapper.find('select').setValue('incident');
        await wrapper.find('textarea').setValue('O aluno interrompeu a aula.');

        expect(wrapper.text()).toContain('Não foi possível pedir a reformulação.');
        expect((wrapper.find('textarea').element as HTMLTextAreaElement).value).toBe('O aluno interrompeu a aula.');
    });
});
