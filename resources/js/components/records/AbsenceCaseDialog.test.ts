import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import type { AbsenceDetail } from '@/lib/recordFollowup';
import AbsenceCaseDialog from './AbsenceCaseDialog.vue';

vi.mock('@inertiajs/vue3', () => ({
    Link: defineComponent({
        inheritAttrs: false,
        setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()),
    }),
}));

vi.mock('@/components/ui/dialog', () => {
    const passThrough = defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) });

    return { Dialog: passThrough, DialogScrollContent: passThrough, DialogHeader: passThrough, DialogTitle: passThrough, DialogDescription: passThrough };
});

const wrappers: VueWrapper[] = [];

function detail(overrides: Partial<AbsenceDetail> = {}): AbsenceDetail {
    return {
        kind: 'absences',
        class: { ulid: 'C1', label: '7.º A', subject: 'Matemática' },
        student: { ulid: 'E1', number: 1, name: 'Ana Costa' },
        category_label: 'Faltas de presença',
        window: { is_period: true, label: '1.º Período (14/09/2026 a 16/12/2026)', period_ulid: 'P1' },
        absences: [
            { ulid: 'A1', lesson_ulid: 'L1', date: '2026-10-05', time: '09:30', lesson_number: 12, context_label: '7.º A' },
            { ulid: 'A2', lesson_ulid: 'L2', date: '2026-10-05', time: '14:00', lesson_number: 13, context_label: '7.º A · T1' },
            { ulid: 'A3', lesson_ulid: 'L3', date: '2026-10-09', time: '09:30', lesson_number: null, context_label: '7.º A' },
        ],
        count: 3,
        days: 2,
        not_recorded: 1,
        ...overrides,
    };
}

function mountDialog(overrides: Partial<AbsenceDetail> = {}) {
    const wrapper = mount(AbsenceCaseDialog, { props: { detail: detail(overrides), currentUrl: '/records?view=absences&case_student=E1' } });
    wrappers.push(wrapper);

    return wrapper;
}

afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    window.sessionStorage.clear();
});

describe('AbsenceCaseDialog', () => {
    it('says absences and days apart, and lists each lesson with its time', () => {
        const wrapper = mountDialog();

        expect(wrapper.find('[data-test="absence-figures"]').text()).toBe('3 faltas em 2 dias');
        expect(wrapper.findAll('ol li')).toHaveLength(3);
        expect(wrapper.text()).toContain('09:30');
        expect(wrapper.text()).toContain('14:00');
        expect(wrapper.text()).toContain('lição n.º 12');
    });

    it('opens the lesson and remembers the way back to this consultation', async () => {
        const wrapper = mountDialog();
        const link = wrapper.find('ol li a');

        expect(link.attributes('href')).toBe('/lessons/L1');
        await link.trigger('click');

        expect(JSON.parse(window.sessionStorage.getItem('lapis.lessons.return') ?? 'null')).toEqual({ url: '/records?view=absences&case_student=E1', lesson: 'L1' });
    });

    it('explains the unit, what never counts, and that there is no justified/unjustified distinction', () => {
        const text = mountDialog().text();

        expect(text).toContain('Duas aulas no mesmo dia são duas faltas num só dia.');
        expect(text).toContain('Há 1 aula lecionada neste período com a assiduidade por registar — não contam como faltas.');
        expect(text).toContain('não distingue faltas justificadas de injustificadas');
    });

    it('offers no Inovar message and nothing to mark', () => {
        const text = mountDialog().text();

        expect(text).not.toContain('Inovar');
        expect(text).not.toContain('Marcar');
    });
});
