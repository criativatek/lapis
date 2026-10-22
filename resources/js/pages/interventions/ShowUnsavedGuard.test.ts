import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick } from 'vue';
import Show from './Show.vue';

/**
 * Estes testes usam o `useForm` REAL do Inertia (só o `router` é substituído),
 * porque o que está em causa é o `isDirty` verdadeiro e o evento `before`.
 */
type BeforeHandler = (event: CustomEvent) => boolean | void;

const mocks = vi.hoisted(() => ({
    beforeHandlers: [] as unknown[],
    patch: vi.fn(),
    get: vi.fn(),
    del: vi.fn(),
}));

vi.mock('@inertiajs/vue3', async () => {
    const actual = await vi.importActual<Record<string, unknown>>('@inertiajs/vue3');

    return {
        useForm: actual.useForm as unknown,
        Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
        Link: defineComponent({
            inheritAttrs: false,
            setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()),
        }),
        router: {
            get: mocks.get,
            patch: mocks.patch,
            delete: mocks.del,
            on: vi.fn((event: string, handler: unknown) => {
                if (event === 'before') {
                    mocks.beforeHandlers.push(handler);
                }

                return () => {
                    mocks.beforeHandlers.splice(mocks.beforeHandlers.indexOf(handler), 1);
                };
            }),
        },
    };
});

const TYPES = [
    {
        value: 'tutorial_support',
        label: 'Apoio tutorial',
        context: 'learning',
        context_label: 'Aprendizagem',
        requires_description: false,
        legal_mapping: null,
    },
];

function baseIntervention() {
    return {
        ulid: 'int-1',
        created_batch_ulid: null,
        origin_label: null,
        title: 'Apoio tutorial',
        description: null,
        motive_code: null,
        motive: 'Dificuldade',
        strategy_code: null,
        strategy: 'Guião',
        objective: 'Melhorar',
        review_on: '2026-11-15',
        needs_review: false,
        purpose: 'recovery',
        purpose_label: 'Recuperação',
        frequency: null,
        tracking_indicator: null,
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
        legal_framing: null,
        support_measures: [],
        reviews: [],
    };
}

function props(overrides: Record<string, unknown> = {}) {
    return {
        schoolClass: { ulid: 'class-1', label: '7.º A', subject: 'Português' },
        enrollments: [
            { id: 1, name: 'Ana Martins' },
            { id: 2, name: 'Bruno Costa' },
        ],
        activeEnrollmentIds: [1, 2],
        domains: [],
        periods: [],
        types: TYPES,
        contexts: [{ value: 'learning', label: 'Aprendizagem' }],
        domainRelations: [{ value: 'none', label: 'Sem domínio' }],
        targetTypes: [{ value: 'student', label: 'Aluno' }],
        supportMeasureLevels: [],
        evaluationAdaptations: [],
        effectivenessOptions: [],
        statusOptions: [
            { value: 'new', label: 'Planeada' },
            { value: 'in_progress', label: 'Em curso' },
        ],
        purposeOptions: [],
        library: { difficulties: [], strategies: {} },
        filters: {},
        interventions: [baseIntervention()],
        prefill: null,
        backTo: { label: 'Voltar', href: '/interventions' },
        ...overrides,
    };
}

const wrappers: VueWrapper[] = [];

function render(overrides: Record<string, unknown> = {}) {
    const wrapper = mount(Show, { props: props(overrides) as never });
    wrappers.push(wrapper);

    return wrapper;
}

/** Simula o `before` do Inertia com uma visita pendente. */
function fireBefore(visit: { method: string; url?: string; preserveState?: boolean | string }): CustomEvent {
    const event = new CustomEvent('inertia:before', {
        cancelable: true,
        detail: {
            visit: {
                method: visit.method,
                url: new URL(visit.url ?? '/classes/class-1/interventions', window.location.origin),
                preserveState: visit.preserveState ?? false,
            },
        },
    });

    for (const handler of mocks.beforeHandlers) {
        (handler as BeforeHandler)(event);
    }

    return event;
}

function buttonByText(wrapper: VueWrapper, text: string) {
    const button = wrapper
        .findAll('button')
        .find((candidate) => candidate.text().trim() === text || candidate.attributes('title') === text);

    expect(button, `botão «${text}»`).toBeDefined();

    return button!;
}

let confirmSpy: ReturnType<typeof vi.spyOn>;

beforeEach(() => {
    mocks.beforeHandlers.length = 0;
    mocks.patch.mockClear();
    mocks.get.mockClear();
    mocks.del.mockClear();
    window.history.replaceState({}, '', '/classes/class-1/interventions');
    window.scrollTo = vi.fn();
    confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);
});

afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    confirmSpy.mockRestore();
});

/** Escreve no campo «objetivo» do formulário principal (dado realmente introduzido). */
async function typeIntoForm(wrapper: VueWrapper): Promise<void> {
    const field = wrapper.find('textarea');

    expect(field.exists()).toBe(true);

    await field.setValue('Texto escrito pelo professor');
    await nextTick();
}

describe('Intervenções — o aviso de alterações por submeter', () => {
    it('nasce limpo: nenhuma visita que sai da página pede confirmação', () => {
        render();

        const event = fireBefore({ method: 'get', url: '/interventions' });

        expect(confirmSpy).not.toHaveBeenCalled();
        expect(event.defaultPrevented).toBe(false);
    });

    it('«Marcar em curso» com o formulário limpo não pede confirmação', async () => {
        const wrapper = render();

        await buttonByText(wrapper, 'Marcar em curso').trigger('click');

        expect(mocks.patch).toHaveBeenCalledWith('/interventions/int-1', { status: 'in_progress' }, { preserveScroll: true });

        const event = fireBefore({ method: 'patch', url: '/interventions/int-1' });

        expect(confirmSpy).not.toHaveBeenCalled();
        expect(event.defaultPrevented).toBe(false);
    });

    it.each(['patch', 'put', 'post', 'delete'])('uma mudança de estado (%s) não pede confirmação nem com o formulário sujo', async (method) => {
        const wrapper = render();

        await typeIntoForm(wrapper);

        const event = fireBefore({ method, url: '/interventions/int-1' });

        expect(confirmSpy).not.toHaveBeenCalled();
        expect(event.defaultPrevented).toBe(false);
    });

    it('os filtros (GET, mesmo caminho, preserveState) não pedem confirmação com o formulário sujo', async () => {
        const wrapper = render();

        await typeIntoForm(wrapper);

        const event = fireBefore({ method: 'get', url: '/classes/class-1/interventions?status=new', preserveState: true });

        expect(confirmSpy).not.toHaveBeenCalled();
        expect(event.defaultPrevented).toBe(false);
    });

    it('sair da página com dados realmente introduzidos pede confirmação e cancela se recusada', async () => {
        const wrapper = render();

        await typeIntoForm(wrapper);

        const event = fireBefore({ method: 'get', url: '/interventions' });

        expect(confirmSpy).toHaveBeenCalledTimes(1);
        expect(event.defaultPrevented).toBe(true);
    });

    it('sair da página com dados introduzidos segue se o professor aceitar', async () => {
        confirmSpy.mockReturnValue(true);

        const wrapper = render();

        await typeIntoForm(wrapper);

        const event = fireBefore({ method: 'get', url: '/interventions' });

        expect(confirmSpy).toHaveBeenCalledTimes(1);
        expect(event.defaultPrevented).toBe(false);
    });

    it('um GET para outro caminho com preserveState continua a contar como sair', async () => {
        const wrapper = render();

        await typeIntoForm(wrapper);

        const event = fireBefore({ method: 'get', url: '/lessons', preserveState: true });

        expect(confirmSpy).toHaveBeenCalledTimes(1);
        expect(event.defaultPrevented).toBe(true);
    });

    it('beforeunload só avisa com dados reais', async () => {
        const wrapper = render();

        const clean = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(clean);

        expect(clean.defaultPrevented).toBe(false);

        await typeIntoForm(wrapper);

        const dirty = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(dirty);

        expect(dirty.defaultPrevented).toBe(true);
    });

    it('«Editar» carrega o registo sem deixar o aviso a disparar numa mudança de estado', async () => {
        const wrapper = render();

        await buttonByText(wrapper, 'Editar').trigger('click');

        const event = fireBefore({ method: 'patch', url: '/interventions/int-1' });

        expect(confirmSpy).not.toHaveBeenCalled();
        expect(event.defaultPrevented).toBe(false);
    });

    it('«Editar» deixa o formulário com dados reais: sair continua a avisar', async () => {
        const wrapper = render();

        await buttonByText(wrapper, 'Editar').trigger('click');

        const event = fireBefore({ method: 'get', url: '/interventions' });

        expect(confirmSpy).toHaveBeenCalledTimes(1);
        expect(event.defaultPrevented).toBe(true);
    });

    it('«+ Acompanhamento» abre o formulário de acompanhamento sem avisar ao sair nem ao mudar estado', async () => {
        const wrapper = render();

        await buttonByText(wrapper, '+ Acompanhamento').trigger('click');

        const state = fireBefore({ method: 'patch', url: '/interventions/int-1' });
        const leave = fireBefore({ method: 'get', url: '/interventions' });

        expect(confirmSpy).not.toHaveBeenCalled();
        expect(state.defaultPrevented).toBe(false);
        expect(leave.defaultPrevented).toBe(false);
    });
});
