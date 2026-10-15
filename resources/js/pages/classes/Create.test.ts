import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';
import Create from './Create.vue';

type MockForm = Record<string, unknown> & { errors: Record<string, string> };

const mocks = vi.hoisted(() => ({
    forms: [] as MockForm[],
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    Link: defineComponent({
        props: { href: { type: String, default: '' } },
        setup: (props, { slots }) => () => h('a', { href: props.href }, slots.default?.()),
    }),
    useForm: (data: Record<string, unknown>) => {
        const form = reactive({
            ...data,
            errors: {},
            processing: false,
            post: vi.fn(),
        }) as MockForm;

        mocks.forms.push(form);

        return form;
    },
}));

const wrappers: VueWrapper[] = [];

type PageProps = {
    academicYears: { id: number; label: string }[];
    subjects: { id: number; label: string }[];
    profiles: { version_id: number; label: string; subject_id: number }[];
    canCreateAcademicYear: boolean;
    canCreateSubject: boolean;
};

function mountPage(overrides: Partial<PageProps> = {}) {
    const wrapper = mount(Create, {
        props: {
            academicYears: [],
            subjects: [],
            profiles: [],
            canCreateAcademicYear: true,
            canCreateSubject: true,
            ...overrides,
        },
    });

    wrappers.push(wrapper);

    return wrapper;
}

/** `Create.vue` creates exactly one form, for the class itself. */
function classForm(): MockForm {
    return mocks.forms[0];
}

afterEach(() => {
    mocks.forms.length = 0;
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
});

/**
 * The backend blocks a class creation over the organization's `active_classes`
 * quota via `ValidationException::withMessages(['limit' => ...])`
 * (`App\Support\Limits\Limits::assertCanIncreaseFor`), which Inertia surfaces
 * as `form.errors.limit`. Before this fix nothing in this page read that key,
 * so a teacher at the limit saw the form simply do nothing.
 */
describe('classes/Create — quota (limit) error', () => {
    it('shows nothing about a quota when there is no limit error', () => {
        const wrapper = mountPage();

        expect(wrapper.text()).not.toContain('Atingiu o limite');
    });

    it('renders the backend quota message once form.errors.limit is set', async () => {
        const wrapper = mountPage();

        classForm().errors.limit =
            'Atingiu o limite de 8 turmas ativas do seu plano. Os dados existentes são mantidos — para criar outra, reduza primeiro o número de turmas ativas.';
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain(
            'Atingiu o limite de 8 turmas ativas do seu plano. Os dados existentes são mantidos — para criar outra, reduza primeiro o número de turmas ativas.',
        );
    });
});

/**
 * A class belongs to an academic year and a subject. A brand-new account has
 * neither, so the two required selects used to open with nothing to choose and
 * no way out. The page now says what is missing and who can fix it.
 */
describe('classes/Create — missing year or subject', () => {
    const filled = {
        academicYears: [{ id: 1, label: '2026/2027' }],
        subjects: [{ id: 1, label: 'Matemática' }],
    };

    function submitButton(wrapper: VueWrapper) {
        return wrapper.find('button[type="submit"]');
    }

    it('explains what is missing and links to where the owner creates it', () => {
        const wrapper = mountPage();
        const notice = wrapper.get('[data-testid="missing-prerequisites"]');

        expect(notice.text()).toContain('Uma turma pertence sempre a um ano letivo e a uma disciplina.');
        expect(notice.find('a[href="/academic-years/create"]').exists()).toBe(true);
        expect(notice.find('a[href="/subjects"]').exists()).toBe(true);
        expect(submitButton(wrapper).attributes('disabled')).toBeDefined();
    });

    it('asks a member who cannot manage them to contact the organization owner, without links', () => {
        const wrapper = mountPage({ canCreateAcademicYear: false, canCreateSubject: false });
        const notice = wrapper.get('[data-testid="missing-prerequisites"]');

        expect(notice.find('a').exists()).toBe(false);
        expect(notice.text()).toContain('Peça ao responsável da organização que o crie.');
        expect(notice.text()).toContain('Peça ao responsável da organização que a adicione.');
        expect(submitButton(wrapper).attributes('disabled')).toBeDefined();
    });

    it('only mentions what is actually missing and keeps the button disabled', () => {
        const wrapper = mountPage({ academicYears: filled.academicYears });
        const notice = wrapper.get('[data-testid="missing-prerequisites"]');

        expect(notice.find('a[href="/academic-years/create"]').exists()).toBe(false);
        expect(notice.find('a[href="/subjects"]').exists()).toBe(true);
        expect(submitButton(wrapper).attributes('disabled')).toBeDefined();
    });

    it('shows no notice and enables the button when both lists are filled', () => {
        const wrapper = mountPage(filled);

        expect(wrapper.find('[data-testid="missing-prerequisites"]').exists()).toBe(false);
        expect(submitButton(wrapper).attributes('disabled')).toBeUndefined();
    });

    it('limits the grade level to 16 characters and marks it optional', () => {
        const wrapper = mountPage(filled);

        expect(wrapper.get('#grade_level').attributes('maxlength')).toBe('16');
        expect(wrapper.text()).toContain('Opcional.');
    });
});
