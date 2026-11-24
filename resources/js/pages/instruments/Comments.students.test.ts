import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';
import Comments from './Comments.vue';

const mocks = vi.hoisted(() => ({ put: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    Link: defineComponent({ inheritAttrs: false, setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()) }),
    router: { put: mocks.put },
    useForm: (data: Record<string, unknown>) => reactive({ ...data, errors: {}, processing: false, put: vi.fn() }),
}));

function baseProps(overrides: Record<string, unknown> = {}) {
    return {
        instrument: {
            ulid: 'inst', title: 'Apresentação oral', status: 'in_correction', status_label: 'Em correção',
            applied_on: '2026-10-15', class_label: '7.º A', class_ulid: 'cls', period: '1.º Período',
        },
        note: { body: '', lock_version: 0, updated_at: null, updated_by: null },
        can_edit: true,
        cancelled: false,
        items: [],
        students: [
            { enrollment_ulid: 'e1', class_number: 1, name: 'Aluno Um', comment: { body: 'Apanhado a copiar na Q2', lock_version: 1 } },
            { enrollment_ulid: 'e2', class_number: 2, name: 'Aluno Dois', comment: null },
            { enrollment_ulid: 'e3', class_number: 3, name: 'Aluno Três', comment: null },
        ],
        links: { note: '/n', item_notes: '/i', grid: '/instruments/inst', results: '/r' },
        ...overrides,
    };
}

describe('instruments/Comments — Por aluno', () => {
    beforeEach(() => {
        mocks.put.mockReset();
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('lista só os alunos com comentário e oferece os restantes no seletor', () => {
        const wrapper = mount(Comments, { props: baseProps(), attachTo: document.body });

        expect(wrapper.text()).toContain('Por aluno');
        expect(wrapper.get('[data-testid="comment-student-e1"]').text()).toContain('Apanhado a copiar na Q2');
        expect(wrapper.find('[data-testid="comment-student-e2"]').exists()).toBe(false);

        const options = wrapper.get('[data-testid="student-picker"]').findAll('option').map((option) => option.text());
        expect(options).toEqual(['Escolher aluno', '2 · Aluno Dois', '3 · Aluno Três']);
    });

    it('escolher um aluno abre o editor e grava com o PUT certo; o aluno passa para a lista', async () => {
        const wrapper = mount(Comments, { props: baseProps(), attachTo: document.body });
        mocks.put.mockImplementation((_url: string, _data: unknown, options: { onSuccess?: () => void; onFinish?: () => void }) => {
            options.onSuccess?.();
            options.onFinish?.();
        });

        await wrapper.get('[data-testid="student-picker"]').setValue('e2');
        await flushPromises();

        const input = document.body.querySelector<HTMLTextAreaElement>('[data-testid="student-comment-input"]')!;
        input.value = 'Não teve tempo para a Q6';
        input.dispatchEvent(new Event('input'));
        await flushPromises();
        document.body.querySelector<HTMLButtonElement>('[data-testid="student-comment-save"]')!.click();
        await flushPromises();

        expect(mocks.put.mock.calls[0][0]).toBe('/instruments/inst/students/e2/comment');
        expect(mocks.put.mock.calls[0][1]).toEqual({ body: 'Não teve tempo para a Q6', lock_version: 0 });
        expect(wrapper.get('[data-testid="comment-student-e2"]').text()).toContain('Não teve tempo para a Q6');
        expect(wrapper.get('[data-testid="student-picker"]').findAll('option').map((option) => option.text())).toEqual(['Escolher aluno', '3 · Aluno Três']);
    });

    it('«Apagar» envia o texto vazio com a versão e tira o aluno da lista', async () => {
        const wrapper = mount(Comments, { props: baseProps(), attachTo: document.body });
        mocks.put.mockImplementation((_url: string, _data: unknown, options: { onSuccess?: () => void; onFinish?: () => void }) => {
            options.onSuccess?.();
            options.onFinish?.();
        });

        await wrapper.get('button[aria-label="Apagar comentário sobre Aluno Um"]').trigger('click');
        await flushPromises();

        expect(mocks.put.mock.calls[0][1]).toEqual({ body: '', lock_version: 1 });
        expect(wrapper.find('[data-testid="comment-student-e1"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="students-empty"]').exists()).toBe(true);
    });

    it('sem edição ou com o elemento anulado é só leitura', () => {
        const readOnly = mount(Comments, { props: baseProps({ can_edit: false }) });
        expect(readOnly.text()).toContain('Apanhado a copiar na Q2');
        expect(readOnly.find('[data-testid="student-picker"]').exists()).toBe(false);
        expect(readOnly.find('button[aria-label="Editar comentário sobre Aluno Um"]').exists()).toBe(false);

        const cancelled = mount(Comments, { props: baseProps({ cancelled: true }) });
        expect(cancelled.find('[data-testid="student-picker"]').exists()).toBe(false);
        expect(cancelled.find('button[aria-label="Apagar comentário sobre Aluno Um"]').exists()).toBe(false);
    });
});
