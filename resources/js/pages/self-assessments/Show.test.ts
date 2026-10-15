import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import type { SelfAssessmentConfiguration, SelfAssessmentReflectionQuestion } from '@/types/self-assessment';
import Show from './Show.vue';

const inertia = vi.hoisted(() => ({
    page: {
        props: {
            modules: [] as string[],
        },
    },
    router: { get: vi.fn(), post: vi.fn(), put: vi.fn() },
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    Link: defineComponent({ inheritAttrs: false, setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()) }),
    router: inertia.router,
    usePage: () => inertia.page,
}));

function configurationOf(overrides: Partial<SelfAssessmentConfiguration> = {}): SelfAssessmentConfiguration {
    return {
        profile: 'Avaliação final',
        version: 3,
        scale: 'Escala 1 a 5',
        domains: [{ name: 'Conhecimento', weight: '100' }],
        answer: null,
        state: 'current',
        class_criteria: { profile: 'Avaliação final', version: 3, scale: 'Escala 1 a 5' },
        saved_count: 0,
        can_renew: false,
        can_edit: true,
        class_url: '/classes/class-1',
        review: null,
        ...overrides,
    };
}

const previous = configurationOf({ state: 'previous', saved_count: 4, class_criteria: { profile: 'Avaliação final', version: 4, scale: 'Escala 0 a 20' }, can_renew: true });

/**
 * `self_assessment_links` gating (Lote 1) — on the FRONTEND half of the
 * guarantee (§8.2 of CLAUDE.md: the server decides, this is the presentation
 * half). The backend gate itself (`module:self_assessment_links` on the
 * route, 403 for Base regardless of what the UI shows) is covered by
 * tests/Feature/SelfAssessment/PublicSelfAssessmentTest.php.
 */
function baseProps(overrides: Record<string, unknown> = {}) {
    return {
        schoolClass: { ulid: 'class-1', label: '7.º A', subject: 'Português' },
        periods: [{ ulid: 'period-1', label: '1.º Período', selected: true }],
        rows: [],
        configuration: configurationOf(),
        reflectionQuestions: [] as SelfAssessmentReflectionQuestion[],
        ...overrides,
    };
}

function linkText(wrapper: ReturnType<typeof mount>): string[] {
    return wrapper.findAll('a').map((a) => a.text());
}

beforeEach(() => {
    vi.clearAllMocks();
    inertia.page.props.modules = [];
});

describe('self-assessments/Show — self_assessment_links gating on the frontend', () => {
    it('hides "Ligações para os alunos" when the organization lacks self_assessment_links (Base)', () => {
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

describe('self-assessments/Show — starting a new self-assessment', () => {
    it('shows the criteria panel at the top', () => {
        const wrapper = mount(Show, { props: baseProps() });

        expect(wrapper.get('[data-test="criteria-current"]').text()).toContain('Avaliação final · versão 3 · Escala 1 a 5');
    });

    it('offers no button when the self-assessment cannot be renewed', () => {
        expect(mount(Show, { props: baseProps() }).find('[data-test="renew-start"]').exists()).toBe(false);
    });

    it('asks in line before doing anything, and cancels without posting', async () => {
        const wrapper = mount(Show, { props: baseProps({ configuration: previous }) });

        expect(wrapper.get('[data-test="renew-start"]').text()).toBe('Iniciar nova autoavaliação com os critérios atuais');
        expect(wrapper.find('[data-test="renew-confirmation"]').exists()).toBe(false);

        await wrapper.get('[data-test="renew-start"]').trigger('click');

        expect(wrapper.get('[data-test="renew-confirmation"]').text()).toContain(
            'As 4 autoavaliações já guardadas ficam como foram preenchidas, com os critérios anteriores, e continuam visíveis. As novas respostas usam os critérios atuais da turma.',
        );

        await wrapper.get('[data-test="renew-cancel"]').trigger('click');

        expect(wrapper.find('[data-test="renew-confirmation"]').exists()).toBe(false);
        expect(inertia.router.post).not.toHaveBeenCalled();
    });

    it('posts to the renew route with the selected period when confirmed', async () => {
        const wrapper = mount(Show, { props: baseProps({ configuration: previous }) });

        await wrapper.get('[data-test="renew-start"]').trigger('click');
        await wrapper.get('[data-test="renew-confirm"]').trigger('click');

        expect(inertia.router.post).toHaveBeenCalledTimes(1);
        const [url, data] = inertia.router.post.mock.calls[0];
        expect(url).toBe('/classes/class-1/self-assessments/renew');
        expect(data).toEqual({ period: 'period-1' });
    });

    it('shows the renew error from the server', async () => {
        const wrapper = mount(Show, { props: baseProps({ configuration: previous }) });

        await wrapper.get('[data-test="renew-start"]').trigger('click');
        await wrapper.get('[data-test="renew-confirm"]').trigger('click');

        inertia.router.post.mock.calls[0][2].onError({ renew: 'Esta autoavaliação já usa os critérios atuais da turma.' });
        await wrapper.vm.$nextTick();

        expect(wrapper.get('[data-test="renew-error"]').text()).toBe('Esta autoavaliação já usa os critérios atuais da turma.');
        expect(wrapper.get('[data-test="renew-error"]').attributes('role')).toBe('alert');
    });

    it('marks a student who also has an answer under earlier criteria', () => {
        const wrapper = mount(Show, {
            props: baseProps({
                rows: [
                    { enrollment_ulid: 'e1', name: 'Ana', class_number: 1, status: null, status_label: null, has_earlier_round: true },
                    { enrollment_ulid: 'e2', name: 'Rui', class_number: 2, status: null, status_label: null, has_earlier_round: false },
                ],
            }),
        });

        const tags = wrapper.findAll('[data-test="earlier-round-tag"]');
        expect(tags).toHaveLength(1);
        expect(tags[0].text()).toBe('Também tem uma resposta anterior');
    });
});

describe('self-assessments/Show — the reflection questions', () => {
    const reflections: SelfAssessmentReflectionQuestion[] = [
        { id: 10, role: 'rationale', role_label: 'Justificação', prompt: 'Porque propões esta classificação?', answered: false },
        { id: 11, role: 'liked', role_label: 'O que gostou', prompt: 'De que gostaste mais?', answered: true },
    ];

    it('is a collapsible section, closed by default', () => {
        const details = mount(Show, { props: baseProps({ reflectionQuestions: reflections }) }).get('[data-test="reflection-editor"]');

        expect(details.element.tagName).toBe('DETAILS');
        expect(details.attributes('open')).toBeUndefined();
        expect(details.get('summary').text()).toBe('Perguntas de reflexão');
    });

    it('is not shown to someone who cannot edit the class', () => {
        const wrapper = mount(Show, { props: baseProps({ reflectionQuestions: reflections, configuration: configurationOf({ can_edit: false }) }) });

        expect(wrapper.find('[data-test="reflection-editor"]').exists()).toBe(false);
    });

    it('lets the unanswered question be reworded and keeps the answered one read-only', () => {
        const wrapper = mount(Show, { props: baseProps({ reflectionQuestions: reflections }) });

        expect(wrapper.findAll('textarea')).toHaveLength(1);
        expect((wrapper.get('textarea').element as HTMLTextAreaElement).value).toBe('Porque propões esta classificação?');
        expect(wrapper.get('[data-test="reflection-editor"]').text()).toContain('Já tem respostas — a redação mantém-se.');
    });

    it('sends only the editable prompts', async () => {
        const wrapper = mount(Show, { props: baseProps({ reflectionQuestions: reflections }) });

        await wrapper.get('textarea').setValue('Porque escolheste esta classificação?');
        await wrapper.get('form').trigger('submit');

        expect(inertia.router.put).toHaveBeenCalledTimes(1);
        const [url, data] = inertia.router.put.mock.calls[0];
        expect(url).toBe('/classes/class-1/self-assessments/questions');
        expect(data).toEqual({ prompts: { 10: 'Porque escolheste esta classificação?' } });
    });

    it('shows a server error under the question it belongs to', async () => {
        const wrapper = mount(Show, { props: baseProps({ reflectionQuestions: reflections }) });

        await wrapper.get('form').trigger('submit');
        inertia.router.put.mock.calls[0][2].onError({ 'prompts.10': 'O texto é demasiado curto.' });
        await wrapper.vm.$nextTick();

        expect(wrapper.get('form [role="alert"]').text()).toBe('O texto é demasiado curto.');
    });
});

describe('self-assessments/Show — read-only mode', () => {
    it('shows the amber notice when the configuration says so', () => {
        const wrapper = mount(Show, { props: baseProps({ configuration: configurationOf({ read_only: true, can_renew: false, can_edit: false }) }) });

        expect(wrapper.get('[data-test="read-only-notice"]').text()).toContain('A autoavaliação está temporariamente só de leitura.');
        expect(wrapper.find('[data-test="renew-start"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="reflection-editor"]').exists()).toBe(false);
    });

    it('shows no notice otherwise', () => {
        expect(mount(Show, { props: baseProps() }).find('[data-test="read-only-notice"]').exists()).toBe(false);
    });
});
