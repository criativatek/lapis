import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick } from 'vue';
import { computeStructuralFingerprint } from '@/composables/useGridDraft';
import Grid from './Grid.vue';

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

type ChannelListener = (event: MessageEvent) => void;

class MemoryBroadcastChannel {
    static channels = new Map<string, Set<MemoryBroadcastChannel>>();

    readonly name: string;
    private listeners = new Set<ChannelListener>();

    constructor(name: string) {
        this.name = name;
        const channels = MemoryBroadcastChannel.channels.get(name) ?? new Set();

        channels.add(this);
        MemoryBroadcastChannel.channels.set(name, channels);
    }

    addEventListener(type: string, listener: ChannelListener): void {
        if (type === 'message') {
            this.listeners.add(listener);
        }
    }

    removeEventListener(type: string, listener: ChannelListener): void {
        if (type === 'message') {
            this.listeners.delete(listener);
        }
    }

    postMessage(data: unknown): void {
        for (const channel of MemoryBroadcastChannel.channels.get(this.name) ?? []) {
            if (channel !== this) {
                for (const listener of channel.listeners) {
                    listener(new MessageEvent('message', { data }));
                }
            }
        }
    }

    close(): void {
        MemoryBroadcastChannel.channels.get(this.name)?.delete(this);
    }
}

const item = {
    id: 31,
    code: 'Q1',
    label: 'Questão 1',
    points_possible: 20,
    is_bonus: false,
    domains: [{ domain_id: 4, name: 'Conhecimento', percent: 100 }],
};

function props(instrumentUlid = 'instrument-a') {
    return {
        instrument: {
            ulid: instrumentUlid,
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
        scaleBands: [],
    };
}

function draftKey(instrumentUlid = 'instrument-a', organizationUlid = 'organization-a', userId = 17): string {
    return `lapis:grid-draft:${organizationUlid}:${userId}:${instrumentUlid}`;
}

function storeDraft(options: { instrumentUlid?: string; organizationUlid?: string; userId?: number; fingerprint?: string; points?: number } = {}): void {
    localStorage.setItem(
        draftKey(options.instrumentUlid, options.organizationUlid, options.userId),
        JSON.stringify({
            savedAt: '2026-08-24T10:30:00.000Z',
            structuralFingerprint: options.fingerprint ?? computeStructuralFingerprint([item]),
            cells: {
                '11:31': { state: 'assessed', points: options.points ?? 14, reason: null },
            },
        }),
    );
}

function mountGrid(instrumentUlid = 'instrument-a'): VueWrapper {
    return mount(Grid, { props: props(instrumentUlid) });
}

async function editPoints(wrapper: VueWrapper, points: string): Promise<void> {
    await wrapper.get('input[data-cell="0-0"]').setValue(points);
}

function button(wrapper: VueWrapper, label: string) {
    return wrapper.findAll('button').find((candidate) => candidate.text().includes(label));
}

describe('Grid local protection integration', () => {
    const wrappers: VueWrapper[] = [];

    beforeEach(() => {
        vi.useFakeTimers();
        localStorage.clear();
        inertia.page.props.auth.user.id = 17;
        inertia.page.props.auth.organization.ulid = 'organization-a';
        Object.defineProperty(navigator, 'onLine', { configurable: true, value: true });
        MemoryBroadcastChannel.channels.clear();
        Object.defineProperty(globalThis, 'BroadcastChannel', { configurable: true, value: MemoryBroadcastChannel });
    });

    afterEach(() => {
        for (const wrapper of wrappers.splice(0)) {
            wrapper.unmount();
        }

        vi.useRealTimers();
    });

    function trackedMount(instrumentUlid = 'instrument-a'): VueWrapper {
        const wrapper = mountGrid(instrumentUlid);

        wrappers.push(wrapper);

        return wrapper;
    }

    it('creates a local draft when a cell is edited', async () => {
        const wrapper = trackedMount();

        await editPoints(wrapper, '13');
        vi.advanceTimersByTime(400);

        expect(JSON.parse(localStorage.getItem(draftKey())!)).toMatchObject({
            cells: { '11:31': { state: 'assessed', points: 13, reason: null } },
        });
    });

    it('offers a compatible draft after remount and recovers it as dirty', async () => {
        storeDraft();
        const wrapper = trackedMount();
        await nextTick();

        expect(wrapper.text()).toContain('Encontrámos alterações não guardadas desta grelha');
        expect(wrapper.text()).toContain('1 célula');

        await button(wrapper, 'Recuperar alterações')!.trigger('click');

        expect((wrapper.get('input[data-cell="0-0"]').element as HTMLInputElement).value).toBe('14');
        expect(wrapper.text()).toContain('1 alteração por guardar');
    });

    it('ignores a compatible draft without changing the server-backed cell', async () => {
        storeDraft();
        const wrapper = trackedMount();
        await nextTick();

        await button(wrapper, 'Ignorar rascunho')!.trigger('click');

        expect((wrapper.get('input[data-cell="0-0"]').element as HTMLInputElement).value).toBe('');
        expect(wrapper.text()).toContain('Sem alterações por guardar');
        expect(localStorage.getItem(draftKey())).toBeNull();
    });

    it.each([
        ['instrumento', { instrumentUlid: 'instrument-b' }],
        ['organização', { organizationUlid: 'organization-b' }],
        ['utilizador', { userId: 99 }],
    ])('does not offer a draft from another %s scope', async (_, scope) => {
        storeDraft(scope);
        const wrapper = trackedMount();
        await nextTick();

        expect(wrapper.text()).not.toContain('Encontrámos alterações não guardadas desta grelha');
    });

    it('shows but never applies a structurally incompatible draft', async () => {
        storeDraft({ fingerprint: 'old-grid', points: 18 });
        const wrapper = trackedMount();
        await nextTick();

        expect(wrapper.text()).toContain('rascunho de uma versão anterior desta grelha');
        expect(wrapper.text()).not.toContain('Recuperar alterações');
        expect((wrapper.get('input[data-cell="0-0"]').element as HTMLInputElement).value).toBe('');
    });

    it('updates the offline indicator without preventing edits', async () => {
        const wrapper = trackedMount();

        window.dispatchEvent(new Event('offline'));
        await nextTick();
        expect(wrapper.text()).toContain('Sem ligação');

        await editPoints(wrapper, '12');
        expect(wrapper.text()).toContain('1 alteração protegida neste dispositivo');
        expect(wrapper.get('button[title]').attributes('disabled')).toBeUndefined();

        window.dispatchEvent(new Event('online'));
        await nextTick();
        expect(wrapper.text()).not.toContain('Sem ligação');
    });

    it('warns both instances editing the same grid and clears on close', async () => {
        const first = trackedMount();
        const second = trackedMount();
        await nextTick();

        expect(first.text()).toContain('também a ser editada noutro separador');
        expect(second.text()).toContain('também a ser editada noutro separador');

        second.unmount();
        wrappers.splice(wrappers.indexOf(second), 1);
        await nextTick();
        expect(first.text()).not.toContain('também a ser editada noutro separador');
    });

    it('does not warn instances editing a different grid scope', async () => {
        const first = trackedMount('instrument-a');
        const second = trackedMount('instrument-b');
        await nextTick();

        expect(first.text()).not.toContain('também a ser editada noutro separador');
        expect(second.text()).not.toContain('também a ser editada noutro separador');
    });

    /**
     * Centred digits shifted sideways as a value's width changed, while the
     * browser's spin arrows stayed pinned to the box's right inner edge — which
     * reads as the arrows moving about. Right-aligned, the last digit holds its
     * position whatever the value. The spinners are kept on purpose.
     */
    describe('score input alignment', () => {
        function scoreCell(wrapper: VueWrapper) {
            return wrapper.get('input[data-cell="0-0"]');
        }

        it('right-aligns the score instead of centring it', () => {
            const classes = scoreCell(trackedMount()).classes();

            expect(classes).toContain('text-right');
            expect(classes).not.toContain('text-center');
        });

        it('keeps a fixed box width and pads the digits clear of the spin arrows', () => {
            const classes = scoreCell(trackedMount()).classes();

            expect(classes).toContain('w-16');
            expect(classes).toContain('pr-5');
            expect(classes).toContain('pl-1.5');
            expect(classes).not.toContain('px-1.5');
        });

        it('keeps the native spinners rather than suppressing them', () => {
            const input = scoreCell(trackedMount());

            expect(input.attributes('type')).toBe('number');
            expect(input.attributes('step')).toBe('0.25');
            expect(input.classes().join(' ')).not.toContain('appearance-none');
        });

        it('still composes with the normal, over-max and disabled styling', async () => {
            const wrapper = trackedMount();

            expect(scoreCell(wrapper).classes()).toContain('border-input');
            expect(scoreCell(wrapper).classes()).toContain('disabled:opacity-40');

            await editPoints(wrapper, '25'); // over the 20-point maximum
            await nextTick();

            const overMax = scoreCell(wrapper).classes();

            expect(overMax).toContain('border-destructive');
            expect(overMax).toContain('text-destructive');
            expect(overMax).toContain('text-right');
            expect(overMax).not.toContain('border-input');
        });
    });
});
