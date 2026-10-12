import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import PrivacyNotice from './PrivacyNotice.vue';

const mocks = vi.hoisted(() => ({
    auth: {} as Record<string, unknown>,
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { auth: mocks.auth } }),
    router: { post: vi.fn() },
}));

const wrappers: VueWrapper[] = [];

function mountWith(auth: Record<string, unknown>) {
    mocks.auth = auth;
    const wrapper = mount(PrivacyNotice, { attachTo: document.body });
    wrappers.push(wrapper);

    return wrapper;
}

afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
});

describe('PrivacyNotice', () => {
    it('shows nothing when the server says so', () => {
        expect(mountWith({ should_see_privacy_notice: false }).text()).toBe('');
    });

    it('keeps the earlier text for an earlier version', () => {
        const text = mountWith({ should_see_privacy_notice: true, privacy_notice_topic: 'general' }).text();

        expect(text).toContain('novos canais de contacto e suporte');
        expect(text).not.toContain('métricas de utilização');
    });

    it('announces the usage metrics with the proposed objection notice and a way to the setting', () => {
        const wrapper = mountWith({ should_see_privacy_notice: true, privacy_notice_topic: 'usage_metrics' });
        const text = wrapper.text();

        expect(text).toContain(
            'Pode opor-se à recolha de métricas de utilização nas definições da sua conta. Ao fazê-lo, a recolha é interrompida e as métricas associadas à sua conta são eliminadas, sem afetar o acesso às funcionalidades do Lapispro.',
        );
        expect(text).not.toContain('novos canais de contacto');
        expect(wrapper.find('a[href="/settings/profile#metricas-de-utilizacao"]').exists()).toBe(true);
    });
});
