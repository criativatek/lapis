import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import Grid from './Grid.vue';

const inertia = vi.hoisted(() => ({ put: vi.fn(), post: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    Link: defineComponent({ inheritAttrs: false, setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()) }),
    router: { post: inertia.post, put: inertia.put },
    useForm: () => ({ reason: '', errors: {}, processing: false, reset: vi.fn(), clearErrors: vi.fn(), post: vi.fn() }),
    usePage: () => ({ props: { auth: { user: { id: 17 }, organization: { ulid: 'organization-a' } } } }),
}));

// reka-ui mede o gatilho do tooltip com um ResizeObserver, que o jsdom não tem.
vi.stubGlobal(
    'ResizeObserver',
    class {
        observe = vi.fn();
        unobserve = vi.fn();
        disconnect = vi.fn();
    },
);

const items = [{ id: 100, group_index: 0, code: 'Q1', label: null, criterion_note: null, points_possible: 5, is_bonus: false, domains: [] }];

const student = (id: number, comment: { body: string; lock_version: number } | null) => ({
    enrollment_id: id, enrollment_ulid: `enr-${id}`, comment, name: `Aluno ${id}`, photo_url: null, class_number: id,
    enrolled_on: '2026-01-01', is_late_entry: false, joined_after_instrument: false,
});

function build(instrument: Record<string, unknown> = {}, overrides: Record<string, unknown> = {}) {
    return mount(Grid, {
        attachTo: document.body,
        props: {
            instrument: {
                ulid: 'i', title: 'Ficha', applied_on: '2026-10-20', status_label: 'Em correção', is_completed: false, can_complete: false,
                pending_count: 0, applicable_count: 1, completed_count: 0, completed_at: null, pending_students: [], status: 'applied',
                cancellation_reason: null, total_points: 10, class_label: '7.º A', class_ulid: 'c', period: '1.º Semestre',
                ...instrument,
            },
            can_edit: true,
            items,
            students: [student(1, { body: 'Apanhado a copiar na Q2', lock_version: 2 }), student(2, null)],
            scores: [],
            states: [
                { value: 'pending', label: 'Por avaliar', carries_value: false, resolves: false },
                { value: 'assessed', label: 'Classificado', carries_value: true, resolves: true },
            ],
            scaleBands: [],
            official: { status: 'provisional', label: 'Classificação provisória', threshold: null, domains: [], students: {} },
            ...overrides,
        },
    });
}

const triggers = (wrapper: ReturnType<typeof build>) => wrapper.findAll('[data-testid="student-comment-trigger"]');

describe('instruments/Grid — comentário por aluno', () => {
    beforeEach(() => {
        inertia.put.mockReset();
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('com comentário o ícone está sempre visível e destacado; sem comentário só aparece com a linha em foco', () => {
        const wrapper = build();
        const [withComment, without] = triggers(wrapper);

        expect(triggers(wrapper)).toHaveLength(2);
        expect(withComment.attributes('aria-label')).toBe('Aluno 1 — com comentário');
        expect(withComment.classes()).toContain('text-primary');
        expect(withComment.classes()).not.toContain('opacity-0');

        expect(without.attributes('aria-label')).toBe('Aluno 2 — adicionar comentário');
        expect(without.classes()).toContain('opacity-0');
        expect(without.classes()).toContain('group-hover/row:opacity-100');
        expect(without.classes()).toContain('group-focus-within/row:opacity-100');
        expect(wrapper.findAll('tbody tr').every((row) => row.classes().includes('group/row'))).toBe(true);
    });

    it('sem edição só o aluno com comentário tem ícone; o outro não tem nada', () => {
        const wrapper = build({}, { can_edit: false });

        expect(triggers(wrapper)).toHaveLength(1);
        expect(triggers(wrapper)[0].attributes('aria-label')).toBe('Aluno 1 — com comentário');
    });

    it('mostra o texto quando o ícone recebe o foco do teclado', async () => {
        const wrapper = build({}, { can_edit: false });

        await triggers(wrapper)[0].trigger('focus');
        await flushPromises();

        expect(document.body.textContent).toContain('Apanhado a copiar na Q2');
    });

    it('um elemento anulado não deixa editar nem oferece o ícone a quem ainda não tem comentário', async () => {
        const wrapper = build({ status: 'cancelled' });

        expect(triggers(wrapper)).toHaveLength(1);
        await triggers(wrapper)[0].trigger('click');
        await flushPromises();
        expect(document.body.querySelector('[data-testid="student-comment-input"]')).toBeNull();
    });

    it('o editor grava com o PUT certo e a versão lida', async () => {
        const wrapper = build();

        await triggers(wrapper)[1].trigger('click');
        await flushPromises();

        const input = document.body.querySelector<HTMLTextAreaElement>('[data-testid="student-comment-input"]')!;
        input.value = '  Não teve tempo para a Q6  ';
        input.dispatchEvent(new Event('input'));
        await flushPromises();
        document.body.querySelector<HTMLButtonElement>('[data-testid="student-comment-save"]')!.click();
        await flushPromises();

        expect(inertia.put).toHaveBeenCalledTimes(1);
        expect(inertia.put.mock.calls[0][0]).toBe('/instruments/i/students/enr-2/comment');
        expect(inertia.put.mock.calls[0][1]).toEqual({ body: 'Não teve tempo para a Q6', lock_version: 0 });
        expect(inertia.put.mock.calls[0][2]).toMatchObject({ preserveScroll: true, preserveState: true, only: [] });
    });

    it('«Apagar comentário» envia o texto vazio com a versão do comentário e o ícone volta ao estado sem comentário', async () => {
        const wrapper = build();
        inertia.put.mockImplementation((_url: string, _data: unknown, options: { onSuccess?: () => void; onFinish?: () => void }) => {
            options.onSuccess?.();
            options.onFinish?.();
        });

        await triggers(wrapper)[0].trigger('click');
        await flushPromises();
        document.body.querySelector<HTMLButtonElement>('[data-testid="student-comment-delete"]')!.click();
        await flushPromises();

        expect(inertia.put.mock.calls[0][1]).toEqual({ body: '', lock_version: 2 });
        expect(triggers(wrapper)[0].attributes('aria-label')).toBe('Aluno 1 — adicionar comentário');
    });

    it('um erro do servidor aparece no editor e o comentário não muda', async () => {
        const wrapper = build();
        inertia.put.mockImplementation((_url: string, _data: unknown, options: { onError?: (errors: Record<string, string>) => void; onFinish?: () => void }) => {
            options.onError?.({ body: 'O comentário foi alterado noutra janela.' });
            options.onFinish?.();
        });

        await triggers(wrapper)[0].trigger('click');
        await flushPromises();
        document.body.querySelector<HTMLButtonElement>('[data-testid="student-comment-save"]')!.click();
        await flushPromises();

        expect(document.body.textContent).toContain('O comentário foi alterado noutra janela.');
        expect(triggers(wrapper)[0].attributes('aria-label')).toBe('Aluno 1 — com comentário');
    });
});
