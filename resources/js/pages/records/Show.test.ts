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

/**
 * Compor um registo e confirmá-lo são DUAS FASES (SUP-B7K823).
 *
 * O formulário está sempre aberto, e enquanto o único botão se chamou
 * «Adicionar registo» o ecrã dizia o que o professor queria fazer em vez de
 * dizer o que aquele botão fazia — quem lia carregava à espera de abrir um
 * registo novo e submetia um formulário vazio. O cartão passa a nomear a fase,
 * e o botão a nomear o que confirma.
 */
describe('records Show — compor e confirmar são fases distintas', () => {
    function recordRow(overrides: Record<string, unknown> = {}) {
        return {
            ulid: 'record-1',
            kind: 'incident',
            kind_label: 'Ocorrência disciplinar',
            enrollment_id: 1,
            domain_id: null,
            disciplinary_severity: 'minor',
            disciplinary_severity_label: 'Ligeira',
            homework_status: null,
            participation_level: null,
            activity_evaluation: null,
            activity_include_in_report: null,
            detail_label: null,
            description: 'Chegou atrasado.',
            student: 'Maria Silva',
            domain: null,
            occurred_at: '2026-09-05T00:00:00+01:00',
            ...overrides,
        };
    }

    it('names the phase on the card, so the form is not just fields', () => {
        const wrapper = mountShow();

        expect(wrapper.text()).toContain('Novo registo');
    });

    it('the button says what it does, never what the teacher wants to do', () => {
        const wrapper = mountShow();

        const submit = wrapper.find('button[type="submit"]');
        expect(submit.text()).toBe('Guardar registo');

        // O rótulo antigo era o do gesto de começar, e é o que se confundia
        // com abrir um registo novo. Não pode voltar por descuido.
        expect(wrapper.text()).not.toContain('Adicionar registo');
    });

    it('offers a way to drop what was composed, and only once there is something to drop', async () => {
        const wrapper = mountShow();

        const clear = wrapper.findAll('button').find((button) => button.text() === 'Limpar');
        expect(clear).toBeDefined();
        expect(clear!.attributes('disabled')).toBeDefined();

        await wrapper.find('textarea').setValue('Chegou atrasado ao início da aula.');

        expect(clear!.attributes('disabled')).toBeUndefined();
    });

    it('editing names its own phase and keeps its own pair of buttons', async () => {
        const wrapper = mountShow({ records: [recordRow()] });

        // O botão de editar é um ícone, e diz-se pelo `aria-label` — o
        // `title` não é um nome acessível fiável.
        const editButton = wrapper.find('button[aria-label="Editar registo de Maria Silva"]');
        expect(editButton.exists()).toBe(true);
        await editButton.trigger('click');

        expect(wrapper.text()).toContain('A editar registo');
        expect(wrapper.text()).not.toContain('Novo registo');

        expect(wrapper.find('button[type="submit"]').text()).toBe('Guardar alterações');

        // «Cancelar» sai da edição; «Limpar» pertence só à criação.
        const labels = wrapper.findAll('button').map((button) => button.text());
        expect(labels).toContain('Cancelar');
        expect(labels).not.toContain('Limpar');
    });
});
