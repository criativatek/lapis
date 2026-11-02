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

    it('announces the lesson service version with its own message, linking both documents it changed', () => {
        const wrapper = mountWith({ should_see_privacy_notice: true, privacy_notice_topic: 'lesson_service' });
        const text = wrapper.text();

        expect(text).toContain('Atualizámos a Política de Privacidade e o Acordo de Tratamento de Dados');
        expect(text).toContain('aulas não realizadas');
        expect(text).not.toContain('novos canais de contacto');
        expect(text).not.toContain('métricas de utilização');
        expect(wrapper.find('[data-test="privacy-notice-lesson-service"]').exists()).toBe(true);
    });

    it('announces the lesson service version in advance, with the date and a way to read the coming text', () => {
        const wrapper = mountWith({
            should_see_privacy_notice: true,
            privacy_notice_topic: 'lesson_service',
            privacy_notice_lesson_service: { effective_from: '2026-11-10', in_force: false },
        });
        const text = wrapper.text();

        expect(text).toContain('A partir de 10 de novembro de 2026');
        expect(text).not.toContain('Atualizámos');
        expect(wrapper.find('[data-test="privacy-notice-lesson-service-upcoming"]').exists()).toBe(true);
        expect(wrapper.find('a[href="/privacidade?versao=proxima"]').text()).toBe('Ler a nova versão');

        const inForce = mountWith({
            should_see_privacy_notice: true,
            privacy_notice_topic: 'lesson_service',
            privacy_notice_lesson_service: { effective_from: '2026-11-10', in_force: true },
        });
        expect(inForce.text()).toContain('Atualizámos a Política de Privacidade e o Acordo');
        expect(inForce.find('a[href="/privacidade"]').exists()).toBe(true);
    });
});
