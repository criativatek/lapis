import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick, reactive } from 'vue';
import type { DayEvent } from '@/components/lessons/LessonDayEvents.vue';
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
            defaults: vi.fn(),
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
        const button = wrapper.findAll('button').find((candidate) => candidate.text().includes('Adicionar ao sumário'))!;

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
