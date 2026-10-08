/**
 * «Aplicar ao calendário» — a pergunta da data, a pré-visualização como única
 * fonte do que vai acontecer, a guarda «só a última resposta escreve», a
 * substituição com confirmação explícita e o cancelamento que não grava nada.
 */
import { flushPromises, mount  } from '@vue/test-utils';
import type {VueWrapper} from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';
import Index from './Index.vue';

const mocks = vi.hoisted(() => ({ post: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    Link: defineComponent({
        inheritAttrs: false,
        setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()),
    }),
    router: { post: mocks.post },
    useForm: (data: Record<string, unknown>) =>
        reactive({
            ...data,
            errors: {},
            processing: false,
            defaults: vi.fn(),
            reset: vi.fn(),
            clearErrors: vi.fn(),
            post: vi.fn(),
            put: vi.fn(),
            delete: vi.fn(),
        }),
}));

vi.mock('@/components/ui/dialog', () => {
    const passthrough = defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) });

    return {
        Dialog: defineComponent({
            props: { open: { type: Boolean, default: false } },
            setup: (props, { slots }) => () => (props.open ? h('div', { 'data-dialog': '' }, slots.default?.()) : null),
        }),
        DialogClose: passthrough,
        DialogContent: passthrough,
        DialogDescription: passthrough,
        DialogFooter: passthrough,
        DialogHeader: passthrough,
        DialogTitle: passthrough,
    };
});

const wrappers: VueWrapper[] = [];

const sequence = {
    ulid: 'seq-1',
    name: 'Frações',
    subject: { id: 1, label: 'Matemática' },
    academic_year: { id: 1, label: '2026/27' },
    grade_level: null,
    items: [{ ulid: 'item-1', summary: 'A', private_notes: null, resources: null, homework: null }],
    applicable_classes: [{ id: 7, label: '7.º A', groups: [] }],
    applications: [],
};

function stepOf(kind: string, ulid: string | null, summary = 'A') {
    return {
        kind,
        lesson: {
            ulid,
            starts_at: '2026-10-08T09:30:00+01:00',
            ends_at: null,
            lesson_number: 2,
            state_label: 'Por preparar',
            current_summary: kind === 'preserve' || kind === 'replace' ? 'Texto do professor' : null,
        },
        item: kind === 'preserve' ? null : { ulid: 'item-1', position: 1, summary },
    };
}

function previewBody(overrides: Record<string, unknown> = {}) {
    return {
        from: '2026-10-08',
        audience: { class_group_id: null, label: 'Turma inteira' },
        complete: true,
        plan_token: 'token-1',
        steps: [stepOf('fill', null)],
        already_applied: [],
        unplaced: [],
        counts: { fill: 1, replace: 0, preserve: 0 },
        requires_replace_confirmation: false,
        ...overrides,
    };
}

function jsonResponse(body: unknown, ok = true): Response {
    return { ok, json: async () => body } as Response;
}

async function openDialog(): Promise<VueWrapper> {
    const wrapper = mount(Index, {
        props: { sequences: [sequence], subjects: [], academicYears: [] },
        attachTo: document.body,
    });
    wrappers.push(wrapper);

    const button = wrapper.findAll('button').find((entry) => entry.text().includes('Aplicar ao calendário'));
    await button?.trigger('click');
    await flushPromises();

    return wrapper;
}

describe('lessons/sequences/Index — aplicar ao calendário', () => {
    beforeEach(() => {
        mocks.post.mockReset();
        vi.stubGlobal('fetch', vi.fn());
    });

    afterEach(() => {
        wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
        vi.unstubAllGlobals();
    });

    it('pergunta a data com o texto exato e pede a pré-visualização com `from`', async () => {
        vi.mocked(fetch).mockResolvedValue(jsonResponse(previewBody()));

        const wrapper = await openDialog();

        expect(wrapper.text()).toContain('A partir de que data pretende aplicar esta sequência?');
        expect(wrapper.find('input[type="date"]').exists()).toBe(true);

        expect(fetch).toHaveBeenCalledTimes(1);
        const [url, init] = vi.mocked(fetch).mock.calls[0];
        expect(url).toBe('/lessons/sequences/seq-1/preview');
        const body = JSON.parse((init as RequestInit).body as string);
        expect(body.from).toMatch(/^\d{4}-\d{2}-\d{2}$/);
        expect(body.class_id).toBe(7);
    });

    it('uma resposta antiga que chega depois não substitui a mais recente', async () => {
        let resolveFirst: (value: Response) => void = () => {};
        vi.mocked(fetch)
            .mockImplementationOnce(() => new Promise<Response>((resolve) => (resolveFirst = resolve)))
            .mockResolvedValueOnce(jsonResponse(previewBody({ plan_token: 'recente', steps: [stepOf('fill', null, 'Recente')] })));

        const wrapper = await openDialog();

        await wrapper.find('input[type="date"]').setValue('2026-10-15');
        await flushPromises();

        resolveFirst(jsonResponse(previewBody({ plan_token: 'antiga', steps: [stepOf('fill', null, 'Antiga')] })));
        await flushPromises();

        expect(wrapper.text()).toContain('Recente');
        expect(wrapper.text()).not.toContain('Antiga');

        await wrapper.find('form').trigger('submit');
        expect(mocks.post.mock.calls[0][1].plan_token).toBe('recente');
    });

    it('substituir exige a caixa de confirmação e o POST leva replace, confirm_replace e plan_token', async () => {
        vi.mocked(fetch).mockResolvedValue(
            jsonResponse(
                previewBody({
                    steps: [stepOf('preserve', 'lesson-9'), stepOf('fill', null)],
                    counts: { fill: 1, replace: 0, preserve: 1 },
                }),
            ),
        );

        const wrapper = await openDialog();

        // Escolher «Substituir» refaz a pré-visualização com `replace`.
        vi.mocked(fetch).mockResolvedValue(
            jsonResponse(
                previewBody({
                    plan_token: 'token-replace',
                    steps: [stepOf('replace', 'lesson-9'), stepOf('fill', null)],
                    counts: { fill: 1, replace: 1, preserve: 0 },
                    requires_replace_confirmation: true,
                }),
            ),
        );

        const radios = wrapper.findAll('input[type="radio"]');
        await radios[1].setValue(true);
        await flushPromises();

        const body = JSON.parse((vi.mocked(fetch).mock.calls.at(-1)?.[1] as RequestInit).body as string);
        expect(body.replace).toEqual(['lesson-9']);

        const confirmButton = wrapper.findAll('button').find((entry) => entry.text().includes('Confirmar aplicação'));
        expect(confirmButton?.attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('Confirmo que quero substituir 1 aula já preparada.');

        await wrapper.find('[data-testid="confirm-replace"]').setValue(true);
        expect(confirmButton?.attributes('disabled')).toBeUndefined();

        await wrapper.find('form').trigger('submit');

        expect(mocks.post).toHaveBeenCalledTimes(1);
        const [url, payload] = mocks.post.mock.calls[0];
        expect(url).toBe('/lessons/sequences/seq-1/apply');
        expect(payload).toMatchObject({ replace: ['lesson-9'], confirm_replace: true, plan_token: 'token-replace' });
    });

    it('«Cancelar» fecha sem chamar post', async () => {
        vi.mocked(fetch).mockResolvedValue(jsonResponse(previewBody()));

        const wrapper = await openDialog();

        const cancel = wrapper.findAll('button').find((entry) => entry.text() === 'Cancelar');
        await cancel?.trigger('click');
        await flushPromises();

        expect(mocks.post).not.toHaveBeenCalled();
        expect(wrapper.text()).not.toContain('A partir de que data');
    });

    it('um plano incompleto desativa a confirmação e mostra os elementos por colocar', async () => {
        vi.mocked(fetch).mockResolvedValue(
            jsonResponse(
                previewBody({
                    complete: false,
                    unplaced: [{ ulid: 'item-9', position: 3, summary: 'Elemento sem aula' }],
                }),
            ),
        );

        const wrapper = await openDialog();

        const confirmButton = wrapper.findAll('button').find((entry) => entry.text().includes('Confirmar aplicação'));
        expect(confirmButton?.attributes('disabled')).toBeDefined();
        expect(wrapper.find('[data-testid="apply-incomplete"]').text()).toContain('Elemento sem aula');

        await wrapper.find('form').trigger('submit');
        expect(mocks.post).not.toHaveBeenCalled();
    });

    it('ao recalcular mantém a lista anterior atenuada, com aria-busy, e a confirmação desativada', async () => {
        vi.mocked(fetch).mockResolvedValueOnce(
            jsonResponse(previewBody({ steps: [stepOf('preserve', 'lesson-9')], counts: { fill: 0, replace: 0, preserve: 1 } })),
        );
        const wrapper = await openDialog();
        expect(wrapper.find('[data-testid="apply-summary"]').text()).toBe('1 preservada');

        let resolveNext: (value: Response) => void = () => {};
        vi.mocked(fetch).mockImplementationOnce(() => new Promise<Response>((resolve) => (resolveNext = resolve)));

        await wrapper.findAll('input[type="radio"]')[1].setValue(true);
        await flushPromises();

        expect(wrapper.find('[data-testid="apply-preview"]').attributes('aria-busy')).toBe('true');
        expect(wrapper.findAll('input[type="radio"]').length).toBe(2);
        const confirmButton = wrapper.findAll('button').find((entry) => entry.text().includes('Confirmar aplicação'));
        expect(confirmButton?.attributes('disabled')).toBeDefined();

        resolveNext(jsonResponse(previewBody()));
        await flushPromises();
        expect(wrapper.find('[data-testid="apply-preview"]').attributes('aria-busy')).toBe('false');
    });

    it('um estado fechado usa o rótulo da própria aula', async () => {
        const step = stepOf('closed', 'lesson-3');
        step.lesson.state_label = 'Professor ausente';
        vi.mocked(fetch).mockResolvedValue(jsonResponse(previewBody({ steps: [step], counts: { closed: 1 } })));

        const wrapper = await openDialog();

        expect(wrapper.text()).toContain('Professor ausente — não é alterada');
    });

    it('se o servidor recusar a confirmação mostra a mensagem e volta a pedir a pré-visualização', async () => {
        vi.mocked(fetch)
            .mockResolvedValueOnce(jsonResponse(previewBody({ plan_token: 'velho' })))
            .mockResolvedValueOnce(jsonResponse(previewBody({ plan_token: 'novo' })));
        mocks.post.mockImplementation((_url: string, _data: unknown, options: { onError: (errors: Record<string, string>) => void; onFinish: () => void }) => {
            options.onError({ plan_token: 'As aulas ou a sequência mudaram desde a pré-visualização.' });
            options.onFinish();
        });

        const wrapper = await openDialog();
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(fetch).toHaveBeenCalledTimes(2);
        expect(wrapper.text()).toContain('As aulas ou a sequência mudaram desde a pré-visualização.');

        await wrapper.find('form').trigger('submit');
        expect(mocks.post.mock.calls[1][1].plan_token).toBe('novo');
    });
});
