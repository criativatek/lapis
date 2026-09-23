import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import Characterisation from './Characterisation.vue';

/**
 * Janela P — cobre J) o empty state de medidas com o botão «Adicionar
 * medida», K) o cartão do aluno só recolhe quando o PUT da caracterização
 * tem sucesso, e L) um erro mantém o cartão aberto e o texto escrito.
 */

const put = vi.fn();
const post = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    Link: { template: '<a><slot /></a>' },
    router: {
        put: (...args: unknown[]) => put(...args),
        post: (...args: unknown[]) => post(...args),
        on: () => () => {},
    },
}));

vi.mock('@/pages/classes/partials/CharacterisationImportDialog.vue', () => ({
    default: { template: '<div />' },
}));

vi.mock('@/pages/classes/partials/AddMeasureDialog.vue', () => ({
    default: { template: '<div class="add-measure-dialog-stub" />', props: ['open', 'classUlid', 'enrollmentUlid', 'studentName', 'supportMeasureLevels'] },
}));

const wrappers: VueWrapper[] = [];

function studentFixture(overrides: Record<string, unknown> = {}) {
    return {
        enrollment_ulid: '01JSTU1',
        name: 'Ana Silva',
        class_number: 1,
        sections: {
            summary: null,
            strengths: null,
            interests: null,
            needs: null,
            barriers: null,
            participation: null,
        },
        has_characterisation: false,
        last_updated_at: null,
        updated_by: null,
        measures: [],
        unresolved_annotations: [],
        revisions: [],
        ...overrides,
    };
}

function mountPage(students = [studentFixture()]) {
    const wrapper = mount(Characterisation, {
        attachTo: document.body,
        props: {
            schoolClass: { ulid: '01JCLASS', label: '7.º A', subject: 'Matemática', academic_year: '2026/2027' },
            classCharacterisation: { summary: null, last_updated_at: null, updated_by: null, revisions: [] },
            students,
            sections: [{ key: 'summary', label: 'Resumo' }],
            supportMeasureLevels: [{ value: 'universal', label: 'Medida universal', measures: [{ value: 'tutorial_support', label: 'Apoio tutorial' }] }],
            can: { update: true, addMeasure: true },
        },
    });

    wrappers.push(wrapper);

    return wrapper;
}

afterEach(() => {
    wrappers.forEach((wrapper) => wrapper.unmount());
    wrappers.length = 0;
    put.mockReset();
    post.mockReset();
});

describe('Caracterização — medidas associadas', () => {
    it('J) shows the empty state and the "Adicionar medida" button when there are no measures', async () => {
        const wrapper = mountPage();

        // Abre o cartão do aluno para ver o conteúdo.
        await wrapper.findAll('button.w-full.text-left')[1].trigger('click');

        expect(wrapper.text()).toContain('Sem medidas registadas.');
        expect(wrapper.text()).toContain('Adicionar medida');
    });

    it('hides the "Adicionar medida" button when can.addMeasure is false', async () => {
        const wrapper = mount(Characterisation, {
            attachTo: document.body,
            props: {
                schoolClass: { ulid: '01JCLASS', label: '7.º A', subject: null, academic_year: null },
                classCharacterisation: { summary: null, last_updated_at: null, updated_by: null, revisions: [] },
                students: [studentFixture()],
                sections: [{ key: 'summary', label: 'Resumo' }],
                supportMeasureLevels: [],
                can: { update: true, addMeasure: false },
            },
        });
        wrappers.push(wrapper);

        await wrapper.findAll('button.w-full.text-left')[1].trigger('click');

        expect(wrapper.text()).not.toContain('Adicionar medida');
    });

    it('K) collapses the student card and confirms only when the save succeeds', async () => {
        const wrapper = mountPage();

        await wrapper.findAll('button.w-full.text-left')[1].trigger('click');
        expect(wrapper.find('textarea').exists()).toBe(true);

        await wrapper.find('textarea').setValue('Trabalha bem em grupo.');

        const saveButton = wrapper.findAll('button').find((button) => button.text() === 'Guardar');
        await saveButton?.trigger('click');

        expect(put).toHaveBeenCalledTimes(1);
        const [, , options] = put.mock.calls[0] as [string, unknown, { onSuccess: () => void }];

        // Antes do sucesso, o cartão continua aberto.
        expect(wrapper.find('textarea').exists()).toBe(true);

        options.onSuccess();
        await wrapper.vm.$nextTick();
        await wrapper.vm.$nextTick();
        await new Promise((resolve) => setTimeout(resolve, 0));

        // Depois do sucesso, o Collapsible fecha — o textarea deixa de estar visível.
        expect(wrapper.find('textarea').exists()).toBe(false);
    });

    it('L) keeps the card open and the typed text intact when the save fails', async () => {
        const wrapper = mountPage();

        await wrapper.findAll('button.w-full.text-left')[1].trigger('click');
        await wrapper.find('textarea').setValue('Texto que não pode desaparecer.');

        const saveButton = wrapper.findAll('button').find((button) => button.text() === 'Guardar');
        await saveButton?.trigger('click');

        const [, , options] = put.mock.calls[0] as [string, unknown, { onError: (errors: Record<string, string>) => void }];

        options.onError({ summary: 'Não foi possível guardar.' });
        await wrapper.vm.$nextTick();

        expect(wrapper.find('textarea').exists()).toBe(true);
        expect((wrapper.find('textarea').element as HTMLTextAreaElement).value).toBe('Texto que não pode desaparecer.');
    });
});
