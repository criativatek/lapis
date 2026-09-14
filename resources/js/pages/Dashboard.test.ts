import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Dashboard from './Dashboard.vue';

const pageProps = { modules: [] as string[], readOnlyModules: [] as string[] };

vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        Head: defineComponent({ setup: () => () => null }),
        Link: defineComponent({
            props: { href: { type: String, default: '' } },
            setup:
                (props, { slots }) =>
                () =>
                    h('a', { href: props.href }, slots.default?.()),
        }),
        router: { post: vi.fn(), visit: vi.fn() },
        usePage: () => ({ props: pageProps }),
    };
});

function dashboard() {
    return mount(Dashboard, {
        props: {
            teacherName: 'Ana Teste',
            classes: [],
            readiness: { is_ready: true, items: [] },
            firstSteps: { dismissed: true, all_done: true, items: [] },
            totals: {
                classes: 0,
                pending_confirmation: 0,
                pending_publication: 0,
            },
        },
    });
}

describe('Dashboard — atividade', () => {
    beforeEach(() => {
        pageProps.modules = [];
        pageProps.readOnlyModules = [];
    });

    it('Base/Pro: «Minha atividade» sempre, sem link para a auditoria da organização', () => {
        pageProps.modules = ['reports', 'lessons', 'ai_reports'];
        const wrapper = dashboard();

        expect(wrapper.find('a[href="/activity"]').text()).toBe(
            'Minha atividade',
        );
        expect(wrapper.find('a[href="/activity/organization"]').exists()).toBe(
            false,
        );
    });

    it('Institucional: as duas leituras', () => {
        pageProps.modules = ['audit_log'];
        const wrapper = dashboard();

        expect(wrapper.find('a[href="/activity"]').exists()).toBe(true);
        expect(wrapper.find('a[href="/activity/organization"]').text()).toBe(
            'Auditoria da organização',
        );
    });

    it('uma organização suspensa que ainda pode consultar mantém a auditoria', () => {
        pageProps.readOnlyModules = ['audit_log'];

        expect(
            dashboard().find('a[href="/activity/organization"]').exists(),
        ).toBe(true);
    });
});
