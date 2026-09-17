/**
 * §46 e §47 — as duas vistas sobre as MESMAS aulas, o número da lição nas duas,
 * e o sumário completo sem sair da lista.
 */
import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import LessonOutcomeDialog from '@/components/lessons/LessonOutcomeDialog.vue';
import type { WeekLesson } from '@/lib/lessons';
import Index from './Index.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: vi.fn(), post: vi.fn(), reload: vi.fn(), on: vi.fn(() => vi.fn()) },
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
}));

// O menu real abre por pointerdown num portal; aqui tudo fica inline e o
// clique num item emite o `select` do reka-ui.
vi.mock('@/components/ui/dropdown-menu', () => {
    const passthrough = (tag: string) =>
        defineComponent({ setup: (_, { slots }) => () => h(tag, slots.default?.()) });

    return {
        DropdownMenu: passthrough('div'),
        DropdownMenuContent: passthrough('div'),
        DropdownMenuTrigger: passthrough('div'),
        DropdownMenuItem: defineComponent({
            emits: ['select'],
            setup: (_, { slots, emit, attrs }) => () =>
                h('div', { ...attrs, role: 'menuitem', onClick: () => emit('select') }, slots.default?.()),
        }),
    };
});

const longSummary = 'Equações do primeiro grau. '.repeat(20);

function makeLesson(overrides: Partial<WeekLesson> = {}): WeekLesson {
    return {
        ulid: 'lesson-a',
        starts_at: '2026-10-08T09:30:00+01:00',
        ends_at: '2026-10-08T10:20:00+01:00',
        school_class: { ulid: 'class-a', label: '7.º A' },
        context_label: '7.º A',
        class_group_label: null,
        class_group_id: null,
        subject: 'Matemática',
        status: 'prepared',
        status_label: 'Preparada',
        has_summary: true,
        summary_excerpt: longSummary.slice(0, 180),
        summary_full: longSummary,
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

function mountPage(lessons: WeekLesson[] = [makeLesson()], options: { attachTo?: HTMLElement } = {}) {
    return mount(Index, {
        ...options,
        props: {
            lessons,
            week: { start: '2026-10-05', end: '2026-10-11' },
            academicYear: '2026/2027',
            configuredClassesCount: 1,
            today: '2026-10-08',
            insertableClasses: [],
            absenceReasons: [{ value: 'training', label: 'Formação' }],
        },
        global: { stubs: { EmptyState: true, teleport: true } },
    });
}

beforeEach(() => {
    window.localStorage.clear();
    // Relógio congelado: quinta-feira, 08/10/2026, 11:00 em Lisboa.
    vi.useFakeTimers({ toFake: ['Date', 'setInterval', 'clearInterval'] });
    vi.setSystemTime(new Date('2026-10-08T11:00:00+01:00'));
});

afterEach(() => {
    vi.useRealTimers();
});

describe('vista de aulas da semana', () => {
    it('mostra o número da lição na vista lista', () => {
        expect(mountPage().text()).toContain('Lição 12');
    });

    it('trunca o sumário e só mostra o texto completo depois de «Ver sumário completo»', async () => {
        const wrapper = mountPage();
        const trigger = wrapper
            .findAll('button')
            .find((button) => button.text().includes('Ver sumário completo'));

        expect(trigger).toBeDefined();
        expect(wrapper.text()).not.toContain(longSummary.trim());

        await trigger!.trigger('click');

        expect(wrapper.text()).toContain(longSummary.trim());
        expect(
            wrapper.findAll('button').some((button) => button.text().includes('Ver menos')),
        ).toBe(true);
    });

    it('não oferece «Ver mais» quando o sumário já cabe no excerto', () => {
        const wrapper = mountPage([
            makeLesson({ summary_excerpt: 'Aula curta.', summary_full: null }),
        ]);

        expect(
            wrapper.findAll('button').some((button) => button.text().includes('Ver sumário completo')),
        ).toBe(false);
    });

    /** §22, §26: alternar de vista não navega e não muda a semana. */
    it('alterna para o horário sem mudar de semana e mostra a mesma aula', async () => {
        const wrapper = mountPage();
        const toggle = wrapper
            .findAll('button')
            .find((button) => button.text().trim() === 'Horário');

        await toggle!.trigger('click');

        expect(wrapper.text()).toContain('Semana de');
        expect(wrapper.text()).toContain('7.º A');
        expect(wrapper.text()).toContain('Lição 12');
        // A mesma aula, uma só vez: não há segunda fonte de dados.
        expect(wrapper.findAll('a[href="/lessons/lesson-a"]').length).toBeGreaterThan(0);
    });

    /** 0.146.1 — o resultado final substitui a preparação, nas duas vistas. */
    it('uma aula com resultado registado mostra só o resultado, na lista e no horário', async () => {
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
        expect(wrapper.text()).not.toContain('Preparada');
        expect(wrapper.text()).not.toContain('Por preparar');
        const listStates = states();
        const listClasses = wrapper.findAll('[data-testid="lesson-state"]').map((badge) => badge.classes().join(' '));
        expect(listClasses[0]).toContain('bg-amber-100');
        expect(listClasses[1]).toContain('bg-violet-100');

        await wrapper.findAll('button').find((button) => button.text().trim() === 'Horário')!.trigger('click');

        // O horário desenha cada aula duas vezes (grelha e vista de dia, para mobile).
        expect([...new Set(states())]).toEqual(listStates);
        expect(wrapper.text()).not.toContain('Preparada');
        expect(wrapper.findAll('[data-testid="lesson-state"]')[0].classes()).toContain('bg-amber-100');
    });

    it('a seleção em lote exclui aulas com resultado registado', async () => {
        const wrapper = mountPage([
            makeLesson(),
            makeLesson({ ulid: 'lesson-b', outcome: 'teacher_absent', outcome_label: 'Professor ausente' }),
            makeLesson({ ulid: 'lesson-c', outcome: 'class_external_activity', outcome_label: 'Turma em outras atividades letivas' }),
        ]);

        await wrapper.findAll('button').find((button) => button.text().includes('Selecionar aulas'))!.trigger('click');

        expect(wrapper.text()).toContain('0 aulas selecionadas');
        expect(wrapper.findAll('[aria-label^="Selecionar a aula"]')).toHaveLength(1);
    });

    it('a seleção em lote só aceita aulas ainda não lecionadas', async () => {
        const wrapper = mountPage([
            makeLesson(),
            makeLesson({ ulid: 'lesson-b', status: 'taught', status_label: 'Lecionada' }),
        ]);
        const selectButton = wrapper
            .findAll('button')
            .find((button) => button.text().includes('Selecionar aulas'));

        await selectButton!.trigger('click');

        expect(wrapper.text()).toContain('0 aulas selecionadas');
        expect(wrapper.findAll('[aria-label^="Selecionar a aula"]')).toHaveLength(1);
    });

    /** 0.147.0 — fecho rápido. Relógio congelado às 11:00 de 08/10/2026. */
    describe('fecho rápido', () => {
        const started = () =>
            makeLesson({ ulid: 'started', starts_at: '2026-10-08T10:40:00+01:00', ends_at: '2026-10-08T11:30:00+01:00' });
        const ended = () => makeLesson({ ulid: 'ended' });
        const future = () =>
            makeLesson({ ulid: 'future', starts_at: '2026-10-08T14:00:00+01:00', ends_at: '2026-10-08T14:50:00+01:00' });
        const pastDay = () =>
            makeLesson({ ulid: 'past', starts_at: '2026-10-06T09:30:00+01:00', ends_at: '2026-10-06T10:20:00+01:00' });
        const closed = () =>
            makeLesson({ ulid: 'closed', status: 'taught', status_label: 'Lecionada', outcome: 'taught', outcome_label: 'Lecionada' });

        const quickButtons = (wrapper: ReturnType<typeof mountPage>) =>
            wrapper.findAll('[data-testid="quick-mark-taught"]').map((button) => button.attributes('aria-label'));

        it('uma aula Preparada já começada oferece «Lecionada» com nome acessível', () => {
            const wrapper = mountPage([started()]);

            expect(quickButtons(wrapper)).toEqual(['Marcar a aula de 7.º A (10:40–11:30) como lecionada']);
            expect(wrapper.find('[data-testid="lesson-attention"]').exists()).toBe(false);
            expect(wrapper.get('[data-testid="lesson-state"]').text()).toBe('Preparada');
        });

        it('uma aula futura não oferece ação nem aviso', () => {
            const wrapper = mountPage([future()]);

            expect(quickButtons(wrapper)).toEqual([]);
            expect(wrapper.find('[data-testid="quick-close-mobile"]').exists()).toBe(false);
            expect(wrapper.find('[data-testid="lesson-attention"]').exists()).toBe(false);
        });

        it('uma aula fechada não oferece ação', () => {
            const wrapper = mountPage([
                closed(),
                makeLesson({ ulid: 'absent', outcome: 'teacher_absent', outcome_label: 'Professor ausente' }),
            ]);

            expect(quickButtons(wrapper)).toEqual([]);
            expect(wrapper.find('[data-testid="lesson-attention"]').exists()).toBe(false);
        });

        it('uma aula terminada hoje ou num dia anterior pede confirmação', () => {
            const wrapper = mountPage([pastDay(), ended()]);
            const warnings = wrapper.findAll('[data-testid="lesson-attention"]');

            expect(warnings).toHaveLength(2);
            expect(warnings[0].text()).toBe('Aula terminada · Confirmar estado');
            // Ícone + texto, e nunca só a cor.
            expect(warnings[0].find('svg').exists()).toBe(true);
            expect(quickButtons(wrapper)).toHaveLength(2);
        });

        it('«Lecionada» publica para o endpoint da aula, preservando o scroll', async () => {
            const { router } = await import('@inertiajs/vue3');
            const wrapper = mountPage([started()]);

            await wrapper.get('[data-testid="quick-mark-taught"]').trigger('click');

            expect(router.post).toHaveBeenCalledWith(
                '/lessons/started/mark-taught',
                {},
                expect.objectContaining({ preserveScroll: true }),
            );
        });

        it('o menu «•••» tem nome acessível e oferece os dois resultados com os rótulos canónicos', () => {
            const wrapper = mountPage([started()]);

            expect(wrapper.get('[data-testid="quick-more-actions"]').attributes('aria-label')).toBe(
                'Mais ações para a aula de 7.º A',
            );
            expect(wrapper.get('[data-testid="quick-teacher-absent"]').text()).toBe('Professor ausente');
            expect(wrapper.get('[data-testid="quick-external-activity"]').text()).toBe('Turma em outras atividades letivas');
            expect(wrapper.text()).not.toContain('Outra atividade educativa');
        });

        it('os botões do cartão são botões focáveis fora da ligação', () => {
            const wrapper = mountPage([started()]);

            for (const selector of ['quick-mark-taught', 'quick-more-actions', 'quick-close-mobile']) {
                const element = wrapper.get(`[data-testid="${selector}"]`);
                expect(element.element.tagName).toBe('BUTTON');
                expect(element.attributes('tabindex')).not.toBe('-1');
                expect(element.element.closest('a')).toBeNull();
            }
        });

        it('no telemóvel há um só gatilho de 44px com as três ações', () => {
            const wrapper = mountPage([started()]);
            const trigger = wrapper.get('[data-testid="quick-close-mobile"]');

            expect(trigger.classes()).toContain('min-h-11');
            expect(trigger.attributes('aria-label')).toBe('Fechar a aula de 7.º A (10:40–11:30)');
            expect(wrapper.get('[data-testid="quick-mark-taught-mobile"]').classes()).toContain('min-h-11');
        });

        it('escolher «Turma em outras atividades letivas» abre o diálogo de resultado para aquela aula', async () => {
            const wrapper = mountPage([started()]);
            const dialog = () => wrapper.findComponent(LessonOutcomeDialog);

            expect(dialog().props('open')).toBe(false);

            await wrapper.get('[data-testid="quick-external-activity"]').trigger('click');

            // Um só diálogo na página, apontado à aula escolhida e ao resultado escolhido.
            expect(wrapper.findAllComponents(LessonOutcomeDialog)).toHaveLength(1);
            expect(dialog().props('open')).toBe(true);
            expect(dialog().props('lessonUlid')).toBe('started');
            expect(dialog().props('initialOutcome')).toBe('class_external_activity');
            expect(dialog().props('lessonContext')).toBe('7.º A · 10:40–11:30');
        });

        it('a seleção só aceita aulas fecháveis, nas duas vistas, sem «Selecionar todas»', async () => {
            const wrapper = mountPage([started(), ended(), future(), closed()]);

            await wrapper.findAll('button').find((button) => button.text().includes('Selecionar aulas'))!.trigger('click');

            const checkboxes = () => wrapper.findAll('[aria-label^="Selecionar a aula"]');
            expect(checkboxes()).toHaveLength(2);
            expect(wrapper.text()).not.toContain('Selecionar todas');

            const confirm = wrapper.get('[data-testid="quick-batch-confirm"]');
            expect(confirm.text()).toBe('Marcar selecionadas como lecionadas');
            expect(confirm.attributes('disabled')).toBeDefined();

            await wrapper.findAll('button').find((button) => button.text().trim() === 'Horário')!.trigger('click');

            // O horário desenha cada aula duas vezes (grelha e dia no telemóvel).
            expect(checkboxes()).toHaveLength(4);
        });

        it('a semana nunca mostra «Preparado» nem «Lecionado»', async () => {
            const wrapper = mountPage([started(), closed(), future()]);

            expect(wrapper.text()).not.toMatch(/Preparado|Lecionado/);

            await wrapper.findAll('button').find((button) => button.text().trim() === 'Horário')!.trigger('click');

            expect(wrapper.text()).not.toMatch(/Preparado|Lecionado/);
        });
    });
});
