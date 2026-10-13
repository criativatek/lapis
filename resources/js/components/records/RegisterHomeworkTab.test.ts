import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import type { ConsultationFilters, RegisterContext } from '@/lib/recordFollowup';
import RegisterHomeworkTab from './RegisterHomeworkTab.vue';

const { routerGet, gridRequest } = vi.hoisted(() => ({ routerGet: vi.fn(), gridRequest: vi.fn((action: () => void) => action()) }));

vi.mock('@inertiajs/vue3', () => ({
    router: { get: routerGet },
}));

// The grid has its own tests; here only what the tab hands it, and its guard.
vi.mock('@/pages/records/HomeworkGrid.vue', () => ({
    default: defineComponent({
        name: 'HomeworkGrid',
        props: { classUlid: String, occurredAt: String, readOnly: Boolean },
        setup(props, { expose }) {
            expose({ request: gridRequest, isDirty: () => false });

            return () => h('div', { 'data-test': 'grid-stub', 'data-class': props.classUlid, 'data-date': props.occurredAt, 'data-read-only': String(props.readOnly) });
        },
    }),
}));

function filters(overrides: Partial<ConsultationFilters> = {}): ConsultationFilters {
    return {
        year: 'Y1', subject: null, class: 'C1', student: 'E7', kind: 'lateness',
        mode: 'period', period: 'P1', from: null, to: null, searched: true, view: null, tab: 'tpc',
        ...overrides,
    };
}

function register(overrides: Partial<RegisterContext> = {}): RegisterContext {
    return {
        class: { ulid: 'C1', label: '7.º A', subject: 'Matemática', archived: false },
        date: '2026-10-13',
        today: '2026-10-13',
        can_write: true,
        ...overrides,
    };
}

const CLASSES = [
    { ulid: 'C1', label: '7.º A', subject: 'Matemática', archived: false },
    { ulid: 'C2', label: '8.º B', subject: 'Português', archived: false },
    { ulid: 'C3', label: '9.º C', subject: 'Matemática', archived: true },
];

const wrappers: VueWrapper[] = [];

function mountTab(overrides: Record<string, unknown> = {}) {
    const wrapper = mount(RegisterHomeworkTab, {
        props: { register: register(), classes: CLASSES, filters: filters(), yearLabel: '2026/2027', ...overrides },
    });
    wrappers.push(wrapper);

    return wrapper;
}

afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    routerGet.mockReset();
    gridRequest.mockClear();
});

describe('RegisterHomeworkTab — registar TPC', () => {
    it('asks for the class when none is known — never picks one, never an archived one', async () => {
        const wrapper = mountTab({ register: register({ class: null }), filters: filters({ class: null }) });

        expect(wrapper.find('[data-test="grid-stub"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="pick-class-C3"]').exists()).toBe(false);

        await wrapper.find('[data-test="pick-class-C2"]').trigger('click');

        // The search's student and kind never follow into the grid.
        expect(routerGet).toHaveBeenCalledWith('/records', { tab: 'tpc', year: 'Y1', class: 'C2', date: '2026-10-13' }, { preserveScroll: true });
    });

    it('shows the grid of the class and the day straight away, with the day in words', () => {
        const wrapper = mountTab();

        expect(wrapper.find('[data-test="register-heading"]').text()).toContain('7.º A · Matemática — terça-feira, 13/10/2026');
        expect(wrapper.find('[data-test="register-heading"]').text()).toContain('(hoje)');
        expect(wrapper.find('[data-test="grid-stub"]').attributes('data-class')).toBe('C1');
        expect(wrapper.find('[data-test="grid-stub"]').attributes('data-date')).toBe('2026-10-13');
        expect(wrapper.find('[data-test="register-today"]').exists()).toBe(false);
    });

    it('changes the day and the class through the grid’s guard, keeping the chosen day', async () => {
        const wrapper = mountTab();

        await wrapper.find('[data-test="register-date"]').setValue('2026-10-08');

        expect(gridRequest).toHaveBeenCalledTimes(1);
        expect(routerGet).toHaveBeenLastCalledWith('/records', { tab: 'tpc', year: 'Y1', class: 'C1', date: '2026-10-08' }, { preserveScroll: true });

        await wrapper.find('[data-test="register-class"]').setValue('C2');

        expect(gridRequest).toHaveBeenCalledTimes(2);
        expect(routerGet).toHaveBeenLastCalledWith('/records', { tab: 'tpc', year: 'Y1', class: 'C2', date: '2026-10-13' }, { preserveScroll: true });
    });

    it('offers «Hoje» only when another day is shown', async () => {
        const wrapper = mountTab({ register: register({ date: '2026-10-08' }) });

        await wrapper.find('[data-test="register-today"]').trigger('click');
        expect(routerGet).toHaveBeenCalledWith('/records', { tab: 'tpc', year: 'Y1', class: 'C1', date: '2026-10-13' }, { preserveScroll: true });
    });

    it('hands the grid a read-only class as read-only', () => {
        const wrapper = mountTab({ register: register({ can_write: false }) });

        expect(wrapper.find('[data-test="grid-stub"]').attributes('data-read-only')).toBe('true');
    });
});
