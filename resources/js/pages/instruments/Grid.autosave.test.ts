import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick } from 'vue';
import Grid from './Grid.vue';

/**
 * GRAVAÇÃO AUTOMÁTICA DA GRELHA (0.195.0).
 *
 * Em 2026-11-07 um professor perdeu classificações que só existiam no
 * rascunho local. A grelha passa a gravar sozinha, três segundos depois da
 * última alteração, pelo mesmo pedido que o «Guardar» — e estes testes fixam
 * o que ela leva, o que deixa de fora e quando se cala.
 */

const inertia = vi.hoisted(() => ({
    page: {
        props: {
            auth: {
                user: { id: 17 },
                organization: { ulid: 'organization-a' },
            },
        },
    },
    post: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    Link: defineComponent({ inheritAttrs: false, setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()) }),
    router: { post: inertia.post },
    useForm: () => ({
        reason: '',
        errors: {},
        processing: false,
        reset: vi.fn(),
        clearErrors: vi.fn(),
        post: vi.fn(),
    }),
    usePage: () => inertia.page,
}));

const item = {
    id: 31,
    code: 'Q1',
    label: 'Questão 1',
    points_possible: 20,
    is_bonus: false,
    domains: [{ domain_id: 4, name: 'Conhecimento', percent: 100 }],
};

function props() {
    return {
        instrument: {
            ulid: 'instrument-a',
            title: 'Teste',
            applied_on: '2026-08-24',
            status_label: 'Em correção',
            is_completed: false,
            can_complete: false,
            pending_count: 1,
            applicable_count: 1,
            completed_count: 0,
            completed_at: null,
            pending_students: ['Aluno Teste'],
            status: 'applied',
            cancellation_reason: null,
            total_points: 20,
            class_label: '7.º A',
            class_ulid: 'class-a',
            period: '1.º período',
        },
        items: [item],
        students: [{
            enrollment_id: 11,
            name: 'Aluno Teste',
            photo_url: null,
            class_number: 1,
            enrolled_on: '2026-01-01',
            is_late_entry: false,
            joined_after_instrument: false,
        }],
        scores: [{
            enrollment_id: 11,
            instrument_item_id: 31,
            result_state: 'pending',
            points_earned: null,
            state_reason: null,
            lock_version: 7,
        }],
        states: [
            { value: 'pending', label: 'Por avaliar', carries_value: false, resolves: false },
            { value: 'assessed', label: 'Avaliado', carries_value: true, resolves: true },
            { value: 'absent', label: 'Faltou', carries_value: false, resolves: true },
        ],
        scaleBands: [
            { label: 'Insuficiente', band_min: '0', band_max: '49.4', sequence: 1, is_negative: true },
            { label: 'Suficiente', band_min: '49.5', band_max: '100', sequence: 2, is_negative: false },
        ],
        official: {
            status: 'official' as 'official' | 'provisional',
            label: 'Classificação oficial',
            threshold: '49.5' as string | null,
            domains: [{ key: 'd4', id: 4, name: 'Conhecimento' }],
            students: {
                11: {
                    status: 'classified',
                    status_label: 'Classificado',
                    global: { value: '72.4', value_precise: null as string | null, exact: '72.399123', band: { key: 'suf', code: 'Suf', label: 'Suficiente', sequence: 2, is_negative: false }, below_threshold: false, is_partial: false },
                    domains: {
                        d4: { value: '72.4', value_precise: null as string | null, exact: '72.399123', band: { key: 'suf', code: 'Suf', label: 'Suficiente', sequence: 2, is_negative: false }, below_threshold: false, is_partial: false },
                    },
                },
            },
        },
    };
}

type PostOptions = {
    onSuccess: (page: unknown) => void;
    onError: (errors: Record<string, string>) => void;
    onFinish: () => void;
};

type PostCall = { url: string; data: { cells: Record<string, unknown>[]; autosave: boolean }; options: PostOptions };

function postCalls(): PostCall[] {
    return inertia.post.mock.calls.map((call) => ({ url: call[0] as string, data: call[1] as PostCall['data'], options: call[2] as PostOptions }));
}

/** O servidor aceitou: as versões novas chegam na flash, como no controlador. */
function resolveSaved(call: PostCall, lockVersion: number): void {
    call.options.onSuccess({
        flash: {
            scoreSaveResult: {
                written: call.data.cells.length,
                versions: call.data.cells.map((cell) => ({ enrollment_id: cell.enrollment_id, instrument_item_id: cell.instrument_item_id, lock_version: lockVersion })),
                stale: [],
            },
        },
    });
    call.options.onFinish();
}

async function typePoints(wrapper: VueWrapper, points: string, leave = true): Promise<void> {
    const input = wrapper.get('input[data-cell="0-0"]');

    await input.setValue(points);

    if (leave) {
        await input.trigger('blur');
    }
}

function status(wrapper: VueWrapper): string {
    return wrapper.find('[data-testid="autosave-status"]').text();
}

describe('instruments/Grid — gravação automática', () => {
    const wrappers: VueWrapper[] = [];

    beforeEach(() => {
        vi.useFakeTimers();
        inertia.post.mockReset();
        localStorage.clear();
        Object.defineProperty(navigator, 'onLine', { configurable: true, value: true });
    });

    afterEach(() => {
        for (const wrapper of wrappers.splice(0)) {
            wrapper.unmount();
        }

        vi.useRealTimers();
    });

    function mountGrid(): VueWrapper {
        const wrapper = mount(Grid, { props: props() });

        wrappers.push(wrapper);

        return wrapper;
    }

    it('grava sozinha três segundos depois de sair da célula, pelo mesmo pedido que o «Guardar»', async () => {
        const wrapper = mountGrid();

        await typePoints(wrapper, '13');
        expect(status(wrapper)).toBe('Guarda sozinho daqui a instantes.');

        await vi.advanceTimersByTimeAsync(2999);
        expect(inertia.post).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(1);
        expect(inertia.post).toHaveBeenCalledTimes(1);

        const call = postCalls()[0];
        expect(call.url).toBe('/instruments/instrument-a/scores');
        expect(call.data.autosave).toBe(true);
        expect(call.data.cells).toEqual([
            { enrollment_id: 11, instrument_item_id: 31, result_state: 'assessed', points_earned: 13, state_reason: null, lock_version: 7 },
        ]);
        expect(status(wrapper)).toBe('A guardar…');

        resolveSaved(call, 8);
        await nextTick();

        expect(wrapper.text()).not.toContain('por guardar');
        expect(status(wrapper)).toMatch(/^Guardado automaticamente às \d{1,2}:\d{2}\.$/);
        // Já não há nada por guardar: o temporizador que sobrou não pede nada.
        await vi.advanceTimersByTimeAsync(3000);
        expect(inertia.post).toHaveBeenCalledTimes(1);
    });

    it('o «Guardar» continua a ser o pedido explícito, sem a marca de automático', async () => {
        const wrapper = mountGrid();

        await typePoints(wrapper, '13');
        await wrapper.findAll('button').find((button) => button.text().includes('Guardar') && !button.text().includes('automaticamente'))!.trigger('click');

        expect(inertia.post).toHaveBeenCalledTimes(1);
        expect(postCalls()[0].data.autosave).toBe(false);
        expect(status(wrapper)).toBe('Guarda sozinho daqui a instantes.');

        resolveSaved(postCalls()[0], 8);
        await vi.advanceTimersByTimeAsync(3000);
        expect(inertia.post).toHaveBeenCalledTimes(1);
    });

    it('não leva a célula que ainda está a ser escrita; leva-a quando o professor sai dela', async () => {
        const wrapper = mountGrid();

        await typePoints(wrapper, '13', false);
        await vi.advanceTimersByTimeAsync(3000);
        expect(inertia.post).not.toHaveBeenCalled();

        await wrapper.get('input[data-cell="0-0"]').trigger('blur');
        await vi.advanceTimersByTimeAsync(3000);
        expect(inertia.post).toHaveBeenCalledTimes(1);
        expect(postCalls()[0].data.cells[0]).toMatchObject({ points_earned: 13 });
    });

    it('não leva uma nota acima da cotação: fica por guardar, como o «Guardar» a deixa', async () => {
        const wrapper = mountGrid();

        await typePoints(wrapper, '25');
        await vi.advanceTimersByTimeAsync(3000);

        expect(inertia.post).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain('1 alteração por guardar');
        expect(wrapper.text()).toContain('Há notas acima da cotação máxima');
    });

    it('sem ligação não tenta; quando a ligação volta, grava o que ficou protegido', async () => {
        Object.defineProperty(navigator, 'onLine', { configurable: true, value: false });
        const wrapper = mountGrid();

        await typePoints(wrapper, '13');
        await vi.advanceTimersByTimeAsync(3000);
        expect(inertia.post).not.toHaveBeenCalled();
        expect(wrapper.find('[data-testid="autosave-status"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('Sem ligação');

        Object.defineProperty(navigator, 'onLine', { configurable: true, value: true });
        window.dispatchEvent(new Event('online'));
        await vi.advanceTimersByTimeAsync(3000);
        expect(inertia.post).toHaveBeenCalledTimes(1);
        expect(postCalls()[0].data.autosave).toBe(true);
    });

    it('uma alteração feita durante uma gravação espera por ela e vai a seguir, com a versão nova', async () => {
        const wrapper = mountGrid();

        await typePoints(wrapper, '13');
        await vi.advanceTimersByTimeAsync(3000);
        expect(inertia.post).toHaveBeenCalledTimes(1);

        // A primeira ainda vai a caminho quando o professor corrige o valor.
        await typePoints(wrapper, '14');
        await vi.advanceTimersByTimeAsync(3000);
        expect(inertia.post).toHaveBeenCalledTimes(1);

        resolveSaved(postCalls()[0], 8);
        await vi.advanceTimersByTimeAsync(3000);
        expect(inertia.post).toHaveBeenCalledTimes(2);
        expect(postCalls()[1].data.cells[0]).toMatchObject({ points_earned: 14, lock_version: 8 });
    });

    it('depois de um erro do servidor diz porquê e espera por uma alteração nova em vez de insistir', async () => {
        const wrapper = mountGrid();

        await typePoints(wrapper, '13');
        await vi.advanceTimersByTimeAsync(3000);
        const first = postCalls()[0];

        first.options.onError({ cells: 'A correção está concluída.' });
        first.options.onFinish();
        await nextTick();

        expect(status(wrapper)).toBe('Não foi possível guardar: A correção está concluída.');
        await vi.advanceTimersByTimeAsync(10000);
        expect(inertia.post).toHaveBeenCalledTimes(1);

        await typePoints(wrapper, '15');
        await vi.advanceTimersByTimeAsync(3000);
        expect(inertia.post).toHaveBeenCalledTimes(2);
        expect(status(wrapper)).toBe('A guardar…');
    });
});
