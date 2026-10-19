import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick, reactive } from 'vue';
import type { DayEvent } from '@/components/lessons/LessonDayEvents.vue';
import LessonOutcomeDialog from '@/components/lessons/LessonOutcomeDialog.vue';
import { consumeLessonsStale, resetConfirmedSummaries } from '@/lib/confirmedSummaries';
import Show from './Show.vue';

type MockForm = Record<string, unknown> & { isDirty: boolean };

const mocks = vi.hoisted(() => ({
    forms: [] as MockForm[],
    beforeHandlers: [] as ((event: Event) => void)[],
    unsubscribe: vi.fn(),
    visit: vi.fn(),
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
        visit: mocks.visit,
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
            delete: vi.fn(),
            clearErrors: vi.fn(),
            transform: vi.fn(),
            defaults: vi.fn(),
            reset: vi.fn(),
            data: () => ({ ...data }),
        }) as MockForm;

        mocks.forms.push(form);

        return form;
    },
}));

// O diálogo real teleporta para fora da árvore; aqui renderiza-se inline, e só
// enquanto está aberto — para se poder ver que fecha.
vi.mock('@/components/ui/dialog', () => {
    const passthrough = defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) });

    return {
        Dialog: defineComponent({
            props: { open: { type: Boolean, default: false } },
            setup: (props, { slots }) => () => (props.open ? h('div', { 'data-dialog': '' }, slots.default?.()) : null),
        }),
        DialogClose: passthrough,
        DialogContent: passthrough,
        DialogDescription: passthrough,
        DialogFooter: passthrough,
        DialogHeader: passthrough,
        DialogTitle: passthrough,
    };
});

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
        status_label?: string;
        outcome?: 'taught' | 'teacher_absent' | 'class_external_activity' | null;
        outcome_label?: string | null;
        can_record_outcome?: boolean;
        context_label?: string;
        class_group_label?: string | null;
        summary?: { content: string; private_notes: string | null; resources: string | null; homework: string | null; reviewed_at: string | null } | null;
        summary_version?: number;
        lesson_number?: number | null;
        lesson_number_end?: number | null;
        lesson_number_manual?: boolean;
        can_set_number?: boolean;
    } = {},
    attendanceOverrides: Partial<typeof defaultAttendance> = {},
    dayEvents: DayEvent[] = [],
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
                lesson_number_end: null,
                lesson_number_manual: false,
                can_set_number: true,
                summary_version: 4,
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
            day_events: dayEvents,
        },
        global: { stubs: { teleport: true } },
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
    const event = new CustomEvent('before', {
        cancelable: true,
        detail: {
            visit: {
                url: new URL('http://localhost/classes'),
                method: 'get',
                data: {},
                replace: false,
                preserveScroll: false,
                preserveState: false,
                only: [],
                except: [],
                headers: {},
            },
        },
    });
    mocks.beforeHandlers.forEach((handler) => handler(event));

    return event;
}

function dialogButton(wrapper: VueWrapper, testId: string) {
    return wrapper.find(`[data-testid="${testId}"]`);
}

beforeEach(() => {
    mocks.forms.length = 0;
    mocks.beforeHandlers.length = 0;
    mocks.unsubscribe.mockClear();
    mocks.visit.mockClear();
    window.sessionStorage.clear();
    resetConfirmedSummaries();
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
        const wrapper = mountPage();

        expect(fireInAppNavigation().defaultPrevented).toBe(false);
        expect(dialogButton(wrapper, 'unsaved-stay').exists()).toBe(false);
    });

    it('suspends in-app navigation and asks, once the sumário has unsaved changes', async () => {
        const wrapper = mountPage();
        summaryForm().isDirty = true;

        expect(fireInAppNavigation().defaultPrevented).toBe(true);
        await nextTick();

        expect(wrapper.text()).toContain('Tens alterações por guardar');
        expect(dialogButton(wrapper, 'unsaved-save').text()).toContain('Guardar e continuar');
    });

    it('«Sair sem guardar» resumes the suspended visit', async () => {
        const wrapper = mountPage();
        summaryForm().isDirty = true;
        fireInAppNavigation();
        await nextTick();

        await dialogButton(wrapper, 'unsaved-leave').trigger('click');

        expect(mocks.visit).toHaveBeenCalledOnce();
        expect(mocks.visit.mock.calls[0][0]).toBe('http://localhost/classes');
    });

    it('«Continuar a editar» keeps the teacher on the page', async () => {
        const wrapper = mountPage();
        summaryForm().isDirty = true;
        fireInAppNavigation();
        await nextTick();

        await dialogButton(wrapper, 'unsaved-stay').trigger('click');
        await nextTick();

        expect(mocks.visit).not.toHaveBeenCalled();
        expect(dialogButton(wrapper, 'unsaved-stay').exists()).toBe(false);
    });

    it('«Guardar e continuar» only continues after the save is accepted', async () => {
        const wrapper = mountPage();
        summaryForm().isDirty = true;
        fireInAppNavigation();
        await nextTick();

        await dialogButton(wrapper, 'unsaved-save').trigger('click');

        const put = summaryForm().put as ReturnType<typeof vi.fn>;
        expect(put).toHaveBeenCalledOnce();
        expect(mocks.visit).not.toHaveBeenCalled();

        const options = put.mock.calls[0][1] as { onSuccess: () => void; onFinish: () => void };
        options.onSuccess();
        options.onFinish();
        await nextTick();
        await nextTick();

        expect(mocks.visit).toHaveBeenCalledOnce();
    });

    it('«Guardar e continuar» stays put when the save fails', async () => {
        const wrapper = mountPage();
        summaryForm().isDirty = true;
        fireInAppNavigation();
        await nextTick();

        await dialogButton(wrapper, 'unsaved-save').trigger('click');
        const options = (summaryForm().put as ReturnType<typeof vi.fn>).mock.calls[0][1] as { onFinish: () => void };
        options.onFinish();
        await nextTick();
        await nextTick();

        expect(mocks.visit).not.toHaveBeenCalled();
    });

    /**
     * The back link goes through the same guard rather than around it.
     */
    it('guards the "Voltar às aulas da semana" link like any other in-app navigation', async () => {
        const wrapper = mountPage();
        summaryForm().isDirty = true;

        const link = wrapper.findAll('a').find((a) => a.text().includes('Voltar às aulas da semana'));

        expect(link!.attributes('href')).toBe('/lessons?week=2026-09-07');

        await link!.trigger('click', { button: 0 });

        expect(mocks.visit).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain('Tens alterações por guardar');
    });

    /** Saving is how the work is kept — it must never be interrogated. */
    it('never interrogates the page\'s own "Guardar" submission', async () => {
        const wrapper = mountPage();
        summaryForm().isDirty = true;

        await wrapper.find('form').trigger('submit');

        expect(summaryForm().put).toHaveBeenCalledOnce();
        expect(fireInAppNavigation().defaultPrevented).toBe(false);
    });

    it('never interrogates the "Marcar como lecionada" request', async () => {
        const wrapper = mountPage();
        summaryForm().isDirty = true;

        const button = wrapper
            .findAll('button')
            .find((candidate) => candidate.text().includes('Marcar como lecionada'));

        await button!.trigger('click');

        expect(mocks.forms[1].post).toHaveBeenCalledOnce();
        expect(fireInAppNavigation().defaultPrevented).toBe(false);
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

describe('lessons/Show — resultado registado (0.146.1)', () => {
    it('o cabeçalho mostra o resultado e não «Preparada», sem ação de lecionar', () => {
        const wrapper = mountPage({
            status: 'prepared',
            status_label: 'Preparada',
            outcome: 'teacher_absent',
            outcome_label: 'Professor ausente',
            can_record_outcome: false,
        });

        expect(wrapper.get('[data-testid="lesson-state"]').text()).toBe('Professor ausente');
        expect(wrapper.text()).not.toContain('Preparada');
        expect(wrapper.findAll('button').some((button) => button.text().includes('Marcar como lecionada'))).toBe(false);
    });

    it('sem resultado mantém o estado de preparação', () => {
        const wrapper = mountPage({ status: 'prepared', status_label: 'Preparada' });

        expect(wrapper.get('[data-testid="lesson-state"]').text()).toBe('Preparada');
    });
});

describe('lessons/Show — acontecimentos do dia', () => {
    const meeting: DayEvent = {
        ulid: 'event-meeting',
        title: 'Reunião de departamento',
        starts_at: '14:10',
        ends_at: '15:00',
        all_day: false,
        notes: 'Trazer a planificação.',
        type_label: 'Reunião',
    };
    const trip: DayEvent = {
        ulid: 'event-trip',
        title: 'Visita de estudo',
        starts_at: null,
        ends_at: null,
        all_day: true,
        notes: null,
        type_label: 'Visita de estudo',
    };
    const existingSummary = {
        content: 'Leitura do capítulo 3.',
        private_notes: null,
        resources: null,
        homework: null,
        reviewed_at: null,
    };

    it('shows no block when the day has no events', () => {
        const wrapper = mountPage();

        expect(wrapper.text()).not.toContain('Acontecimentos do dia');
    });

    it('shows each event with its title, type, schedule and notes', () => {
        const wrapper = mountPage({}, {}, [trip, meeting]);
        const items = wrapper.findAll('[data-testid="day-event"]');

        expect(wrapper.text()).toContain('Acontecimentos do dia');
        expect(items).toHaveLength(2);
        expect(items[0].text()).toContain('Visita de estudo');
        expect(items[0].text()).toContain('Todo o dia');
        expect(items[1].text()).toContain('Reunião de departamento');
        expect(items[1].text()).toContain('Reunião');
        expect(items[1].text()).toContain('14:10–15:00');
        expect(items[1].text()).toContain('Trazer a planificação.');
    });

    it('appends to the existing sumário without saving, then shows «Já no sumário»', async () => {
        const wrapper = mountPage({ summary: existingSummary }, {}, [meeting]);
        const button = wrapper.findAll('button').find((candidate) => candidate.text().includes('Incluir no sumário'))!;

        expect(button.attributes('type')).toBe('button');
        // Alvo táctil ≥44px no telemóvel (DESIGN.md), sem mudar o tamanho no ecrã largo.
        expect(button.classes()).toEqual(expect.arrayContaining(['min-h-11', 'sm:min-h-0']));

        await button.trigger('click');

        expect(summaryForm().content).toBe('Leitura do capítulo 3.\nReunião de departamento — Trazer a planificação.');
        expect(summaryForm().put).not.toHaveBeenCalled();
        expect(summaryForm().post).not.toHaveBeenCalled();

        const after = wrapper.findAll('button').find((candidate) => candidate.text().includes('Já no sumário'))!;

        expect(after.attributes('disabled')).toBeDefined();
    });
});

describe('lessons/Show — versão do sumário e gravações concorrentes (0.158.0)', () => {
    it('sends the summary version it read with the sumário', () => {
        mountPage({ summary_version: 7 });

        expect(summaryForm().summary_version).toBe(7);
    });

    it('sends the version with «Limpar sumário» too', () => {
        mountPage({ summary_version: 7 });

        expect(mocks.forms.find((form) => 'summary_version' in form && !('content' in form))?.summary_version).toBe(7);
    });

    it('shows the stored text next to the draft when the save is refused by a newer version', async () => {
        const wrapper = mountPage({
            summary_version: 5,
            summary: { content: 'Gravado noutra janela.', private_notes: null, resources: null, homework: null, reviewed_at: null },
        });
        summaryForm().content = 'O meu rascunho.';
        (summaryForm().errors as Record<string, string>).summary_version = 'O sumário desta aula foi alterado noutra janela depois de o abrires.';
        await nextTick();

        const panel = wrapper.find('[data-testid="summary-conflict"]');
        expect(panel.exists()).toBe(true);
        expect(panel.text()).toContain('O meu rascunho.');
        expect(panel.text()).toContain('Gravado noutra janela.');
        // A mensagem não se repete na lista de erros por cima.
        expect(wrapper.text().match(/alterado noutra janela depois de o abrires/g)).toBeNull();
    });

    it('«Combinar no editor» keeps the draft, adds the stored lines and adopts the version just seen', async () => {
        const wrapper = mountPage({
            summary_version: 5,
            summary: { content: 'Linha gravada.', private_notes: null, resources: null, homework: null, reviewed_at: null },
        });
        summaryForm().content = 'O meu rascunho.';
        (summaryForm().errors as Record<string, string>).summary_version = 'alterado';
        await nextTick();

        await wrapper.find('[data-testid="conflict-combine"]').trigger('click');

        expect(summaryForm().content).toBe('O meu rascunho.\nLinha gravada.');
        expect(summaryForm().summary_version).toBe(5);
        expect(summaryForm().clearErrors).toHaveBeenCalledWith('summary_version');
        expect(summaryForm().put).not.toHaveBeenCalled();
    });
});

describe('lessons/Show — regressar ao sítio de onde se veio (0.158.0)', () => {
    it('goes back through history when the teacher came from the week view', async () => {
        window.sessionStorage.setItem('lapis.lessons.return', JSON.stringify({ url: '/lessons?week=2026-09-07&view=turma', lesson: 'lesson-a' }));
        const back = vi.spyOn(window.history, 'back').mockImplementation(() => {});
        Object.defineProperty(window.history, 'length', { configurable: true, value: 3 });
        const wrapper = mountPage();
        await nextTick();

        const link = wrapper.find('[data-testid="lesson-back"]');
        expect(link.attributes('href')).toBe('/lessons?week=2026-09-07&view=turma');

        await link.trigger('click', { button: 0 });

        expect(back).toHaveBeenCalledOnce();
        expect(mocks.visit).not.toHaveBeenCalled();
    });

    it('names the search results as the way back when the teacher came from a summary search (0.162.0)', async () => {
        const url = '/lessons?week=2026-10-05&view=semana&q=fra%C3%A7%C3%B5es&q_states=taught&q_page=2';
        window.sessionStorage.setItem('lapis.lessons.return', JSON.stringify({ url, lesson: 'lesson-a' }));
        const back = vi.spyOn(window.history, 'back').mockImplementation(() => {});
        Object.defineProperty(window.history, 'length', { configurable: true, value: 3 });
        const wrapper = mountPage();
        await nextTick();

        const link = wrapper.get('[data-testid="lesson-back"]');
        expect(link.text()).toBe('Voltar aos resultados da pesquisa');
        expect(link.attributes('href')).toBe(url);
        expect(wrapper.get('[data-testid="lesson-back-bottom"]').text()).toBe('Voltar aos resultados da pesquisa');

        await link.trigger('click', { button: 0 });
        expect(back).toHaveBeenCalledOnce();
    });

    it('goes back to the Registos consultation when the teacher opened the lesson from «Faltas de presença» (0.164.0)', async () => {
        const url = '/records?year=Y1&period=P1&view=absences&case_class=C1&case_student=E1&case_category=absences';
        window.sessionStorage.setItem('lapis.lessons.return', JSON.stringify({ url, lesson: 'lesson-a' }));
        const back = vi.spyOn(window.history, 'back').mockImplementation(() => {});
        Object.defineProperty(window.history, 'length', { configurable: true, value: 3 });
        const wrapper = mountPage();
        await nextTick();

        const link = wrapper.get('[data-testid="lesson-back"]');
        expect(link.text()).toBe('Voltar à consulta de registos');
        expect(link.attributes('href')).toBe(url);

        await link.trigger('click', { button: 0 });
        expect(back).toHaveBeenCalledOnce();
    });

    it('never follows a remembered origin outside the application', async () => {
        window.sessionStorage.setItem('lapis.lessons.return', JSON.stringify({ url: 'https://example.com/records', lesson: 'lesson-a' }));
        const wrapper = mountPage();
        await nextTick();

        expect(wrapper.find('[data-testid="lesson-back"]').attributes('href')).toBe('/lessons?week=2026-09-07');
    });

    it('ignores a remembered origin that belongs to another lesson', async () => {
        window.sessionStorage.setItem('lapis.lessons.return', JSON.stringify({ url: '/lessons?week=2026-01-05', lesson: 'other-lesson' }));
        const wrapper = mountPage();
        await nextTick();

        expect(wrapper.find('[data-testid="lesson-back"]').attributes('href')).toBe('/lessons?week=2026-09-07');
    });

    it('marks the attendance section so «Assiduidade» on the week card lands on it', () => {
        const wrapper = mountPage();

        expect(wrapper.find('#assiduidade').exists()).toBe(true);
    });
});

describe('lessons/Show — «Aula anterior»', () => {
    const entry = (overrides: Record<string, unknown> = {}) => ({
        ulid: 'e',
        class_ulid: 'turma-1',
        starts_at: '2026-09-07T09:00:00+01:00',
        ends_at: '2026-09-07T09:50:00+01:00',
        lesson_number: 1,
        lesson_number_end: null,
        context_label: '7.º A',
        state: 'taught',
        state_label: 'Lecionada',
        content: 'Texto.',
        plan: null,
        resources: null,
        homework: null,
        ...overrides,
    });

    function stubFetch(routes: { context?: () => Promise<Response>; previous?: () => Promise<Response> }) {
        vi.stubGlobal(
            'fetch',
            vi.fn((url: string) => {
                if (url.includes('/preparation-context')) {
                    return (routes.context ?? (() => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ lesson: null }) } as Response)))();
                }

                return (routes.previous ?? (() => Promise.resolve({ status: 204 } as Response)))();
            }),
        );
    }

    const json = (body: unknown) => () => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(body) } as Response);

    it('mostra UMA só aula anterior, com data, hora, número, estado e o conteúdo completo', async () => {
        const longSummary = `${'Funções afins e lineares. '.repeat(30)}\nSegunda linha.`;
        stubFetch({
            context: json({
                lesson: entry({
                    lesson_number: 11,
                    lesson_number_end: 12,
                    state: 'prepared',
                    state_label: 'Preparada — por lecionar',
                    content: longSummary,
                    plan: 'Plano da aula.',
                    homework: 'Ficha 3',
                    resources: 'https://exemplo.pt/um/caminho/muito/longo/que/nao/deve/passar/a/largura',
                }),
            }),
        });

        const wrapper = mountPage();
        await flushPromises();

        expect(wrapper.get('[data-testid="lesson-context-toggle"]').text()).toBe('Aula anterior');
        expect(wrapper.findAll('[data-testid="lesson-context-entry"]')).toHaveLength(1);
        const card = wrapper.get('[data-testid="lesson-context-entry"]');
        expect(card.get('[data-testid="lesson-context-when"]').text()).toContain('Segunda-feira, 7 de setembro, 09:00');
        expect(card.get('[data-testid="lesson-context-number"]').text()).toBe('Lições 11–12');
        expect(card.get('[data-testid="lesson-context-state"]').text()).toBe('Preparada — por lecionar');
        expect(card.get('[data-testid="lesson-context-content"] p:last-child').text()).toBe(longSummary);
        expect(card.get('[data-testid="lesson-context-plan"]').text()).toContain('Plano da aula.');
        expect(card.get('[data-testid="lesson-context-homework"]').text()).toContain('Ficha 3');
        expect(card.get('[data-testid="lesson-context-resources"]').text()).toContain('https://exemplo.pt/um/caminho');
        // Sem contador, sem «Mostrar mais» e sem «Ver mais».
        expect(wrapper.find('[data-testid="lesson-context-more"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="lesson-context"]').text()).not.toMatch(/\(\d+\+?\)|Mostrar mais|Ver mais/);
        expect(wrapper.get('[data-testid="lesson-context-history"]').attributes('href')).toBe('/lessons?view=turma&class=turma-1&week=2026-09-07');
    });

    it('guarda as quebras de linha e quebra qualquer URL longo dentro do cartão', async () => {
        stubFetch({ context: json({ lesson: entry({ content: 'a\nb', resources: 'https://exemplo.pt/x' }) }) });
        const wrapper = mountPage();
        await flushPromises();

        const card = wrapper.get('[data-testid="lesson-context-entry"]');
        expect(card.classes()).toContain('min-w-0');

        for (const selector of ['content', 'resources']) {
            const text = card.get(`[data-testid="lesson-context-${selector}"] p:last-child`);
            expect(text.classes()).toEqual(expect.arrayContaining(['min-w-0', 'whitespace-pre-line', 'wrap-anywhere']));
        }

        expect(wrapper.get('[data-testid="lesson-context"]').classes()).toContain('min-w-0');
    });

    it('uma lecionada sem texto diz «Sem sumário registado»', async () => {
        stubFetch({ context: json({ lesson: entry({ content: '' }) }) });
        const wrapper = mountPage();
        await flushPromises();

        expect(wrapper.get('[data-testid="lesson-context-state"]').text()).toBe('Lecionada');
        expect(wrapper.get('[data-testid="lesson-context-no-summary"]').text()).toBe('Sem sumário registado.');
        expect(wrapper.find('[data-testid="lesson-context-content"]').exists()).toBe(false);
    });

    it('uma preparada só com plano mostra o plano e não diz «sem sumário»', async () => {
        stubFetch({ context: json({ lesson: entry({ state: 'prepared', state_label: 'Preparada — por lecionar', content: '', plan: 'Só o plano.' }) }) });
        const wrapper = mountPage();
        await flushPromises();

        expect(wrapper.get('[data-testid="lesson-context-plan"]').text()).toContain('Só o plano.');
        expect(wrapper.find('[data-testid="lesson-context-no-summary"]').exists()).toBe(false);
    });

    it('sem aula anterior elegível o bloco não aparece, nem título nem «sem aulas»', async () => {
        stubFetch({});
        const wrapper = mountPage();
        await flushPromises();

        expect(wrapper.find('[data-testid="lesson-context"]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Aula anterior');
        expect(wrapper.text()).not.toContain('Sem aulas anteriores');
    });

    it('um erro de carregamento mostra a mensagem e não parte o formulário', async () => {
        stubFetch({ context: () => Promise.resolve({ ok: false, status: 500 } as Response) });
        const wrapper = mountPage();
        await flushPromises();

        expect(wrapper.get('[data-testid="lesson-context-error"]').text()).toContain('Não foi possível carregar a aula anterior.');
        expect(wrapper.find('#lesson-summary').exists()).toBe(true);
        expect(wrapper.text()).toContain('Guardar');
    });

    it('«Basear…» cita a aula e o estado, e só copia ao clicar', async () => {
        stubFetch({
            previous: json({
                content: 'Plano de terça.',
                private_notes: null,
                resources: null,
                homework: null,
                starts_at: '2026-09-08T09:00:00+01:00',
                state: 'prepared',
                state_label: 'Preparada — por lecionar',
            }),
        });
        const wrapper = mountPage();
        await flushPromises();

        const base = wrapper.findAll('button').find((button) => button.text().startsWith('Basear na aula de'));
        expect(base?.text()).toBe('Basear na aula de 08/09 (Preparada — por lecionar)');
        expect(summaryForm().content).toBe('');

        await base!.trigger('click');

        expect(summaryForm().content).toBe('Plano de terça.');
    });

    it('CASO D: «Basear…» copia o conteúdo mas não mexe no rascunho do número, e o PUT não leva chaves lesson_number*', async () => {
        stubFetch({
            previous: json({
                content: 'Plano de terça.',
                private_notes: null,
                resources: 'Manual p. 12',
                homework: 'Ficha 3',
                starts_at: '2026-09-08T09:00:00+01:00',
                state: 'taught',
                state_label: 'Lecionada',
            }),
        });
        const wrapper = mountPage({ lesson_number: 13 });
        await flushPromises();

        const base = wrapper.findAll('button').find((button) => button.text().startsWith('Basear na aula de'));
        await base!.trigger('click');

        expect(summaryForm().content).toBe('Plano de terça.');
        expect(summaryForm().homework).toBe('Ficha 3');
        expect((wrapper.get('[data-testid="lesson-number-start"]').element as HTMLInputElement).value).toBe('13');

        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(summaryForm().put).toHaveBeenCalledOnce();
        const transform = (summaryForm().transform as unknown as { mock: { calls: [(data: Record<string, unknown>) => Record<string, unknown>][] } }).mock.calls[0][0];
        const sent = transform({ content: 'x' });
        expect(Object.keys(sent).filter((key) => key.startsWith('lesson_number'))).toEqual([]);
    });
});

describe('lessons/Show — gravação confirmada e falhas (0.158.1)', () => {
    type PutOptions = {
        onSuccess: () => void;
        onFinish: () => void;
        onHttpException: () => boolean | void;
        onNetworkError: () => boolean | void;
    };

    const saved = { content: 'Texto gravado.', private_notes: null, resources: null, homework: null, reviewed_at: null };

    async function submit(wrapper: VueWrapper): Promise<PutOptions> {
        await wrapper.get('form').trigger('submit');

        return (summaryForm().put as ReturnType<typeof vi.fn>).mock.calls[0][1] as PutOptions;
    }

    it('uma gravação aceite fica registada com a versão das props atualizadas e avisa a semana', async () => {
        const wrapper = mountPage({ summary_version: 5, summary: saved });
        const options = await submit(wrapper);

        options.onSuccess();
        options.onFinish();

        const stored = JSON.parse(window.sessionStorage.getItem('lapis.lessons.confirmedSummaries') ?? '{}') as Record<string, unknown>;
        expect(stored['lesson-a']).toEqual({ ulid: 'lesson-a', content: 'Texto gravado.', version: 5 });
        expect(consumeLessonsStale()).toBe(true);
    });

    it.each([
        ['a ligação falha', 'onNetworkError', 'a ligação falhou'],
        ['o servidor responde mal', 'onHttpException', 'o servidor não respondeu como devia'],
    ] as const)('quando %s mostra a mensagem, mantém o texto e nunca diz «Sumário guardado.»', async (_name, hook, message) => {
        const wrapper = mountPage({ summary_version: 5, summary: saved });
        summaryForm().content = 'O meu texto por gravar.';
        const options = await submit(wrapper);

        options[hook]();
        options.onFinish();
        await nextTick();

        expect(wrapper.get('[data-testid="summary-save-failure"]').text()).toContain(message);
        expect(wrapper.text()).toContain('O teu texto continua aqui');
        expect(wrapper.text()).not.toContain('Sumário guardado.');
        expect(summaryForm().content).toBe('O meu texto por gravar.');
        expect(window.sessionStorage.getItem('lapis.lessons.confirmedSummaries')).toBeNull();
        expect(consumeLessonsStale()).toBe(false);
    });

    it('a mensagem de falha desaparece na tentativa seguinte', async () => {
        const wrapper = mountPage({ summary_version: 5, summary: saved });
        const first = await submit(wrapper);
        first.onNetworkError();
        first.onFinish();
        await nextTick();
        expect(wrapper.find('[data-testid="summary-save-failure"]').exists()).toBe(true);

        await wrapper.get('form').trigger('submit');
        await nextTick();

        expect(wrapper.find('[data-testid="summary-save-failure"]').exists()).toBe(false);
    });
});

describe('lessons/Show — o número da lição (0.169.0)', () => {
    const transformOf = () => (summaryForm().transform as unknown as { mock: { calls: [(data: Record<string, unknown>) => Record<string, unknown>][] } }).mock.calls[0][0];

    function stubPreview(body: Record<string, unknown>) {
        const fetchMock = vi.fn((url: string) =>
            Promise.resolve({
                ok: true,
                status: String(url).includes('/number/preview') ? 200 : 204,
                json: () => Promise.resolve(String(url).includes('/number/preview') ? body : { lesson: null }),
            } as Response),
        );
        vi.stubGlobal('fetch', fetchMock);

        return fetchMock;
    }

    const preview = (overrides: Record<string, unknown> = {}) => ({
        changed: true,
        result: { start: 14, end: null, manual: true },
        conflicts: [],
        effects: [],
        warnings: [],
        requires_confirmation: false,
        confirmation: null,
        following_from: 15,
        ...overrides,
    });

    it('uma aula antiga «Lições 11–12» mantém o selo (histórico), sem input do fim e com o aviso legado', () => {
        const wrapper = mountPage({ lesson_number: 11, lesson_number_end: 12 });

        expect(wrapper.text()).toContain('Lições 11–12');
        expect(wrapper.find('[data-testid="lesson-number-end"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="lesson-number-multiple"]').exists()).toBe(false);
        expect((wrapper.get('[data-testid="lesson-number-start"]').element as HTMLInputElement).value).toBe('11');
        expect(wrapper.get('[data-testid="lesson-number-legacy-range"]').text()).toContain('Lições 11–12');
    });

    it('número igual: grava como sempre, sem pré-visualização nem chaves de numeração', async () => {
        const fetchMock = stubPreview(preview());
        const wrapper = mountPage();

        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(fetchMock.mock.calls.filter(([url]) => String(url).includes('/number/preview'))).toHaveLength(0);
        expect(summaryForm().put).toHaveBeenCalledOnce();
        expect(transformOf()({ content: 'x' })).toEqual({ content: 'x' });
    });

    it('o número alterado conta como alteração por guardar na guarda de navegação', async () => {
        const wrapper = mountPage();

        await wrapper.get('[data-testid="lesson-number-start"]').setValue('20');
        const event = fireInAppNavigation();

        expect(event.defaultPrevented).toBe(true);
    });

    it('número alterado sem efeitos registados: pré-visualiza e grava logo, com as chaves', async () => {
        stubPreview(preview());
        const wrapper = mountPage();
        await wrapper.get('[data-testid="lesson-number-start"]').setValue('14');

        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(summaryForm().put).toHaveBeenCalledOnce();
        expect(transformOf()({ content: 'x' })).toEqual({ content: 'x', lesson_number: 14 });
    });

    it('um conflito aparece junto do campo e não grava', async () => {
        stubPreview(preview({ conflicts: ['A Lição 14 já está atribuída à aula de 22/10/2026 (8.º F).'] }));
        const wrapper = mountPage();
        await wrapper.get('[data-testid="lesson-number-start"]').setValue('14');

        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(summaryForm().put).not.toHaveBeenCalled();
        expect(wrapper.get('[data-testid="lesson-number-error"]').text()).toContain('A Lição 14 já está atribuída');
    });

    it('efeitos registados pedem confirmação no painel; «Guardar» envia a confirmação', async () => {
        stubPreview(
            preview({
                effects: [{ ulid: 'x', starts_at: '2026-10-22T09:30:00+01:00', context_label: '8.º F', from: 2, from_end: null, to: 15, to_end: null, recorded: true }],
                confirmation: 'hash-9',
            }),
        );
        const wrapper = mountPage();
        await wrapper.get('[data-testid="lesson-number-start"]').setValue('14');

        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(summaryForm().put).not.toHaveBeenCalled();
        expect(wrapper.get('[data-testid="lesson-number-effects"]').text()).toContain('22/10 · 8.º F · Lição 2 → Lição 15');

        await wrapper.get('[data-testid="lesson-number-confirm"]').trigger('click');

        expect(summaryForm().put).toHaveBeenCalledOnce();
        expect(transformOf()({ content: 'x' })).toEqual({
            content: 'x',
            lesson_number: 14,
            lesson_number_confirmation: 'hash-9',
        });
    });

    it('professor ausente: sem campo, só a frase', () => {
        const wrapper = mountPage({ can_set_number: false, lesson_number: null, outcome: 'teacher_absent', outcome_label: 'Professor ausente', can_record_outcome: false });

        expect(wrapper.get('[data-testid="lesson-number-absent"]').text()).toContain('Professor ausente: esta aula não tem número de lição.');
        expect(wrapper.find('[data-testid="lesson-number-start"]').exists()).toBe(false);
    });
});


describe('lessons/Show — atividade do calendário que coincide (0.171.0)', () => {
    const partial: DayEvent = {
        ulid: 'event-partial',
        title: 'Teste de Física',
        starts_at: '09:30',
        ends_at: '10:30',
        all_day: false,
        notes: null,
        type_label: 'Teste',
        coincides: true,
        coverage: 'partial',
    };
    const whole: DayEvent = {
        ulid: 'event-whole',
        title: 'Visita de estudo',
        starts_at: null,
        ends_at: null,
        all_day: true,
        notes: 'Autocarro às 9h.',
        type_label: 'Visita de estudo',
        coincides: true,
        coverage: 'all_day',
    };
    const later: DayEvent = {
        ulid: 'event-later',
        title: 'Reunião',
        starts_at: '14:00',
        ends_at: '15:00',
        all_day: false,
        notes: null,
        coincides: false,
        coverage: null,
    };

    it('mostra os que coincidem primeiro, com as duas ações distintas', () => {
        const wrapper = mountPage({}, {}, [later, partial]);
        const block = wrapper.get('[data-testid="coinciding-events"]');

        expect(block.text()).toContain('Esta aula coincide com uma atividade do calendário');
        expect(block.text()).toContain('Teste de Física');
        expect(block.text()).toContain('parte da aula');
        expect(block.text()).toContain('Incluir no sumário');
        expect(block.text()).toContain('Registar e adiar a preparação');
        // A diferença entre as duas ações vem explicada numa frase.
        expect(block.text()).toContain('mantém o plano inteiro');

        const others = wrapper.get('[data-testid="other-events"]');

        expect(others.text()).toContain('Outros acontecimentos do dia');
        expect(others.text()).toContain('Reunião');
        expect(others.text()).toContain('Incluir no sumário');
        expect(others.text()).not.toContain('Registar e adiar a preparação');
    });

    it('usa o plural quando coincidem várias; um acontecimento de dia inteiro diz «Todo o dia» e não afirma que ocupa a aula', () => {
        const wrapper = mountPage({}, {}, [whole, partial]);

        expect(wrapper.get('[data-testid="coinciding-events"]').text()).toContain('Esta aula coincide com atividades do calendário');
        expect(wrapper.text()).toContain('Todo o dia');
        expect(wrapper.text()).toContain('cobre parte da aula');
        expect(wrapper.text()).not.toContain('ocupa a aula toda');
    });

    it('não oferece «Registar e adiar» quando a aula já não pode registar um resultado', () => {
        const wrapper = mountPage({ can_record_outcome: false }, {}, [partial]);

        expect(wrapper.text()).toContain('Incluir no sumário');
        expect(wrapper.find('[data-testid="postpone-preparation"]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Registar e adiar a preparação');
    });

    it('«Incluir no sumário» acrescenta uma só vez, sem gravar, e depois diz «Já no sumário»', async () => {
        const wrapper = mountPage({ summary: { content: 'Plano.', private_notes: null, resources: null, homework: null, reviewed_at: null } }, {}, [partial]);

        await wrapper.get('[data-testid="include-in-summary"]').trigger('click');
        await wrapper.get('[data-testid="include-in-summary"]').trigger('click');

        expect(summaryForm().content).toBe('Plano.\nTeste de Física');
        expect(summaryForm().put).not.toHaveBeenCalled();
        expect(wrapper.get('[data-testid="include-in-summary"]').text()).toContain('Já no sumário');
    });

    it('«Registar e adiar a preparação» abre o diálogo e, com texto por guardar, pede para guardar primeiro', async () => {
        const wrapper = mountPage({}, {}, [partial]);
        summaryForm().isDirty = true;

        await wrapper.get('[data-testid="postpone-preparation"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[data-testid="postpone-unsaved"]').text()).toContain('Tens alterações por guardar no sumário desta aula');
        // Com alterações por guardar nenhuma pré-visualização é pedida.
        expect(vi.mocked(fetch).mock.calls.some(([url]) => String(url).includes('activity-postponement'))).toBe(false);
        expect(wrapper.get('[data-testid="postpone-confirm"]').attributes('disabled')).toBeDefined();
    });
});

describe('lessons/Show — «Não houve aula» com texto por guardar e formulário refeito (0.171.0)', () => {
    async function openOutcomeDialog(wrapper: VueWrapper): Promise<void> {
        // O diálogo simulado só desenha o conteúdo aberto — e o botão que o abre
        // vive dentro dele —, por isso abre-se pelo v-model do próprio diálogo.
        wrapper.findComponent(LessonOutcomeDialog).vm.$emit('update:open', true);
        await nextTick();
    }

    it('com alterações por guardar o diálogo pede para guardar e não deixa registar', async () => {
        const wrapper = mountPage();
        summaryForm().isDirty = true;
        await openOutcomeDialog(wrapper);

        expect(wrapper.get('[data-testid="outcome-unsaved"]').text()).toContain(
            'Guarda-as primeiro: a preparação que passa para a aula seguinte é a que está guardada.',
        );

        const register = wrapper.findAll('button').find((button) => button.text() === 'Registar')!;

        expect(register.attributes('disabled')).toBeDefined();
    });

    it('sem alterações por guardar o diálogo não mostra a frase', async () => {
        const wrapper = mountPage();
        await openOutcomeDialog(wrapper);

        expect(wrapper.find('[data-testid="outcome-unsaved"]').exists()).toBe(false);
    });

    it('«Guardar alterações» no diálogo grava o sumário da página', async () => {
        const wrapper = mountPage();
        summaryForm().isDirty = true;
        await openOutcomeDialog(wrapper);

        await wrapper.get('[data-testid="outcome-save-first"]').trigger('click');

        expect(summaryForm().put).toHaveBeenCalled();
    });

    it('quando o resultado passa de nulo a um valor o formulário refaz-se a partir das props frescas', async () => {
        const wrapper = mountPage({
            summary: { content: 'Plano 2', private_notes: 'Nota', resources: null, homework: null, reviewed_at: null },
            summary_version: 4,
        });
        const lesson = wrapper.props('lesson');

        await wrapper.setProps({
            lesson: { ...lesson, outcome: 'class_external_activity', outcome_label: 'Turma em outras atividades letivas', can_record_outcome: false, summary: null, summary_version: 9 },
        });

        const form = summaryForm();

        expect(form.defaults).toHaveBeenCalledWith(
            expect.objectContaining({ content: '', private_notes: '', resources: '', homework: '', summary_version: 9 }),
        );
        expect(form.reset).toHaveBeenCalled();
    });

    it('não refaz o formulário enquanto o resultado não muda', async () => {
        const wrapper = mountPage();
        const lesson = wrapper.props('lesson');

        await wrapper.setProps({ lesson: { ...lesson, summary_version: 5 } });

        expect(summaryForm().reset).not.toHaveBeenCalled();
    });
});
