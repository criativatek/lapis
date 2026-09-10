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
