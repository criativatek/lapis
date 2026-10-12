import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import UsageMetricsPreference from './UsageMetricsPreference.vue';

const mocks = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
    put: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: mocks.props }),
    router: { put: mocks.put },
}));

const wrappers: VueWrapper[] = [];

function mountWith(usageMetrics: Record<string, unknown> | null) {
    mocks.props = { usageMetrics };
    const wrapper = mount(UsageMetricsPreference, { attachTo: document.body });
    wrappers.push(wrapper);

    return wrapper;
}

const collecting = {
    opted_out: false,
    opted_out_at: null,
    resumed_at: null,
    collecting: true,
};

afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    mocks.put.mockReset();
});

describe('UsageMetricsPreference', () => {
    it('does not exist when the server sends no section', () => {
        const wrapper = mountWith(null);

        expect(wrapper.find('[data-test="usage-metrics-preference"]').exists()).toBe(false);
    });

    it('names the choice and says what objecting does before the click', () => {
        const wrapper = mountWith(collecting);
        const text = wrapper.text();

        expect(text).toContain('Não registar a minha utilização');
        expect(text).toContain(
            'a recolha é interrompida e as métricas associadas à sua conta são eliminadas, sem afetar o acesso às funcionalidades do Lapispro',
        );
        expect(text).toContain('A sua utilização está a ser registada.');
    });

    it('objects with one click and no confirmation', async () => {
        const wrapper = mountWith(collecting);

        await wrapper.find('[data-test="usage-opt-out"]').trigger('click');

        expect(mocks.put).toHaveBeenCalledTimes(1);
        expect(mocks.put.mock.calls[0][0]).toBe('/settings/usage-metrics');
        expect(mocks.put.mock.calls[0][1]).toEqual({ opted_out: true });
    });

    it('describes each state without judging anyone', () => {
        expect(
            mountWith({ ...collecting, opted_out: true, opted_out_at: '2026-10-12T10:00:00+01:00' }).text(),
        ).toContain('Desde 12/10/2026, a sua utilização não é registada.');
        expect(mountWith({ ...collecting, collecting: false }).text()).toContain(
            'A recolha de métricas de utilização está neste momento desligada no Lapispro.',
        );
        expect(
            mountWith({ ...collecting, resumed_at: '2026-10-14T08:00:00+01:00' }).text(),
        ).toContain('A sua utilização é registada desde 14/10/2026.');
    });
});
