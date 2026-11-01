import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ReportSectionData from './ReportSectionData.vue';

const totals = {
    planned: 4,
    counted_as_taught: 2,
    subject_development: 2,
    teacher_absent: 0,
    class_external_activity: 0,
    not_held: 2,
    not_recorded: 0,
};

function mountLessons(data: Record<string, unknown>) {
    return mount(ReportSectionData, { props: { sectionKey: 'class_lessons', data } });
}

describe('ReportSectionData — class_lessons', () => {
    it('lists only the aggregated cause under the not-held count', () => {
        const wrapper = mountLessons({
            totals,
            rows: [],
            causes: [
                { cause: 'strike', label: 'Greve', count: 1 },
                { cause: 'weather_closure', label: 'Encerramento por mau tempo', count: 1 },
            ],
        });

        const text = wrapper.get('[data-testid="class-lessons"]').text();

        expect(text).toContain('Aula não realizada');
        expect(text).toContain('— Greve');
        expect(text).toContain('— Encerramento por mau tempo');
        expect(text).not.toContain('Aderi');
        expect(text).not.toContain('Não aderi');
    });

    it('renders without causes (older reports and photographs)', () => {
        const wrapper = mountLessons({ totals: { ...totals, not_held: 0 }, rows: [] });

        expect(wrapper.get('[data-testid="class-lessons"]').text()).not.toContain('—');
    });
});
