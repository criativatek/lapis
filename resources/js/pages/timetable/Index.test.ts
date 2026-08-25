import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import Index from './Index.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    Link: defineComponent({ inheritAttrs: false, setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()) }),
}));

type TimetableSlot = {
    ulid: string;
    day_of_week: number;
    starts_at: string;
    ends_at: string;
    starts_on: string | null;
    ends_on: string | null;
    school_class: { ulid: string; label: string };
    subject: string;
};

type ClassOption = { ulid: string; label: string; subject: string };

function slot(overrides: Partial<TimetableSlot> = {}): TimetableSlot {
    return {
        ulid: 'slot-a',
        day_of_week: 1,
        starts_at: '08:30',
        ends_at: '09:20',
        starts_on: null,
        ends_on: null,
        school_class: { ulid: 'class-a', label: '7.º C' },
        subject: 'Matemática',
        ...overrides,
    };
}

function mountPage(slots: TimetableSlot[], classes: ClassOption[] = []) {
    return mount(Index, { props: { slots, classes } });
}

/**
 * «Horário do Professor» — the teacher's whole week read in one place, plus
 * the two existing, unmodified ways of filling it in. Nothing here writes: the
 * page has no form and no mutating link, and the assertions below are about
 * what it SHOWS — the grouping by weekday, the empty state, and the two entry
 * points pointing at the real routes.
 */
const week = (wrapper: ReturnType<typeof mountPage>) =>
    wrapper.find('section[aria-label="Horário semanal"]');

const agenda = (wrapper: ReturnType<typeof mountPage>) =>
    wrapper.find('section[aria-label="Agenda da semana"]');

const labelsOf = (root: ReturnType<typeof week>) =>
    root.findAll('section[aria-label]').map((s) => s.attributes('aria-label'));

describe('timetable/Index', () => {
    it('groups the week by weekday, in Portuguese, only for the days actually taught', () => {
        const wrapper = mountPage([
            slot({ ulid: 'a', day_of_week: 1 }),
            slot({ ulid: 'b', day_of_week: 3, starts_at: '11:00', ends_at: '11:50' }),
        ]);

        const headings = agenda(wrapper)
            .findAll('h2')
            .map((heading) => heading.text());

        expect(headings).toContain('Segunda-feira');
        expect(headings).toContain('Quarta-feira');
        // In the narrow-screen agenda a day with no aulas is not a line: there
        // is no week to read at a glance on a phone, only a list, and an empty
        // Saturday is not information.
        expect(headings).not.toContain('Terça-feira');
        expect(headings).not.toContain('Sábado');
    });

    // ------------------------------------------- a semana em ecrã largo

    /**
     * A SEXTA-FEIRA NUNCA CAI PARA A LINHA DE BAIXO. Com aulas à segunda,
     * terça, quarta e sexta, um número de colunas que acompanhasse a largura
     * do ecrã punha a sexta sozinha numa segunda linha só porque a quinta
     * estava vazia — e uma semana lida assim deixa de ser uma semana.
     */
    it('holds all five weekdays in place, in order, however wide the ecrã is', () => {
        const wrapper = mountPage([
            slot({ ulid: 'a', day_of_week: 1 }),
            slot({ ulid: 'b', day_of_week: 2 }),
            slot({ ulid: 'c', day_of_week: 3 }),
            slot({ ulid: 'e', day_of_week: 5 }),
        ]);

        expect(labelsOf(week(wrapper))).toEqual([
            'Segunda-feira',
            'Terça-feira',
            'Quarta-feira',
            'Quinta-feira',
            'Sexta-feira',
        ]);

        // Five columns, fixed — not a responsive count that can wrap.
        const grid = week(wrapper).find('div.grid');

        expect(grid.classes().filter((c) => c.includes('grid-cols'))).toEqual([
            'grid-cols-5',
        ]);
    });

    it('says «Sem aulas» on a free weekday instead of dropping the column', () => {
        const wrapper = mountPage([
            slot({ ulid: 'a', day_of_week: 1 }),
            slot({ ulid: 'e', day_of_week: 5 }),
        ]);

        const thursday = week(wrapper).find('section[aria-label="Quinta-feira"]');

        expect(thursday.exists()).toBe(true);
        expect(thursday.text()).toContain('Sem aulas');
    });

    /**
     * O DIA VAZIO É SÓ UM RÓTULO. Não há bloco nenhum por trás dele, nem passa
     * a haver por ser mostrado: a página continua a ser uma leitura.
     */
    it('invents no aula behind an empty weekday: the placeholder is a label and nothing else', () => {
        const wrapper = mountPage([
            slot({ ulid: 'a', day_of_week: 1 }),
            slot({ ulid: 'b', day_of_week: 2 }),
            slot({ ulid: 'c', day_of_week: 3 }),
            slot({ ulid: 'e', day_of_week: 5 }),
        ]);

        // Four blocks sent, four blocks drawn — the fifth column adds none.
        expect(week(wrapper).findAll('li')).toHaveLength(4);

        const thursday = week(wrapper).find('section[aria-label="Quinta-feira"]');

        expect(thursday.findAll('li')).toHaveLength(0);
        expect(thursday.findAll('a')).toHaveLength(0);
        expect(thursday.findAll('form')).toHaveLength(0);
        expect(thursday.findAll('button')).toHaveLength(0);
    });

    it('keeps a rare sábado visible, in a row of its own, and never a column reserved for it', () => {
        const withSaturday = mountPage([
            slot({ ulid: 'a', day_of_week: 1 }),
            slot({ ulid: 's', day_of_week: 6, starts_at: '09:00', ends_at: '09:50' }),
        ]);

        expect(labelsOf(week(withSaturday))).toEqual([
            'Segunda-feira',
            'Terça-feira',
            'Quarta-feira',
            'Quinta-feira',
            'Sexta-feira',
            'Sábado',
        ]);
        expect(
            week(withSaturday).find('section[aria-label="Sábado"]').findAll('li'),
        ).toHaveLength(1);
        // The weekend never shares the guaranteed five-column row.
        expect(week(withSaturday).findAll('div.grid')).toHaveLength(2);

        // With no aulas on it, o sábado simply is not there.
        expect(week(mountPage([slot()])).text()).not.toContain('Sábado');
    });

    // ------------------------------------------ a semana em ecrã estreito

    it('still lists only the days actually taught in ecrã estreito, with no «Sem aulas» filler', () => {
        const wrapper = mountPage([
            slot({ ulid: 'a', day_of_week: 1 }),
            slot({ ulid: 'b', day_of_week: 2 }),
            slot({ ulid: 'c', day_of_week: 3 }),
            slot({ ulid: 'e', day_of_week: 5 }),
        ]);

        expect(labelsOf(agenda(wrapper))).toEqual([
            'Segunda-feira',
            'Terça-feira',
            'Quarta-feira',
            'Sexta-feira',
        ]);
        expect(agenda(wrapper).text()).not.toContain('Sem aulas');
        expect(agenda(wrapper).text()).not.toContain('Quinta-feira');
    });

    it('keeps every block of a day together, in the order the server sent them', () => {
        const wrapper = mountPage([
            slot({ ulid: 'a', day_of_week: 1, starts_at: '08:30', ends_at: '09:20' }),
            slot({
                ulid: 'b',
                day_of_week: 1,
                starts_at: '10:30',
                ends_at: '11:20',
                school_class: { ulid: 'class-b', label: '8.º A' },
            }),
            slot({ ulid: 'c', day_of_week: 5, starts_at: '14:00', ends_at: '14:50' }),
        ]);

        const monday = wrapper.find('section[aria-label="Segunda-feira"]');

        expect(monday.exists()).toBe(true);
        expect(monday.findAll('li')).toHaveLength(2);
        expect(monday.text()).toContain('08:30–09:20');
        expect(monday.text()).toContain('10:30–11:20');
        expect(monday.text()).toContain('7.º C');
        expect(monday.text()).toContain('8.º A');

        expect(wrapper.find('section[aria-label="Sexta-feira"]').findAll('li')).toHaveLength(1);
    });

    it('shows the turma and the disciplina of each block, and never a sala', () => {
        const wrapper = mountPage([slot()]);

        expect(wrapper.text()).toContain('7.º C');
        expect(wrapper.text()).toContain('Matemática');
        // There is no room column anywhere in the schema, so there is nothing
        // honest to show — and nothing invented here either.
        expect(wrapper.text()).not.toContain('Sala');
    });

    it('shows a block\'s optional validity window only when it has one', () => {
        expect(mountPage([slot()]).text()).not.toContain('Vigência');

        const limited = mountPage([
            slot({ starts_on: '2026-09-14', ends_on: '2026-12-18' }),
        ]);

        expect(limited.text()).toContain('Vigência: 2026-09-14 a 2026-12-18');
    });

    it('summarises the week from the real blocks, not from an invented total', () => {
        const wrapper = mountPage([
            slot({ ulid: 'a', day_of_week: 1 }),
            slot({ ulid: 'b', day_of_week: 2 }),
            slot({
                ulid: 'c',
                day_of_week: 3,
                school_class: { ulid: 'class-b', label: '8.º A' },
            }),
        ]);

        expect(wrapper.text()).toContain('3 aulas por semana · 2 turmas');
    });

    it('explains both ways forward, instead of an empty grid, when there is no horário yet', () => {
        const wrapper = mountPage([], [
            { ulid: 'class-a', label: '7.º C', subject: 'Matemática' },
        ]);

        expect(wrapper.text()).toContain('Ainda não tens horário configurado');
        expect(wrapper.text()).toContain('importar o PDF');
        expect(wrapper.text()).toContain('definir os blocos à mão');
        // Never a bare, wordless grid.
        expect(wrapper.findAll('section[aria-label="Horário semanal"]')).toHaveLength(0);
    });

    it('offers both configuration paths whether or not a horário already exists', () => {
        for (const wrapper of [mountPage([]), mountPage([slot()])]) {
            expect(wrapper.text()).toContain('Importar horário do professor');
            expect(wrapper.text()).toContain('Configurar manualmente');
        }
    });

    it('the "Importar PDF" link points at the real, unmodified import route', () => {
        const wrapper = mountPage([slot()]);

        const link = wrapper.findAll('a').find((a) => a.text().includes('Importar PDF'));

        expect(link).toBeTruthy();
        expect(link!.attributes('href')).toBe('/timetable-imports/create');
    });

    it('the manual path lists the teacher\'s own turmas, each linking to its own horário', () => {
        const wrapper = mountPage([], [
            { ulid: 'class-a', label: '7.º C', subject: 'Matemática' },
            { ulid: 'class-b', label: '8.º A', subject: 'Matemática' },
        ]);

        const hrefs = wrapper.findAll('a').map((a) => a.attributes('href'));

        expect(hrefs).toContain('/classes/class-a#horario');
        expect(hrefs).toContain('/classes/class-b#horario');
    });

    it('a block links to its own turma\'s schedule editor, so the week is a way in and not a dead end', () => {
        const wrapper = mountPage([slot()]);

        const monday = wrapper.find('section[aria-label="Segunda-feira"]');

        expect(monday.find('a').attributes('href')).toBe('/classes/class-a#horario');
    });

    it('offers a way to create a turma when there is not even one to configure', () => {
        const wrapper = mountPage([], []);

        expect(wrapper.text()).toContain('Ainda não existem turmas para configurar.');

        const link = wrapper.findAll('a').find((a) => a.text().includes('Nova turma'));

        expect(link).toBeTruthy();
        expect(link!.attributes('href')).toBe('/classes/create');
    });

    it('never renders a form or a mutating control: the page is a reading', () => {
        const wrapper = mountPage([slot()], [
            { ulid: 'class-a', label: '7.º C', subject: 'Matemática' },
        ]);

        expect(wrapper.findAll('form')).toHaveLength(0);
        expect(wrapper.findAll('input')).toHaveLength(0);
        expect(wrapper.findAll('button')).toHaveLength(0);
    });
});
