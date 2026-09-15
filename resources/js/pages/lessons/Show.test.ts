import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';
import Show from './Show.vue';

type MockForm = Record<string, unknown> & { isDirty: boolean };

const mocks = vi.hoisted(() => ({
    forms: [] as MockForm[],
    beforeHandlers: [] as ((event: Event) => void)[],
    unsubscribe: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    Link: defineComponent({
        inheritAttrs: false,
        setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()),
    }),
    router: {
        on: (event: string, callback: (event: Event) => void) => {
            if (event === 'before') {
                mocks.beforeHandlers.push(callback);
            }

            return mocks.unsubscribe;
        },
        patch: vi.fn(),
    },
    useForm: (data: Record<string, unknown>) => {
        const form = reactive({
            ...data,
            errors: {},
            processing: false,
            recentlySuccessful: false,
            isDirty: false,
            put: vi.fn(),
            post: vi.fn(),
        }) as MockForm;

        mocks.forms.push(form);

        return form;
    },
}));

const wrappers: VueWrapper[] = [];

const defaultAttendance = {
    recorded: false,
    recorded_at: null,
    can_edit: true,
    excluded_without_left_on: 0,
    students: [] as Array<{
        student_ulid: string;
        enrollment_ulid: string | null;
        class_number: number | null;
        name: string;
        photo_url: string | null;
        status: 'present' | 'absent' | null;
    }>,
    counts: { present: 0, absent: 0 },
    roster_error: null as string | null,
};

function mountPage(
    overrides: {
        starts_at?: string;
        ends_at?: string | null;
        status?: 'preparation' | 'prepared' | 'taught';
        context_label?: string;
        class_group_label?: string | null;
    } = {},
    attendanceOverrides: Partial<typeof defaultAttendance> = {},
) {
    const wrapper = mount(Show, {
        props: {
            lesson: {
                ulid: 'lesson-a',
                starts_at: '2026-09-09T09:00:00+01:00',
                ends_at: '2026-09-09T09:50:00+01:00',
                status: 'preparation' as const,
                status_label: 'Por preparar',
                lesson_number: 3,
                outcome: null,
                outcome_label: null,
                outcome_reason_label: null,
                outcome_note: null,
                can_record_outcome: true,
                absence_reasons: [{ value: 'training', label: 'Formação' }],
                pending_plan: null,
                can_delete: true,
                can_clear_summary: false,
                school_class: { ulid: 'class-a', label: '7.º A', subject: 'Matemática' },
                // Aula da turma inteira: o rótulo é o da turma, sem sufixo.
                context_label: '7.º A',
                class_group_label: null,
                summary: null,
                ...overrides,
            },
            attendance: { ...defaultAttendance, ...attendanceOverrides },
        },
    });

    wrappers.push(wrapper);

    return wrapper;
}

/** The sumário form is the first one the page creates; "lecionada" the second. */
function summaryForm(): MockForm {
    return mocks.forms[0];
}

function fireBeforeUnload(): Event {
    const event = new Event('beforeunload', { cancelable: true });
    window.dispatchEvent(event);

    return event;
}

function fireInAppNavigation(): Event {
    const event = new Event('before', { cancelable: true });
    mocks.beforeHandlers.forEach((handler) => handler(event));

    return event;
}

beforeEach(() => {
    mocks.forms.length = 0;
    mocks.beforeHandlers.length = 0;
    mocks.unsubscribe.mockClear();
    vi.stubGlobal(
        'fetch',
        vi.fn(() => Promise.resolve({ status: 204 } as Response)),
    );
});

afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    vi.restoreAllMocks();
});

describe('lessons/Show — back link', () => {
    function backLinkHref(overrides: { starts_at?: string; ends_at?: string | null } = {}) {
        const link = mountPage(overrides)
            .findAll('a')
            .find((a) => a.text().includes('Voltar às aulas da semana'));

        expect(link).toBeTruthy();

        return link!.attributes('href');
    }

    it('returns to the weekly view instead of the class page', () => {
        expect(backLinkHref()).toBe('/lessons?week=2026-09-07');
    });

    /**
     * The target week is derived from the lesson's own starts_at, so it is
     * correct however the teacher reached this page — nothing is threaded in
     * from the weekly view as a query parameter.
     */
    it.each([
        ['2026-09-07T08:30:00+01:00', '2026-09-07'], // Monday — its own week start
        ['2026-09-13T18:00:00+01:00', '2026-09-07'], // Sunday — still the same ISO week
        ['2026-09-14T09:00:00+01:00', '2026-09-14'], // the following Monday
        ['2027-01-01T09:00:00+00:00', '2026-12-28'], // a Friday across the year boundary
    ])('derives the Monday of the lesson week for %s', (startsAt, expectedWeek) => {
        expect(backLinkHref({ starts_at: startsAt, ends_at: null })).toBe(
            `/lessons?week=${expectedWeek}`,
        );
    });

    /**
     * A lesson late on a Lisbon evening is already the next day in UTC; the
     * week has to follow the Lisbon calendar date, not the UTC one.
     */
    it('uses the Lisbon calendar date, not the UTC date', () => {
        expect(backLinkHref({ starts_at: '2026-09-13T23:30:00+01:00', ends_at: null })).toBe(
            '/lessons?week=2026-09-07',
        );
    });
});

describe('lessons/Show — pt-PT date', () => {
    /**
     * Only the opening character is uppercased. The CSS `capitalize` class
     * used to uppercase every word, giving "Quarta-Feira, 9 De Setembro De
     * 2026" — the Intl output itself was always right.
     */
    it('renders the date in sentence case, not title case', () => {
        const wrapper = mountPage();

        expect(wrapper.text()).toContain('Quarta-feira, 9 de setembro de 2026');
        expect(wrapper.text()).not.toContain('De setembro');
    });

    it('no longer leaves the capitalize class to do it in CSS', () => {
        const date = mountPage().find('dd');

        expect(date.classes()).not.toContain('capitalize');
    });
});

describe('lessons/Show — unsaved changes warning', () => {
    it('does not warn on tab close while nothing has been typed', () => {
        mountPage();

        expect(fireBeforeUnload().defaultPrevented).toBe(false);
    });

    it('warns on tab close once the sumário has unsaved changes', () => {
        mountPage();
        summaryForm().isDirty = true;

        expect(fireBeforeUnload().defaultPrevented).toBe(true);
    });

    it('does not interrupt in-app navigation while nothing has been typed', () => {
        mountPage();
        const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true);

        expect(fireInAppNavigation().defaultPrevented).toBe(false);
        expect(confirmSpy).not.toHaveBeenCalled();
    });

    it('lets in-app navigation through when the teacher confirms losing the changes', () => {
        mountPage();
        summaryForm().isDirty = true;
        const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true);

        expect(fireInAppNavigation().defaultPrevented).toBe(false);
        expect(confirmSpy).toHaveBeenCalledOnce();
    });

    it('cancels in-app navigation when the teacher declines', () => {
        mountPage();
        summaryForm().isDirty = true;
        vi.spyOn(window, 'confirm').mockReturnValue(false);

        expect(fireInAppNavigation().defaultPrevented).toBe(true);
    });

    /**
     * The back link built in the previous fix is an ordinary Inertia visit, so
     * it goes through the same guard rather than around it.
     */
    it('guards the "Voltar às aulas da semana" link like any other in-app navigation', () => {
        const wrapper = mountPage();
        summaryForm().isDirty = true;
        vi.spyOn(window, 'confirm').mockReturnValue(false);

        const link = wrapper.findAll('a').find((a) => a.text().includes('Voltar às aulas da semana'));

        expect(link!.attributes('href')).toBe('/lessons?week=2026-09-07');
        expect(fireInAppNavigation().defaultPrevented).toBe(true);
    });

    /** Saving is how the work is kept — it must never be interrogated. */
    it('never interrogates the page\'s own "Guardar" submission', async () => {
        const wrapper = mountPage();
        summaryForm().isDirty = true;
        const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);

        await wrapper.find('form').trigger('submit');

        expect(summaryForm().put).toHaveBeenCalledOnce();
        expect(fireInAppNavigation().defaultPrevented).toBe(false);
        expect(fireBeforeUnload().defaultPrevented).toBe(false);
        expect(confirmSpy).not.toHaveBeenCalled();
    });

    it('never interrogates the "Marcar como lecionada" request', async () => {
        const wrapper = mountPage();
        summaryForm().isDirty = true;
        const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);

        const button = wrapper
            .findAll('button')
            .find((candidate) => candidate.text().includes('Marcar como lecionada'));

        await button!.trigger('click');

        expect(mocks.forms[1].post).toHaveBeenCalledOnce();
        expect(fireInAppNavigation().defaultPrevented).toBe(false);
        expect(confirmSpy).not.toHaveBeenCalled();
    });

    it('unregisters both listeners when the page goes away', () => {
        const wrapper = mountPage();
        summaryForm().isDirty = true;

        wrapper.unmount();
        wrappers.length = 0;

        expect(mocks.unsubscribe).toHaveBeenCalledOnce();
        expect(fireBeforeUnload().defaultPrevented).toBe(false);
    });
});

describe('lessons/Show — saída no fundo da página', () => {
    it('repeats «Voltar às aulas da semana» at the bottom, to the same week', () => {
        const links = mountPage()
            .findAll('a')
            .filter((a) => a.text().includes('Voltar às aulas da semana'));

        expect(links).toHaveLength(2);
        expect(links.map((link) => link.attributes('href'))).toEqual([
            '/lessons?week=2026-09-07',
            '/lessons?week=2026-09-07',
        ]);
    });

    it('never submits, saves or marks the lesson as taught', () => {
        const wrapper = mountPage();
        const bottom = wrapper
            .findAll('a')
            .filter((a) => a.text().includes('Voltar às aulas da semana'))
            .at(-1)!;

        expect(bottom.element.closest('form')).toBeNull();
        expect(bottom.attributes('type')).toBeUndefined();
        expect(mocks.forms[0].put).not.toHaveBeenCalled();
        expect(mocks.forms[0].post).not.toHaveBeenCalled();
        expect(mocks.forms[1].post).not.toHaveBeenCalled();
    });
});

describe('lessons/Show — assiduidade', () => {
    const alice = {
        student_ulid: 'student-alice',
        enrollment_ulid: 'enrollment-alice',
        class_number: 1,
        name: 'Alice Andrade',
        photo_url: null,
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

    it('shows the "not yet taught" help text before consolidation', () => {
        const wrapper = mountPage({}, { students: [alice, bruno] });

        expect(wrapper.text()).toContain('Assinala só quem faltou.');
    });

    it('shows "não registada" when the lesson was taught without recording attendance', () => {
        const wrapper = mountPage({ status: 'taught' }, { students: [alice, bruno] });

        expect(wrapper.text()).toContain('Assiduidade não registada.');
    });

    it('shows the consolidated counts once recorded', () => {
        const wrapper = mountPage(
            { status: 'taught' },
            {
                recorded: true,
                students: [
                    { ...alice, status: 'present' },
                    { ...bruno, status: 'absent' },
                ],
                counts: { present: 1, absent: 1 },
            },
        );

        expect(wrapper.text()).toContain('Registada: 1 presença · 1 falta. Podes corrigir.');
    });

    it('shows the class-group note when the lesson belongs to a group', () => {
        const wrapper = mountPage(
            { context_label: '7.º A · T1', class_group_label: 'T1' },
            { students: [alice] },
        );

        expect(wrapper.text()).toContain('Só aparecem os alunos de T1 nesta data.');
    });

    it('shows the roster error instead of the list when the roster cannot be determined', () => {
        const wrapper = mountPage({}, { roster_error: 'Não foi possível determinar os alunos desta aula.' });

        expect(wrapper.text()).toContain('Não foi possível determinar os alunos desta aula.');
    });

    it('shows the excluded-without-left-on note', () => {
        const wrapper = mountPage({}, { students: [alice], excluded_without_left_on: 2 });

        expect(wrapper.text()).toContain('2 inscrição(ões) terminada(s) sem data de saída não aparecem nesta lista.');
    });

    it('shows the empty state when there are no eligible students', () => {
        const wrapper = mountPage({}, { students: [] });

        expect(wrapper.text()).toContain('Não há alunos elegíveis para esta aula.');
    });

    it('includes the toggled "absent" ulids in the sumário payload', async () => {
        const wrapper = mountPage({}, { students: [alice, bruno] });

        const faltaButtons = wrapper.findAll('button').filter((b) => b.text() === 'Falta');
        await faltaButtons[0].trigger('click');

        expect(summaryForm().absent).toEqual(['student-alice']);

        await wrapper.find('form').trigger('submit');

        expect(summaryForm().put).toHaveBeenCalledWith(
            '/lessons/lesson-a/summary',
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('sends the drafted "absent" ulids with "marcar lecionada"', async () => {
        const wrapper = mountPage({}, { students: [alice, bruno] });

        const faltaButtons = wrapper.findAll('button').filter((b) => b.text() === 'Falta');
        await faltaButtons[0].trigger('click');

        const markTaughtButton = wrapper
            .findAll('button')
            .find((b) => b.text().includes('Marcar como lecionada'));
        await markTaughtButton!.trigger('click');

        expect(mocks.forms[1].absent).toEqual(['student-alice']);
        expect(mocks.forms[1].post).toHaveBeenCalledOnce();
    });

    it('shows a muted line near "Marcar como lecionada" while the lesson is not taught', () => {
        const wrapper = mountPage({}, { students: [alice] });

        expect(wrapper.text()).toContain('Os alunos sem falta assinalada ficam presentes.');
    });

    it('offers "Registar assiduidade" once taught without a recorded attendance, and posts it', async () => {
        const wrapper = mountPage({ status: 'taught' }, { students: [alice, bruno] });

        const faltaButtons = wrapper.findAll('button').filter((b) => b.text() === 'Falta');
        await faltaButtons[0].trigger('click');

        const recordButton = wrapper.findAll('button').find((b) => b.text().includes('Registar assiduidade'));
        expect(recordButton).toBeTruthy();

        await recordButton!.trigger('click');

        // record é o terceiro useForm criado (sumário, lecionada, registar).
        expect(mocks.forms[2].absent).toEqual(['student-alice']);
        expect(mocks.forms[2].post).toHaveBeenCalledWith(
            '/lessons/lesson-a/attendance',
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('does not render "Registar assiduidade" without edit permission', () => {
        const wrapper = mountPage({ status: 'taught' }, { students: [alice], can_edit: false });

        expect(wrapper.findAll('button').find((b) => b.text().includes('Registar assiduidade'))).toBeUndefined();
    });

    it('renders rows as a flex column, never a table', () => {
        const wrapper = mountPage({}, { students: [alice, bruno] });

        expect(wrapper.find('table').exists()).toBe(false);
        const list = wrapper.find('[data-testid="attendance-rows"]');
        expect(list.classes()).toContain('flex');
        expect(list.classes()).toContain('flex-col');
    });

    it('calls router.patch to correct attendance once consolidated', async () => {
        const wrapper = mountPage(
            { status: 'taught' },
            {
                recorded: true,
                students: [
                    { ...alice, status: 'present' },
                    { ...bruno, status: 'absent' },
                ],
                counts: { present: 1, absent: 1 },
            },
        );

        const presenteButton = wrapper.findAll('button').find((b) => b.text().includes('Presente'));
        await presenteButton!.trigger('click');

        const { router: mockedRouter } = await import('@inertiajs/vue3');
        expect(mockedRouter.patch).toHaveBeenCalledWith(
            '/lessons/lesson-a/attendance/student-alice',
            { status: 'absent' },
            expect.objectContaining({ preserveScroll: true }),
        );

        wrapper.unmount();
        wrappers.pop();
    });

    it('marks the sumário form dirty when attendance drafts change', async () => {
        const wrapper = mountPage({}, { students: [alice] });

        expect(summaryForm().isDirty).toBe(false);

        const faltaButton = wrapper.findAll('button').find((b) => b.text() === 'Falta');
        await faltaButton!.trigger('click');
        summaryForm().isDirty = true; // o mock não recalcula isDirty sozinho — ver summaryFormDirtyState.test.ts

        expect(fireBeforeUnload().defaultPrevented).toBe(true);
    });
});
