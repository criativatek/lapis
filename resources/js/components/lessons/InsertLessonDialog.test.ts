import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, onMounted, reactive } from 'vue';
import InsertLessonDialog from './InsertLessonDialog.vue';

vi.mock('@inertiajs/vue3', () => ({
    useForm: (data: Record<string, unknown>) => reactive({ ...data, processing: false, post: vi.fn() }),
}));

// O diálogo real teleporta e só abre com um clique; aqui abre-se ao montar e
// renderiza-se inline, para o teste mandar nas respostas da pré-visualização.
vi.mock('@/components/ui/dialog', () => {
    const passthrough = defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) });
    const Dialog = defineComponent({
        emits: ['update:open'],
        setup: (_, { emit, slots }) => {
            onMounted(() => emit('update:open', true));

            return () => h('div', slots.default?.());
        },
    });

    return {
        Dialog,
        DialogClose: passthrough,
        DialogContent: passthrough,
        DialogDescription: passthrough,
        DialogFooter: passthrough,
        DialogHeader: passthrough,
        DialogTitle: passthrough,
        DialogTrigger: passthrough,
    };
});

function deferred<T>(): { promise: Promise<T>; resolve: (value: T) => void } {
    let resolve!: (value: T) => void;
    const promise = new Promise<T>((done) => {
        resolve = done;
    });

    return { promise, resolve };
}

function jsonResponse(status: number, body: unknown): Response {
    return { ok: status >= 200 && status < 300, status, json: async () => body } as Response;
}

const refusal = { errors: { insert_at: ['Não é possível deslocar esta sequência porque contém uma aula já lecionada em 28/09.'] } };
const preview = { inserted_at: '2026-10-07T10:00:00+01:00', shifted_count: 0, moves: [] };

async function mountAndChangeDate(): Promise<{
    wrapper: ReturnType<typeof mount>;
    first: ReturnType<typeof deferred<Response>>;
    second: ReturnType<typeof deferred<Response>>;
}> {
    const first = deferred<Response>();
    const second = deferred<Response>();
    const fetchMock = vi.fn().mockReturnValueOnce(first.promise).mockReturnValueOnce(second.promise);
    vi.stubGlobal('fetch', fetchMock);

    const wrapper = mount(InsertLessonDialog, {
        props: {
            classes: [{ ulid: 'class-a', label: '8.º F', subject: 'Português', groups: [] }],
            defaultDate: '2026-09-28',
        },
    });
    await flushPromises();

    // O professor muda a data antes de a primeira pré-visualização responder.
    await wrapper.get('#insert-at').setValue('2026-10-06');
    await flushPromises();
    expect(fetchMock).toHaveBeenCalledTimes(2);

    return { wrapper, first, second };
}

function insertButton(wrapper: ReturnType<typeof mount>) {
    return wrapper.findAll('button').filter((button) => button.text() === 'Inserir aula').at(-1)!;
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('InsertLessonDialog', () => {
    it('ignora a recusa de uma data anterior que chega depois da pré-visualização da data nova', async () => {
        const { wrapper, first, second } = await mountAndChangeDate();

        second.resolve(jsonResponse(200, preview));
        await flushPromises();
        first.resolve(jsonResponse(422, refusal));
        await flushPromises();

        expect(wrapper.text()).not.toContain('Não é possível deslocar');
        expect(wrapper.text()).toContain('Nova aula a');
        expect(insertButton(wrapper).attributes('disabled')).toBeUndefined();
    });

    it('não deixa uma pré-visualização de uma data anterior ativar o botão da data nova', async () => {
        const { wrapper, first, second } = await mountAndChangeDate();

        second.resolve(jsonResponse(422, refusal));
        await flushPromises();
        first.resolve(jsonResponse(200, preview));
        await flushPromises();

        expect(wrapper.text()).toContain('Não é possível deslocar');
        expect(wrapper.text()).not.toContain('Nova aula a');
        expect(insertButton(wrapper).attributes('disabled')).toBeDefined();
    });
});
