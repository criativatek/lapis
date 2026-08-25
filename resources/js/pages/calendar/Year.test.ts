import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import type { CalendarPeriod } from './calendar';
import Year from './Year.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    Link: defineComponent({ inheritAttrs: false, setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()) }),
}));

type YearPeriod = CalendarPeriod & { assessments_count: number };

function period(overrides: Partial<YearPeriod> = {}): YearPeriod {
    return {
        ulid: 'period-1',
        label: '1.º Período',
        kind: 'term',
        kind_label: 'Período',
        sequence: 1,
        starts_on: '2026-09-01',
        ends_on: '2026-12-18',
        assessments_count: 0,
        ...overrides,
    };
}

function month(value: string, overrides: Record<string, unknown> = {}) {
    return {
        value,
        starts_on: `${value}-01`,
        assessments_count: 0,
        period_ulids: [] as string[],
        is_current: false,
        ...overrides,
    };
}

function mountPage(overrides: Partial<InstanceType<typeof Year>['$props']> = {}) {
    return mount(Year, {
        props: {
            academicYear: {
                ulid: 'year-a',
                label: '2026/2027',
                starts_on: '2026-09-01',
                ends_on: '2027-07-31',
            },
            months: [month('2026-09'), month('2026-10'), month('2026-11')],
            periods: [],
            assessmentsTotal: 0,
            ...overrides,
        },
    });
}

/**
 * «Calendário do Ano Letivo», vista Ano — the whole year at a glance: its
 * períodos as bands, and how many avaliações fall in each month and in each
 * band. A SYNOPSIS AND NOT A LIST, and never a day grid: the itemized listing
 * already exists elsewhere, and a hundred rows here would bury the one thing
 * this view is for. Aulas do not appear, here as anywhere in this calendar.
 */
describe('calendar/Year', () => {
    it('lays out every month of the year, each linking into its own Mês view', () => {
        const wrapper = mountPage();

        const tiles = wrapper.findAll('[data-month]');

        expect(tiles.map((tile) => tile.attributes('data-month'))).toEqual([
            '2026-09',
            '2026-10',
            '2026-11',
        ]);
        expect(tiles[1]!.attributes('href')).toBe('/calendar?month=2026-10');
    });

    it('writes each month with only its first letter raised', () => {
        const text = mountPage().text();

        expect(text).toContain('Setembro de 2026');
        expect(text).not.toContain('Setembro De 2026');
    });

    it('shows the períodos as bands with their kind, range and count', () => {
        const wrapper = mountPage({
            periods: [
                period({ assessments_count: 3 }),
                period({
                    ulid: 'period-2',
                    label: '2.º Período',
                    sequence: 2,
                    starts_on: '2027-01-05',
                    ends_on: '2027-04-02',
                    assessments_count: 1,
                }),
            ],
            assessmentsTotal: 4,
        });

        const bands = wrapper.find('section[aria-label="Períodos do ano letivo"]');

        expect(bands.text()).toContain('1.º Período');
        expect(bands.text()).toContain('2.º Período');
        expect(bands.text()).toContain('Período');
        expect(bands.text()).toContain('3 avaliações');
        expect(bands.text()).toContain('1 avaliação');
        expect(wrapper.text()).toContain('2 períodos');
        expect(wrapper.text()).toContain('4 avaliações');
    });

    it('counts a month\'s avaliações instead of listing them', () => {
        const wrapper = mountPage({
            months: [
                month('2026-09'),
                month('2026-10', { assessments_count: 4, period_ulids: ['period-1'] }),
            ],
            periods: [period({ assessments_count: 4 })],
            assessmentsTotal: 4,
        });

        const october = wrapper.find('[data-month="2026-10"]');

        expect(october.text()).toContain('4 avaliações');
        expect(october.text()).toContain('1.º Período');
        expect(wrapper.find('[data-month="2026-09"]').text()).toContain(
            'Sem avaliações',
        );
    });

    it('marks a month sitting in two períodos with both', () => {
        const first = period();
        const second = period({
            ulid: 'period-2',
            label: '2.º Período',
            sequence: 2,
            starts_on: '2026-10-20',
            ends_on: '2026-12-18',
        });

        const wrapper = mountPage({
            months: [month('2026-10', { period_ulids: ['period-1', 'period-2'] })],
            periods: [first, second],
        });

        const october = wrapper.find('[data-month="2026-10"]');

        expect(october.text()).toContain('1.º Período');
        expect(october.text()).toContain('2.º Período');
    });

    it('distinguishes a count of avaliações from a período band by more than colour', () => {
        const wrapper = mountPage({
            months: [month('2026-10', { assessments_count: 2, period_ulids: ['period-1'] })],
            periods: [period({ assessments_count: 2 })],
            assessmentsTotal: 2,
        });

        // The count carries a border, an icon and emphasis; the band around it
        // carries none of the three.
        const badge = wrapper
            .find('[data-month="2026-10"]')
            .findAll('span')
            .find((span) => span.classes().join(' ').includes('border'));

        expect(badge).toBeTruthy();
        expect(badge!.classes().join(' ')).toContain('font-medium');
        expect(badge!.find('svg').exists()).toBe(true);
    });

    it('never draws a day grid and never itemizes the avaliações', () => {
        const wrapper = mountPage({
            months: [month('2026-10', { assessments_count: 12, period_ulids: ['period-1'] })],
            periods: [period({ assessments_count: 12 })],
            assessmentsTotal: 12,
        });

        // A synopsis: no per-day cells anywhere, and the indicator marks are
        // capped rather than one per avaliação.
        expect(wrapper.findAll('[data-date]')).toHaveLength(0);
        expect(wrapper.findAll('[data-month="2026-10"] span[aria-hidden="true"] span')).toHaveLength(6);
        expect(wrapper.text()).toContain('12 avaliações');
    });

    it('links back to the Mês view, which is an address and not a toggle', () => {
        const hrefs = mountPage()
            .findAll('a')
            .map((anchor) => anchor.attributes('href'));

        expect(hrefs).toContain('/calendar');
    });

    it('still lays out the months for a year with no períodos', () => {
        const wrapper = mountPage();

        expect(wrapper.findAll('[data-month]')).toHaveLength(3);
        expect(wrapper.text()).toContain('ainda não tem períodos definidos');
    });

    it('explains itself instead of drawing months when there is no academic year at all', () => {
        const wrapper = mountPage({ academicYear: null, months: [], periods: [] });

        expect(wrapper.text()).toContain('Ainda não há um ano letivo para mostrar');
        expect(wrapper.findAll('[data-month]')).toHaveLength(0);
    });

    it('never renders a form or a mutating control: the page is a reading', () => {
        const wrapper = mountPage({
            months: [month('2026-10', { assessments_count: 2, period_ulids: ['period-1'] })],
            periods: [period({ assessments_count: 2 })],
        });

        expect(wrapper.findAll('form')).toHaveLength(0);
        expect(wrapper.findAll('input')).toHaveLength(0);
    });

    it('shows nothing whatsoever about aulas', () => {
        const text = mountPage({
            months: [month('2026-10', { assessments_count: 2, period_ulids: ['period-1'] })],
            periods: [period({ assessments_count: 2 })],
            assessmentsTotal: 2,
        }).text();

        expect(text).not.toContain('aula');
        expect(text).not.toContain('Aula');
        expect(text).not.toContain('horário');
        expect(text).not.toContain('Horário');
    });
});
