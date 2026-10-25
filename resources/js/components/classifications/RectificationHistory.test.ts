import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import type { RectificationHistoryEntry } from '@/lib/rectification';
import RectificationHistory from './RectificationHistory.vue';

vi.mock('@/components/ui/dialog', () => {
    const passthrough = defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) });

    return {
        Dialog: defineComponent({
            props: { open: { type: Boolean, default: false } },
            setup: (props, { slots }) => () => (props.open ? h('div', { 'data-dialog': '' }, slots.default?.()) : null),
        }),
        DialogContent: passthrough,
        DialogDescription: passthrough,
        DialogHeader: passthrough,
        DialogTitle: passthrough,
    };
});

const wrappers: VueWrapper[] = [];

function entry(overrides: Partial<RectificationHistoryEntry>): RectificationHistoryEntry {
    return {
        ulid: 'x',
        status: 'superseded',
        is_live: false,
        final: { code: '4', label: 'Bom', scale_level_id: 4 },
        decided_by: 'Ana Martins',
        decided_at: '2026-10-20T10:30:00+01:00',
        reason: null,
        note: null,
        rectified: false,
        ...overrides,
    };
}

const history: RectificationHistoryEntry[] = [
    entry({ ulid: 'a' }),
    entry({
        ulid: 'b',
        final: { code: '3', label: 'Suficiente', scale_level_id: 3 },
        decided_at: '2026-10-22T09:00:00+01:00',
        reason: 'Erro de lançamento',
        rectified: true,
    }),
    entry({
        ulid: 'c',
        status: 'published',
        is_live: true,
        final: { code: '2', label: 'Insuficiente', scale_level_id: 2 },
        decided_at: '2026-10-25T14:30:00+00:00',
        reason: 'Outro',
        note: 'Conselho de turma.',
        rectified: true,
    }),
];

function mountHistory(count: number): VueWrapper {
    const wrapper = mount(RectificationHistory, {
        props: { count, historyUrl: '/classes/c1/classifications/cla-1/rectification', studentName: 'Carolina Nunes' },
        attachTo: document.body,
    });
    wrappers.push(wrapper);

    return wrapper;
}

beforeEach(() => {
    vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue({ ok: true, status: 200, json: async () => ({ history, notice: { generic: '', export: null } }) }),
    );
});

afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    vi.unstubAllGlobals();
});

describe('RectificationHistory', () => {
    it('o marcador só existe com retificações', () => {
        expect(mountHistory(0).find('[data-testid="rectified-marker"]').exists()).toBe(false);

        const marked = mountHistory(2).get('[data-testid="rectified-marker"]');
        expect(marked.attributes('aria-label')).toBe('Classificação retificada');
        expect(marked.attributes('title')).toBe('Classificação retificada');
    });

    it('não pede nada até ser aberto', () => {
        mountHistory(1);

        expect(fetch).not.toHaveBeenCalled();
    });

    it('lista por ordem cronológica, com autor, data, motivo e nota, e marca a vigente', async () => {
        const wrapper = mountHistory(2);
        await wrapper.get('[data-testid="rectified-marker"]').trigger('click');
        await flushPromises();

        expect(vi.mocked(fetch).mock.calls[0][0]).toBe('/classes/c1/classifications/cla-1/rectification');

        const items = wrapper.findAll('[data-testid="rectification-entry"]');
        expect(items.map((item) => item.find('.font-semibold').text())).toEqual(['4 — Bom', '3 — Suficiente', '2 — Insuficiente']);
        expect(items[0].text()).toContain('Ana Martins · 20/10/2026, 10:30');
        expect(items[0].text()).toContain('Classificação publicada originalmente.');
        expect(items[1].text()).toContain('Motivo: Erro de lançamento');
        expect(items[2].text()).toContain('Nota: Conselho de turma.');
        // 14:30 UTC a 25/10 já é hora de inverno em Lisboa (muda na madrugada de 25/10).
        expect(items[2].text()).toContain('25/10/2026, 14:30');

        expect(wrapper.findAll('[data-testid="rectification-live"]')).toHaveLength(1);
        expect(items[2].find('[data-testid="rectification-live"]').exists()).toBe(true);
    });

    it('diz quando não consegue carregar', async () => {
        vi.mocked(fetch).mockResolvedValue({ ok: false, status: 500, json: async () => ({}) } as Response);
        const wrapper = mountHistory(1);
        await wrapper.get('[data-testid="rectified-marker"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toContain('Não foi possível carregar o histórico');
    });
});
