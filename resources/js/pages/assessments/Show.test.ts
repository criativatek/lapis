import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import Show from './Show.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    Link: defineComponent({
        props: { href: { type: String, default: '' } },
        setup: (props, { slots }) => () => h('a', { href: props.href }, slots.default?.()),
    }),
}));

const wrappers: VueWrapper[] = [];

type StudentRow = {
    enrollment_id: number;
    name: string;
    photo_url: string | null;
    class_number: number | null;
    state_key: string;
    state_label: string;
    state_detail: string | null;
    action_label: string;
};

function mountPage(status = 'in_correction', students: StudentRow[] = []) {
    const wrapper = mount(Show, {
        props: {
            instrument: {
                ulid: '01INSTRUMENT',
                title: 'Teste 1',
                class_label: '7.º A',
                period: '1.º Período',
                applied_on: '2026-10-15',
                purpose_label: 'Sumativa',
                counts_toward_classification: true,
                state_label: 'Em correção',
                status,
            },
            summary: { applicable: students.length, completed: 0, pending: students.length, absent: 0, under_review: 0 },
            students,
            nonApplicableStudents: [],
            items: [],
            scores: [],
            scaleBands: [],
        },
    });

    wrappers.push(wrapper);

    return wrapper;
}

afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
});

describe('assessments/Show — ações do cabeçalho', () => {
    it('offers the grid and the edit even when there are no applicable students', () => {
        const wrapper = mountPage();

        expect(wrapper.text()).toContain('Nenhum aluno aplicável a esta avaliação.');
        expect(wrapper.find('a[href="/instruments/01INSTRUMENT?from=assessments"]').text()).toBe('Abrir grelha de correção');
        expect(wrapper.find('a[href="/instruments/01INSTRUMENT/edit"]').text()).toBe('Editar elemento de avaliação');
    });

    it('offers the same actions when there are students', () => {
        const wrapper = mountPage('prepared', [
            {
                enrollment_id: 1,
                name: 'Aluno Teste',
                photo_url: null,
                class_number: 1,
                state_key: 'pending',
                state_label: 'Por corrigir',
                state_detail: null,
                action_label: 'Corrigir',
            },
        ]);

        expect(wrapper.find('a[href="/instruments/01INSTRUMENT/edit"]').exists()).toBe(true);
        expect(wrapper.find('a[href="/instruments/01INSTRUMENT?from=assessments"]').text()).toBe('Abrir grelha de correção');
    });

    it('hides the edit but keeps the grid when the element is annulled', () => {
        const wrapper = mountPage('cancelled');

        expect(wrapper.find('a[href="/instruments/01INSTRUMENT/edit"]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Editar elemento de avaliação');
        expect(wrapper.find('a[href="/instruments/01INSTRUMENT?from=assessments"]').exists()).toBe(true);
    });
});
