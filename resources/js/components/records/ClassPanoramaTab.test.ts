import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { ConsultationFilters, Panorama, PanoramaRow } from '@/lib/recordFollowup';
import ClassPanoramaTab from './ClassPanoramaTab.vue';

const { routerGet } = vi.hoisted(() => ({ routerGet: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    router: { get: routerGet },
}));

function filters(overrides: Partial<ConsultationFilters> = {}): ConsultationFilters {
    return {
        year: 'Y1', subject: null, class: 'C1', student: null, kind: null,
        mode: 'period', period: 'P1', from: null, to: null, searched: false, view: null, tab: 'panorama',
        ...overrides,
    };
}

function row(overrides: Partial<PanoramaRow> & { ulid: string; name: string }): PanoramaRow {
    return {
        number: null,
        enrolled_on: '2026-09-01',
        left_on: null,
        partial: false,
        counts: { homework_not_done: 0, missing_material: 0, lateness: 0, absences: 0 },
        missing: {},
        homework: { done: 2, partially_done: 0, not_done: 0, verified: 2 },
        absence_days: 0,
        attendance_recorded: 4,
        statuses: { homework_not_done: null, missing_material: null, lateness: null },
        has_occurrences: false,
        ...overrides,
    };
}

function panorama(): Panorama {
    return {
        rows: [
            row({ ulid: 'E1', number: 1, name: 'Ana Sofia Costa', counts: { homework_not_done: 3, missing_material: 0, lateness: 1, absences: 2 }, absence_days: 1, statuses: { homework_not_done: 'pending', missing_material: null, lateness: null }, has_occurrences: true }),
            row({ ulid: 'E2', number: 2, name: 'Bruno Ângelo Lopes', counts: { homework_not_done: null, missing_material: 4, lateness: 0, absences: null }, missing: { homework_not_done: 'no_data', absences: 'no_data' }, homework: { done: 0, partially_done: 0, not_done: 0, verified: 0 }, attendance_recorded: 0, statuses: { homework_not_done: null, missing_material: 'registered', lateness: null }, has_occurrences: true }),
            row({ ulid: 'E3', number: 3, name: 'Carla Dias', partial: true, enrolled_on: '2026-10-05' }),
        ],
        totals: { homework_not_done: 3, missing_material: 4, lateness: 1, absences: 2 },
        meta: { is_period: true, attendance_applicable: true, homework_days: 5, lessons_counted: 12, lessons_not_recorded: 2 },
    };
}

const wrappers: VueWrapper[] = [];

function mountTab(overrides: Record<string, unknown> = {}) {
    const wrapper = mount(ClassPanoramaTab, {
        props: {
            panorama: panorama(),
            filters: filters(),
            years: [{ ulid: 'Y1', label: '2026/2027' }],
            options: {
                classes: [
                    { ulid: 'C1', label: '7.º A', subject: 'Matemática', subject_ulid: 'S1', archived: false },
                    { ulid: 'C2', label: '8.º B', subject: 'Português', subject_ulid: 'S2', archived: false },
                ],
                periods: [{ ulid: 'P1', label: '1.º Período', starts_on: '2026-09-14', ends_on: '2026-12-16', is_current: true }],
            },
            context: { window_label: '1.º Período (14/09/2026 a 16/12/2026)', period_is_current: true, needs_window: false, window_problem: null, periods_notice: null, overlaps: [] },
            ...overrides,
        },
    });
    wrappers.push(wrapper);

    return wrapper;
}

function names(wrapper: VueWrapper): string[] {
    return wrapper.findAll('tbody tr th').map((cell) => cell.find('span').text());
}

afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    routerGet.mockReset();
});

describe('ClassPanoramaTab — o panorama da turma', () => {
    it('shows every student, those without occurrences included, in one table with the four indicators', () => {
        const wrapper = mountTab();

        expect(names(wrapper)).toEqual(['Ana Sofia Costa', 'Bruno Ângelo Lopes', 'Carla Dias']);
        expect(wrapper.findAll('thead th').map((cell) => cell.text())).toEqual(expect.arrayContaining(['TPC não realizados', 'Faltas de material', 'Atrasos', 'Faltas de presença']));
        expect(wrapper.find('[data-test="total-missing_material"]').text()).toBe('4');
        expect(wrapper.find('[data-test="panorama-row-E3"]').text()).toContain('entrou a 05/10/2026');
    });

    it('says zero, no data and not applicable apart', () => {
        const wrapper = mountTab();
        const bruno = wrapper.find('[data-test="panorama-row-E2"]');
        const carla = wrapper.find('[data-test="panorama-row-E3"]');

        expect(bruno.find('[data-test="cell-homework_not_done"]').text()).toBe('—');
        expect(bruno.find('[data-test="cell-absences"]').text()).toBe('—');
        expect(carla.find('[data-test="cell-lateness"]').text()).toBe('0');
        expect(wrapper.find('[data-test="panorama-notes"]').text()).toContain('não prova cumprimento');

        const noAttendance = mountTab({
            panorama: { ...panorama(), rows: [row({ ulid: 'E9', name: 'Zé', counts: { homework_not_done: 0, missing_material: 0, lateness: 0, absences: null }, missing: { absences: 'not_applicable' } })], meta: { ...panorama().meta, attendance_applicable: false } },
        });
        expect(noAttendance.find('[data-test="cell-absences"]').text()).toBe('n.a.');
        expect(noAttendance.find('[data-test="total-absences"]').text()).toBe('n.a.');
    });

    it('sorts by an indicator from the most, always leaving «—» at the end, and back by number', async () => {
        const wrapper = mountTab();

        await wrapper.find('[data-test="sort-homework_not_done"]').trigger('click');
        expect(names(wrapper)).toEqual(['Ana Sofia Costa', 'Carla Dias', 'Bruno Ângelo Lopes']);

        await wrapper.find('[data-test="sort-missing_material"]').trigger('click');
        expect(names(wrapper)[0]).toBe('Bruno Ângelo Lopes');

        await wrapper.find('[data-test="sort-number"]').trigger('click');
        expect(names(wrapper)).toEqual(['Ana Sofia Costa', 'Bruno Ângelo Lopes', 'Carla Dias']);
    });

    it('filters to the students with occurrences and finds a student by name, accents or not, or number', async () => {
        const wrapper = mountTab();

        await wrapper.find('[data-test="panorama-only-occurrences"]').setValue(true);
        expect(names(wrapper)).toEqual(['Ana Sofia Costa', 'Bruno Ângelo Lopes']);

        await wrapper.find('[data-test="panorama-only-occurrences"]').setValue(false);
        await wrapper.find('[data-test="panorama-search"]').setValue('angelo');
        expect(names(wrapper)).toEqual(['Bruno Ângelo Lopes']);

        await wrapper.find('[data-test="panorama-search"]').setValue('3');
        expect(names(wrapper)).toEqual(['Carla Dias']);
    });

    it('opens the detail of a number above zero, with the case marks in the cell', async () => {
        const wrapper = mountTab();
        const ana = wrapper.find('[data-test="panorama-row-E1"]');

        expect(ana.find('[data-test="cell-homework_not_done"] [data-test="status-pending"]').exists()).toBe(true);
        expect(wrapper.find('[data-test="panorama-row-E2"] [data-test="cell-missing_material"] [data-test="status-registered"]').exists()).toBe(true);
        expect(ana.find('[data-test="cell-absences"]').text()).toContain('1 dia');

        await ana.find('[data-test="cell-absences"] button').trigger('click');
        expect(wrapper.emitted('open-case')?.[0]).toEqual(['C1', 'E1', 'absences']);

        // Zero is not a door.
        expect(wrapper.find('[data-test="panorama-row-E3"] [data-test="cell-lateness"] button').exists()).toBe(false);
    });

    it('asks for the class when there is none, and loads the panorama as soon as one is chosen', async () => {
        const wrapper = mountTab({ panorama: null, filters: filters({ class: null }) });

        expect(wrapper.find('[data-test="class-picker"]').exists()).toBe(true);
        await wrapper.find('[data-test="pick-class-C2"]').trigger('click');

        expect(routerGet).toHaveBeenCalledWith('/records', { tab: 'panorama', year: 'Y1', class: 'C2', period: 'P1' }, expect.objectContaining({ only: ['panorama', 'context', 'filters', 'openCase'] }));
    });

    it('says plainly when no period applies, and loads another period or a date range on demand', async () => {
        const needs = mountTab({ panorama: null, filters: filters({ period: null }), context: { window_label: null, period_is_current: false, needs_window: true, window_problem: null, periods_notice: null, overlaps: [] } });
        expect(needs.find('[data-test="panorama-needs-window"]').text()).toContain('nunca é assumido');

        const wrapper = mountTab();
        await wrapper.find('[data-test="panorama-mode-range"]').trigger('click');
        await wrapper.find('[data-test="panorama-from"]').setValue('2026-10-01');
        await wrapper.find('[data-test="panorama-to"]').setValue('2026-10-31');
        await wrapper.find('[data-test="panorama-apply"]').trigger('click');

        expect(routerGet).toHaveBeenCalledWith('/records', { tab: 'panorama', year: 'Y1', class: 'C1', mode: 'range', from: '2026-10-01', to: '2026-10-31' }, expect.any(Object));
    });

    it('explains the coverage behind the numbers', () => {
        const notes = mountTab().find('[data-test="panorama-notes"]').text();

        expect(notes).toContain('TPC marcado em 5 dias');
        expect(notes).toContain('assiduidade lançada em 12 aulas');
        expect(notes).toContain('2 aulas dadas ainda sem assiduidade lançada');
        expect(notes).toContain('não distingue faltas justificadas de injustificadas');
    });
});
