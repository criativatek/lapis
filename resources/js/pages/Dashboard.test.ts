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

function dashboard(classes: InstanceType<typeof Dashboard>["$props"]["classes"] = []) {
    return mount(Dashboard, {
        props: {
            teacherName: 'Ana Teste',
            classes,
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

const oneClass = [
    {
        ulid: '01TESTCLASS',
        label: '8.º F',
        subject: 'Matemática',
        academic_year: '2026/2027',
        has_profile: true,
        pending_confirmation: 0,
        pending_publication: 0,
    },
];

describe('Dashboard — «Aulas de hoje» com turmas', () => {
    beforeEach(() => {
        pageProps.modules = [];
        pageProps.readOnlyModules = [];
    });

    it('aparece uma só vez, para /lessons, com o módulo lessons e uma turma', () => {
        pageProps.modules = ['lessons', 'assessments', 'calendar'];
        const wrapper = dashboard(oneClass);
        const quickAction = wrapper.find('[data-testid="lessons-today"]');

        expect(quickAction.attributes('href')).toBe('/lessons');
        expect(quickAction.text()).toContain('Aulas de hoje');
        expect(quickAction.element.tagName).toBe('A');
        expect(quickAction.attributes('tabindex')).toBeUndefined();
        expect(quickAction.classes()).toContain('focus-visible:ring-2');
        expect(wrapper.findAll('a[href="/lessons"]')).toHaveLength(1);
    });

    it('sem turmas não compete com os primeiros passos', () => {
        pageProps.modules = ['lessons'];

        expect(dashboard().find('a[href="/lessons"]').exists()).toBe(false);
    });

    it('sem o módulo, ou só em consulta, não há link para as aulas', () => {
        pageProps.modules = ['reports', 'records', 'calendar'];
        expect(dashboard(oneClass).find('a[href="/lessons"]').exists()).toBe(false);

        pageProps.modules = [];
        pageProps.readOnlyModules = ['lessons'];
        expect(dashboard(oneClass).find('a[href="/lessons"]').exists()).toBe(false);
    });
});
