import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';
import Show from './Show.vue';

type MockForm = Record<string, unknown> & { errors: Record<string, string> };

const mocks = vi.hoisted(() => ({
    forms: [] as MockForm[],
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    Link: defineComponent({
        inheritAttrs: false,
        setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()),
    }),
    router: {
        post: vi.fn(),
        put: vi.fn(),
        delete: vi.fn(),
        get: vi.fn(),
    },
    useForm: (data: Record<string, unknown>) => {
        const form = reactive({
            ...data,
            errors: {},
            processing: false,
            recentlySuccessful: false,
            post: vi.fn(),
            put: vi.fn(),
            reset: vi.fn(),
            clearErrors: vi.fn(),
            transform: vi.fn(function (this: MockForm) {
                return this;
            }),
        }) as MockForm;

        mocks.forms.push(form);

        return form;
    },
}));

const wrappers: VueWrapper[] = [];

function baseProps() {
    return {
        schoolClass: {
            id: 1,
            ulid: 'class-1',
            label: '7.º A',
            subject: 'Matemática',
            academic_year: '2026/2027',
            grade_level: '7.º',
            status: 'active',
            status_label: 'Ativa',
            // Set so the page renders the plain "Avaliada por" line rather
            // than the profile-assignment form — irrelevant to this fix and
            // kept out of the way.
            profile_name: 'Perfil Teste',
        },
        students: [],
        former_students: [],
        availableProfiles: [],
        recurringLessonSlots: null,
    };
}

function mountPage() {
    const wrapper = mount(Show, { props: baseProps() });

    wrappers.push(wrapper);

    return wrapper;
}

/**
 * `Show.vue` creates its forms in a fixed order: n.º de processo, perfil,
 * THE ENROLLMENT FORM (index 2 — the only one that can ever carry
 * `errors.limit`), edição, foto do aluno, importação de pauta, fotos em lote.
 */
function enrollmentForm(): MockForm {
    return mocks.forms[2];
}

afterEach(() => {
    mocks.forms.length = 0;
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
});

/**
 * `StudentEnrollmentService::enrollNew()` blocks a new enrolment over the
 * organization's `active_students` quota via
 * `ValidationException::withMessages(['limit' => ...])`, which Inertia
 * surfaces as `form.errors.limit` on THIS form (`classes.students.store`) —
 * never on `editForm`, `processNumberForm`, `profileForm`, `importForm` or
 * `photoForm`, since none of those can grow active_students. Before this
 * fix, nothing on this page read that key.
 */
describe('classes/Show — enrollment quota (limit) error', () => {
    it('shows nothing about a quota when there is no limit error', () => {
        const wrapper = mountPage();

        expect(wrapper.text()).not.toContain('Atingiu o limite');
    });

    it('renders the backend quota message once the enrollment form.errors.limit is set', async () => {
        const wrapper = mountPage();

        enrollmentForm().errors.limit =
            'Atingiu o limite de 300 alunos ativos do seu plano. Os dados existentes são mantidos — para inscrever outro, reduza primeiro o número de alunos ativos.';
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain(
            'Atingiu o limite de 300 alunos ativos do seu plano. Os dados existentes são mantidos — para inscrever outro, reduza primeiro o número de alunos ativos.',
        );
    });

    it('does not leak the enrollment form limit error onto an unrelated form', async () => {
        const wrapper = mountPage();

        // A stray `limit` key on a DIFFERENT form's errors must never surface
        // through the enrollment section — proves the InputError added for
        // this fix is bound to the enrollment `form`, not to any other one.
        mocks.forms[0].errors.limit = 'Erro estranho de outro formulário.';
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).not.toContain('Erro estranho de outro formulário.');
    });
});
