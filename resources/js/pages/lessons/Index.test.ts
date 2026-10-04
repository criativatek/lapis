/**
 * Aulas e Sumários (0.158.0) — três vistas sobre as MESMAS aulas, o sumário
 * completo por omissão, a identidade estável de cada turma, os filtros no URL,
 * a edição no cartão que grava só o sumário, o conflito com outra janela e a
 * guarda de alterações por guardar. Mais o fecho rápido e o lote, que vêm de
 * antes e não podem regredir.
 */
import { DOMWrapper, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick } from 'vue';
import LessonOutcomeDialog from '@/components/lessons/LessonOutcomeDialog.vue';
import type { WeekLesson } from '@/lib/lessons';
import type { ClassViewData, TeacherClass } from '@/lib/lessonWeekView';
import Index from './Index.vue';

type VisitOptions = {
    onSuccess?: () => void;
    onError?: (errors: Record<string, string>) => void;
    onFinish?: () => void;
    onNetworkError?: () => boolean | void;
    only?: string[];
};

const inertia = vi.hoisted(() => ({
    page: { url: '/lessons?week=2026-10-05' },
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
            delete: vi.fn(),
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

    return {
        DropdownMenu: passthrough('div'),
        DropdownMenuContent: passthrough('div'),
        DropdownMenuTrigger: passthrough('div'),
        DropdownMenuItem: defineComponent({
            emits: ['select'],
            setup: (_, { slots, emit, attrs }) => () => h('div', { ...attrs, role: 'menuitem', onClick: () => emit('select') }, slots.default?.()),
        }),
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
    options: { url?: string; classView?: ClassViewData | null; classes?: TeacherClass[]; attach?: boolean } = {},
) {
    inertia.page.url = options.url ?? '/lessons?week=2026-10-05';

    return mount(Index, {
        props: {
            lessons,
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
    Object.values(inertia.router).forEach((mock) => mock.mockClear());
    // Relógio congelado: quinta-feira, 08/10/2026, 11:00 em Lisboa.
    vi.useFakeTimers({ toFake: ['Date', 'setInterval', 'clearInterval'] });
    vi.setSystemTime(new Date('2026-10-08T11:00:00+01:00'));
});

afterEach(() => {
    vi.useRealTimers();
});

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
            { group_id: null, group_label: null, lesson: null },
            {
                group_id: 1,
                group_label: 'T1',
                lesson: makeLesson({
                    ulid: 'prev-t1',
                    summary: 'Anterior do T1.',
                    lesson_number: 11,
                    starts_at: '2026-10-01T09:30:00+01:00',
                    ends_at: '2026-10-01T10:20:00+01:00',
                }),
            },
            { group_id: 2, group_label: 'T2', lesson: null },
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
        expect(wrapper.get('#class-view-previous').text()).toContain('Último sumário de Grupo T1');
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
        expect(options.only).toEqual(['lessons', 'classView']);
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

        await wrapper
            .findAll('button')
            .find((candidate) => candidate.text().includes('Selecionar aulas'))!
            .trigger('click');

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
