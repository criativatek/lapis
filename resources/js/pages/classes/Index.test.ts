import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import Index from './Index.vue';
import type { SchoolClass } from './Index.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    Link: defineComponent({ inheritAttrs: false, setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()) }),
    router: { delete: vi.fn() },
}));

function mountIndex(classes: SchoolClass[] = [], viewingArchived = false) {
    return mount(Index, { props: { classes, viewingArchived } });
}

/**
 * The "Importar horário" button was replaced by "Configurar horários",
 * pointing at the new picker (classes.schedule-setup) instead of jumping
 * straight into the PDF importer — see routes/web.php and
 * ClassController::scheduleSetup(). "Nova turma" stays the primary action.
 */
describe('classes/Index header actions', () => {
    it('offers "Configurar horários", linking to the schedule-setup picker', () => {
        const wrapper = mountIndex();

        const link = wrapper.findAll('a').find((a) => a.text().includes('Configurar horários'));

        expect(link).toBeTruthy();
        expect(link!.attributes('href')).toBe('/classes/schedule-setup');
    });

    it('no longer offers "Importar horário" as a label', () => {
        const wrapper = mountIndex();

        expect(wrapper.text()).not.toContain('Importar horário');
    });

    it('never links directly to the raw import route from the header', () => {
        const wrapper = mountIndex();

        const directImportLink = wrapper.findAll('a').find((a) => a.attributes('href') === '/timetable-imports/create');

        expect(directImportLink).toBeUndefined();
    });

    it('still offers "Nova turma" as the primary action', () => {
        const wrapper = mountIndex();

        const link = wrapper.findAll('a').find((a) => a.text().includes('Nova turma'));

        expect(link).toBeTruthy();
        expect(link!.attributes('href')).toBe('/classes/create');
    });
});

describe('classes/Index — ativas e arquivadas', () => {
    it('offers «Ver turmas arquivadas» as a button, never a heading over active classes', () => {
        const wrapper = mountIndex();

        const link = wrapper.findAll('a').find((a) => a.text().includes('Ver turmas arquivadas'));

        expect(link!.attributes('href')).toBe('/classes/archived');
        expect(wrapper.find('h1, h2').text()).toBe('Turmas');
    });

    it('offers «Voltar às turmas» at the top of the archived list, and again at the end of a long one', () => {
        const archived = (index: number): SchoolClass => ({
            ulid: `c${index}`,
            label: `7.º ${index}`,
            subject: 'Matemática',
            academic_year: '2024/2025',
            grade_level: null,
            status_label: 'Arquivada',
            status: 'archived',
            students_count: 0,
        });

        const short = mountIndex([archived(1)], true);
        const shortBack = short.findAll('a').filter((a) => a.text().includes('Voltar às turmas'));

        expect(shortBack).toHaveLength(1);
        expect(shortBack[0].attributes('href')).toBe('/classes');
        expect(short.text()).not.toContain('Nova turma');
        expect(short.text()).not.toContain('Ver turmas arquivadas');

        const long = mountIndex(Array.from({ length: 7 }, (_, index) => archived(index)), true);

        expect(long.findAll('a').filter((a) => a.text().includes('Voltar às turmas'))).toHaveLength(2);
    });
});
