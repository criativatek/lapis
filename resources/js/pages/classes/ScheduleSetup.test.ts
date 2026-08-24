import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import ScheduleSetup from './ScheduleSetup.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    Link: defineComponent({ inheritAttrs: false, setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()) }),
}));

function mountPage(classes: { ulid: string; label: string; subject: string }[]) {
    return mount(ScheduleSetup, { props: { classes } });
}

/**
 * "Configurar horários" — the picker in front of the two existing, unmodified
 * flows (PDF import, manual editing on a turma's own page). Neither
 * underlying mechanism is touched here; this only checks the picker itself
 * offers both paths, links to the real routes, and copes with zero turmas.
 */
describe('classes/ScheduleSetup', () => {
    it('offers both the import and the manual paths', () => {
        const wrapper = mountPage([{ ulid: 'class-a', label: '7.º C', subject: 'Matemática' }]);

        expect(wrapper.text()).toContain('Importar horário do professor');
        expect(wrapper.text()).toContain('Configurar manualmente');
    });

    it('the "Importar PDF" link points at the real, unmodified import route', () => {
        const wrapper = mountPage([]);

        const link = wrapper.findAll('a').find((a) => a.text().includes('Importar PDF'));

        expect(link).toBeTruthy();
        expect(link!.attributes('href')).toBe('/timetable-imports/create');
    });

    it('lists each of the teacher\'s own classes with a link to configure its schedule', () => {
        const wrapper = mountPage([
            { ulid: 'class-a', label: '7.º C', subject: 'Matemática' },
            { ulid: 'class-b', label: '8.º A', subject: 'Matemática' },
        ]);

        const link = wrapper.findAll('a').find((a) => a.text().includes('7.º C'));

        expect(link).toBeTruthy();
        expect(link!.attributes('href')).toBe('/classes/class-a#horario');

        expect(wrapper.findAll('a').some((a) => a.attributes('href') === '/classes/class-b#horario')).toBe(true);
    });

    it('shows a clear empty state with a way to create a class, not a blank list, when there are no classes', () => {
        const wrapper = mountPage([]);

        expect(wrapper.text()).toContain('Ainda não existem turmas para configurar.');

        const link = wrapper.findAll('a').find((a) => a.text().includes('Nova turma'));

        expect(link).toBeTruthy();
        expect(link!.attributes('href')).toBe('/classes/create');
    });

    it('never leaves the manual list empty and silent when classes exist', () => {
        const wrapper = mountPage([{ ulid: 'class-a', label: '7.º C', subject: 'Matemática' }]);

        expect(wrapper.text()).not.toContain('Ainda não existem turmas para configurar.');
    });
});
