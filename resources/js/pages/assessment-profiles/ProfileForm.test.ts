import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import ProfileForm from './ProfileForm.vue';

type MockForm = Record<string, unknown> & {
    errors: Record<string, string>;
    grade_levels: string[];
    labels?: string[];
    profile_name?: string;
    submit: ReturnType<typeof vi.fn>;
    post: ReturnType<typeof vi.fn>;
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

describe('a escala por defeito', () => {
    it('abre com a escala por defeito quando é um perfil novo', () => {
        const wrapper = mount(ProfileForm, {
            props: {
                academicYears: [],
                subjects: [],
                scales: [{ id: 7, ulid: 'scale-1-5', label: 'Escala 1 a 5', system: true, kind: 'level', min_value: 1, max_value: 5, levels: [] }],
                submitUrl: '/assessment-profiles',
                method: 'post',
                defaultScaleId: 7,
            },
        });
        wrappers.push(wrapper);

        expect(profileForm().scale_id).toBe(7);
    });

    it('não toca na escala de um perfil existente', () => {
        const wrapper = mount(ProfileForm, {
            props: {
                academicYears: [],
                subjects: [],
                scales: [],
                initial: { name: 'Português', academic_year_id: 1, subject_id: 1, grade_levels: [], description: null, scale_id: 3, domains: [] },
                submitUrl: '/assessment-profiles/x',
                method: 'put',
                defaultScaleId: 7,
            },
        });
        wrappers.push(wrapper);

        expect(profileForm().scale_id).toBe(3);
    });
});

const levelScale = {
    id: 7,
    ulid: 'scale-1-5',
    label: 'Escala 1 a 5',
    system: true,
    kind: 'level',
    min_value: 1,
    max_value: 5,
    levels: [
        { code: '1', label: 'Fraco' },
        { code: '2', label: 'Insuficiente' },
        { code: '3', label: 'Suficiente' },
        { code: '4', label: 'Bom' },
        { code: '5', label: 'Muito Bom' },
    ],
};
const percentageScale = { id: 8, ulid: 'scale-pct', label: 'Percentagem (0 a 100)', system: true, kind: 'percentage', min_value: 0, max_value: 100, levels: [] };

function mentionsButton(wrapper: VueWrapper) {
    return wrapper.findAll('button').find((candidate) => candidate.text().includes('Personalizar menções'))!;
}

function mentionsFormElement(): HTMLFormElement {
    return document.querySelector('form[data-test="mentions-form"]') as HTMLFormElement;
}

/**
 * «Personalizar menções»: as menções da escola, por perfil. Por defeito nada
 * muda — o botão só existe para quem quiser outros nomes, e só numa escala de
 * níveis. Ao guardar, o perfil passa a apontar à escala derivada.
 */
describe('personalizar menções', () => {
    it('o botão só está ativo numa escala de níveis', async () => {
        const wrapper = mount(ProfileForm, {
            props: { academicYears: [], subjects: [], scales: [levelScale, percentageScale], submitUrl: '/assessment-profiles', method: 'post', defaultScaleId: 7 },
        });
        wrappers.push(wrapper);

        expect(mentionsButton(wrapper).attributes('disabled')).toBeUndefined();

        profileForm().scale_id = 8;
        await wrapper.vm.$nextTick();

        expect(mentionsButton(wrapper).attributes('disabled')).toBeDefined();
    });

    it('o diálogo abre com as menções atuais e envia as cinco com o nome do perfil', async () => {
        const wrapper = mount(ProfileForm, {
            props: { academicYears: [], subjects: [], scales: [levelScale], submitUrl: '/assessment-profiles', method: 'post', defaultScaleId: 7 },
            attachTo: document.body,
        });
        wrappers.push(wrapper);
        profileForm().name = 'Português';

        await mentionsButton(wrapper).trigger('click');
        await wrapper.vm.$nextTick();

        const inputs = Array.from(document.querySelectorAll<HTMLInputElement>('input[id^="mention-"]'));
        expect(inputs.map((input) => input.value)).toEqual(['Fraco', 'Insuficiente', 'Suficiente', 'Bom', 'Muito Bom']);

        // O terceiro useForm é o das menções (perfil, escala personalizada, menções).
        const mentionsForm = mocks.forms[2];
        mentionsForm.labels = ['Muito insuficiente', 'Insuficiente', 'Suficiente', 'Bom', 'Muito bom'];
        mentionsFormElement().dispatchEvent(new Event('submit', { cancelable: true }));
        await wrapper.vm.$nextTick();

        expect(mentionsForm.post).toHaveBeenCalledWith('/scales/scale-1-5/mencoes', expect.objectContaining({ preserveState: true }));
        expect(mentionsForm.profile_name).toBe('Português');
    });

    it('depois de guardar, o perfil passa a apontar à escala derivada', async () => {
        const derived = { ...levelScale, id: 9, ulid: 'scale-derived', label: 'Escala 1 a 5 · Português', system: false };
        const wrapper = mount(ProfileForm, {
            props: { academicYears: [], subjects: [], scales: [levelScale], submitUrl: '/assessment-profiles', method: 'post', defaultScaleId: 7 },
            attachTo: document.body,
        });
        wrappers.push(wrapper);

        await mentionsButton(wrapper).trigger('click');
        await wrapper.vm.$nextTick();

        const mentionsForm = mocks.forms[2];
        mentionsForm.post.mockImplementation(async (_url: string, options: { onSuccess?: () => void }) => {
            await wrapper.setProps({ scales: [levelScale, derived] });
            options.onSuccess?.();
        });

        mentionsFormElement().dispatchEvent(new Event('submit', { cancelable: true }));
        await wrapper.vm.$nextTick();
        await wrapper.vm.$nextTick();

        expect(profileForm().scale_id).toBe(9);
    });
});
