import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import Show from './Show.vue';

const inertia = vi.hoisted(() => ({
    page: {
        props: {
            modules: [] as string[],
        },
    },
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    Link: defineComponent({ inheritAttrs: false, setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()) }),
    router: { get: vi.fn() },
    usePage: () => inertia.page,
}));

/**
 * `self_assessment_links` gating (Lote 1) — on the FRONTEND half of the
 * guarantee (§8.2 of CLAUDE.md: the server decides, this is the presentation
 * half). The backend gate itself (`module:self_assessment_links` on the
 * route, 403 for Base regardless of what the UI shows) is covered by
 * tests/Feature/SelfAssessment/PublicSelfAssessmentTest.php.
 */
function baseProps() {
    return {
        schoolClass: { ulid: 'class-1', label: '7.º A', subject: 'Português' },
        periods: [{ ulid: 'period-1', label: '1.º Período', selected: true }],
        rows: [],
    };
}

function linkText(wrapper: ReturnType<typeof mount>): string[] {
    return wrapper.findAll('a').map((a) => a.text());
}

describe('self-assessments/Show — self_assessment_links gating on the frontend', () => {
    it('hides "Ligações para os alunos" when the organization lacks self_assessment_links (Base)', () => {
        inertia.page.props.modules = [];

        const wrapper = mount(Show, { props: baseProps() });

        expect(linkText(wrapper)).not.toContain('Ligações para os alunos');
    });

    it('shows "Ligações para os alunos" when self_assessment_links is present and a period is selected (Pro/Institucional)', () => {
        inertia.page.props.modules = ['self_assessment_links'];

        const wrapper = mount(Show, { props: baseProps() });

        expect(linkText(wrapper)).toContain('Ligações para os alunos');
    });

    it('still hides the link when entitled but no period is selected', () => {
        inertia.page.props.modules = ['self_assessment_links'];

        const wrapper = mount(Show, {
            props: {
                ...baseProps(),
                periods: [{ ulid: 'period-1', label: '1.º Período', selected: false }],
            },
        });

        expect(linkText(wrapper)).not.toContain('Ligações para os alunos');
    });
});
