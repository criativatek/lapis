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

function mountPage() {
    const wrapper = mount(Create, {
        props: {
            academicYears: [],
            subjects: [],
            profiles: [],
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
