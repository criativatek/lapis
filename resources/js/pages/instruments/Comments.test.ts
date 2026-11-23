import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';
import Comments from './Comments.vue';

type MockForm = Record<string, unknown> & { errors: Record<string, string> };

const mocks = vi.hoisted(() => ({ forms: [] as MockForm[], put: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    Link: defineComponent({ inheritAttrs: false, setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()) }),
    router: { put: mocks.put },
    useForm: (data: Record<string, unknown>) => {
        const form = reactive({
            ...data,
            errors: {},
            processing: false,
            put: vi.fn(),
        }) as unknown as MockForm;

        mocks.forms.push(form);

        return form;
    },
}));

function baseProps(overrides: Record<string, unknown> = {}) {
    return {
        instrument: {
            ulid: 'inst', title: 'Apresentação oral', status: 'in_correction', status_label: 'Em correção',
            applied_on: '2026-10-15', class_label: '7.º A', class_ulid: 'cls', period: '1.º Período',
        },
        note: { body: 'Observação inicial.', lock_version: 1, updated_at: null, updated_by: null },
        can_edit: true,
        cancelled: false,
        items: [
            { id: 1, code: 'Q1', label: 'Oralidade', points_possible: 50, is_bonus: false, criterion_note: null },
            { id: 2, code: 'Q2', label: null, points_possible: 50, is_bonus: false, criterion_note: 'Audibilidade 5 + dicção 10' },
        ],
        links: { note: '/instruments/inst/resultados/observacoes', item_notes: '/instruments/inst/items/notes', grid: '/instruments/inst', results: '/instruments/inst/resultados' },
        ...overrides,
    };
}

describe('instruments/Comments', () => {
    beforeEach(() => {
        mocks.forms.length = 0;
        mocks.put.mockReset();
    });

    it('mostra os separadores com Comentários ativo, a nota geral e as questões', () => {
        const wrapper = mount(Comments, { props: baseProps() });

        expect(wrapper.get('[aria-current="page"]').text()).toBe('Comentários');
        expect(wrapper.text()).toContain('Geral');
        expect(wrapper.text()).toContain('Por questão / critério');
        expect((wrapper.get('textarea[aria-label="Observações do professor"]').element as HTMLTextAreaElement).value).toBe('Observação inicial.');
        expect((wrapper.get('textarea[aria-label="Comentário de Q2"]').element as HTMLTextAreaElement).value).toBe('Audibilidade 5 + dicção 10');
        expect(wrapper.text()).toContain('Oralidade');
    });

    it('guarda a nota geral no endpoint dos Resultados, com a versão lida', async () => {
        const wrapper = mount(Comments, { props: baseProps() });
        const form = mocks.forms.find((candidate) => 'lock_version' in candidate)!;

        await wrapper.findAll('button').find((button) => button.text() === 'Guardar observações')!.trigger('click');

        expect(form.put).toHaveBeenCalledTimes(1);
        expect((form.put as ReturnType<typeof vi.fn>).mock.calls[0][0]).toBe('/instruments/inst/resultados/observacoes');
        expect(form.lock_version).toBe(1);
    });

    it('leva a versão nova para a gravação seguinte, mas não copia a de outra janela', async () => {
        const wrapper = mount(Comments, { props: baseProps() });
        const form = mocks.forms.find((candidate) => 'lock_version' in candidate)!;

        await wrapper.setProps({ note: { ...baseProps().note, lock_version: 5 } });
        expect(form.lock_version).toBe(1);

        (form.put as ReturnType<typeof vi.fn>).mockImplementation((_url: string, options: { onSuccess?: () => void }) => options.onSuccess?.());
        await wrapper.findAll('button').find((button) => button.text() === 'Guardar observações')!.trigger('click');
        expect(form.lock_version).toBe(5);
    });

    it('guarda o comentário de uma questão com o PUT certo e só depois de alterado', async () => {
        const wrapper = mount(Comments, { props: baseProps() });
        const save = wrapper.get('button[aria-label="Guardar comentário de Q1"]');

        expect(save.attributes('disabled')).toBeDefined();

        await wrapper.get('textarea[aria-label="Comentário de Q1"]').setValue('  Nível 4 exige contacto visual ');
        expect(save.attributes('disabled')).toBeUndefined();
        await save.trigger('click');

        expect(mocks.put).toHaveBeenCalledTimes(1);
        expect(mocks.put.mock.calls[0][0]).toBe('/instruments/inst/items/notes');
        expect(mocks.put.mock.calls[0][1]).toEqual({ notes: { 1: 'Nível 4 exige contacto visual' } });
    });

    it('sem can_edit tudo fica só de leitura', () => {
        const wrapper = mount(Comments, { props: baseProps({ can_edit: false }) });

        expect(wrapper.get('textarea[aria-label="Observações do professor"]').attributes('disabled')).toBeDefined();
        expect(wrapper.get('textarea[aria-label="Comentário de Q1"]').attributes('disabled')).toBeDefined();
        expect(wrapper.text()).not.toContain('Guardar');
    });

    it('um elemento anulado deixa as questões só de leitura e não oferece «Imprimir / PDF»', () => {
        const wrapper = mount(Comments, { props: baseProps({ cancelled: true }) });

        expect(wrapper.get('textarea[aria-label="Comentário de Q1"]').attributes('disabled')).toBeDefined();
        expect(wrapper.find('button[aria-label="Guardar comentário de Q1"]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Imprimir / PDF');
    });
});
