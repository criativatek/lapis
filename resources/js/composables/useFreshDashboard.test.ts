import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';
import { useFreshDashboard } from './useFreshDashboard';

const get = vi.fn();
const reload = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    router: { get: (...args: unknown[]) => get(...args), reload: (...args: unknown[]) => reload(...args) },
    usePage: () => ({ url: '/dashboard?x=1' }),
}));

const NOW = '2026-10-26T12:00:00+00:00';

const mounted: { unmount: () => void }[] = [];

function host(serverNow: string) {
    const wrapper = mount(
        defineComponent({
            setup() {
                useFreshDashboard(() => serverNow);

                return () => null;
            },
        }),
    );
    mounted.push(wrapper);

    return wrapper;
}

function becomeVisible() {
    Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'visible' });
    document.dispatchEvent(new Event('visibilitychange'));
}

describe('useFreshDashboard', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        get.mockReset();
        reload.mockReset();
    });
    afterEach(() => {
        mounted.splice(0).forEach((wrapper) => wrapper.unmount());
        vi.useRealTimers();
    });

    it('não pede nada aos 4 s e revalida aos 6 s (limiar de 5 s)', () => {
        vi.setSystemTime(new Date('2026-10-26T12:00:04+00:00'));
        host(NOW);
        expect(get).not.toHaveBeenCalled();

        vi.setSystemTime(new Date('2026-10-26T12:00:06+00:00'));
        host(NOW);
        expect(get).toHaveBeenCalledTimes(1);
    });

    it('ao montar com dados antigos faz uma visita síncrona ao próprio URL, só com `dashboard`', () => {
        vi.setSystemTime(new Date('2026-10-26T12:05:00+00:00'));
        host(NOW);

        expect(get).toHaveBeenCalledTimes(1);
        expect(get).toHaveBeenCalledWith('/dashboard?x=1', {}, expect.objectContaining({ only: ['dashboard'], preserveState: true, preserveScroll: true, replace: true }));
        expect(reload).not.toHaveBeenCalled();
    });

    it('volta a revalidar quando o separador fica visível, mas não em cima de uma visita em curso', () => {
        vi.setSystemTime(new Date('2026-10-26T12:00:03+00:00'));
        host(NOW);
        vi.setSystemTime(new Date('2026-10-26T12:10:00+00:00'));

        becomeVisible();
        becomeVisible();
        expect(get).toHaveBeenCalledTimes(1);

        get.mock.calls[0]![2].onFinish();
        becomeVisible();
        expect(get).toHaveBeenCalledTimes(2);
        expect(reload).not.toHaveBeenCalled();
    });

    it('deixa de ouvir ao desmontar', () => {
        vi.setSystemTime(new Date('2026-10-26T12:00:03+00:00'));
        const wrapper = host(NOW);
        wrapper.unmount();
        vi.setSystemTime(new Date('2026-10-26T12:10:00+00:00'));
        becomeVisible();

        expect(get).not.toHaveBeenCalled();
    });
});
