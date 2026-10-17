/**
 * Aulas e Sumários (0.158.0) — três vistas sobre as MESMAS aulas, o sumário
 * completo por omissão, a identidade estável de cada turma, os filtros no URL,
 * a edição no cartão que grava só o sumário, o conflito com outra janela e a
 * guarda de alterações por guardar. Mais o fecho rápido e o lote, que vêm de
 * antes e não podem regredir.
 */
import { DOMWrapper, enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick } from 'vue';
import InsertLessonDialog from '@/components/lessons/InsertLessonDialog.vue';
import LessonOutcomeDialog from '@/components/lessons/LessonOutcomeDialog.vue';
import type { ActivitySessionItem } from '@/lib/activitySessions';
import { resetConfirmedSummaries } from '@/lib/confirmedSummaries';
import type { WeekLesson } from '@/lib/lessons';
import type { SummarySearchResponse, SummarySearchResult } from '@/lib/lessonSummarySearch';
import type { ClassViewData, TeacherClass } from '@/lib/lessonWeekView';
import Index from './Index.vue';

type VisitOptions = {
    onSuccess?: () => void;
    onError?: (errors: Record<string, string>) => void;
    onFinish?: () => void;
    onNetworkError?: () => boolean | void;
    onHttpException?: () => boolean | void;
    only?: string[];
};

const inertia = vi.hoisted(() => ({
    page: { url: '/lessons?week=2026-10-05', props: {} as Record<string, unknown> },
    router: {
        get: vi.fn(),
        post: vi.fn(),
        patch: vi.fn(),
        push: vi.fn(),
        replace: vi.fn(),
        reload: vi.fn(),
        visit: vi.fn(),
        on: vi.fn(() => vi.fn()),
    },
}));

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    const page = reactive(inertia.page);
    // As visitas do lado do cliente mudam o URL da página, como o Inertia faz.
    inertia.router.push.mockImplementation((options: { url: string }) => {
        page.url = options.url;
    });
    inertia.router.replace.mockImplementation((options: { url: string }) => {
        page.url = options.url;
    });
    inertia.router.get.mockImplementation((url: string) => {
        page.url = url;
    });
    inertia.page = page;

    return {
        Head: { template: '<div><slot /></div>' },
        Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
        router: inertia.router,
        usePage: () => page,
        useForm: () => ({
            errors: {},
            processing: false,
            post: vi.fn(),
            put: vi.fn(),
            delete: vi.fn(),
            clearErrors: vi.fn(),
            data: () => ({}),
            defaults: vi.fn(),
            transform: vi.fn(),
            reset: vi.fn(),
        }),
    };
});

// O menu real abre por pointerdown num portal; aqui tudo fica inline e o
// clique num item emite o `select` do reka-ui.
vi.mock('@/components/ui/dropdown-menu', () => {
    const passthrough = (tag: string) => defineComponent({ setup: (_, { slots }) => () => h(tag, slots.default?.()) });

    const item = (role: string) =>
        defineComponent({
            emits: ['select'],
            setup: (_, { slots, emit, attrs }) => () => h('div', { ...attrs, role, onClick: () => emit('select') }, slots.default?.()),
        });

    return {
        DropdownMenu: passthrough('div'),
        DropdownMenuContent: passthrough('div'),
        DropdownMenuTrigger: passthrough('div'),
        DropdownMenuLabel: passthrough('div'),
        DropdownMenuSeparator: passthrough('hr'),
        DropdownMenuRadioGroup: passthrough('div'),
        DropdownMenuItem: item('menuitem'),
        DropdownMenuRadioItem: item('menuitemradio'),
    };
});

// Os diálogos reais teleportam; aqui renderizam inline e só enquanto abertos.
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
        DialogTrigger: passthrough,
    };
});

const longSummary = 'Equações do primeiro grau.\nResolução de exercícios da página 32.\n\nTPC: exercícios 4 e 5.';

function makeLesson(overrides: Partial<WeekLesson> = {}): WeekLesson {
    return {
        ulid: 'lesson-a',
        starts_at: '2026-10-08T09:30:00+01:00',
        ends_at: '2026-10-08T10:20:00+01:00',
        school_class: { ulid: 'class-a', label: '7.º A', is_support_class: false },
        context_label: '7.º A',
        class_group_label: null,
        class_group_id: null,
        subject: 'Matemática',
        status: 'prepared',
        status_label: 'Preparada',
        has_summary: true,
        summary: longSummary,
        summary_version: 3,
        summary_reviewed: false,
        identity_tone: 'violet',
        day_events: [],
        lesson_number: 12,
        lesson_number_end: null,
        lesson_number_manual: false,
        outcome: null,
        outcome_label: null,
        can_delete: true,
        can_clear_summary: true,
        attendance_recorded: false,
        absent_count: null,
        ...overrides,
    };
}

const teacherClasses: TeacherClass[] = [
    { ulid: 'class-a', label: '7.º A', subject: 'Matemática', is_support_class: false, archived: false, identity_tone: 'violet', groups: [] },
    {
        ulid: 'class-b',
        label: '8.º B',
        subject: 'Matemática',
        is_support_class: false,
        archived: false,
        identity_tone: 'emerald',
        groups: [
            { id: 1, label: 'T1' },
            { id: 2, label: 'T2' },
        ],
    },
];

function mountPage(
    lessons: WeekLesson[] = [makeLesson()],
    options: {
        url?: string;
        classView?: ClassViewData | null;
        classes?: TeacherClass[];
        attach?: boolean;
        activitySessions?: ActivitySessionItem[];
    } = {},
) {
    inertia.page.url = options.url ?? '/lessons?week=2026-10-05';

    return mount(Index, {
        props: {
            lessons,
            activitySessions: options.activitySessions ?? [],
            week: { start: '2026-10-05', end: '2026-10-11' },
            academicYear: '2026/2027',
            configuredClassesCount: 1,
            today: '2026-10-08',
            insertableClasses: [],
            absenceReasons: [{ value: 'training', label: 'Formação' }],
            classes: options.classes ?? teacherClasses,
            classView: options.classView ?? null,
        },
        global: { stubs: { EmptyState: true, teleport: !options.attach } },
        attachTo: options.attach ? document.body : undefined,
    });
}

function button(wrapper: ReturnType<typeof mountPage>, text: string) {
    return wrapper.findAll('button').find((candidate) => candidate.text().trim() === text);
}

beforeEach(() => {
    window.localStorage.clear();
    window.sessionStorage.clear();
    resetConfirmedSummaries();
    Object.values(inertia.router).forEach((mock) => mock.mockClear());
    // Relógio congelado: quinta-feira, 08/10/2026, 11:00 em Lisboa.
    vi.useFakeTimers({ toFake: ['Date', 'setInterval', 'clearInterval'] });
    vi.setSystemTime(new Date('2026-10-08T11:00:00+01:00'));
});

afterEach(() => {
    vi.useRealTimers();
});

// Cada página montada reage ao URL partilhado do mock do Inertia: sem isto, as
// páginas dos testes anteriores continuavam vivas e a fazer os seus pedidos.
enableAutoUnmount(afterEach);

/** A revalidação ao montar: uma visita SÍNCRONA ao próprio URL, só das aulas. */
function expectRevalidation(): void {
    expect(inertia.router.reload).not.toHaveBeenCalled();
    expect(inertia.router.get).toHaveBeenCalledWith(
        inertia.page.url,
        {},
        // `preserveUrl`: uma pesquisa iniciada entretanto não pode ser desfeita pela resposta.
        expect.objectContaining({ only: ['lessons', 'activitySessions', 'classView'], preserveState: true, preserveScroll: true, preserveUrl: true, replace: true }),
    );
}

describe('vista Semana — ler os sumários sem abrir aulas', () => {
    it('mostra o sumário completo por omissão, com parágrafos e quebras de linha', () => {
        const wrapper = mountPage();
        const summary = wrapper.get('[data-testid="lesson-summary"]');

        expect(summary.attributes('data-density')).toBe('completo');
        const paragraphs = summary.findAll('p');
        expect(paragraphs).toHaveLength(2);
        expect(paragraphs[0].text()).toContain('Equações do primeiro grau.');
        expect(paragraphs[0].classes()).toContain('whitespace-pre-line');
        expect(paragraphs[1].text()).toBe('TPC: exercícios 4 e 5.');
        // Nenhuma caixa com scroll interno.
        expect(summary.classes().join(' ')).not.toMatch(/overflow-(y-)?(auto|scroll)/);
    });

    it('«Compacto» passa a uma linha recortada, sem scroll, e fica no URL', async () => {
        const wrapper = mountPage();

        await wrapper.get('[data-testid="density-compact"]').trigger('click');

        expect(inertia.router.replace).toHaveBeenCalledWith(expect.objectContaining({ url: '/lessons?week=2026-10-05&view=semana&density=compacto' }));
        const summary = wrapper.get('[data-testid="lesson-summary"]');
        expect(summary.attributes('data-density')).toBe('compacto');
        expect(summary.classes()).toContain('line-clamp-2');
        expect(summary.text()).toContain('Equações do primeiro grau. · Resolução de exercícios da página 32. · TPC');
    });

    it('mostra o número da lição', () => {
        expect(mountPage().get('[data-testid="lesson-number"]').text()).toBe('Lição 12');
    });

    it('identifica a turma inteira, os grupos e a turma de apoio por extenso, nunca só pela cor', () => {
        const wrapper = mountPage([
            makeLesson(),
            makeLesson({
                ulid: 'lesson-t1',
                school_class: { ulid: 'class-b', label: '8.º B', is_support_class: false },
                class_group_id: 1,
                class_group_label: 'T1',
                context_label: '8.º B · T1',
            }),
            makeLesson({ ulid: 'lesson-apoio', school_class: { ulid: 'class-c', label: 'Apoio 7.º', is_support_class: true }, context_label: 'Apoio 7.º' }),
        ]);
        const scopes = wrapper
            .findAll('[data-testid="lesson-scope"]')
            .map((scope) => [scope.text(), scope.attributes('data-scope'), scope.classes().join(' ')]);

        expect(scopes[0]).toEqual(['Turma inteira', 'whole', expect.stringContaining('border-solid')]);
        expect(scopes[1]).toEqual(['Grupo T1', 'group', expect.stringContaining('border-dashed')]);
        expect(scopes[2]).toEqual(['Turma de apoio', 'support', expect.stringContaining('border-double')]);
    });

    it('usa o tom GUARDADO da turma, o mesmo para a mesma turma em todas as aulas', () => {
        const wrapper = mountPage([
            makeLesson(),
            makeLesson({ ulid: 'lesson-b', starts_at: '2026-10-09T09:30:00+01:00', ends_at: '2026-10-09T10:20:00+01:00' }),
        ]);
        const tones = wrapper.findAll('[data-testid="lesson-class-tag"]').map((tag) => tag.attributes('data-tone'));

        expect(tones).toEqual(['violet', 'violet']);
    });

    it('separa o estado (pílula) da presença do sumário: lecionada sem sumário diz o motivo', () => {
        const wrapper = mountPage([
            makeLesson({ status: 'taught', status_label: 'Lecionada', outcome: 'taught', outcome_label: 'Lecionada', has_summary: false, summary: null }),
        ]);

        expect(wrapper.get('[data-testid="lesson-state"]').text()).toBe('Lecionada');
        expect(wrapper.get('[data-testid="lesson-summary-empty"]').text()).toBe(
            'Sem sumário. A aula já foi lecionada e ainda não tem registo do que foi dado.',
        );
        // O «✓ Sumário» que repetia «Preparada» desapareceu.
        expect(wrapper.text()).not.toMatch(/✓ Sumário/);
    });

    it('identifica aulas simultâneas', () => {
        const wrapper = mountPage([
            makeLesson({
                ulid: 'lesson-t1',
                school_class: { ulid: 'class-b', label: '8.º B', is_support_class: false },
                class_group_id: 1,
                class_group_label: 'T1',
                context_label: '8.º B · T1',
            }),
            makeLesson({
                ulid: 'lesson-t2',
                school_class: { ulid: 'class-b', label: '8.º B', is_support_class: false },
                class_group_id: 2,
                class_group_label: 'T2',
                context_label: '8.º B · T2',
            }),
        ]);
        const simultaneous = wrapper.findAll('[data-testid="lesson-simultaneous"]').map((line) => line.text());

        expect(simultaneous).toContain('Em simultâneo com 8.º B · T2');
        expect(simultaneous).toContain('Em simultâneo com 8.º B · T1');
    });

    it('mostra os acontecimentos do dia no cartão', () => {
        const wrapper = mountPage([
            makeLesson({
                day_events: [{ ulid: 'event-a', title: 'Visita de estudo', starts_at: null, ends_at: null, all_day: true, notes: null, type_label: 'Visita de estudo' }],
            }),
        ]);

        expect(wrapper.get('[data-testid="lesson-day-event"]').text()).toContain('Todo o dia');
    });

    it('oferece atalhos para os dias, com hoje assinalado', () => {
        const wrapper = mountPage([
            makeLesson(),
            makeLesson({ ulid: 'lesson-b', starts_at: '2026-10-09T09:30:00+01:00', ends_at: '2026-10-09T10:20:00+01:00' }),
        ]);
        const nav = wrapper.get('[data-testid="lesson-day-nav"]');
        const labels = nav.findAll('button').map((item) => item.attributes('aria-label'));

        expect(labels).toEqual(['Quinta-feira, 8 de outubro (hoje)', 'Sexta-feira, 9 de outubro']);
        expect(wrapper.find('#dia-2026-10-08').exists()).toBe(true);
    });

    it('lembra de onde se saiu ao abrir uma aula, para o «Voltar» regressar aqui', async () => {
        const wrapper = mountPage(undefined, { url: '/lessons?week=2026-10-05&states=prepared' });

        await wrapper.get('[data-testid="open-lesson"]').trigger('click');

        expect(JSON.parse(window.sessionStorage.getItem('lapis.lessons.return') ?? '{}')).toEqual({
            url: '/lessons?week=2026-10-05&states=prepared',
            lesson: 'lesson-a',
        });
    });
});

describe('filtros — turmas à vista, o resto recolhível, sempre identificados', () => {
    const lessons = () => [
        makeLesson(),
        makeLesson({
            ulid: 'lesson-b',
            school_class: { ulid: 'class-b', label: '8.º B', is_support_class: false },
            context_label: '8.º B',
            identity_tone: 'emerald',
            status: 'taught',
            status_label: 'Lecionada',
            outcome: 'taught',
            outcome_label: 'Lecionada',
        }),
    ];

    it('filtrar por turma muda só o URL (sem ir ao servidor)', async () => {
        const wrapper = mountPage(lessons());

        await wrapper.get('[data-testid="filter-class-class-b"]').trigger('click');

        expect(inertia.router.replace).toHaveBeenCalledWith(expect.objectContaining({ url: '/lessons?week=2026-10-05&view=semana&classes=class-b' }));
        expect(inertia.router.get).not.toHaveBeenCalled();
        expect(wrapper.findAll('[data-testid="lesson-card"]').map((card) => card.attributes('data-lesson'))).toEqual(['lesson-b']);
    });

    it('os filtros secundários estão recolhidos, mas um filtro ativo aparece sempre como pílula removível', async () => {
        const wrapper = mountPage(lessons(), { url: '/lessons?week=2026-10-05&states=taught' });

        expect(wrapper.get('[data-testid="filters-more-toggle"]').attributes('aria-expanded')).toBe('false');
        expect(wrapper.get('[data-testid="filters-more-toggle"]').text()).toContain('1');
        const active = wrapper.get('[data-testid="active-filters"]');
        expect(active.text()).toContain('A mostrar 1 de 2 aulas desta semana');
        expect(active.text()).toContain('Lecionada');

        await active.get('button[aria-label="Remover o filtro Lecionada"]').trigger('click');

        expect(inertia.router.replace).toHaveBeenLastCalledWith(expect.objectContaining({ url: '/lessons?week=2026-10-05&view=semana' }));
    });

    it('«Limpar filtros» tira todos', async () => {
        const wrapper = mountPage(lessons(), { url: '/lessons?week=2026-10-05&classes=class-a&summary=sem' });

        await wrapper.get('[data-testid="clear-filters"]').trigger('click');

        expect(inertia.router.replace).toHaveBeenLastCalledWith(expect.objectContaining({ url: '/lessons?week=2026-10-05&view=semana' }));
    });
});

describe('vista Por turma — a sequência de uma turma', () => {
    const classView = (overrides: Partial<ClassViewData> = {}): ClassViewData => ({
        class: { ulid: 'class-b', label: '8.º B', subject: 'Matemática', is_support_class: false, identity_tone: 'emerald', groups: teacherClasses[1].groups },
        group: 'todos',
        range: { key: '1', start: '2026-10-05', end: '2026-10-11', clamped: false },
        lessons: [
            makeLesson({
                ulid: 'lesson-t1',
                school_class: { ulid: 'class-b', label: '8.º B', is_support_class: false },
                class_group_id: 1,
                class_group_label: 'T1',
                context_label: '8.º B · T1',
                identity_tone: 'emerald',
            }),
        ],
        previous: [
            { group_id: null, group_label: null, lesson: null, state: null, state_label: null },
            {
                group_id: 1,
                group_label: 'T1',
                state: 'prepared',
                state_label: 'Preparada — por lecionar',
                lesson: makeLesson({
                    ulid: 'prev-t1',
                    summary: 'Anterior do T1.',
                    lesson_number: 11,
                    starts_at: '2026-10-01T09:30:00+01:00',
                    ends_at: '2026-10-01T10:20:00+01:00',
                }),
            },
            { group_id: 2, group_label: 'T2', lesson: null, state: null, state_label: null },
        ],
        ...overrides,
    });

    it('passar à vista por turma pede ao servidor só a vista por turma', async () => {
        const wrapper = mountPage();

        await button(wrapper, 'Por turma')!.trigger('click');

        expect(inertia.router.get).toHaveBeenCalledWith(
            '/lessons?week=2026-10-05&view=turma',
            {},
            expect.objectContaining({ only: ['classView', 'classes'], preserveState: true }),
        );
    });

    it('com todos os grupos, mostra o anterior de cada grupo num bloco que se recolhe', async () => {
        const wrapper = mountPage([], { url: '/lessons?week=2026-10-05&view=turma&class=class-b', classView: classView() });

        const lanes = wrapper.findAll('[data-testid="class-view-previous-lane"]');
        expect(lanes).toHaveLength(3);
        expect(lanes[1].text()).toContain('Anterior do T1.');
        expect(lanes[0].text()).toContain('Sem sumário anterior');
        expect(lanes[1].text()).toContain('Preparada — por lecionar');

        const toggle = wrapper.get('[data-testid="class-view-previous-toggle"]');
        expect(toggle.attributes('aria-expanded')).toBe('true');

        await toggle.trigger('click');

        expect(toggle.attributes('aria-expanded')).toBe('false');
        expect(window.localStorage.getItem('lapis.lessons.previousCollapsed')).toBe('1');
    });

    it('com um grupo escolhido, privilegia o anterior desse grupo, sozinho', () => {
        const wrapper = mountPage([], {
            url: '/lessons?week=2026-10-05&view=turma&class=class-b&group=1',
            classView: classView({ group: '1', previous: [classView().previous[1]] }),
        });

        expect(wrapper.find('[data-testid="class-view-previous-toggle"]').exists()).toBe(false);
        expect(wrapper.get('#class-view-previous').text()).toContain('Antes de 5/10 — último sumário de Grupo T1');
        // O bloco é o anterior ao INÍCIO DO INTERVALO e diz em que estado está.
        expect(wrapper.get('[data-testid="previous-state"]').text()).toBe('Preparada — por lecionar');
        expect(wrapper.findAll('[data-testid="class-view-previous-lane"]')).toHaveLength(1);
    });

    it('alargar o intervalo pede ao servidor (que só lê) o intervalo seguinte', async () => {
        const wrapper = mountPage([], { url: '/lessons?week=2026-10-05&view=turma&class=class-b', classView: classView() });

        await wrapper.get('[data-testid="class-view-widen"]').trigger('click');

        expect(inertia.router.get).toHaveBeenCalledWith(
            '/lessons?week=2026-10-05&view=turma&class=class-b&range=2',
            {},
            expect.objectContaining({ only: ['classView', 'classes'] }),
        );
    });

    it('mostra os cartões da turma com o número da lição em destaque', () => {
        const wrapper = mountPage([], { url: '/lessons?week=2026-10-05&view=turma&class=class-b', classView: classView() });

        expect(wrapper.get('[data-testid="lesson-card"]').text()).toContain('Lição 12');
        expect(wrapper.get('[data-testid="class-view-sequence"]').text()).toContain('L12');
    });
});

describe('vista Horário — horas reais, tempos livres e simultâneas', () => {
    const week = () => [
        makeLesson({ ulid: 'a', starts_at: '2026-10-08T08:20:00+01:00', ends_at: '2026-10-08T09:10:00+01:00' }),
        makeLesson({ ulid: 'b', starts_at: '2026-10-08T10:20:00+01:00', ends_at: '2026-10-08T11:10:00+01:00' }),
        makeLesson({
            ulid: 'c',
            starts_at: '2026-10-08T10:20:00+01:00',
            ends_at: '2026-10-08T11:10:00+01:00',
            school_class: { ulid: 'class-b', label: '8.º B', is_support_class: false },
            context_label: '8.º B · T2',
            class_group_id: 2,
            class_group_label: 'T2',
        }),
    ];

    it('as linhas são as horas reais e os intervalos dizem «Sem aulas no horário do professor»', () => {
        const wrapper = mountPage(week(), { url: '/lessons?week=2026-10-05&view=horario' });

        expect(wrapper.findAll('[data-testid="timetable-time"]').map((cell) => cell.text())).toEqual(['08:20', '10:20']);
        expect(wrapper.get('[data-testid="timetable-grid"] [data-testid="timetable-free"]').text()).toBe(
            '09:10–10:20 · Sem aulas no horário do professor',
        );
    });

    it('no telemóvel agrupa as simultâneas sob um cabeçalho horário comum, cada uma separada', () => {
        const wrapper = mountPage(week(), { url: '/lessons?week=2026-10-05&view=horario' });
        const group = wrapper.get('[data-testid="timetable-day-list"] [data-testid="timetable-simultaneous"]');

        expect(group.text()).toContain('10:20–11:10 · 2 aulas em simultâneo');
        expect(group.findAll('[data-testid="timetable-block"]')).toHaveLength(2);
    });

    it('«Ver sumário completo» leva ao cartão na vista Semana e permite voltar ao horário', async () => {
        const wrapper = mountPage(week(), { url: '/lessons?week=2026-10-05&view=horario' });

        await wrapper.get('[data-testid="timetable-grid"] [data-testid="timetable-read"]').trigger('click');

        expect(inertia.router.push).toHaveBeenCalledWith(expect.objectContaining({ url: '/lessons?week=2026-10-05&view=semana' }));
        await nextTick();
        expect(wrapper.find('#aula-a').exists()).toBe(true);
        expect(wrapper.get('[data-testid="back-to-timetable"]').text()).toContain('Voltar ao horário');
    });

    it('os filtros esbatem as aulas no horário em vez de as tirar', () => {
        const wrapper = mountPage(week(), { url: '/lessons?week=2026-10-05&view=horario&classes=class-b' });
        const grid = wrapper.get('[data-testid="timetable-grid"]');

        expect(grid.findAll('[data-testid="timetable-block"]')).toHaveLength(3);
        expect(grid.findAll('[data-testid="timetable-block"][data-dimmed="true"]')).toHaveLength(2);
    });
});

describe('editar o sumário no cartão — grava só o sumário', () => {
    async function openEditor(lesson = makeLesson()) {
        const wrapper = mountPage([lesson]);
        await wrapper.get(`[data-testid="edit-${lesson.ulid}"]`).trigger('click');
        await nextTick();

        return wrapper;
    }

    const textarea = (wrapper: ReturnType<typeof mountPage>) => wrapper.get('[data-testid="summary-editor"] textarea');
    const value = (wrapper: ReturnType<typeof mountPage>) => (textarea(wrapper).element as HTMLTextAreaElement).value;

    it('abre o editor no cartão e indica as alterações pendentes', async () => {
        const wrapper = await openEditor();

        expect(value(wrapper)).toBe(longSummary);
        expect(wrapper.get('[data-testid="summary-editor-status"]').text()).toBe('Sem alterações');

        await textarea(wrapper).setValue(`${longSummary}\nMais uma linha.`);

        expect(wrapper.get('[data-testid="summary-editor-status"]').text()).toContain('Alterações por guardar');
    });

    it('Guardar envia SÓ o texto e a versão lida, para a rota do sumário', async () => {
        const wrapper = await openEditor();
        await textarea(wrapper).setValue('Novo sumário.');

        await wrapper.get('[data-testid="summary-save"]').trigger('click');

        expect(inertia.router.patch).toHaveBeenCalledOnce();
        const [url, payload, options] = inertia.router.patch.mock.calls[0] as unknown as [string, Record<string, unknown>, VisitOptions];
        expect(url).toBe('/lessons/lesson-a/summary/content');
        expect(payload).toEqual({ content: 'Novo sumário.', summary_version: 3 });
        expect(options.only).toEqual(['lessons', 'activitySessions', 'classView']);
    });

    it('recusa guardar vazio, com a explicação de «Limpar sumário»', async () => {
        const wrapper = await openEditor();
        await textarea(wrapper).setValue('   ');

        await wrapper.get('[data-testid="summary-save"]').trigger('click');

        expect(inertia.router.patch).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain('usa «Limpar sumário» na página da aula');
    });

    it('uma falha de rede deixa o texto no editor, por gravar', async () => {
        const wrapper = await openEditor();
        await textarea(wrapper).setValue('Texto por gravar.');
        await wrapper.get('[data-testid="summary-save"]').trigger('click');
        const options = inertia.router.patch.mock.calls[0][2] as VisitOptions;

        options.onNetworkError?.();
        options.onFinish?.();
        await nextTick();

        expect(value(wrapper)).toBe('Texto por gravar.');
        expect(wrapper.text()).toContain('a ligação falhou');
    });

    it('um conflito de versão mostra os dois textos, e combinar mantém o rascunho e adota a versão vista', async () => {
        const wrapper = await openEditor();
        await textarea(wrapper).setValue('O meu rascunho.');
        await wrapper.get('[data-testid="summary-save"]').trigger('click');
        const options = inertia.router.patch.mock.calls[0][2] as VisitOptions;

        // O servidor recusou e a página recarregou a aula com o texto e a versão novos.
        await wrapper.setProps({ lessons: [makeLesson({ summary: 'Gravado noutra janela.', summary_version: 4 })] });
        options.onError?.({ summary_version: 'O sumário desta aula foi alterado noutra janela depois de o abrires.' });
        options.onFinish?.();
        await nextTick();
        await nextTick();

        const conflict = wrapper.get('[data-testid="summary-conflict"]');
        expect(conflict.text()).toContain('O meu rascunho.');
        expect(conflict.text()).toContain('Gravado noutra janela.');
        // Substituir o gravado nunca é a ação principal.
        expect(conflict.findAll('button')[0].text()).toContain('Combinar no editor');

        await conflict.get('[data-testid="conflict-combine"]').trigger('click');

        expect(value(wrapper)).toBe('O meu rascunho.\nGravado noutra janela.');

        await wrapper.get('[data-testid="summary-save"]').trigger('click');

        // A nova gravação volta a ser verificada — já com a versão que se viu.
        expect(inertia.router.patch.mock.calls[1][1]).toEqual({ content: 'O meu rascunho.\nGravado noutra janela.', summary_version: 4 });
    });

    it('mudar de vista com alterações por guardar pergunta, e «Guardar e continuar» só continua depois de gravar', async () => {
        const wrapper = await openEditor();
        await textarea(wrapper).setValue('Por gravar.');

        await button(wrapper, 'Horário')!.trigger('click');

        expect(inertia.router.push).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain('Tens alterações por guardar');

        await wrapper.get('[data-testid="unsaved-save"]').trigger('click');
        const options = inertia.router.patch.mock.calls[0][2] as VisitOptions;

        expect(inertia.router.push).not.toHaveBeenCalled();

        options.onSuccess?.();
        options.onFinish?.();
        await nextTick();
        await nextTick();

        expect(inertia.router.push).toHaveBeenCalledWith(expect.objectContaining({ url: '/lessons?week=2026-10-05&view=horario' }));
    });

    it('«Guardar e continuar» que falha fica onde estava, com o texto', async () => {
        const wrapper = await openEditor();
        await textarea(wrapper).setValue('Por gravar.');
        await button(wrapper, 'Horário')!.trigger('click');
        await wrapper.get('[data-testid="unsaved-save"]').trigger('click');
        const options = inertia.router.patch.mock.calls[0][2] as VisitOptions;

        options.onNetworkError?.();
        options.onFinish?.();
        await nextTick();
        await nextTick();

        expect(inertia.router.push).not.toHaveBeenCalled();
        expect(value(wrapper)).toBe('Por gravar.');
    });

    it('recarregar ou fechar com alterações por guardar usa a proteção nativa do browser', async () => {
        const wrapper = await openEditor();
        await textarea(wrapper).setValue('Por gravar.');
        const event = new Event('beforeunload', { cancelable: true });

        window.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(true);
        wrapper.unmount();
    });
});

describe('navegação entre semanas', () => {
    it('«Semana anterior» pede a semana anterior, mantendo a vista e os filtros', async () => {
        const wrapper = mountPage(undefined, { url: '/lessons?week=2026-10-05&states=prepared' });

        await wrapper.get('button[aria-label="Semana anterior"]').trigger('click');

        expect(inertia.router.get).toHaveBeenCalledWith(
            '/lessons?week=2026-09-28&view=semana&states=prepared',
            {},
            expect.objectContaining({ preserveState: true }),
        );
    });
});

/** 0.146.1 e 0.147.0 — o resultado e o fecho rápido continuam iguais. */
describe('estado, fecho rápido e lote (inalterados)', () => {
    const started = () => makeLesson({ ulid: 'started', starts_at: '2026-10-08T10:40:00+01:00', ends_at: '2026-10-08T11:30:00+01:00' });
    const ended = () => makeLesson({ ulid: 'ended' });
    const future = () => makeLesson({ ulid: 'future', starts_at: '2026-10-08T14:00:00+01:00', ends_at: '2026-10-08T14:50:00+01:00' });
    const pastDay = () => makeLesson({ ulid: 'past', starts_at: '2026-10-06T09:30:00+01:00', ends_at: '2026-10-06T10:20:00+01:00' });
    const closed = () => makeLesson({ ulid: 'closed', status: 'taught', status_label: 'Lecionada', outcome: 'taught', outcome_label: 'Lecionada' });

    const quickButtons = (wrapper: ReturnType<typeof mountPage>) =>
        wrapper.findAll('[data-testid="quick-mark-taught"]').map((element) => element.attributes('aria-label'));

    it('uma aula com resultado registado mostra só o resultado, na semana e no horário', async () => {
        const wrapper = mountPage([
            makeLesson({ outcome: 'teacher_absent', outcome_label: 'Professor ausente' }),
            makeLesson({
                ulid: 'lesson-b',
                status: 'preparation',
                status_label: 'Por preparar',
                outcome: 'class_external_activity',
                outcome_label: 'Turma em outras atividades letivas',
            }),
        ]);
        const states = () => wrapper.findAll('[data-testid="lesson-state"]').map((badge) => badge.text());

        expect(states()).toEqual(['Professor ausente', 'Turma em outras atividades letivas']);
        expect(wrapper.findAll('[data-testid="lesson-state"]')[0].classes()).toContain('bg-amber-100');

        await button(wrapper, 'Horário')!.trigger('click');

        expect([...new Set(states())]).toEqual(['Professor ausente', 'Turma em outras atividades letivas']);
    });

    it('uma aula Preparada já começada oferece «Lecionada» com nome acessível', () => {
        const wrapper = mountPage([started()]);

        expect(quickButtons(wrapper)).toEqual(['Marcar a aula de 7.º A (10:40–11:30) como lecionada']);
        expect(wrapper.find('[data-testid="lesson-attention"]').exists()).toBe(false);
    });

    it('uma aula futura ou fechada não oferece ação', () => {
        const wrapper = mountPage([future(), closed()]);

        expect(quickButtons(wrapper)).toEqual([]);
        expect(wrapper.find('[data-testid="lesson-attention"]').exists()).toBe(false);
    });

    it('uma aula terminada hoje ou num dia anterior pede confirmação, com ícone e texto', () => {
        const wrapper = mountPage([pastDay(), ended()]);
        const warnings = wrapper.findAll('[data-testid="lesson-attention"]');

        expect(warnings).toHaveLength(2);
        expect(warnings[0].text()).toBe('Aula terminada · Confirmar estado');
        expect(warnings[0].find('svg').exists()).toBe(true);
    });

    it('«Lecionada» publica para o endpoint da aula, preservando o scroll', async () => {
        const wrapper = mountPage([started()]);

        await wrapper.get('[data-testid="quick-mark-taught"]').trigger('click');

        expect(inertia.router.post).toHaveBeenCalledWith('/lessons/started/mark-taught', {}, expect.objectContaining({ preserveScroll: true }));
    });

    it('escolher «Turma em outras atividades letivas» abre o diálogo de resultado para aquela aula', async () => {
        const wrapper = mountPage([started()]);
        const dialog = () => wrapper.findComponent(LessonOutcomeDialog);

        await wrapper.get('[data-testid="quick-external-activity"]').trigger('click');

        expect(dialog().props('open')).toBe(true);
        expect(dialog().props('lessonUlid')).toBe('started');
        expect(dialog().props('initialOutcome')).toBe('class_external_activity');
        expect(dialog().props('lessonContext')).toBe('7.º A · 10:40–11:30');
    });

    it('a seleção só aceita aulas fecháveis, nas duas vistas, sem «Selecionar todas»', async () => {
        const wrapper = mountPage([started(), ended(), future(), closed()]);

        // «Selecionar aulas» vive em «Mais ações» (0.162.0).
        const toggle = wrapper.get('[data-testid="selection-toggle"]');
        expect(toggle.text()).toContain('Selecionar aulas');
        await toggle.trigger('click');

        const checkboxes = () => wrapper.findAll('[aria-label^="Selecionar a aula"]');
        expect(checkboxes()).toHaveLength(2);
        expect(wrapper.text()).not.toContain('Selecionar todas');
        expect(wrapper.get('[data-testid="quick-batch-confirm"]').attributes('disabled')).toBeDefined();

        await button(wrapper, 'Horário')!.trigger('click');

        // O horário desenha cada aula duas vezes (grelha e dia no telemóvel).
        expect(checkboxes()).toHaveLength(4);
    });

    it('a semana nunca mostra «Preparado» nem «Lecionado»', async () => {
        const wrapper = mountPage([started(), closed(), future()]);

        expect(wrapper.text()).not.toMatch(/Preparado|Lecionado/);

        await button(wrapper, 'Horário')!.trigger('click');

        expect(wrapper.text()).not.toMatch(/Preparado|Lecionado/);
    });
});

describe('a vista vai sempre no URL', () => {
    it('um URL sem vista (entrada nova) aplica a última vista escolhida neste browser', () => {
        window.localStorage.setItem('lapis.lessons.view', 'horario');

        mountPage([makeLesson()], { url: '/lessons?week=2026-10-05' });

        expect(inertia.router.replace).toHaveBeenCalledWith(expect.objectContaining({ url: '/lessons?week=2026-10-05&view=horario' }));
    });

    it('um URL que diz a vista — também a Semana — manda sobre a vista guardada', () => {
        window.localStorage.setItem('lapis.lessons.view', 'horario');

        const wrapper = mountPage([makeLesson()], { url: '/lessons?week=2026-10-05&view=semana' });

        expect(inertia.router.replace).not.toHaveBeenCalled();
        expect(wrapper.find('[data-testid="lesson-card"]').exists()).toBe(true);
    });
});

describe('projetar o sumário — só de leitura, na sala', () => {
    // A projeção teleporta para o <body>: aqui o portal é real (sem o stub) e procura-se lá.
    const projection = () => new DOMWrapper(document.body).find('[data-testid="lesson-projection"]');
    // `router.on` só regista um ouvinte ao montar; tudo o resto seria uma visita ou um pedido.
    const networkCalls = () =>
        Object.entries(inertia.router)
            .filter(([name]) => name !== 'on')
            .reduce((total, [, mock]) => total + mock.mock.calls.length, 0);

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('«Projetar sumário» abre a projeção com o conteúdo certo, sem pedidos nem mudar o URL', async () => {
        const wrapper = mountPage([makeLesson()], { attach: true });
        const url = inertia.page.url;
        const before = networkCalls();

        await wrapper.get('[data-testid="project-lesson-a"]').trigger('click');
        await nextTick();

        const dialog = projection();
        expect(dialog.exists()).toBe(true);
        expect(dialog.get('[data-testid="projection-context"]').text()).toContain('7.º A · Matemática');
        expect(dialog.get('[data-testid="projection-date"]').text()).toBe('Quinta-feira, 8 de outubro de 2026');
        expect(dialog.get('[data-testid="projection-number"]').text()).toBe('Lição 12');
        expect(dialog.get('[data-testid="projection-summary"]').findAll('p')).toHaveLength(2);
        expect(dialog.find('[data-testid="projection-unsaved"]').exists()).toBe(false);
        expect(inertia.page.url).toBe(url);
        expect(networkCalls()).toBe(before);

        await dialog.get('[data-testid="projection-larger"]').trigger('click');
        expect(networkCalls()).toBe(before);
        wrapper.unmount();
    });

    it('fechar devolve o foco ao botão que abriu, sem pedidos', async () => {
        const wrapper = mountPage([makeLesson()], { attach: true });
        const trigger = wrapper.get('[data-testid="project-lesson-a"]');
        (trigger.element as HTMLElement).focus();

        await trigger.trigger('click');
        await nextTick();
        await nextTick();
        await projection().get('[data-testid="projection-close"]').trigger('click');
        await nextTick();
        await nextTick();

        expect(projection().exists()).toBe(false);
        expect(document.activeElement).toBe(trigger.element);
        expect(networkCalls()).toBe(0);
        wrapper.unmount();
    });

    it('com o editor aberto e texto alterado mostra o rascunho «Por guardar», sem gravar nada', async () => {
        const draft = 'Rascunho novo.\n\nSegundo bloco.';
        const wrapper = mountPage([makeLesson()], { attach: true });
        await wrapper.get('[data-testid="edit-lesson-a"]').trigger('click');
        await nextTick();
        await wrapper.get('[data-testid="summary-editor"] textarea').setValue(draft);

        await wrapper.get('[data-testid="summary-project"]').trigger('click');
        await nextTick();

        const dialog = projection();
        expect(dialog.get('[data-testid="projection-summary"]').text()).toContain('Rascunho novo.');
        expect(dialog.get('[data-testid="projection-unsaved"]').text()).toContain('Por guardar');

        await dialog.get('[data-testid="projection-close"]').trigger('click');
        await nextTick();

        expect(projection().exists()).toBe(false);
        expect((wrapper.get('[data-testid="summary-editor"] textarea').element as HTMLTextAreaElement).value).toBe(draft);
        expect(wrapper.get('[data-testid="summary-editor-status"]').text()).toContain('Alterações por guardar');
        expect(networkCalls()).toBe(0);
        wrapper.unmount();
    });

    it('com o editor aberto mas sem alterações projeta o texto guardado', async () => {
        const wrapper = mountPage([makeLesson()], { attach: true });
        await wrapper.get('[data-testid="edit-lesson-a"]').trigger('click');
        await nextTick();

        await wrapper.get('[data-testid="summary-project"]').trigger('click');
        await nextTick();

        expect(projection().find('[data-testid="projection-unsaved"]').exists()).toBe(false);
        expect(projection().get('[data-testid="projection-summary"]').findAll('p')).toHaveLength(2);
        wrapper.unmount();
    });

    it('no Horário abre a projeção e a vista continua o Horário', async () => {
        const wrapper = mountPage([makeLesson()], { url: '/lessons?week=2026-10-05&view=horario', attach: true });

        await wrapper.get('[data-testid="timetable-project"]').trigger('click');
        await nextTick();

        expect(projection().exists()).toBe(true);
        expect(projection().get('[data-testid="projection-number"]').text()).toBe('Lição 12');
        expect(inertia.page.url).toContain('view=horario');
        expect(networkCalls()).toBe(0);
        wrapper.unmount();
    });

    it('no bloco «Últimos sumários» da vista Por turma projeta essa aula anterior', async () => {
        const wrapper = mountPage([], {
            url: '/lessons?week=2026-10-05&view=turma&class=class-b',
            attach: true,
            classView: {
                class: { ulid: 'class-b', label: '8.º B', subject: 'Matemática', is_support_class: false, identity_tone: 'emerald', groups: teacherClasses[1].groups },
                group: 'todos',
                range: { key: '1', start: '2026-10-05', end: '2026-10-11', clamped: false },
                lessons: [],
                previous: [
                    {
                        group_id: 1,
                        group_label: 'T1',
                        state: 'taught',
                        state_label: 'Lecionada',
                        lesson: makeLesson({
                            ulid: 'prev-t1',
                            summary: 'Anterior do T1.',
                            lesson_number: 11,
                            school_class: { ulid: 'class-b', label: '8.º B', is_support_class: false },
                            class_group_id: 1,
                            class_group_label: 'T1',
                            starts_at: '2026-10-01T09:30:00+01:00',
                            ends_at: '2026-10-01T10:20:00+01:00',
                        }),
                    },
                ],
            },
        });

        await wrapper.get('[data-testid="previous-project-prev-t1"]').trigger('click');
        await nextTick();

        const dialog = projection();
        expect(dialog.get('[data-testid="projection-context"]').text()).toContain('8.º B · Matemática · Grupo T1');
        expect(dialog.get('[data-testid="projection-number"]').text()).toBe('Lição 11');
        expect(dialog.get('[data-testid="projection-date"]').text()).toBe('Quinta-feira, 1 de outubro de 2026');
        expect(dialog.get('[data-testid="projection-summary"]').text()).toBe('Anterior do T1.');
        wrapper.unmount();
    });

    it('uma aula sem número não mostra «Lição» e uma sem sumário diz que ainda não tem', async () => {
        const wrapper = mountPage([makeLesson({ lesson_number: null, summary: null, has_summary: false })], { attach: true });

        await wrapper.get('[data-testid="project-lesson-a"]').trigger('click');
        await nextTick();

        expect(projection().exists()).toBe(true);
        expect(projection().text()).not.toContain('Lição');
        expect(projection().get('[data-testid="projection-empty"]').text()).toContain('Esta aula ainda não tem sumário.');
        wrapper.unmount();
    });

    it('fecha se a aula projetada deixa de existir nas props', async () => {
        const wrapper = mountPage([makeLesson()], { attach: true });
        await wrapper.get('[data-testid="project-lesson-a"]').trigger('click');
        await nextTick();
        expect(projection().exists()).toBe(true);

        await wrapper.setProps({ lessons: [] });
        await nextTick();
        await nextTick();
        await nextTick();

        expect(projection().exists()).toBe(false);
        wrapper.unmount();
    });
});

describe('sumário atualizado depois de guardar — nunca volta atrás', () => {
    const textarea = (wrapper: ReturnType<typeof mountPage>) => wrapper.get('[data-testid="summary-editor"] textarea');
    const cardText = (wrapper: ReturnType<typeof mountPage>) => wrapper.get('[data-testid="lesson-summary"]').text();

    async function saveWith(wrapper: ReturnType<typeof mountPage>, text: string) {
        await wrapper.get('[data-testid="edit-lesson-a"]').trigger('click');
        await nextTick();
        await textarea(wrapper).setValue(text);
        await wrapper.get('[data-testid="summary-save"]').trigger('click');

        return inertia.router.patch.mock.calls[0][2] as VisitOptions;
    }

    async function confirmSave(wrapper: ReturnType<typeof mountPage>, options: VisitOptions, text: string, version: number) {
        // As props frescas chegam antes do onSuccess, como no reload parcial.
        await wrapper.setProps({ lessons: [makeLesson({ summary: text, summary_version: version })] });
        options.onSuccess?.();
        options.onFinish?.();
        await nextTick();
        await nextTick();
    }

    it('depois de guardar, o cartão mostra o texto novo sem recarregar a página', async () => {
        const wrapper = mountPage();
        const options = await saveWith(wrapper, 'Texto novo.');

        await confirmSave(wrapper, options, 'Texto novo.', 4);

        expect(cardText(wrapper)).toContain('Texto novo.');
        expect(cardText(wrapper)).not.toContain('Equações do primeiro grau.');
        expect(inertia.router.reload).not.toHaveBeenCalled();
    });

    it('uma resposta antiga e atrasada não repõe o texto anterior', async () => {
        const wrapper = mountPage();
        const options = await saveWith(wrapper, 'Texto novo.');
        await confirmSave(wrapper, options, 'Texto novo.', 4);

        // Chega uma resposta mais antiga do que a gravação confirmada.
        await wrapper.setProps({ lessons: [makeLesson({ summary: longSummary, summary_version: 3 })] });
        await nextTick();

        expect(cardText(wrapper)).toContain('Texto novo.');
        expect(cardText(wrapper)).not.toContain('Equações do primeiro grau.');

        // E o próximo guardar parte da versão efetiva, não de um conflito falso.
        await wrapper.get('[data-testid="edit-lesson-a"]').trigger('click');
        await nextTick();
        await textarea(wrapper).setValue('Texto novo, outra vez.');
        await wrapper.get('[data-testid="summary-save"]').trigger('click');

        expect(inertia.router.patch.mock.calls[1][1]).toEqual({ content: 'Texto novo, outra vez.', summary_version: 4 });
    });

    it('sem a aula nas props frescas, regista a gravação com a versão seguinte e o texto enviado', async () => {
        const wrapper = mountPage();
        const options = await saveWith(wrapper, 'Texto novo.');

        options.onSuccess?.();
        options.onFinish?.();
        await nextTick();

        expect(cardText(wrapper)).toContain('Texto novo.');
    });

    it.each([
        ['validação', (options: VisitOptions) => options.onError?.({ content: 'O sumário é demasiado longo.' }), 'O sumário é demasiado longo.'],
        ['ligação', (options: VisitOptions) => options.onNetworkError?.(), 'a ligação falhou'],
        ['servidor', (options: VisitOptions) => options.onHttpException?.(), 'o servidor não respondeu como devia'],
    ])('um erro de %s deixa o editor aberto com o texto, sem «Guardado» e sem alterar o cartão', async (_name, fail, message) => {
        const wrapper = mountPage();
        const options = await saveWith(wrapper, 'Texto por gravar.');

        fail(options);
        options.onFinish?.();
        await nextTick();

        expect((textarea(wrapper).element as HTMLTextAreaElement).value).toBe('Texto por gravar.');
        expect(wrapper.get('[data-testid="summary-editor"]').text()).toContain(message);
        expect(wrapper.text()).not.toContain('Guardado');
        expect(wrapper.get('[data-testid="summary-editor-status"]').text()).toContain('Alterações por guardar');
        expect(wrapper.find('[data-testid="summary-editor"]').exists()).toBe(true);
    });

    it('mostra «A guardar…» anunciado enquanto grava, com o texto só de leitura', async () => {
        const wrapper = mountPage();
        await saveWith(wrapper, 'Texto novo.');

        const status = wrapper.get('[data-testid="summary-editor-status"]');
        expect(status.text()).toBe('A guardar…');
        expect(status.attributes('aria-live')).toBe('polite');
        expect(textarea(wrapper).attributes('readonly')).toBeDefined();
        expect(wrapper.get('[data-testid="summary-save"]').text()).toContain('A guardar…');
    });

    it('ao regressar com props antigas mostra logo o texto confirmado e revalida no servidor', () => {
        window.sessionStorage.setItem(
            'lapis.lessons.confirmedSummaries',
            JSON.stringify({ 'lesson-a': { ulid: 'lesson-a', content: 'Gravado antes de sair.', version: 4 } }),
        );

        const wrapper = mountPage([makeLesson({ summary: longSummary, summary_version: 3 })]);

        expect(cardText(wrapper)).toContain('Gravado antes de sair.');
        expectRevalidation();
    });

    it('quando outra página alterou aulas, revalida ao montar mesmo sem gravação registada', () => {
        window.sessionStorage.setItem('lapis.lessons.stale', '1');

        mountPage();

        expectRevalidation();
    });

    it('com as props em dia não revalida', () => {
        mountPage();

        expect(inertia.router.reload).not.toHaveBeenCalled();
        expect(inertia.router.get).not.toHaveBeenCalled();
    });

    it('revalida com uma visita síncrona e só depois continua o arranque (vista guardada, «Aulas de hoje»)', () => {
        window.sessionStorage.setItem('lapis.lessons.stale', '1');
        window.localStorage.setItem('lapis.lessons.view', 'horario');
        inertia.page.url = '/lessons?week=2026-10-05';

        mountPage();

        // Nunca o reload assíncrono: uma resposta tardia dele repunha a semana antiga.
        expect(inertia.router.reload).not.toHaveBeenCalled();
        expect(inertia.router.get).toHaveBeenCalledTimes(1);
        // A vista guardada ainda não foi aplicada: espera pela revalidação.
        expect(inertia.router.replace).not.toHaveBeenCalled();

        const options = inertia.router.get.mock.calls[0][2] as unknown as { onFinish: () => void };
        options.onFinish();

        expect(inertia.router.replace).toHaveBeenCalledWith(expect.objectContaining({ url: '/lessons?week=2026-10-05&view=horario' }));
    });

    it('uma revalidação cancelada por outra visita não continua o arranque', () => {
        window.sessionStorage.setItem('lapis.lessons.stale', '1');
        window.localStorage.setItem('lapis.lessons.view', 'horario');
        inertia.page.url = '/lessons?week=2026-10-05';

        mountPage();

        const options = inertia.router.get.mock.calls[0][2] as unknown as { onCancel: () => void; onFinish: () => void };
        options.onCancel();
        options.onFinish();

        expect(inertia.router.replace).not.toHaveBeenCalled();
    });
});

describe('«Aulas de hoje» — posiciona no dia certo (foco=hoje)', () => {
    const FOCUS_URL = '/lessons?week=2026-10-05&view=semana&foco=hoje';
    const scrolled: string[] = [];
    const lessonOn = (ulid: string, day: string, overrides: Partial<WeekLesson> = {}) =>
        makeLesson({
            ulid,
            starts_at: `${day}T09:30:00+01:00`,
            ends_at: `${day}T10:20:00+01:00`,
            ...overrides,
        });
    const classB = { ulid: 'class-b', label: '8.º B', is_support_class: false };
    const nextDayResponse = (date: string | null) =>
        vi.fn(() => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ date }) } as Response));

    beforeEach(() => {
        scrolled.length = 0;
        Element.prototype.scrollIntoView = vi.fn(function (this: Element) {
            scrolled.push(this.id);
        });
        // O rAF do jsdom corre sobre temporizadores: aqui dispara logo.
        vi.spyOn(window, 'requestAnimationFrame').mockImplementation((callback: FrameRequestCallback) => {
            callback(0);

            return 0;
        });
        vi.stubGlobal('fetch', nextDayResponse(null));
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    const replacedUrls = () => inertia.router.replace.mock.calls.map((call) => (call[0] as { url: string }).url);

    it('com aulas hoje posiciona em #dia-<hoje>, e não no primeiro dia da semana, e limpa o foco do URL', async () => {
        const wrapper = mountPage(
            [lessonOn('mon', '2026-10-05'), lessonOn('thu', '2026-10-08'), lessonOn('fri', '2026-10-09')],
            { url: FOCUS_URL, attach: true },
        );
        await flushPromises();

        expect(scrolled).toEqual(['dia-2026-10-08']);
        expect(document.activeElement?.id).toBe('dia-2026-10-08');
        expect(replacedUrls()).toEqual(['/lessons?week=2026-10-05&view=semana']);
        expect(fetch).not.toHaveBeenCalled();
        expect(inertia.router.get).not.toHaveBeenCalled();
        wrapper.unmount();
    });

    it('sem aulas hoje, mas mais tarde na semana, posiciona nesse dia', async () => {
        const wrapper = mountPage([lessonOn('mon', '2026-10-05'), lessonOn('fri', '2026-10-09')], {
            url: FOCUS_URL,
            attach: true,
        });
        await flushPromises();

        expect(scrolled).toEqual(['dia-2026-10-09']);
        expect(fetch).not.toHaveBeenCalled();
        wrapper.unmount();
    });

    it('sem foco no URL não posiciona nada, nem toca no URL', async () => {
        const wrapper = mountPage([lessonOn('thu', '2026-10-08')], { url: '/lessons?week=2026-10-05&view=semana', attach: true });
        await flushPromises();

        expect(scrolled).toEqual([]);
        expect(inertia.router.replace).not.toHaveBeenCalled();
        wrapper.unmount();
    });

    it('com o destino na semana seguinte pede o próximo dia, navega com foco=hoje e posiciona quando as props chegam', async () => {
        vi.stubGlobal('fetch', nextDayResponse('2026-10-14'));
        const wrapper = mountPage([lessonOn('mon', '2026-10-05'), lessonOn('tue', '2026-10-06')], {
            url: FOCUS_URL,
            attach: true,
        });
        await flushPromises();

        expect(fetch).toHaveBeenCalledOnce();
        expect(vi.mocked(fetch).mock.calls[0][0]).toBe('/lessons/next-day?after=2026-10-11');
        expect(inertia.router.get).toHaveBeenCalledOnce();
        const [url, , options] = inertia.router.get.mock.calls[0] as unknown as [string, unknown, Record<string, unknown> & { onSuccess: () => void }];
        expect(url).toBe('/lessons?week=2026-10-12&view=semana&foco=hoje');
        expect(options).toMatchObject({ preserveState: true, replace: true });
        expect(scrolled).toEqual([]);

        // As props da semana seguinte chegam.
        await wrapper.setProps({
            week: { start: '2026-10-12', end: '2026-10-18' },
            lessons: [lessonOn('wed', '2026-10-14')],
        });
        options.onSuccess();
        await flushPromises();

        expect(scrolled).toEqual(['dia-2026-10-14']);
        expect(replacedUrls().at(-1)).toBe('/lessons?week=2026-10-12&view=semana');
        wrapper.unmount();
    });

    it('sem próximas aulas mostra o aviso, sem navegar, e limpa o foco', async () => {
        const wrapper = mountPage([lessonOn('mon', '2026-10-05')], { url: FOCUS_URL, attach: true });
        await flushPromises();

        const notice = wrapper.get('[data-testid="today-notice"]');
        expect(notice.attributes('role')).toBe('status');
        expect(notice.text()).toContain('Não há aulas hoje nem nas próximas semanas deste ano letivo.');
        expect(inertia.router.get).not.toHaveBeenCalled();
        expect(replacedUrls()).toEqual(['/lessons?week=2026-10-05&view=semana']);
        wrapper.unmount();
    });

    it('com filtros ativos o aviso di-lo e oferece «Limpar filtros»', async () => {
        const wrapper = mountPage([lessonOn('mon', '2026-10-05')], {
            url: '/lessons?week=2026-10-05&view=semana&classes=class-a&foco=hoje',
            attach: true,
        });
        await flushPromises();

        const notice = wrapper.get('[data-testid="today-notice"]');
        expect(notice.text()).toContain('com os filtros ativos');
        expect(vi.mocked(fetch).mock.calls[0][0]).toBe('/lessons/next-day?after=2026-10-11&classes=class-a');

        await wrapper.get('[data-testid="today-clear-filters"]').trigger('click');

        expect(wrapper.find('[data-testid="today-notice"]').exists()).toBe(false);
        wrapper.unmount();
    });

    it('um erro de rede no pedido mostra o aviso e não salta', async () => {
        vi.stubGlobal('fetch', vi.fn(() => Promise.reject(new TypeError('offline'))));
        const wrapper = mountPage([lessonOn('mon', '2026-10-05')], { url: FOCUS_URL, attach: true });
        await flushPromises();

        expect(wrapper.get('[data-testid="today-notice"]').text()).toContain('Não foi possível encontrar a próxima aula.');
        expect(inertia.router.get).not.toHaveBeenCalled();
        wrapper.unmount();
    });

    it('uma resposta de erro do servidor também é só um aviso', async () => {
        vi.stubGlobal('fetch', vi.fn(() => Promise.resolve({ ok: false, status: 500 } as Response)));
        const wrapper = mountPage([lessonOn('mon', '2026-10-05')], { url: FOCUS_URL, attach: true });
        await flushPromises();

        expect(wrapper.get('[data-testid="today-notice"]').text()).toContain('Não foi possível encontrar a próxima aula.');
        expect(inertia.router.get).not.toHaveBeenCalled();
        wrapper.unmount();
    });

    it('com o filtro de turma a esconder as aulas de hoje, salta para o próximo dia visível', async () => {
        const wrapper = mountPage(
            [lessonOn('today-b', '2026-10-08', { school_class: classB }), lessonOn('fri-a', '2026-10-09')],
            { url: '/lessons?week=2026-10-05&view=semana&classes=class-a&foco=hoje', attach: true },
        );
        await flushPromises();

        expect(scrolled).toEqual(['dia-2026-10-09']);
        expect(fetch).not.toHaveBeenCalled();
        wrapper.unmount();
    });

    it('se os filtros escondem tudo na semana de destino, volta a pedir a partir do fim dessa semana (sem ciclos)', async () => {
        vi.stubGlobal(
            'fetch',
            vi
                .fn()
                .mockImplementationOnce(nextDayResponse('2026-10-14'))
                .mockImplementationOnce(nextDayResponse('2026-10-21')),
        );
        const wrapper = mountPage([], { url: '/lessons?week=2026-10-05&view=semana&classes=class-a&foco=hoje', attach: true });
        await flushPromises();
        const first = inertia.router.get.mock.calls[0] as unknown as [string, unknown, { onSuccess: () => void }];

        // A semana de destino só tem aulas de outra turma: tudo escondido.
        await wrapper.setProps({
            week: { start: '2026-10-12', end: '2026-10-18' },
            lessons: [lessonOn('b', '2026-10-14', { school_class: classB })],
        });
        first[2].onSuccess();
        await flushPromises();

        expect(vi.mocked(fetch).mock.calls[1][0]).toBe('/lessons/next-day?after=2026-10-18&classes=class-a');
        expect(inertia.router.get.mock.calls[1][0]).toBe('/lessons?week=2026-10-19&view=semana&classes=class-a&foco=hoje');
        expect(scrolled).toEqual([]);
        wrapper.unmount();
    });

    it('o posicionamento não depende de temporizadores', async () => {
        vi.useRealTimers();
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'setInterval', 'clearInterval', 'Date'] });
        vi.setSystemTime(new Date('2026-10-08T11:00:00+01:00'));
        // Regista quem pede temporizadores. O `focus()` do jsdom pede um por si (Selection),
        // e isso não é da página: o que importa é que o posicionamento não peça nenhum.
        const origin = globalThis.setTimeout;
        const requestedBy: string[] = [];
        vi.spyOn(globalThis, 'setTimeout').mockImplementation(((...args: Parameters<typeof setTimeout>) => {
            requestedBy.push(new Error().stack ?? '');

            return origin(...args);
        }) as typeof setTimeout);

        const wrapper = mountPage([lessonOn('thu', '2026-10-08')], { url: FOCUS_URL, attach: true });
        // Sem avançar o relógio: só nextTick e rAF.
        await flushPromises();

        expect(scrolled).toEqual(['dia-2026-10-08']);
        expect(requestedBy.filter((stack) => stack.includes('Index.vue') && !stack.includes('jsdom'))).toEqual([]);
        wrapper.unmount();
    });
});

/**
 * 0.162.0 — PESQUISA NOS SUMÁRIOS. A pesquisa é do servidor (aqui, `fetch`
 * simulado); a página só guarda o estado no URL — por cima do do calendário —,
 * pede, mostra e nunca deixa uma resposta antiga tomar o lugar da mais recente.
 */
describe('pesquisa nos sumários — em todo o ano letivo, no servidor', () => {
    const fetchMock = vi.fn();

    function searchResult(overrides: Partial<SummarySearchResult> = {}): SummarySearchResult {
        return {
            ulid: 'result-1',
            starts_at: '2026-09-22T09:30:00+01:00',
            ends_at: '2026-09-22T10:20:00+01:00',
            school_class: { ulid: 'class-a', label: '7.º A', is_support_class: false },
            context_label: '7.º A',
            class_group_id: null,
            class_group_label: null,
            subject: 'Matemática',
            status: 'taught',
            status_label: 'Lecionada',
            outcome: 'taught',
            outcome_label: 'Lecionada',
            lesson_number: 3,
            lesson_number_end: null,
            identity_tone: 'violet',
            occurrences: 2,
            segments: [
                { text: 'Revisão de ', match: false },
                { text: 'frações', match: true },
                { text: '.\n\nExercícios com ', match: false },
                { text: 'Frações', match: true },
                { text: ' equivalentes.', match: false },
            ],
            ...overrides,
        };
    }

    function searchResponse(
        results: SummarySearchResult[],
        overrides: Partial<SummarySearchResponse> = {},
    ): SummarySearchResponse {
        return {
            term: 'frações',
            academic_year: '2026/2027',
            classes: [],
            total: results.length,
            occurrences: results.reduce((sum, result) => sum + result.occurrences, 0),
            page: 1,
            last_page: 1,
            per_page: 20,
            results,
            ...overrides,
        };
    }

    function reply(body: unknown, status = 200): Response {
        return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
    }

    const searchUrl = '/lessons?week=2026-10-05&view=semana&classes=class-a&q=fra%C3%A7%C3%B5es&q_classes=class-a&q_states=taught';

    function lastReplaceUrl(): string {
        const calls = inertia.router.replace.mock.calls;

        return (calls[calls.length - 1]?.[0] as { url: string }).url;
    }

    function queryOf(url: string): Record<string, string> {
        return Object.fromEntries(new URLSearchParams(url.slice(url.indexOf('?') + 1)));
    }

    /** Os outros pedidos da página (p. ex. «Aula anterior», no editor) não são da pesquisa. */
    const otherRequests = vi.fn((url: string) =>
        Promise.resolve(reply(url.includes('preparation-context') ? { lesson: null } : {})),
    );

    beforeEach(() => {
        fetchMock.mockReset();
        fetchMock.mockImplementation(() => Promise.resolve(reply(searchResponse([searchResult()]))));
        vi.stubGlobal('fetch', (url: string, init?: RequestInit) =>
            url.startsWith('/lessons/search?') ? fetchMock(url, init) : otherRequests(url),
        );
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('o campo «Pesquisar nos sumários…» está à vista, na mesma linha do seletor de vista', () => {
        const wrapper = mountPage();
        const query = wrapper.get('[data-testid="lessons-query"]');

        expect(query.find('[role="tablist"]').exists()).toBe(true);
        expect(query.get('[data-testid="summary-search-input"]').attributes('placeholder')).toBe('Pesquisar nos sumários…');
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('Enter pesquisa logo: a pesquisa entra no URL POR CIMA do calendário, com as turmas que este filtrava e só as lecionadas', async () => {
        const wrapper = mountPage([makeLesson()], { url: '/lessons?week=2026-10-05&view=semana&classes=class-a&states=prepared' });
        const input = wrapper.get('[data-testid="summary-search-input"]');

        await input.setValue('frações');
        await input.trigger('keydown', { key: 'Enter' });
        await flushPromises();

        expect(queryOf(lastReplaceUrl())).toEqual({
            week: '2026-10-05',
            view: 'semana',
            classes: 'class-a',
            states: 'prepared',
            q: 'frações',
            q_classes: 'class-a',
            q_states: 'taught',
        });
        expect(fetchMock).toHaveBeenCalledTimes(1);
        const [requested] = fetchMock.mock.calls[0] as [string];
        expect(requested.startsWith('/lessons/search?')).toBe(true);
        expect(queryOf(requested)).toEqual({ q: 'frações', classes: 'class-a', states: 'taught', page: '1' });
    });

    it('durante a escrita espera por uma pausa, e com um só carácter só explica', async () => {
        vi.useFakeTimers({ toFake: ['Date', 'setInterval', 'clearInterval', 'setTimeout', 'clearTimeout'] });
        vi.setSystemTime(new Date('2026-10-08T11:00:00+01:00'));
        const wrapper = mountPage();
        const input = wrapper.get('[data-testid="summary-search-input"]');

        await input.setValue('f');
        vi.advanceTimersByTime(1000);
        await nextTick();
        expect(wrapper.text()).toContain('Escreve pelo menos dois caracteres para pesquisar.');
        expect(inertia.router.replace).not.toHaveBeenCalled();

        await input.setValue('fra');
        vi.advanceTimersByTime(200);
        await input.setValue('fraç');
        vi.advanceTimersByTime(200);
        expect(inertia.router.replace).not.toHaveBeenCalled();

        vi.advanceTimersByTime(200);
        expect(inertia.router.replace).toHaveBeenCalledTimes(1);
        expect(queryOf(lastReplaceUrl()).q).toBe('fraç');
    });

    it('mostra os resultados no lugar do calendário, com o âmbito por inteiro e as aulas separadas das ocorrências', async () => {
        const wrapper = mountPage([makeLesson()], { url: searchUrl });
        await flushPromises();

        expect(wrapper.find('[data-testid="lesson-card"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="week-navigation"]').exists()).toBe(false);
        expect(wrapper.find('[role="tablist"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="search-back"]').text()).toContain('Voltar ao calendário');
        expect(wrapper.get('[data-testid="search-scope"]').text()).toContain('Ano letivo 2026/2027 · 7.º A · Aulas lecionadas');
        expect(wrapper.get('[data-testid="search-count"]').text()).toContain('1 aula encontrada');
        expect(wrapper.get('[data-testid="search-count"]').text()).toContain('2 ocorrências da expressão');

        const card = wrapper.get('[data-testid="search-result"]');
        expect(card.text()).toContain('Terça-feira, 22 de setembro de 2026');
        expect(card.get('[data-testid="search-result-number"]').text()).toBe('Lição 3');
        expect(card.get('[data-testid="lesson-state"]').text()).toBe('Lecionada');
        expect(card.get('[data-testid="open-lesson"]').attributes('href')).toBe('/lessons/result-1');
        expect(card.findAll('[data-testid="search-match"]').map((mark) => mark.text())).toEqual(['frações', 'Frações']);
        // O sumário inteiro, com a estrutura de parágrafos do professor.
        expect(card.get('[data-testid="search-result-summary"]').element.textContent).toBe(
            'Revisão de frações.\n\nExercícios com Frações equivalentes.',
        );
    });

    it('o destaque é texto: um sumário com HTML aparece tal como foi escrito', async () => {
        fetchMock.mockImplementation(() =>
            Promise.resolve(
                reply(
                    searchResponse([
                        searchResult({
                            occurrences: 1,
                            segments: [
                                { text: '<b>negrito</b> e ', match: false },
                                { text: '<script>', match: true },
                            ],
                        }),
                    ]),
                ),
            ),
        );
        const wrapper = mountPage([makeLesson()], { url: searchUrl });
        await flushPromises();

        const summary = wrapper.get('[data-testid="search-result-summary"]');
        expect(summary.find('b').exists()).toBe(false);
        expect(summary.find('script').exists()).toBe(false);
        expect(summary.text()).toBe('<b>negrito</b> e <script>');
    });

    it('grupos diferentes com o mesmo número e o mesmo texto são resultados distintos, cada um com o seu grupo', async () => {
        fetchMock.mockImplementation(() =>
            Promise.resolve(
                reply(
                    searchResponse([
                        searchResult({ ulid: 'r-t1', school_class: { ulid: 'class-b', label: '8.º B', is_support_class: false }, context_label: '8.º B · T1', class_group_id: 1, class_group_label: 'T1' }),
                        searchResult({ ulid: 'r-t2', school_class: { ulid: 'class-b', label: '8.º B', is_support_class: false }, context_label: '8.º B · T2', class_group_id: 2, class_group_label: 'T2' }),
                    ]),
                ),
            ),
        );
        const wrapper = mountPage([makeLesson()], { url: searchUrl });
        await flushPromises();

        const cards = wrapper.findAll('[data-testid="search-result"]');
        expect(cards).toHaveLength(2);
        expect(cards.map((card) => card.get('[data-testid="lesson-scope"]').text())).toEqual(['Grupo T1', 'Grupo T2']);
        expect(cards.map((card) => card.get('[data-testid="search-result-number"]').text())).toEqual(['Lição 3', 'Lição 3']);
    });

    it('uma resposta antiga, mesmo chegando depois, nunca substitui a mais recente', async () => {
        const pending: Record<string, (value: Response) => void> = {};
        fetchMock.mockImplementation(
            (url: string) =>
                new Promise<Response>((resolve) => {
                    pending[queryOf(url).q as string] = resolve;
                }),
        );
        const wrapper = mountPage([makeLesson()], { url: '/lessons?week=2026-10-05&view=semana' });
        const input = wrapper.get('[data-testid="summary-search-input"]');

        await input.setValue('fra');
        await input.trigger('keydown', { key: 'Enter' });
        await input.setValue('frações');
        await input.trigger('keydown', { key: 'Enter' });
        expect(Object.keys(pending)).toEqual(['fra', 'frações']);

        pending['frações']!(reply(searchResponse([searchResult({ ulid: 'recente' })], { term: 'frações' })));
        await flushPromises();
        pending['fra']!(reply(searchResponse([searchResult({ ulid: 'antiga' }), searchResult({ ulid: 'antiga-2' })], { term: 'fra' })));
        await flushPromises();

        expect(wrapper.findAll('[data-testid="search-result"]').map((card) => card.attributes('data-lesson'))).toEqual(['recente']);
    });

    it('por omissão, só as lecionadas; a escolha fica lembrada para a próxima pesquisa', async () => {
        const wrapper = mountPage([makeLesson()], { url: searchUrl });
        await flushPromises();

        expect(wrapper.get('[data-testid="search-state-taught"]').attributes('aria-checked')).toBe('true');
        expect(wrapper.find('[data-testid="search-prepared-note"]').exists()).toBe(false);

        await wrapper.get('[data-testid="search-state-both"]').trigger('click');
        expect(queryOf(lastReplaceUrl()).q_states).toBe('both');
        expect(window.localStorage.getItem('lapis.lessons.search.states')).toBe('both');
        await flushPromises();
        expect(wrapper.get('[data-testid="search-prepared-note"]').text()).toContain('não confirma que o conteúdo foi lecionado');
        expect(queryOf((fetchMock.mock.calls.at(-1) as [string])[0]).states).toBe('taught,prepared');

        const next = mountPage();
        const input = next.get('[data-testid="summary-search-input"]');
        await input.setValue('equações');
        await input.trigger('keydown', { key: 'Enter' });
        expect(queryOf(lastReplaceUrl()).q_states).toBe('both');
    });

    it('mudar as turmas, o período ou as outras ocorrências muda só a pesquisa, e volta à primeira página', async () => {
        const wrapper = mountPage([makeLesson()], { url: `${searchUrl}&q_page=3` });
        await flushPromises();

        await wrapper.get('[data-testid="search-class-class-b"]').trigger('click');
        let query = queryOf(lastReplaceUrl());
        expect(query.q_classes).toBe('class-a,class-b');
        expect(query.classes).toBe('class-a');
        expect(query.q_page).toBeUndefined();

        await wrapper.get('[data-testid="search-from"]').setValue('2026-10-01');
        await wrapper.get('[data-testid="search-from"]').trigger('change');
        query = queryOf(lastReplaceUrl());
        expect(query.q_from).toBe('2026-10-01');

        await wrapper.get('[data-testid="search-other"]').trigger('click');
        query = queryOf(lastReplaceUrl());
        expect(query.q_other).toBe('1');
        await flushPromises();
        expect(queryOf((fetchMock.mock.calls.at(-1) as [string])[0])).toMatchObject({
            classes: 'class-a,class-b',
            states: 'taught,other',
            from: '2026-10-01',
            page: '1',
        });
        expect(wrapper.get('[data-testid="search-scope"]').text()).toContain('Aulas lecionadas e outras ocorrências · Desde 01/10/2026');
    });

    it('sem resultados, diz que não há sumários com estes critérios — nunca que não foi lecionado — e oferece incluir as preparadas', async () => {
        fetchMock.mockImplementation(() => Promise.resolve(reply(searchResponse([]))));
        const wrapper = mountPage([makeLesson()], { url: searchUrl });
        await flushPromises();

        const empty = wrapper.get('[data-testid="search-empty"]');
        expect(empty.text()).toContain('Não foram encontrados sumários correspondentes aos critérios selecionados.');
        expect(wrapper.text()).not.toMatch(/não foi lecionad|nunca foi dad/i);

        await wrapper.get('[data-testid="search-include-prepared"]').trigger('click');
        expect(queryOf(lastReplaceUrl()).q_states).toBe('both');
    });

    it('enquanto não chega a primeira resposta diz que está a pesquisar; uma falha diz o que aconteceu e deixa tentar outra vez', async () => {
        let fail: (reason: unknown) => void = () => {};
        fetchMock.mockImplementationOnce(() => new Promise<Response>((_, reject) => (fail = reject)));
        const wrapper = mountPage([makeLesson()], { url: searchUrl });
        await nextTick();

        expect(wrapper.get('[data-testid="search-loading"]').text()).toContain('A pesquisar');

        fail(new TypeError('Failed to fetch'));
        await flushPromises();
        expect(wrapper.get('[data-testid="search-error"]').text()).toContain('Não foi possível pesquisar nos sumários');

        await button(wrapper, 'Tentar outra vez')!.trigger('click');
        await flushPromises();
        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(wrapper.find('[data-testid="search-error"]').exists()).toBe(false);
        expect(wrapper.findAll('[data-testid="search-result"]')).toHaveLength(1);
    });

    it('uma recusa do servidor (422) mostra a mensagem dele, sem «Tentar outra vez»', async () => {
        fetchMock.mockImplementation(() =>
            Promise.resolve(reply({ message: 'x', errors: { to: ['A data final tem de ser igual ou posterior à data inicial.'] } }, 422)),
        );
        const wrapper = mountPage([makeLesson()], { url: `${searchUrl}&q_from=2026-11-01&q_to=2026-10-01` });
        await flushPromises();

        expect(wrapper.get('[data-testid="search-error"]').text()).toContain('A data final tem de ser igual ou posterior à data inicial.');
        expect(button(wrapper, 'Tentar outra vez')).toBeUndefined();
    });

    it('«Voltar ao calendário» retira só a pesquisa do URL: a mesma semana, a mesma vista e os mesmos filtros de antes', async () => {
        const wrapper = mountPage([makeLesson()], { url: `${searchUrl}&q_page=2&q_from=2026-10-01` });
        await flushPromises();

        await wrapper.get('[data-testid="search-back"]').trigger('click');

        expect(lastReplaceUrl()).toBe('/lessons?week=2026-10-05&view=semana&classes=class-a');
        expect(inertia.router.get).not.toHaveBeenCalledWith(expect.stringContaining('week=2026-09'), expect.anything(), expect.anything());
        expect(wrapper.find('[data-testid="search-results"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="lesson-card"]').exists()).toBe(true);
        expect((wrapper.get('[data-testid="summary-search-input"]').element as HTMLInputElement).value).toBe('');
    });

    it('limpar o campo (botão ou Escape) também volta ao calendário', async () => {
        const wrapper = mountPage([makeLesson()], { url: searchUrl });
        await flushPromises();

        await wrapper.get('[data-testid="summary-search-clear"]').trigger('click');
        expect(lastReplaceUrl()).toBe('/lessons?week=2026-10-05&view=semana&classes=class-a');

        const again = mountPage([makeLesson()], { url: searchUrl });
        await flushPromises();
        await again.get('[data-testid="summary-search-input"]').trigger('keydown', { key: 'Escape' });
        expect(lastReplaceUrl()).toBe('/lessons?week=2026-10-05&view=semana&classes=class-a');
    });

    it('abrir uma aula guarda o URL da pesquisa, para o «Voltar» da aula regressar aos resultados', async () => {
        const wrapper = mountPage([makeLesson()], { url: `${searchUrl}&q_page=2` });
        await flushPromises();

        await wrapper.get('[data-testid="search-result"] [data-testid="open-lesson"]').trigger('click');

        expect(JSON.parse(window.sessionStorage.getItem('lapis.lessons.return') ?? '{}')).toEqual({
            url: `${searchUrl}&q_page=2`,
            lesson: 'result-1',
        });
    });

    it('ao regressar, os últimos resultados aparecem logo e são revalidados no servidor', async () => {
        const first = mountPage([makeLesson()], { url: searchUrl });
        await flushPromises();
        first.unmount();

        let answer: (value: Response) => void = () => {};
        fetchMock.mockImplementationOnce(() => new Promise<Response>((resolve) => (answer = resolve)));
        const back = mountPage([makeLesson()], { url: searchUrl });
        await nextTick();

        // Antes de o servidor responder, já lá estão (da sessão).
        expect(back.findAll('[data-testid="search-result"]')).toHaveLength(1);
        expect(back.find('[data-testid="search-loading"]').exists()).toBe(false);
        expect(fetchMock).toHaveBeenCalledTimes(2);

        answer(reply(searchResponse([searchResult({ segments: [{ text: 'Texto revisto com ', match: false }, { text: 'frações', match: true }] , occurrences: 1 })])));
        await flushPromises();
        expect(back.get('[data-testid="search-result-summary"]').text()).toBe('Texto revisto com frações');
    });

    it('a paginação mantém a expressão e os filtros, e só muda a página', async () => {
        fetchMock.mockImplementation(() =>
            Promise.resolve(reply(searchResponse([searchResult()], { total: 25, occurrences: 30, last_page: 2 }))),
        );
        const wrapper = mountPage([makeLesson()], { url: searchUrl });
        await flushPromises();

        expect(wrapper.get('[data-testid="search-pagination"]').text()).toContain('Página 1 de 2');
        expect(wrapper.get('[data-testid="search-page-previous"]').attributes('disabled')).toBeDefined();

        await wrapper.get('[data-testid="search-page-next"]').trigger('click');

        expect(queryOf(lastReplaceUrl())).toMatchObject({ q: 'frações', q_classes: 'class-a', q_states: 'taught', q_page: '2', week: '2026-10-05' });
    });

    it('na vista Por turma, a pesquisa começa na turma aberta', async () => {
        const classView: ClassViewData = {
            class: { ulid: 'class-b', label: '8.º B', subject: 'Matemática', is_support_class: false, identity_tone: 'emerald', groups: [] },
            group: 'todos',
            range: { key: '1', start: '2026-10-05', end: '2026-10-11', clamped: false },
            lessons: [],
            previous: [],
        };
        const wrapper = mountPage([makeLesson()], { url: '/lessons?week=2026-10-05&view=turma&class=class-b', classView });
        const input = wrapper.get('[data-testid="summary-search-input"]');

        await input.setValue('frações');
        await input.trigger('keydown', { key: 'Enter' });

        expect(queryOf(lastReplaceUrl())).toMatchObject({ view: 'turma', class: 'class-b', q_classes: 'class-b' });
    });

    it('a resposta guardada na sessão é de QUEM e de ONDE: outra pessoa, escola ou ano nunca a vê', async () => {
        const request = 'q=fra%C3%A7%C3%B5es&classes=class-a&states=taught&page=1';
        window.sessionStorage.setItem(
            'lapis.lessons.search.last',
            JSON.stringify({ key: `7|01organizacao|2026/2027|${request}`, response: searchResponse([searchResult({ ulid: 'de-outra-pessoa' })]) }),
        );
        let answer: (value: Response) => void = () => {};
        fetchMock.mockImplementationOnce(() => new Promise<Response>((resolve) => (answer = resolve)));

        const wrapper = mountPage([makeLesson()], { url: searchUrl });
        await nextTick();

        expect(wrapper.find('[data-lesson="de-outra-pessoa"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="search-loading"]').exists()).toBe(true);

        answer(reply(searchResponse([searchResult()])));
        await flushPromises();
        expect(wrapper.findAll('[data-testid="search-result"]').map((card) => card.attributes('data-lesson'))).toEqual(['result-1']);
    });

    it('enquanto a nova pesquisa não responde, a lista anterior fica esbatida e SEM as contagens dela', async () => {
        const wrapper = mountPage([makeLesson()], { url: searchUrl });
        await flushPromises();
        expect(wrapper.get('[data-testid="search-count"]').text()).toContain('1 aula encontrada');

        let answer: (value: Response) => void = () => {};
        fetchMock.mockImplementationOnce(() => new Promise<Response>((resolve) => (answer = resolve)));
        const input = wrapper.get('[data-testid="summary-search-input"]');
        await input.setValue('equações');
        await input.trigger('keydown', { key: 'Enter' });

        expect(wrapper.get('[data-testid="search-count"]').text()).toBe('A pesquisar nos sumários…');
        expect(wrapper.get('[data-testid="search-result-list"]').classes()).toContain('opacity-60');

        answer(reply({ message: 'x' }, 500));
        await flushPromises();
        // Falhou: nada da pesquisa anterior fica debaixo da expressão nova.
        expect(wrapper.find('[data-testid="search-result"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="search-count"]').text()).toBe('');
        expect(wrapper.get('[data-testid="search-error"]').text()).toContain('Não foi possível pesquisar');
    });

    it('se o servidor pesquisou noutro ano letivo (mudado noutra janela), não mostra nada com o âmbito errado', async () => {
        fetchMock.mockImplementation(() => Promise.resolve(reply(searchResponse([searchResult()], { academic_year: '2025/2026' }))));
        const wrapper = mountPage([makeLesson()], { url: searchUrl });
        await flushPromises();

        expect(wrapper.get('[data-testid="search-year-changed"]').text()).toContain('O ano letivo selecionado mudou noutra janela (2025/2026)');
        expect(wrapper.find('[data-testid="search-result"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="search-count"]').text()).toBe('');
    });

    it('uma sessão terminada diz isso mesmo e oferece recarregar, sem «Tentar outra vez»', async () => {
        fetchMock.mockImplementation(() => Promise.resolve(reply({ message: 'Unauthenticated.' }, 401)));
        const wrapper = mountPage([makeLesson()], { url: searchUrl });
        await flushPromises();

        expect(wrapper.get('[data-testid="search-error"]').text()).toContain('A tua sessão terminou');
        expect(button(wrapper, 'Recarregar a página')).toBeDefined();
        expect(button(wrapper, 'Tentar outra vez')).toBeUndefined();
    });

    it('turmas que a página não conhece (ligação antiga) não entram no pedido nem no âmbito', async () => {
        const wrapper = mountPage([makeLesson()], { url: `${searchUrl.replace('q_classes=class-a', 'q_classes=turma-antiga,class-a')}` });
        await flushPromises();

        expect(queryOf((fetchMock.mock.calls.at(-1) as [string])[0]).classes).toBe('class-a');
        expect(wrapper.get('[data-testid="search-scope"]').text()).toContain('· 7.º A ·');

        const stale = mountPage([makeLesson()], { url: `${searchUrl.replace('q_classes=class-a', 'q_classes=turma-antiga')}` });
        await flushPromises();
        expect(queryOf((fetchMock.mock.calls.at(-1) as [string])[0]).classes).toBeUndefined();
        expect(stale.get('[data-testid="search-scope"]').text()).toContain('Todas as turmas');
        expect(stale.get('[data-testid="search-class-all"]').attributes('aria-pressed')).toBe('true');
    });

    it('uma escrita feita na página durante a pesquisa (as aulas recarregadas) revalida os resultados', async () => {
        const wrapper = mountPage([makeLesson()], { url: searchUrl });
        await flushPromises();
        const before = fetchMock.mock.calls.length;

        await wrapper.setProps({ lessons: [makeLesson({ summary_version: 4 })] });
        await flushPromises();

        expect(fetchMock.mock.calls.length).toBe(before + 1);
    });

    it('se o URL mudar por fora (uma resposta atrasada, o histórico), o campo acompanha a expressão', async () => {
        const wrapper = mountPage([makeLesson()], { url: searchUrl });
        await flushPromises();

        inertia.page.url = searchUrl.replace('q=fra%C3%A7%C3%B5es', 'q=equa%C3%A7%C3%B5es');
        await flushPromises();

        expect((wrapper.get('[data-testid="summary-search-input"]').element as HTMLInputElement).value).toBe('equações');
    });

    it('com alterações por guardar no editor, entrar na pesquisa pergunta antes', async () => {
        const wrapper = mountPage();
        await wrapper.get('[data-testid="edit-lesson-a"]').trigger('click');
        await wrapper.get('textarea').setValue('Texto novo por guardar');

        const input = wrapper.get('[data-testid="summary-search-input"]');
        await input.setValue('frações');
        await input.trigger('keydown', { key: 'Enter' });

        expect(inertia.router.replace).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain('Tens alterações por guardar');
    });
});

describe('a página arrumada por função (0.162.0)', () => {
    it('cabeçalho: o título, o ano letivo, «Inserir aula» como ação principal e o menu «Mais ações»', () => {
        inertia.page.url = '/lessons?week=2026-10-05';
        const wrapper = mount(Index, {
            props: {
                lessons: [makeLesson()],
                week: { start: '2026-10-05', end: '2026-10-11' },
                academicYear: '2026/2027',
                configuredClassesCount: 1,
                today: '2026-10-08',
                insertableClasses: [{ ulid: 'class-a', label: '7.º A', subject: 'Matemática', groups: [] }],
                absenceReasons: [],
                classes: teacherClasses,
            },
            global: { stubs: { EmptyState: true, teleport: true } },
        });

        expect(wrapper.get('h1').text()).toBe('Aulas e Sumários');
        expect(wrapper.get('[data-testid="lessons-academic-year"]').text()).toBe('Ano letivo 2026/2027');
        expect(wrapper.getComponent(InsertLessonDialog).props('triggerVariant')).toBe('default');
        expect(wrapper.get('[data-testid="more-actions"]').text()).toBe('Mais ações');
        // As ações da semana vivem no menu, e não em botões soltos no cabeçalho.
        expect(button(wrapper, 'Selecionar aulas')).toBeUndefined();
        expect(button(wrapper, 'Marcar lecionadas')).toBeUndefined();
        // O menu (no mock, inline): o elemento que envolve o botão «Mais ações» e o conteúdo.
        const moreActions = new DOMWrapper(wrapper.get('[data-testid="more-actions"]').element.parentElement!.parentElement!);
        const menu = moreActions.findAll('[role="menuitem"]').map((item) => item.text());
        expect(menu).toEqual(['Selecionar aulas', 'Marcar aulas como lecionadas…', 'Atualizar aulas desta semana', 'Sequências de aulas']);
    });

    it('a navegação entre semanas fica junto do calendário, com a semana e os factos dela', () => {
        const wrapper = mountPage();
        const navigation = wrapper.get('[data-testid="week-navigation"]');

        expect(navigation.find('[aria-label="Semana anterior"]').exists()).toBe(true);
        expect(navigation.get('[data-testid="week-label"]').text()).toContain('(semana atual)');
        expect(wrapper.get('[data-testid="week-facts"]').text()).toContain('Esta semana:');
    });

    it('a seleção mostra uma barra contextual com «Marcar como lecionadas» e uma saída clara — também por Escape', async () => {
        const started = makeLesson({ ulid: 'started', starts_at: '2026-10-08T10:40:00+01:00', ends_at: '2026-10-08T11:30:00+01:00' });
        const wrapper = mountPage([started], { attach: true });

        await wrapper.get('[data-testid="selection-toggle"]').trigger('click');
        const bar = wrapper.get('[data-testid="quick-batch-bar"]');
        expect(bar.get('[data-testid="quick-batch-confirm"]').text()).toBe('Marcar como lecionadas');
        expect(wrapper.get('[data-testid="selection-toggle"]').text()).toBe('Sair da seleção');

        await bar.get('[data-testid="quick-batch-exit"]').trigger('click');
        expect(wrapper.find('[data-testid="quick-batch-bar"]').exists()).toBe(false);

        await wrapper.get('[data-testid="selection-toggle"]').trigger('click');
        expect(wrapper.find('[data-testid="quick-batch-bar"]').exists()).toBe(true);
        document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        await nextTick();
        expect(wrapper.find('[data-testid="quick-batch-bar"]').exists()).toBe(false);
        wrapper.unmount();
    });
});

describe('editar o sumário no cartão — o número da lição (0.169.0)', () => {
    const previewResponse = (overrides: Record<string, unknown> = {}) => ({
        changed: true,
        result: { start: 12, end: null, manual: true },
        conflicts: [],
        effects: [],
        warnings: [],
        requires_confirmation: false,
        confirmation: null,
        following_from: 13,
        ...overrides,
    });

    const recordedEffect = (overrides: Record<string, unknown> = {}) => ({
        ulid: 'x',
        starts_at: '2026-10-22T09:30:00+01:00',
        context_label: '8.º F',
        from: 2,
        from_end: null,
        to: 12,
        to_end: null,
        recorded: true,
        ...overrides,
    });

    // O painel «Aula anterior» também usa o fetch: só a pré-visualização do
    // número responde com `body`, e é só ela que `previewCalls` conta.
    function respondWith(body: unknown, status = 200) {
        const fetchMock = vi.fn((url: string) =>
            Promise.resolve({
                ok: status < 400,
                status,
                json: () => Promise.resolve(String(url).includes('/number/preview') ? body : { lesson: null }),
            }),
        );
        vi.stubGlobal('fetch', fetchMock);

        return {
            fetchMock,
            previewCalls: () => fetchMock.mock.calls.filter(([url]) => String(url).includes('/number/preview')),
        };
    }

    async function openEditor(lesson = makeLesson()) {
        const wrapper = mountPage([lesson]);
        await wrapper.get('[data-testid="edit-lesson-a"]').trigger('click');
        await nextTick();

        return wrapper;
    }

    async function changeNumber(wrapper: ReturnType<typeof mountPage>, start: string) {
        await wrapper.get('[data-testid="lesson-number-start"]').setValue(start);
    }

    async function save(wrapper: ReturnType<typeof mountPage>) {
        await wrapper.get('[data-testid="summary-save"]').trigger('click');
        await flushPromises();
    }

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('o cartão mostra «Lições 11–12» numa aula de várias lições', () => {
        const wrapper = mountPage([makeLesson({ lesson_number: 11, lesson_number_end: 12 })]);

        expect(wrapper.get('[data-testid="lesson-number"]').text()).toBe('Lições 11–12');
    });

    it('mostra o número atual no editor do cartão', async () => {
        const wrapper = await openEditor();

        expect((wrapper.get('[data-testid="lesson-number-start"]').element as HTMLInputElement).value).toBe('12');
    });

    it('com o número igual, grava sem pré-visualização e sem chaves de numeração', async () => {
        const { previewCalls } = respondWith(previewResponse());
        const wrapper = await openEditor();
        await wrapper.get('[data-testid="summary-editor"] textarea').setValue('Outro texto.');

        await save(wrapper);

        expect(previewCalls()).toHaveLength(0);
        expect(inertia.router.patch.mock.calls[0][1]).toEqual({ content: 'Outro texto.', summary_version: 3 });
    });

    it('o número alterado conta como alteração por guardar', async () => {
        const wrapper = await openEditor();
        expect(wrapper.get('[data-testid="summary-editor-status"]').text()).toBe('Sem alterações');

        await changeNumber(wrapper, '20');

        expect(wrapper.get('[data-testid="summary-editor-status"]').text()).toContain('Alterações por guardar');
    });

    it('um conflito mostra-se no campo e NÃO grava', async () => {
        const { previewCalls } = respondWith(previewResponse({ conflicts: ['A Lição 15 já está atribuída à aula de 22/10/2026 (8.º F).'] }));
        const wrapper = await openEditor();
        await changeNumber(wrapper, '15');

        await save(wrapper);

        expect(previewCalls()).toHaveLength(1);
        expect(String(previewCalls()[0][0])).toBe('/lessons/lesson-a/number/preview');
        expect(JSON.parse(((previewCalls()[0] as unknown as [string, RequestInit])[1]).body as string)).toEqual({ mode: 'manual', start: 15, end: null });
        expect(inertia.router.patch).not.toHaveBeenCalled();
        const error = wrapper.get('[data-testid="lesson-number-error"]');
        expect(error.attributes('role')).toBe('alert');
        expect(error.text()).toContain('A Lição 15 já está atribuída');
    });

    it('sem efeitos registados nem avisos grava logo, com as chaves do número', async () => {
        respondWith(previewResponse({ effects: [recordedEffect({ recorded: false, from: 13, to: 14 })] }));
        const wrapper = await openEditor();
        await changeNumber(wrapper, '13');

        await save(wrapper);

        expect(wrapper.find('[data-testid="lesson-number-confirmation"]').exists()).toBe(false);
        expect(inertia.router.patch).toHaveBeenCalledOnce();
        expect(inertia.router.patch.mock.calls[0][1]).toEqual({
            content: longSummary.trim(),
            summary_version: 3,
            lesson_number: 13,
            lesson_number_end: null,
        });
    });

    it('um efeito registado pede confirmação; «Guardar» envia a confirmação do servidor', async () => {
        respondWith(
            previewResponse({
                effects: [recordedEffect()],
                warnings: ['A aula de 22/10/2026 (Lição 5) vem depois desta e tem um número inferior.'],
                requires_confirmation: true,
                confirmation: 'hash-123',
            }),
        );
        const wrapper = await openEditor();
        await changeNumber(wrapper, '14');

        await save(wrapper);

        expect(inertia.router.patch).not.toHaveBeenCalled();
        const panel = wrapper.get('[data-testid="lesson-number-confirmation"]');
        expect(panel.get('[data-testid="lesson-number-effects"]').text()).toContain('22/10 · 8.º F · Lição 2 → Lição 12');
        expect(panel.get('[data-testid="lesson-number-warnings"]').text()).toContain('tem um número inferior');

        await panel.get('[data-testid="lesson-number-confirm"]').trigger('click');

        expect(inertia.router.patch).toHaveBeenCalledOnce();
        expect(inertia.router.patch.mock.calls[0][1]).toMatchObject({
            lesson_number: 14,
            lesson_number_end: null,
            lesson_number_confirmation: 'hash-123',
        });
    });

    it('«Cancelar» fecha o painel sem gravar e mantém o que se escreveu', async () => {
        respondWith(previewResponse({ effects: [recordedEffect({ to: null })], confirmation: 'h' }));
        const wrapper = await openEditor();
        await changeNumber(wrapper, '14');
        await save(wrapper);

        expect(wrapper.get('[data-testid="lesson-number-effects"]').text()).toContain('fica sem número');

        await wrapper.get('[data-testid="lesson-number-cancel"]').trigger('click');

        expect(wrapper.find('[data-testid="lesson-number-confirmation"]').exists()).toBe(false);
        expect(inertia.router.patch).not.toHaveBeenCalled();
        expect((wrapper.get('[data-testid="lesson-number-start"]').element as HTMLInputElement).value).toBe('14');
    });

    it('um erro `lesson_number` do servidor fica no campo e não se confunde com o conflito de versão', async () => {
        respondWith(previewResponse());
        const wrapper = await openEditor();
        await changeNumber(wrapper, '13');
        await save(wrapper);
        const options = inertia.router.patch.mock.calls[0][2] as VisitOptions;

        options.onError?.({ lesson_number: 'Esta alteração muda o número de outras aulas já registadas. Revê a lista e confirma.' });
        options.onFinish?.();
        await nextTick();

        expect(wrapper.get('[data-testid="lesson-number-error"]').text()).toContain('Revê a lista e confirma');
        expect(wrapper.find('[data-testid="summary-conflict"]').exists()).toBe(false);
        // O editor continua aberto, com o número escrito.
        expect((wrapper.get('[data-testid="lesson-number-start"]').element as HTMLInputElement).value).toBe('13');
    });

    it('«Repor numeração automática» grava com a bandeira automática (numa aula manual)', async () => {
        respondWith(previewResponse({ result: { start: 12, end: null, manual: false } }));
        const wrapper = await openEditor(makeLesson({ lesson_number_manual: true }));

        await wrapper.get('[data-testid="lesson-number-reset"]').trigger('click');
        await save(wrapper);

        expect(inertia.router.patch.mock.calls[0][1]).toMatchObject({ lesson_number_automatic: true });
        expect(inertia.router.patch.mock.calls[0][1]).not.toHaveProperty('lesson_number');
    });

    it('professor ausente: o editor não oferece o número', async () => {
        const wrapper = await openEditor(
            makeLesson({ outcome: 'teacher_absent', outcome_label: 'Professor ausente', lesson_number: null, status: 'taught', status_label: 'Lecionada' }),
        );

        expect(wrapper.get('[data-testid="lesson-number-absent"]').text()).toContain('Professor ausente');
        expect(wrapper.find('[data-testid="lesson-number-start"]').exists()).toBe(false);
    });
});


/**
 * 0.170.0 — as sessões das «outras atividades» vivem ao lado das aulas na vista
 * Semana, e SÓ nela: nunca entram nos contadores, nos filtros de estado, na
 * seleção em lote nem nas vistas Horário e Por turma.
 */
describe('vista Semana — sessões das outras atividades', () => {
    function makeSession(overrides: Partial<ActivitySessionItem> = {}): ActivitySessionItem {
        return {
            key: 'activity-act-1-2026-10-08',
            session_ulid: null,
            activity_ulid: 'act-1',
            title: 'Apoio ao estudo',
            kind: 'student_support',
            kind_label: 'Apoio a alunos',
            occurs_on: '2026-10-08',
            starts_at: '2026-10-08T12:00:00+01:00',
            ends_at: '2026-10-08T12:50:00+01:00',
            start_time: '12:00',
            end_time: '12:50',
            school_class: null,
            status: 'planned',
            presentation: 'planned',
            presentation_label: 'Por realizar',
            summary: null,
            has_summary: false,
            session_number: null,
            numbering_enabled: false,
            registrable: true,
            follows_schedule: true,
            ...overrides,
        };
    }

    const cardsOfDay = (wrapper: ReturnType<typeof mountPage>, date: string) =>
        wrapper
            .get(`section[aria-labelledby="dia-${date}"]`)
            .findAll('ol > li > *')
            .map((element) => element.attributes('data-testid'));

    it('junta as sessões às aulas do dia, por ordem de início, com um cartão próprio', () => {
        const wrapper = mountPage([makeLesson()], {
            activitySessions: [
                makeSession({ key: 'late', title: 'Reunião', starts_at: '2026-10-08T17:00:00+01:00', start_time: '17:00' }),
                makeSession({ key: 'early', starts_at: '2026-10-08T08:00:00+01:00', start_time: '08:00' }),
            ],
        });

        // Aula às 09:30: a sessão das 08:00 antes, a das 17:00 depois — e não pela ordem recebida.
        expect(cardsOfDay(wrapper, '2026-10-08')).toEqual(['activity-session-card', 'lesson-card', 'activity-session-card']);
        expect(wrapper.findAll('[data-testid="activity-session-card"]').map((card) => card.text())[0]).toContain('Apoio ao estudo');
    });

    it('ordena pelo instante real, não pelo texto, na semana em que muda a hora', () => {
        const wrapper = mountPage([makeLesson({ ulid: 'l', starts_at: '2026-10-08T09:30:00+01:00' })], {
            activitySessions: [makeSession({ key: 'before', starts_at: '2026-10-08T09:00:00+01:00', start_time: '09:00' })],
        });

        expect(cardsOfDay(wrapper, '2026-10-08')[0]).toBe('activity-session-card');
    });

    it('conta-se à parte: os contadores e o cabeçalho do dia continuam a ser só de aulas', () => {
        const without = mountPage([makeLesson()]);
        const factsWithout = without.get('[data-testid="week-facts"]').text();

        const wrapper = mountPage([makeLesson()], { activitySessions: [makeSession()] });
        const heading = wrapper.get('#dia-2026-10-08').text();

        expect(wrapper.get('[data-testid="week-facts"]').text()).toBe(factsWithout);
        expect(heading).toContain('1 aula · 1 com sumário');
        expect(heading).toContain('1 outra atividade');
        expect(wrapper.findAll('[data-testid="lesson-card"]')).toHaveLength(1);
    });

    it('mostra o estado, o número e o sumário completo da sessão', () => {
        const wrapper = mountPage([makeLesson()], {
            activitySessions: [
                makeSession({
                    session_ulid: 'ses-1',
                    status: 'done',
                    presentation: 'done',
                    presentation_label: 'Realizada',
                    has_summary: true,
                    summary: 'Primeira linha.\nSegunda linha.',
                    session_number: 4,
                }),
            ],
        });
        const card = wrapper.get('[data-testid="activity-session-card"]');

        expect(card.get('[data-testid="activity-session-state"]').text()).toBe('Realizada');
        expect(card.text()).toContain('Sessão 4');
        expect(card.get('[data-testid="activity-session-summary-text"]').text()).toContain('Segunda linha.');
        expect(card.get('[data-testid="activity-session-open"]').text()).toBe('Editar sumário');
    });

    it('«Registar sumário» abre o registo da sessão, e nada é pedido ao servidor', async () => {
        const wrapper = mountPage([makeLesson()], { activitySessions: [makeSession()] });

        expect(wrapper.get('[data-testid="activity-session-open"]').text()).toBe('Registar sumário');
        inertia.router.get.mockClear();

        await wrapper.get('[data-testid="activity-session-open"]').trigger('click');

        expect(wrapper.find('[data-testid="activity-session-dialog"]').exists()).toBe(true);
        expect(inertia.router.get).not.toHaveBeenCalled();
        expect(inertia.router.post).not.toHaveBeenCalled();
    });

    it('os filtros de estado das aulas não escondem as sessões, e a seleção em lote não as vê', async () => {
        const wrapper = mountPage([makeLesson({ ulid: 'started', starts_at: '2026-10-08T10:40:00+01:00', ends_at: '2026-10-08T11:30:00+01:00' })], {
            url: '/lessons?week=2026-10-05&states=taught',
            activitySessions: [makeSession()],
            attach: true,
        });

        // O filtro «Lecionada» esconde a aula preparada, mas a sessão continua lá.
        expect(wrapper.findAll('[data-testid="lesson-card"]')).toHaveLength(0);
        expect(wrapper.findAll('[data-testid="activity-session-card"]')).toHaveLength(1);

        await wrapper.setProps({});
        const toggle = wrapper.find('[data-testid="selection-toggle"]');

        if (toggle.exists()) {
            await toggle.trigger('click');
            expect(wrapper.get('[data-testid="activity-session-card"]').find('input[type="checkbox"], [role="checkbox"]').exists()).toBe(false);
        }
    });

    it('com um filtro de turma, só as sessões dessa turma', () => {
        const wrapper = mountPage([makeLesson()], {
            url: '/lessons?week=2026-10-05&classes=class-a',
            activitySessions: [
                makeSession({ key: 'of-a', title: 'DT 7.º A', school_class: { ulid: 'class-a', label: '7.º A' } }),
                makeSession({ key: 'of-b', title: 'DT 8.º B', school_class: { ulid: 'class-b', label: '8.º B' } }),
                makeSession({ key: 'none', title: 'Sem turma' }),
            ],
        });

        expect(wrapper.findAll('[data-testid="activity-session-card"]').map((card) => card.text())).toEqual([expect.stringContaining('DT 7.º A')]);
    });

    it('uma semana só com sessões mostra-as, e não «Sem aulas nesta semana»', () => {
        const wrapper = mountPage([], { activitySessions: [makeSession({ occurs_on: '2026-10-06', starts_at: '2026-10-06T12:00:00+01:00', key: 'tue' })] });

        expect(wrapper.find('empty-state-stub').exists()).toBe(false);
        expect(wrapper.findAll('[data-testid="activity-session-card"]')).toHaveLength(1);
        expect(wrapper.find('#dia-2026-10-06').exists()).toBe(true);
        expect(wrapper.get('#dia-2026-10-06').text()).not.toContain('0 aulas');
    });

    it('não aparecem na vista Horário, que fica sem aulas nesta semana', async () => {
        const wrapper = mountPage([makeLesson()], { activitySessions: [makeSession()] });

        await button(wrapper, 'Horário')!.trigger('click');

        expect(wrapper.find('[data-testid="activity-session-card"]').exists()).toBe(false);
    });

    it('uma semana sem aulas nem sessões continua a dizer que não há aulas', () => {
        const wrapper = mountPage([]);

        expect(wrapper.find('empty-state-stub').exists()).toBe(true);
    });
});
