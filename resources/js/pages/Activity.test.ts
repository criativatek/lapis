import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Activity from './Activity.vue';

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
        usePage: () => ({ props: pageProps }),
    };
});

const event = (overrides: Record<string, unknown> = {}) => ({
    ulid: '01TEST',
    event: 'lesson.taught',
    summary: 'Aula de 12 de setembro.',
    causer: 'Ana Teste',
    subject_type: 'Lesson',
    at: '2026-09-12T10:00:00+01:00',
    ...overrides,
});

describe('Activity', () => {
    beforeEach(() => {
        pageProps.modules = [];
        pageProps.readOnlyModules = [];
    });

    it('«Minha atividade» usa o nome humano do evento e não repete o autor', () => {
        const text = mount(Activity, {
            props: { events: [event()], scope: 'mine' },
        }).text();

        expect(text).toContain('Minha atividade');
        expect(text).toContain('Aula marcada como lecionada');
        expect(text).not.toContain('lesson.taught');
        expect(text).not.toContain('por Ana Teste');
    });

    it('um evento sem rótulo nunca mostra a chave técnica', () => {
        const text = mount(Activity, {
            props: {
                events: [event({ event: 'something.new' })],
                scope: 'mine',
            },
        }).text();

        expect(text).toContain('Ação registada');
        expect(text).not.toContain('something.new');
    });

    it('sem audit_log não há alternância para a auditoria da organização', () => {
        const wrapper = mount(Activity, {
            props: { events: [], scope: 'mine' },
        });

        expect(wrapper.find('a[href="/activity/organization"]').exists()).toBe(
            false,
        );
    });

    it('com audit_log, a auditoria mostra o autor e as duas leituras', () => {
        pageProps.modules = ['audit_log'];
        const wrapper = mount(Activity, {
            props: { events: [event()], scope: 'organization' },
        });

        expect(wrapper.text()).toContain('Auditoria da organização');
        expect(wrapper.text()).toContain('por Ana Teste');
        expect(wrapper.find('a[href="/activity"]').exists()).toBe(true);
        expect(wrapper.find('a[href="/activity/organization"]').exists()).toBe(
            true,
        );
    });
});
