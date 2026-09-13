import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import LessonAttendanceList from './LessonAttendanceList.vue';
import type { LessonAttendance } from './LessonAttendanceList.vue';

const mocks = vi.hoisted(() => ({ patch: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    router: { patch: mocks.patch },
}));

const alice = {
    student_ulid: 'student-alice',
    enrollment_ulid: 'enrollment-alice',
    class_number: 1,
    name: 'Alice Andrade',
    photo_url: '/photos/alice.jpg',
    status: null as 'present' | 'absent' | null,
};
const bruno = {
    student_ulid: 'student-bruno',
    enrollment_ulid: 'enrollment-bruno',
    class_number: 2,
    name: 'Bruno Baptista',
    photo_url: null,
    status: null as 'present' | 'absent' | null,
};

function baseAttendance(overrides: Partial<LessonAttendance> = {}): LessonAttendance {
    return {
        recorded: false,
        recorded_at: null,
        can_edit: true,
        excluded_without_left_on: 0,
        students: [alice, bruno],
        counts: { present: 0, absent: 0 },
        roster_error: null,
        ...overrides,
    };
}

function mountList(overrides: Partial<LessonAttendance> = {}, absent: string[] = [], classGroupLabel: string | null = null) {
    return mount(LessonAttendanceList, {
        props: {
            lessonUlid: 'lesson-a',
            attendance: baseAttendance(overrides),
            classGroupLabel,
            lessonTaught: false,
            absent,
        },
    });
}

beforeEach(() => {
    mocks.patch.mockClear();
});

describe('LessonAttendanceList — listagem', () => {
    it('renders each student with photo, class number and name', () => {
        const wrapper = mountList();

        expect(wrapper.text()).toContain('1.');
        expect(wrapper.text()).toContain('Alice Andrade');
        expect(wrapper.text()).toContain('2.');
        expect(wrapper.text()).toContain('Bruno Baptista');
        expect(wrapper.find('img[src="/photos/alice.jpg"]').exists()).toBe(true);
    });

    it('never renders a table and lays out rows as a flex column (mobile-first)', () => {
        const wrapper = mountList();

        expect(wrapper.find('table').exists()).toBe(false);
        const list = wrapper.find('[data-testid="attendance-rows"]');
        expect(list.classes()).toEqual(expect.arrayContaining(['flex', 'flex-col']));
    });

    it('shows the whole-class note only when a class_group_label is given', () => {
        const whole = mountList();
        expect(whole.text()).not.toContain('Só aparecem os alunos de');

        const grouped = mountList({}, [], 'T1');
        expect(grouped.text()).toContain('Só aparecem os alunos de T1 nesta data.');
    });

    it('shows the empty state when there are no eligible students', () => {
        const wrapper = mountList({ students: [] });

        expect(wrapper.text()).toContain('Não há alunos elegíveis para esta aula.');
    });

    it('shows the roster error and hides the list', () => {
        const wrapper = mountList({ roster_error: 'Não foi possível determinar os alunos desta aula.' });

        expect(wrapper.text()).toContain('Não foi possível determinar os alunos desta aula.');
        expect(wrapper.find('[data-testid="attendance-rows"]').exists()).toBe(false);
    });

    it('shows the excluded-without-left-on note when greater than zero', () => {
        const wrapper = mountList({ excluded_without_left_on: 3 });

        expect(wrapper.text()).toContain('3 inscrição(ões) terminada(s) sem data de saída não aparecem nesta lista.');
    });
});

describe('LessonAttendanceList — antes da consolidação', () => {
    it('shows the "not taught yet" help text', () => {
        const wrapper = mountList();

        expect(wrapper.text()).toContain(
            'Assinala só quem faltou. Ao marcar a aula como lecionada, os restantes ficam registados como presentes.',
        );
    });

    it('toggles a student into the local draft and emits update:absent', async () => {
        const wrapper = mountList();

        const faltaButtons = wrapper.findAll('button').filter((b) => b.text() === 'Falta');
        await faltaButtons[0].trigger('click');

        expect(wrapper.emitted('update:absent')).toEqual([[['student-alice']]]);
    });

    it('untoggles a drafted student', async () => {
        const wrapper = mountList({}, ['student-alice']);

        const faltaButtons = wrapper.findAll('button').filter((b) => b.text() === 'Falta');
        await faltaButtons[0].trigger('click');

        expect(wrapper.emitted('update:absent')).toEqual([[[]]]);
    });

    it('shows the counter of drafted faltas', () => {
        const wrapper = mountList({}, ['student-alice']);

        expect(wrapper.text()).toContain('1 falta assinalada');
    });

    it('marks a drafted-absent row visually, not by colour alone', () => {
        const wrapper = mountList({}, ['student-alice']);

        const rows = wrapper.findAll('[data-testid="attendance-rows"] > li');
        expect(rows[0].classes()).toContain('border-destructive/40');
        expect(rows[0].text()).toContain('Falta');
    });

    it('never calls router.patch before consolidation', async () => {
        const wrapper = mountList();

        const faltaButtons = wrapper.findAll('button').filter((b) => b.text() === 'Falta');
        await faltaButtons[0].trigger('click');

        expect(mocks.patch).not.toHaveBeenCalled();
    });

    it('disables toggles when can_edit is false', () => {
        const wrapper = mountList({ can_edit: false });

        const faltaButtons = wrapper.findAll('button').filter((b) => b.text() === 'Falta');
        expect(faltaButtons.every((button) => button.attributes('disabled') !== undefined)).toBe(true);
    });

    it('offers "Registar assiduidade" once taught, and emits record', async () => {
        const wrapper = mount(LessonAttendanceList, {
            props: {
                lessonUlid: 'lesson-a',
                attendance: baseAttendance(),
                classGroupLabel: null,
                lessonTaught: true,
                absent: [],
            },
        });

        const recordButton = wrapper.findAll('button').find((b) => b.text().includes('Registar assiduidade'));
        expect(recordButton).toBeTruthy();
        expect(wrapper.text()).toContain('Assiduidade não registada.');

        await recordButton!.trigger('click');
        expect(wrapper.emitted('record')).toHaveLength(1);
    });

    it('does not offer "Registar assiduidade" before the lesson is taught', () => {
        const wrapper = mountList();

        expect(wrapper.findAll('button').find((b) => b.text().includes('Registar assiduidade'))).toBeUndefined();
    });
});

describe('LessonAttendanceList — depois da consolidação', () => {
    function consolidated(overrides: Partial<LessonAttendance> = {}) {
        return mountList(
            {
                recorded: true,
                recorded_at: '2026-09-09T09:50:00+01:00',
                students: [
                    { ...alice, status: 'present' },
                    { ...bruno, status: 'absent' },
                ],
                counts: { present: 1, absent: 1 },
                ...overrides,
            },
            [],
        );
    }

    it('shows the recorded counts help text', () => {
        const wrapper = consolidated();

        expect(wrapper.text()).toContain('Registada: 1 presença · 1 falta. Podes corrigir.');
    });

    it('shows Presente/Falta per row from the consolidated snapshot', () => {
        const wrapper = consolidated();
        const rows = wrapper.findAll('[data-testid="attendance-rows"] > li');

        expect(rows[0].text()).toContain('Presente');
        expect(rows[1].text()).toContain('Falta');
    });

    it('calls router.patch with the flipped status, preserveScroll, and disables the row while processing', async () => {
        let resolvePatch: (() => void) | null = null;
        mocks.patch.mockImplementation((_url, _data, options) => {
            resolvePatch = () => options.onSuccess?.({});
        });

        const wrapper = consolidated();
        const rows = wrapper.findAll('[data-testid="attendance-rows"] > li');
        const presenteButton = rows[0].findAll('button')[0];

        await presenteButton.trigger('click');

        expect(mocks.patch).toHaveBeenCalledWith(
            '/lessons/lesson-a/attendance/student-alice',
            { status: 'absent' },
            expect.objectContaining({ preserveScroll: true }),
        );
        expect(presenteButton.attributes('disabled')).toBeDefined();

        resolvePatch!();
        await wrapper.vm.$nextTick();
    });

    it('shows the returned error message on a failed correction', async () => {
        mocks.patch.mockImplementation((_url, _data, options) => {
            options.onError?.({ status: 'Não foi possível corrigir esta assiduidade.' });
            options.onFinish?.();
        });

        const wrapper = consolidated();
        const rows = wrapper.findAll('[data-testid="attendance-rows"] > li');
        await rows[0].findAll('button')[0].trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('Não foi possível corrigir esta assiduidade.');
    });

    it('disables corrections when can_edit is false', () => {
        const wrapper = consolidated({ can_edit: false });
        const rows = wrapper.findAll('[data-testid="attendance-rows"] > li');

        expect(rows[0].findAll('button')[0].attributes('disabled')).toBeDefined();
    });
});
