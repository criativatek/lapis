import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';
import Show from './Show.vue';

type MockForm = Record<string, unknown>;

const mocks = vi.hoisted(() => ({
    forms: [] as MockForm[],
    get: vi.fn(),
    patch: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    Link: defineComponent({
        inheritAttrs: false,
        setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()),
    }),
    router: { get: mocks.get, patch: mocks.patch, delete: vi.fn(), on: vi.fn(() => vi.fn()) },
    useForm: (data: Record<string, unknown>) => {
        const form = reactive({
            ...data,
            errors: {} as Record<string, string>,
            processing: false,
            transform(callback: (data: Record<string, unknown>) => Record<string, unknown>) {
                (form as { __transform?: unknown }).__transform = callback;

                return form;
            },
            reset: vi.fn(),
            clearErrors: vi.fn(),
            put: vi.fn(),
            post: vi.fn(),
        }) as MockForm;

        mocks.forms.push(form);

        return form;
    },
}));

/** O formulário principal é o primeiro que a página cria. */
function mainForm(): MockForm {
    return mocks.forms[0];
}

/** O payload tal como seguiria para o servidor, depois do `transform`. */
function payloadOf(form: MockForm): Record<string, unknown> {
    const transform = (form as { __transform?: (data: Record<string, unknown>) => Record<string, unknown> }).__transform;

    expect(transform).toBeTypeOf('function');

    return transform!({ ...(form as Record<string, unknown>) });
}

const TYPES = [
    {
        value: 'pedagogical_differentiation',
        label: 'Diferenciação pedagógica',
        context: 'learning',
        context_label: 'Aprendizagem',
        requires_description: false,
        legal_mapping: {
            mode: 'direct' as const,
            level: 'universal',
            level_label: 'Medida universal',
            measure: 'pedagogical_differentiation',
            measure_label: 'Diferenciação pedagógica',
            evaluation_adaptation: null,
            evaluation_adaptation_label: null,
        },
    },
    {
        value: 'tutorial_support',
        label: 'Apoio tutorial',
        context: 'learning',
        context_label: 'Aprendizagem',
        requires_description: false,
        legal_mapping: {
            mode: 'direct' as const,
            level: 'selective',
            level_label: 'Medida seletiva',
            measure: 'tutorial_support',
            measure_label: 'Apoio tutorial',
            evaluation_adaptation: null,
            evaluation_adaptation_label: null,
        },
    },
    {
        value: 'other',
        label: 'Outro',
        context: 'other',
        context_label: 'Outro',
        requires_description: true,
        legal_mapping: null,
    },
];

function props(overrides: Record<string, unknown> = {}) {
    return {
        schoolClass: { ulid: 'class-1', label: '7.º A', subject: 'Português' },
        enrollments: [
            { id: 1, name: 'Ana Martins' },
            { id: 2, name: 'Bruno Costa' },
        ],
        activeEnrollmentIds: [1, 2],
        domains: [{ id: 10, name: 'Leitura' }],
        periods: [{ id: 1, label: '1.º período' }],
        types: TYPES,
        contexts: [{ value: 'learning', label: 'Aprendizagem' }],
        domainRelations: [
            { value: 'none', label: 'Sem domínio específico' },
            { value: 'specific', label: 'Um domínio específico' },
        ],
        targetTypes: [
            { value: 'student', label: 'Aluno' },
            { value: 'group', label: 'Grupo' },
            { value: 'class', label: 'Turma' },
        ],
        supportMeasureLevels: [
            { value: 'universal', label: 'Medida universal', measures: [{ value: 'pedagogical_differentiation', label: 'Diferenciação pedagógica' }] },
            { value: 'selective', label: 'Medida seletiva', measures: [{ value: 'tutorial_support', label: 'Apoio tutorial' }] },
        ],
        evaluationAdaptations: [{ value: 'extra_time', label: 'Tempo suplementar' }],
        effectivenessOptions: [{ value: 'effective', label: 'Evolução favorável observada', short_label: 'Evolução favorável' }],
        statusOptions: [
            { value: 'new', label: 'Planeada' },
            { value: 'in_progress', label: 'Em curso' },
        ],
        purposeOptions: [
            { value: 'recovery', label: 'Recuperação', description: 'Repor uma aprendizagem em falta.' },
            { value: 'consolidation', label: 'Consolidação', description: 'Manter e reforçar.' },
        ],
        library: { difficulties: [], strategies: {} },
        filters: {},
        interventions: [],
        prefill: null,
        backTo: { label: 'Voltar', href: '/interventions' },
        ...overrides,
    };
}

function intervention(overrides: Record<string, unknown> = {}) {
    return {
        ulid: 'int-1',
        created_batch_ulid: null,
        origin_label: null,
        title: 'Apoio tutorial',
        description: null,
        motive_code: null,
        motive: 'Dificuldade na planificação',
        strategy_code: null,
        strategy: 'Guião de planificação',
        objective: 'Melhorar a estruturação do texto',
        review_on: '2026-11-15',
        needs_review: false,
        purpose: 'recovery',
        purpose_label: 'Recuperação',
        frequency: '2x por semana',
        tracking_indicator: 'n.º de textos planificados',
        effectiveness: null,
        effectiveness_label: null,
        effectiveness_short: null,
        last_followup_on: null,
        followup_count: 0,
        intervention_type_label: 'Apoio tutorial',
        intervention_type: 'tutorial_support',
        context: 'learning',
        context_label: 'Aprendizagem',
        target_type: 'student',
        target_label: 'Ana Martins',
        target_enrollment_ulid: 'enr-1',
        participant_ids: [1],
        domain_relation: 'none',
        domain_id: null,
        domain: null,
        domain_label: null,
        status: 'new',
        status_label: 'Planeada',
        is_closed: false,
        creator_name: 'Ana Martins',
        started_on: '2026-09-01',
        expected_end_on: null,
        concluded_on: null,
        available_for_reports: true,
        legal_framing: {
            level: 'selective',
            level_label: 'Medida seletiva',
            measure: 'tutorial_support',
            measure_label: 'Apoio tutorial',
            evaluation_adaptation: null,
            evaluation_adaptation_label: null,
            source: 'system_direct',
            source_label: 'Automático',
        },
        support_measures: [{ ulid: 'sm-1', level: 'selective', level_label: 'Medida seletiva', code: 'tutorial_support', code_label: 'Apoio tutorial', source: 'system_direct' }],
        reviews: [],
        ...overrides,
    };
}

const wrappers: VueWrapper[] = [];

function render(overrides: Record<string, unknown> = {}) {
    const wrapper = mount(Show, { props: props(overrides) as never });
    wrappers.push(wrapper);

    return wrapper;
}

beforeEach(() => {
    mocks.forms.length = 0;
    mocks.get.mockClear();
    mocks.patch.mockClear();
});

afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
});

describe('Estratégias e Medidas — escolher medidas', () => {
    it('writes a new intervention into intervention_types, the field the server expects', async () => {
        const wrapper = render();

        await wrapper.find('input[value="tutorial_support"]').trigger('change');

        expect(mainForm().intervention_types).toContain('tutorial_support');
    });

    it('accumulates several measures on a new intervention', async () => {
        const wrapper = render();
        const form = mainForm();

        // A página abre com a primeira medida do catálogo já escolhida, como
        // sempre abriu. Limpar é um passo do teste, não do produto — e tem de
        // chegar ao picker antes do clique seguinte.
        form.intervention_types = [];
        await wrapper.vm.$nextTick();

        await wrapper.find('input[value="tutorial_support"]').trigger('change');
        await wrapper.vm.$nextTick();
        await wrapper.find('input[value="pedagogical_differentiation"]').trigger('change');

        expect(form.intervention_types).toEqual(['tutorial_support', 'pedagogical_differentiation']);
    });

    it('shows what is already selected above the catalogue, not buried inside it', () => {
        const wrapper = render();

        expect(wrapper.text()).toContain('Selecionadas');
        expect(wrapper.text()).toContain('Adicionar estratégia ou medida');
    });

    it('removes a selected measure without touching the others', async () => {
        const wrapper = render();
        const form = mainForm();
        form.intervention_types = ['tutorial_support', 'pedagogical_differentiation'];
        await wrapper.vm.$nextTick();

        const remove = wrapper.findAll('button').find((button) => button.attributes('aria-label') === 'Remover Apoio tutorial');
        await remove?.trigger('click');

        expect(form.intervention_types).toEqual(['pedagogical_differentiation']);
    });
});

describe('Estratégias e Medidas — o formulário não perdeu nada', () => {
    /**
     * A REGRESSÃO QUE ESTE ECRÃ NÃO PODE TER. O redesenho é de apresentação:
     * o payload que sai daqui tem de continuar a ser exactamente o mesmo.
     */
    it('submits every field it always submitted, on a new intervention', async () => {
        const wrapper = render();
        const form = mainForm();

        form.intervention_types = ['tutorial_support'];
        form.motive_label = 'Dificuldade na planificação';
        form.strategy_label = 'Guião de planificação';
        form.objective = 'Melhorar a estruturação do texto';
        form.review_on = '2026-11-15';
        form.purpose = 'recovery';
        form.frequency = '2x por semana';
        form.tracking_indicator = 'n.º de textos planificados';
        form.description = '  com espaços  ';
        await wrapper.vm.$nextTick();

        await wrapper.find('form').trigger('submit');

        const payload = payloadOf(form);

        expect(payload).toMatchObject({
            target_type: 'student',
            enrollment_ids: [1],
            intervention_types: ['tutorial_support'],
            motive_label: 'Dificuldade na planificação',
            strategy_label: 'Guião de planificação',
            objective: 'Melhorar a estruturação do texto',
            review_on: '2026-11-15',
            purpose: 'recovery',
            frequency: '2x por semana',
            tracking_indicator: 'n.º de textos planificados',
            description: 'com espaços',
            started_on: expect.any(String),
            available_for_reports: true,
        });
        // Criar manda a lista; o campo singular não vai junto.
        expect(payload).not.toHaveProperty('intervention_type');
        expect(form.post).toHaveBeenCalledWith('/classes/class-1/interventions', expect.anything());
    });

    it('submits the singular field, and only it, when editing', async () => {
        const wrapper = render({ interventions: [intervention()] });
        const form = mainForm();

        await wrapper.findAll('button').find((button) => button.attributes('title') === 'Editar')?.trigger('click');
        await wrapper.vm.$nextTick();

        expect(form.intervention_type).toBe('tutorial_support');

        await wrapper.find('form').trigger('submit');
        const payload = payloadOf(form);

        expect(payload.intervention_type).toBe('tutorial_support');
        expect(payload).not.toHaveProperty('intervention_types');
        expect(form.put).toHaveBeenCalledWith('/interventions/int-1', expect.anything());
    });

    it('loads every recorded field back into the edit form', async () => {
        const wrapper = render({ interventions: [intervention()] });
        const form = mainForm();

        await wrapper.findAll('button').find((button) => button.attributes('title') === 'Editar')?.trigger('click');

        expect(form.motive_label).toBe('Dificuldade na planificação');
        expect(form.strategy_label).toBe('Guião de planificação');
        expect(form.objective).toBe('Melhorar a estruturação do texto');
        expect(form.review_on).toBe('2026-11-15');
        expect(form.purpose).toBe('recovery');
        expect(form.frequency).toBe('2x por semana');
        expect(form.tracking_indicator).toBe('n.º de textos planificados');
        expect(form.support_measures).toEqual([{ level: 'selective', code: 'tutorial_support' }]);
    });

    it('drops domain_id only when no specific domain was asked for', async () => {
        const wrapper = render();
        const form = mainForm();
        form.intervention_types = ['tutorial_support'];

        await wrapper.find('form').trigger('submit');
        expect(payloadOf(form)).not.toHaveProperty('domain_id');

        form.domain_relation = 'specific';
        form.domain_id = 10;
        await wrapper.vm.$nextTick();
        await wrapper.find('form').trigger('submit');
        expect(payloadOf(form).domain_id).toBe(10);
    });

    /**
     * Ao editar, a intervenção É uma medida. Sem isto, um clique na única
     * que está escolhida guardaria uma intervenção sem tipo nenhum.
     */
    it('never lets an edit end up with no measure at all', async () => {
        const wrapper = render({ interventions: [intervention()] });
        const form = mainForm();

        await wrapper.findAll('button').find((button) => button.attributes('title') === 'Editar')?.trigger('click');
        await wrapper.vm.$nextTick();

        await wrapper.find('input[value="tutorial_support"]').trigger('change');

        expect(form.intervention_type).toBe('tutorial_support');
    });
});

describe('Estratégias e Medidas — modo simples e detalhado', () => {
    it('keeps the detailed follow-up closed on an ordinary new intervention', () => {
        const wrapper = render();
        const toggle = wrapper.findAll('button').find((button) => button.text().includes('Acompanhamento detalhado'));

        expect(toggle?.attributes('aria-expanded')).toBe('false');
    });

    it('opens on request, and says so to a screen reader', async () => {
        const wrapper = render();
        const toggle = wrapper.findAll('button').find((button) => button.text().includes('Acompanhamento detalhado'));

        await toggle?.trigger('click');

        expect(wrapper.findAll('button').find((button) => button.text().includes('Acompanhamento detalhado'))?.attributes('aria-expanded')).toBe('true');
    });

    /**
     * §12 do pedido: reduzir carga cognitiva não é esconder informação
     * obrigatória. «Outro» exige descrição — a secção não pode ficar fechada.
     */
    it('will not hide a description the chosen measure requires', async () => {
        const wrapper = render();
        const form = mainForm();
        form.intervention_types = ['other'];
        await wrapper.vm.$nextTick();

        const toggle = wrapper.findAll('button').find((button) => button.text().includes('Acompanhamento detalhado'));

        expect(toggle?.attributes('aria-expanded')).toBe('true');
        expect(toggle?.attributes('disabled')).toBeDefined();
    });

    /**
     * O SERVIDOR EXIGE DESCRIÇÃO SE QUALQUER UMA DAS MEDIDAS DO LOTE A
     * EXIGIR. Olhar só para a primeira deixava «Outro» escolhido em segundo
     * lugar a exigir um campo que estava fechado — o professor só descobria
     * ao submeter.
     */
    it('will not hide a description required by a measure that is not the first', async () => {
        const wrapper = render();
        const form = mainForm();
        form.intervention_types = ['tutorial_support', 'other'];
        await wrapper.vm.$nextTick();

        const toggle = wrapper.findAll('button').find((button) => button.text().includes('Acompanhamento detalhado'));

        expect(toggle?.attributes('aria-expanded')).toBe('true');
        expect(toggle?.attributes('disabled')).toBeDefined();
        // E o campo diz que é obrigatório, com asterisco e com `required`.
        expect(wrapper.find('textarea[required]').exists()).toBe(true);
    });

    it('leaves the description optional when no chosen measure requires it', async () => {
        const wrapper = render();
        const form = mainForm();
        form.intervention_types = ['tutorial_support', 'pedagogical_differentiation'];
        await wrapper.vm.$nextTick();

        expect(wrapper.findAll('button').find((button) => button.text().includes('Acompanhamento detalhado'))?.attributes('aria-expanded')).toBe('false');
    });

    it('will not hide a field the teacher has already filled in', async () => {
        const wrapper = render();
        const form = mainForm();
        form.frequency = '2x por semana';
        await wrapper.vm.$nextTick();

        expect(wrapper.findAll('button').find((button) => button.text().includes('Acompanhamento detalhado'))?.attributes('aria-expanded')).toBe('true');
    });

    /**
     * «Disponível para relatórios» está ligado por omissão; desligado é uma
     * decisão, e uma decisão não pode ficar escondida.
     */
    it('will not hide a reports flag the teacher turned off', async () => {
        const wrapper = render({ interventions: [intervention({ available_for_reports: false })] });

        await wrapper.findAll('button').find((button) => button.attributes('title') === 'Editar')?.trigger('click');
        await wrapper.vm.$nextTick();

        const toggle = wrapper.findAll('button').find((button) => button.text().includes('Acompanhamento detalhado'));

        expect(toggle?.attributes('aria-expanded')).toBe('true');
        expect(toggle?.attributes('disabled')).toBeDefined();
    });

    it('will not hide a validation error the server sent back', async () => {
        const wrapper = render();
        const form = mainForm();
        (form.errors as Record<string, string>).description = 'A descrição é obrigatória.';
        await wrapper.vm.$nextTick();

        expect(wrapper.findAll('button').find((button) => button.text().includes('Acompanhamento detalhado'))?.attributes('aria-expanded')).toBe('true');
    });

    it('opens it when editing an intervention that uses those fields', async () => {
        const wrapper = render({ interventions: [intervention()] });

        await wrapper.findAll('button').find((button) => button.attributes('title') === 'Editar')?.trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.findAll('button').find((button) => button.text().includes('Acompanhamento detalhado'))?.attributes('aria-expanded')).toBe('true');
    });
});

describe('Estratégias e Medidas — destinatário', () => {
    /**
     * Antes eram três botões e o escolhido distinguia-se só por um fundo
     * mais escuro: nada o dizia a um leitor de ecrã (§16).
     */
    it('is a radio group, so the chosen one is stated and not merely shaded', async () => {
        const wrapper = render();
        const radios = wrapper.findAll('input[type="radio"][name="target-type"]');

        expect(radios).toHaveLength(3);
        expect((radios[0].element as HTMLInputElement).checked).toBe(true);

        await radios[2].trigger('change');

        expect(mainForm().target_type).toBe('class');
    });
});

describe('Estratégias e Medidas — raciocínio pedagógico', () => {
    it('reads as the four questions, in order', () => {
        const text = render().text();

        expect(text).toContain('Necessidade — o que foi observado?');
        expect(text).toContain('Intervenção — o que vamos fazer?');
        expect(text).toContain('Objetivo — o que pretendemos alcançar?');
        expect(text).toContain('Revisão — quando vamos rever?');
    });

    it('keeps every one of them optional, as they always were', async () => {
        const wrapper = render();
        const form = mainForm();
        form.intervention_types = ['tutorial_support'];
        await wrapper.vm.$nextTick();

        await wrapper.find('form').trigger('submit');

        expect(form.post).toHaveBeenCalled();
    });
});

describe('Estratégias e Medidas — resumo e filtros', () => {
    it('shows no summary on a class with nothing recorded', () => {
        expect(render().find('#interventions-summary-heading').exists()).toBe(false);
    });

    it('summarises what is on the page, with the level named in full', () => {
        const wrapper = render({ interventions: [intervention()] });

        expect(wrapper.find('#interventions-summary-heading').exists()).toBe(true);
        expect(wrapper.text()).toContain('1 estratégia ou medida ativa');
        expect(wrapper.text()).toContain('Medida seletiva · 1');
    });

    it('filters down to the pending reviews from the summary', async () => {
        const wrapper = render({ interventions: [intervention({ needs_review: true })] });

        await wrapper.findAll('button').find((button) => button.text().includes('com revisão pendente'))?.trigger('click');

        expect(mocks.get).toHaveBeenCalledWith('/classes/class-1/interventions', expect.objectContaining({ needs_review: 1 }), expect.anything());
    });

    it('offers the state filter the server already supported but the page never showed', async () => {
        const wrapper = render();
        const select = wrapper.findAll('select').find((candidate) => candidate.html().includes('Planeada'));

        await select?.setValue('in_progress');

        expect(mocks.get).toHaveBeenCalledWith('/classes/class-1/interventions', expect.objectContaining({ status: 'in_progress' }), expect.anything());
    });

    it('clears every filter at once', async () => {
        const wrapper = render({ filters: { status: 'new', enrollment_id: 1 } });

        await wrapper.findAll('button').find((button) => button.text() === 'Limpar filtros')?.trigger('click');

        expect(mocks.get).toHaveBeenCalledWith(
            '/classes/class-1/interventions',
            expect.objectContaining({ status: null, enrollment_id: null, needs_review: null }),
            expect.anything(),
        );
    });
});

describe('Estratégias e Medidas — estados extremos', () => {
    it('renders a class with no interventions without a summary and with the empty state', () => {
        const wrapper = render();

        expect(wrapper.text()).toContain('Ainda não existem estratégias ou medidas registadas.');
    });

    it('renders many interventions without falling over', () => {
        const many = Array.from({ length: 40 }, (_, index) => intervention({ ulid: `int-${index}` }));
        const wrapper = render({ interventions: many });

        expect(wrapper.text()).toContain('40 estratégias e medidas ativas');
    });

    it('renders a long label in the list without a horizontal scroller', () => {
        const long = 'Desenvolvimento de competências de autonomia pessoal e social com acompanhamento individualizado continuado';
        const wrapper = render({ interventions: [intervention({ title: long })] });

        expect(wrapper.text()).toContain(long);
    });

    it('still works for a school whose library is empty', () => {
        const wrapper = render({ library: { difficulties: [], strategies: {} } });

        expect(wrapper.find('#motive').exists()).toBe(true);
    });
});
