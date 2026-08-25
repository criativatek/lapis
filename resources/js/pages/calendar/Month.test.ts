import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import type { CalendarAssessment, CalendarDay, CalendarPeriod } from './calendar';
import Month from './Month.vue';

const routerGet = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    Link: defineComponent({ inheritAttrs: false, setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()) }),
    router: { get: (...args: unknown[]) => routerGet(...args) },
}));

function assessment(overrides: Partial<CalendarAssessment> = {}): CalendarAssessment {
    return {
        ulid: 'inst-a',
        title: 'Teste de Frações',
        applied_on: '2026-10-15',
        class_ulid: 'class-a',
        class_label: '7.º C',
        subject: 'Matemática',
        type: 'Teste',
        status: 'prepared',
        status_label: 'Preparado',
        href: '/instruments/inst-a',
        ...overrides,
    };
}

function period(overrides: Partial<CalendarPeriod> = {}): CalendarPeriod {
    return {
        ulid: 'period-1',
        label: '1.º Período',
        kind: 'term',
        kind_label: 'Período',
        sequence: 1,
        starts_on: '2026-09-01',
        ends_on: '2026-12-18',
        ...overrides,
    };
}

/**
 * The real October 2026 grid: the month opens on a Thursday, so the grid runs
 * from Monday 28 September to Sunday 1 November — 35 cells.
 */
function octoberDays(
    fill: (date: string) => Partial<CalendarDay> = () => ({}),
): CalendarDay[] {
    const days: CalendarDay[] = [];
    const cursor = new Date('2026-09-28T00:00:00Z');

    for (let index = 0; index < 35; index += 1) {
        const date = cursor.toISOString().slice(0, 10);

        days.push({
            date,
            day: cursor.getUTCDate(),
            in_month: date >= '2026-10-01' && date <= '2026-10-31',
            is_today: false,
            period: null,
            assessments: [],
            ...fill(date),
        });

        cursor.setUTCDate(cursor.getUTCDate() + 1);
    }

    return days;
}

function mountPage(overrides: Partial<InstanceType<typeof Month>['$props']> = {}) {
    return mount(Month, {
        props: {
            academicYear: {
                ulid: 'year-a',
                label: '2026/2027',
                starts_on: '2026-09-01',
                ends_on: '2027-07-31',
            },
            month: {
                value: '2026-10',
                starts_on: '2026-10-01',
                ends_on: '2026-10-31',
            },
            days: octoberDays(),
            periods: [],
            navigation: {
                previous: '2026-09',
                next: '2026-11',
                home: '2026-10',
                home_is_today: true,
            },
            assessmentsPerDay: 3,
            ...overrides,
        },
    });
}

/**
 * «Calendário do Ano Letivo», vista Mês — a estrutura do ano e as avaliações,
 * lidas juntas. The page is a READING: it has no form and no mutating control,
 * and nothing here creates anything. Aulas never appear — that is «Horário do
 * Professor»'s question, and this page must never become a copy of it.
 */
describe('calendar/Month', () => {
    beforeEach(() => {
        routerGet.mockClear();
    });

    it('lays the month out as whole weeks of seven days', () => {
        const wrapper = mountPage();

        const cells = wrapper.findAll('[data-date]');

        expect(cells).toHaveLength(35);
        expect(cells[0]!.attributes('data-date')).toBe('2026-09-28');
        expect(cells[34]!.attributes('data-date')).toBe('2026-11-01');
    });

    it('names the weekdays in Portuguese without capitalising every word', () => {
        const text = mountPage().text();

        // capitalizeFirst, never the CSS `capitalize` class: the day after the
        // hyphen stays lower case, and so does every «de» on this page.
        expect(text).toContain('Seg');
        expect(text).toContain('Sáb');
        expect(mountPage().html()).not.toContain('capitalize');
    });

    it('writes the month heading with only its first letter raised', () => {
        const text = mountPage().text();

        expect(text).toContain('Outubro de 2026');
        expect(text).not.toContain('Outubro De 2026');
    });

    it('renders an assessment as a link to the instrument\'s own page', () => {
        const wrapper = mountPage({
            days: octoberDays((date) =>
                date === '2026-10-15' ? { assessments: [assessment()] } : {},
            ),
        });

        const cell = wrapper.find('[data-date="2026-10-15"]');
        const link = cell.find('a');

        expect(link.attributes('href')).toBe('/instruments/inst-a');
        expect(cell.text()).toContain('Teste de Frações');
        expect(cell.text()).toContain('7.º C');
        expect(cell.text()).toContain('Teste');
    });

    it('shows a período as structural context, distinguished from an assessment by more than colour', () => {
        const wrapper = mountPage({
            periods: [period()],
            days: octoberDays((date) => ({
                period: period(),
                ...(date === '2026-10-15' ? { assessments: [assessment()] } : {}),
            })),
        });

        // The legend names the período in words — the tint is never the only
        // thing carrying it.
        expect(wrapper.text()).toContain('1.º Período');
        expect(wrapper.text()).toContain('Período');

        // An avaliação carries a border, an icon and emphasis; a período band
        // carries none of the three, so the two stay apart on a monochrome
        // screen and for a reader who does not see the hue.
        const entry = wrapper.find('[data-date="2026-10-15"]').find('a');
        expect(entry.classes().join(' ')).toContain('border');
        expect(entry.classes().join(' ')).toContain('font-medium');
        expect(entry.find('svg').exists()).toBe(true);
    });

    it('names a período where it begins and where it changes, not in all thirty cells', () => {
        const first = period({ ulid: 'p1', label: '1.º Período', ends_on: '2026-10-10' });
        const second = period({ ulid: 'p2', label: '2.º Período', starts_on: '2026-10-11' });

        const wrapper = mountPage({
            periods: [first, second],
            days: octoberDays((date) => ({
                period: date <= '2026-10-10' ? first : second,
            })),
        });

        const named = wrapper
            .findAll('[data-date]')
            .filter((cell) => cell.text().includes('.º Período'))
            .map((cell) => cell.attributes('data-date'));

        // The first cell of the grid, and the day the band changes. Nowhere else.
        expect(named).toEqual(['2026-09-28', '2026-10-11']);
    });

    it('leaves a day in a gap between períodos honestly unbanded', () => {
        const wrapper = mountPage({
            periods: [period({ ends_on: '2026-10-10' })],
            days: octoberDays((date) => ({
                period: date <= '2026-10-10' ? period({ ends_on: '2026-10-10' }) : null,
            })),
        });

        expect(wrapper.find('[data-date="2026-10-20"]').text()).not.toContain('Período');
    });

    it('caps a crowded day and offers the rest behind one control', () => {
        const crowded = ['a', 'b', 'c', 'd', 'e'].map((key) =>
            assessment({ ulid: key, title: `Ficha ${key.toUpperCase()}`, href: `/instruments/${key}` }),
        );

        const wrapper = mountPage({
            days: octoberDays((date) =>
                date === '2026-10-15' ? { assessments: crowded } : {},
            ),
        });

        const cell = wrapper.find('[data-date="2026-10-15"]');

        // Three shown, two behind «+2 mais» — the cell does not grow without bound.
        expect(cell.findAll('li')).toHaveLength(3);
        expect(cell.text()).toContain('+2 mais');
        expect(cell.text()).not.toContain('Ficha E');
    });

    it('shows the whole crowded day once the overflow control is used, and closes again', async () => {
        const crowded = ['a', 'b', 'c', 'd', 'e'].map((key) =>
            assessment({ ulid: key, title: `Ficha ${key.toUpperCase()}`, href: `/instruments/${key}` }),
        );

        const wrapper = mountPage({
            days: octoberDays((date) =>
                date === '2026-10-15' ? { assessments: crowded } : {},
            ),
        });

        await wrapper.find('[data-date="2026-10-15"]').find('button').trigger('click');

        let cell = wrapper.find('[data-date="2026-10-15"]');
        expect(cell.findAll('li')).toHaveLength(5);
        expect(cell.text()).toContain('Ficha E');
        expect(cell.text()).toContain('Ver menos');

        await cell.find('button').trigger('click');

        cell = wrapper.find('[data-date="2026-10-15"]');
        expect(cell.findAll('li')).toHaveLength(3);
        expect(cell.text()).toContain('+2 mais');
    });

    it('offers no overflow control on a day that fits', () => {
        const wrapper = mountPage({
            days: octoberDays((date) =>
                date === '2026-10-15'
                    ? { assessments: [assessment(), assessment({ ulid: 'b', title: 'Ficha B' })] }
                    : {},
            ),
        });

        const cell = wrapper.find('[data-date="2026-10-15"]');

        expect(cell.findAll('li')).toHaveLength(2);
        expect(cell.find('button').exists()).toBe(false);
    });

    it('navigates to the previous and next month by their real values', async () => {
        const wrapper = mountPage();
        const buttons = wrapper.findAll('nav[aria-label="Navegação entre meses"] button');

        await buttons[0]!.trigger('click');
        expect(routerGet).toHaveBeenLastCalledWith(
            '/calendar',
            { month: '2026-09' },
            expect.anything(),
        );

        await buttons[2]!.trigger('click');
        expect(routerGet).toHaveBeenLastCalledWith(
            '/calendar',
            { month: '2026-11' },
            expect.anything(),
        );
    });

    it('offers «Mês atual» when the year is running, and «Início do ano» when it is not', async () => {
        const running = mountPage();
        expect(running.text()).toContain('Mês atual');

        await running
            .findAll('nav[aria-label="Navegação entre meses"] button')[1]!
            .trigger('click');
        expect(routerGet).toHaveBeenLastCalledWith(
            '/calendar',
            { month: '2026-10' },
            expect.anything(),
        );

        const notRunning = mountPage({
            month: { value: '2026-09', starts_on: '2026-09-01', ends_on: '2026-09-30' },
            navigation: {
                previous: '2026-08',
                next: '2026-10',
                home: '2026-09',
                home_is_today: false,
            },
        });

        expect(notRunning.text()).toContain('Início do ano');
        expect(notRunning.text()).not.toContain('Mês atual');
    });

    it('links to the Ano view, which is an address and not a toggle', () => {
        const hrefs = mountPage()
            .findAll('a')
            .map((anchor) => anchor.attributes('href'));

        expect(hrefs).toContain('/calendar/ano');
    });

    it('still draws a real grid for a year with no períodos and no avaliações', () => {
        const wrapper = mountPage();

        expect(wrapper.findAll('[data-date]')).toHaveLength(35);
        expect(wrapper.text()).toContain('ainda não tem períodos definidos');
        // Never a blank page, and never invented content to fill it.
        expect(wrapper.find('section[aria-label="Grelha do mês"]').exists()).toBe(true);
    });

    it('explains itself instead of drawing a grid when there is no academic year at all', () => {
        const wrapper = mountPage({
            academicYear: null,
            month: null,
            navigation: null,
            days: [],
        });

        expect(wrapper.text()).toContain('Ainda não há um ano letivo para mostrar');
        expect(wrapper.findAll('[data-date]')).toHaveLength(0);
    });

    it('reads the month as an agenda for a narrow viewport, listing only the days that carry something', () => {
        const wrapper = mountPage({
            days: octoberDays((date) =>
                date === '2026-10-15' ? { assessments: [assessment()] } : {},
            ),
        });

        const agenda = wrapper.find('section[aria-label="Agenda do mês"]');

        expect(agenda.exists()).toBe(true);
        expect(agenda.findAll('section')).toHaveLength(1);
        expect(agenda.text()).toContain('Quinta-feira, 15 de outubro');
        expect(agenda.text()).toContain('Matemática');
    });

    it('never renders a form or a mutating control: the page is a reading', () => {
        const wrapper = mountPage({
            periods: [period()],
            days: octoberDays((date) => ({
                period: period(),
                ...(date === '2026-10-15' ? { assessments: [assessment()] } : {}),
            })),
        });

        expect(wrapper.findAll('form')).toHaveLength(0);
        expect(wrapper.findAll('input')).toHaveLength(0);
    });

    /**
     * THE PRODUCT DECISION, ASSERTED. Aulas belong to «Horário do Professor»;
     * this calendar answers a different question, and must never grow a lesson
     * count, a workload dot, or anything else derived from them.
     */
    it('shows nothing whatsoever about aulas', () => {
        const wrapper = mountPage({
            periods: [period()],
            days: octoberDays((date) => ({
                period: period(),
                ...(date === '2026-10-15' ? { assessments: [assessment()] } : {}),
            })),
        });

        const text = wrapper.text();

        expect(text).not.toContain('aula');
        expect(text).not.toContain('Aula');
        expect(text).not.toContain('horário');
        expect(text).not.toContain('Horário');
    });
});
