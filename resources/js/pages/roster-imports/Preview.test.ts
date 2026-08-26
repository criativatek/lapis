import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';
import Preview from './Preview.vue';

type MockForm = Record<string, unknown> & { errors: Record<string, string> };

const mocks = vi.hoisted(() => ({
    forms: [] as MockForm[],
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    router: {
        post: vi.fn(),
    },
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
    const wrapper = mount(Preview, {
        props: {
            schoolClassUlid: 'class-1',
            token: 'token-1',
            rows: [],
            photos: [],
        },
    });

    wrappers.push(wrapper);

    return wrapper;
}

/** `Preview.vue` creates exactly one `useForm` — the `{ rows }` confirmation form. */
function confirmForm(): MockForm {
    return mocks.forms[0];
}

afterEach(() => {
    mocks.forms.length = 0;
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
});

/**
 * `confirm()` (`RosterImportController::confirm`, reached from `submit()`
 * here) can reactivate an existing enrolment via
 * `StudentEnrollmentService::fillFromRoster()`, which is blocked over the
 * organization's `active_students` quota the same way a brand-new enrolment
 * is — `ValidationException::withMessages(['limit' => ...])`, surfaced by
 * Inertia as `form.errors.limit`. Before this fix, nothing on this page read
 * that key: the existing `photosError` `InputError` is for a wholly
 * different, unrelated form (`photos`, a plain ref — see the "Adicionar
 * fotos" section).
 */
describe('roster-imports/Preview — reactivation quota (limit) error', () => {
    it('shows nothing about a quota when there is no limit error', () => {
        const wrapper = mountPage();

        expect(wrapper.text()).not.toContain('Atingiu o limite');
    });

    it('renders the backend quota message once form.errors.limit is set', async () => {
        const wrapper = mountPage();

        confirmForm().errors.limit =
            'Atingiu o limite de 300 alunos ativos do seu plano. Os dados existentes são mantidos — para inscrever outro, reduza primeiro o número de alunos ativos.';
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain(
            'Atingiu o limite de 300 alunos ativos do seu plano. Os dados existentes são mantidos — para inscrever outro, reduza primeiro o número de alunos ativos.',
        );
    });
});
