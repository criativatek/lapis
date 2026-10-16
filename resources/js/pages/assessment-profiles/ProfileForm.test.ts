import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import ProfileForm from './ProfileForm.vue';

type MockForm = Record<string, unknown> & {
    errors: Record<string, string>;
    grade_levels: string[];
    submit: ReturnType<typeof vi.fn>;
};

const mocks = vi.hoisted(() => ({
    forms: [] as MockForm[],
}));

vi.mock('@inertiajs/vue3', () => ({
    useForm: (data: Record<string, unknown>) => {
        const form = reactive({
            ...data,
            errors: {},
            processing: false,
            submit: vi.fn(),
            post: vi.fn(),
            reset: vi.fn(),
            clearErrors: vi.fn(),
        }) as unknown as MockForm;

        mocks.forms.push(form);

        return form;
    },
}));

const wrappers: VueWrapper[] = [];

function mountForm() {
    const wrapper = mount(ProfileForm, {
        props: {
            academicYears: [],
            subjects: [],
            scales: [],
            submitUrl: '/assessment-profiles',
            method: 'post',
        },
    });

    wrappers.push(wrapper);

    return wrapper;
}

/** The first `useForm` call is the profile itself; the second is the scale dialog. */
function profileForm(): MockForm {
    return mocks.forms[0];
}

afterEach(() => {
    mocks.forms.length = 0;
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
});

describe('ProfileForm — Ano(s) de escolaridade', () => {
    it('adds what was typed but not «Adicionado» before submitting', async () => {
        const wrapper = mountForm();

        await wrapper.get('#grade_level_input').setValue('7.º');
        await wrapper.get('form').trigger('submit');

        expect(profileForm().grade_levels).toEqual(['7.º']);
        expect(profileForm().submit).toHaveBeenCalledTimes(1);
    });

    it('does not duplicate a value that is already in the list', async () => {
        const wrapper = mountForm();

        profileForm().grade_levels.push('7.º');
        await wrapper.get('#grade_level_input').setValue(' 7.º ');
        await wrapper.get('form').trigger('submit');

        expect(profileForm().grade_levels).toEqual(['7.º']);
    });

    it('leaves the list alone when nothing was typed', async () => {
        const wrapper = mountForm();

        await wrapper.get('form').trigger('submit');

        expect(profileForm().grade_levels).toEqual([]);
        expect(profileForm().submit).toHaveBeenCalledTimes(1);
    });

    it('shows the per-element error the server returns as grade_levels.N', async () => {
        const wrapper = mountForm();

        profileForm().errors['grade_levels.0'] = 'O campo grade_levels.0 não pode ter mais de 16 caracteres.';
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('não pode ter mais de 16 caracteres');
    });

    it('shows the list error and the element errors together', async () => {
        const wrapper = mountForm();

        profileForm().errors.grade_levels = 'Erro da lista.';
        profileForm().errors['grade_levels.1'] = 'Valor repetido.';
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('Erro da lista.');
        expect(wrapper.text()).toContain('Valor repetido.');
    });
});
