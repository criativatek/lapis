/**
 * §46 e §47 — as duas vistas sobre as MESMAS aulas, o número da lição nas duas,
 * e o sumário completo sem sair da lista.
 */
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { WeekLesson } from '@/lib/lessons';
import Index from './Index.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: vi.fn(), reload: vi.fn(), on: vi.fn(() => vi.fn()) },
    useForm: () => ({
        errors: {},
        processing: false,
        post: vi.fn(),
        delete: vi.fn(),
        data: () => ({}),
        defaults: vi.fn(),
    }),
}));

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
        status_label: 'Preparado',
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

function mountPage(lessons: WeekLesson[] = [makeLesson()]) {
    return mount(Index, {
        props: {
            lessons,
            week: { start: '2026-10-05', end: '2026-10-11' },
            academicYear: '2026/2027',
            configuredClassesCount: 1,
            today: '2026-10-08',
            insertableClasses: [],
        },
        global: { stubs: { EmptyState: true, teleport: true } },
    });
}

beforeEach(() => {
    window.localStorage.clear();
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
        expect(wrapper.text()).not.toContain('Preparado');
        expect(wrapper.text()).not.toContain('Por preparar');
        const listStates = states();
        const listClasses = wrapper.findAll('[data-testid="lesson-state"]').map((badge) => badge.classes().join(' '));
        expect(listClasses[0]).toContain('bg-amber-100');
        expect(listClasses[1]).toContain('bg-violet-100');

        await wrapper.findAll('button').find((button) => button.text().trim() === 'Horário')!.trigger('click');

        // O horário desenha cada aula duas vezes (grelha e vista de dia, para mobile).
        expect([...new Set(states())]).toEqual(listStates);
        expect(wrapper.text()).not.toContain('Preparado');
        expect(wrapper.findAll('[data-testid="lesson-state"]')[0].classes()).toContain('bg-amber-100');
    });

    it('a seleção em lote exclui aulas com resultado registado', async () => {
        const wrapper = mountPage([
            makeLesson(),
            makeLesson({ ulid: 'lesson-b', outcome: 'teacher_absent', outcome_label: 'Professor ausente' }),
            makeLesson({ ulid: 'lesson-c', outcome: 'class_external_activity', outcome_label: 'Turma em outras atividades letivas' }),
        ]);

        await wrapper.findAll('button').find((button) => button.text().includes('Selecionar aulas'))!.trigger('click');

        expect(wrapper.text()).toContain('0 de 1 aulas selecionadas.');
    });

    it('a seleção em lote só aceita aulas ainda não lecionadas', async () => {
        const wrapper = mountPage([
            makeLesson(),
            makeLesson({ ulid: 'lesson-b', status: 'taught', status_label: 'Lecionado' }),
        ]);
        const selectButton = wrapper
            .findAll('button')
            .find((button) => button.text().includes('Selecionar aulas'));

        await selectButton!.trigger('click');

        expect(wrapper.text()).toContain('0 de 1 aulas selecionadas.');
    });
});
