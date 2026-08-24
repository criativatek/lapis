import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import InstrumentForm from './InstrumentForm.vue';

const inertia = vi.hoisted(() => ({
    submit: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    useForm: (data: Record<string, unknown>) => {
        const form = reactive({
            ...data,
            errors: {},
            processing: false,
            transform(callback: (value: Record<string, unknown>) => Record<string, unknown>) {
                callback(form);

                return form;
            },
            submit: inertia.submit,
        });

        return form;
    },
}));

function mountForm() {
    return mount(InstrumentForm, {
        props: {
            schoolClass: { ulid: 'class-a', label: '7.º A' },
            periods: [{ id: 3, label: '1.º período' }],
            types: [{ id: 5, label: 'Teste', default_purpose: 'summative' }],
            domains: [{ id: 7, label: 'Conhecimento' }],
            submitUrl: '/classes/class-a/instruments',
            method: 'post' as const,
            defaultAcademicPeriodId: 3,
        },
    });
}

function modeButton(wrapper: ReturnType<typeof mountForm>, label: string) {
    return wrapper.findAll('button').find((button) => button.text().includes(label))!;
}

describe('InstrumentForm creation modes', () => {
    beforeEach(() => {
        inertia.submit.mockReset();
    });

    it('returns from simple to advanced and back when the structure remains compatible', async () => {
        const wrapper = mountForm();

        await wrapper.get('input[type="checkbox"]').setValue(true);
        await modeButton(wrapper, 'Criação avançada').trigger('click');

        expect(wrapper.find('#title').exists()).toBe(true);
        expect(modeButton(wrapper, 'Criação simples').attributes('disabled')).toBeUndefined();

        await modeButton(wrapper, 'Criação simples').trigger('click');

        expect(wrapper.find('#quick-title').exists()).toBe(true);
    });

    it('preserves common data throughout the mode cycle', async () => {
        const wrapper = mountForm();

        await wrapper.get('#quick-title').setValue('Teste de leitura');
        await wrapper.get('#quick-instrument-type').setValue('5');
        await wrapper.get('#quick-applied-on').setValue('2026-09-18');
        await wrapper.get('#quick-period').setValue('3');
        await wrapper.get('#quick-total-points').setValue('80');
        await wrapper.get('input[type="checkbox"]').setValue(true);
        await modeButton(wrapper, 'Criação avançada').trigger('click');

        expect((wrapper.get('#title').element as HTMLInputElement).value).toBe('Teste de leitura');
        expect((wrapper.get('#instrument_type_id').element as HTMLSelectElement).value).toBe('5');
        expect((wrapper.get('#applied_on').element as HTMLInputElement).value).toBe('2026-09-18');
        expect((wrapper.get('#academic_period_id').element as HTMLSelectElement).value).toBe('3');
        expect((wrapper.get('#total_points').element as HTMLInputElement).value).toBe('80');

        await wrapper.get('#purpose').setValue('formative');
        await modeButton(wrapper, 'Criação simples').trigger('click');
        await modeButton(wrapper, 'Criação avançada').trigger('click');

        expect((wrapper.get('#title').element as HTMLInputElement).value).toBe('Teste de leitura');
        expect((wrapper.get('#purpose').element as HTMLSelectElement).value).toBe('formative');
        expect((wrapper.get('#total_points').element as HTMLInputElement).value).toBe('80');
    });

    it('never submits merely by changing creation mode', async () => {
        const wrapper = mountForm();

        await wrapper.get('input[type="checkbox"]').setValue(true);
        await modeButton(wrapper, 'Criação avançada').trigger('click');
        await modeButton(wrapper, 'Criação simples').trigger('click');

        expect(inertia.submit).not.toHaveBeenCalled();
    });

    it('keeps an incompatible advanced question intact and prevents returning to simple mode', async () => {
        const wrapper = mountForm();

        await wrapper.get('input[type="checkbox"]').setValue(true);
        await modeButton(wrapper, 'Criação avançada').trigger('click');

        const questionLabel = wrapper.get('input[placeholder="Ex.: Compreensão do texto"]');
        await questionLabel.setValue('Interpretação do excerto');

        const simpleButton = modeButton(wrapper, 'Criação simples');
        expect(simpleButton.attributes('disabled')).toBeDefined();
        expect(simpleButton.attributes('title')).toBe(
            'A configuração avançada já contém dados que ficariam ocultos.',
        );

        await simpleButton.trigger('click');

        expect(wrapper.find('#title').exists()).toBe(true);
        expect((questionLabel.element as HTMLInputElement).value).toBe('Interpretação do excerto');
        expect(inertia.submit).not.toHaveBeenCalled();
    });
});
