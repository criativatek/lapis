import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import type { ActivitySessionItem } from '@/lib/activitySessions';
import Index from './Index.vue';

const routerGet = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');

    return {
        router: { get: routerGet },
        usePage: () => ({ props: {}, flash: {} }),
        useForm: (initial: Record<string, unknown>) =>
            reactive({
                ...initial,
                errors: {},
                processing: false,
                clearErrors: () => {},
                transform: () => ({ post: () => {}, put: () => {} }),
            }),
        Head: defineComponent({
            setup:
                (_, { slots }) =>
                () =>
                    h('div', slots.default?.()),
        }),
        Link: defineComponent({
            inheritAttrs: false,
            setup:
                (_, { attrs, slots }) =>
                () =>
                    h('a', attrs, slots.default?.()),
        }),
    };
});

function item(
    overrides: Partial<ActivitySessionItem> = {},
): ActivitySessionItem {
    return {
        key: 'session-1',
        session_ulid: 'ses-1',
        activity_ulid: 'act-1',
        title: 'Apoio 5.º',
        kind: 'student_support',
        kind_label: 'Apoio a alunos',
        occurs_on: '2026-10-13',
        starts_at: '2026-10-13T10:30:00+01:00',
        ends_at: '2026-10-13T11:20:00+01:00',
        start_time: '10:30',
        end_time: '11:20',
        school_class: null,
        status: 'done',
        presentation: 'done',
        presentation_label: 'Realizada',
        summary: 'Fichas de revisão.\nJogo final.',
        has_summary: true,
        session_number: 2,
        numbering_enabled: true,
        registrable: true,
        follows_schedule: true,
        ...overrides,
    };
}

function mountPage(
    sessions: ActivitySessionItem[] = [item()],
    extra: Record<string, unknown> = {},
) {
    return mount(Index, {
        props: {
            sessions,
            filters: { activity: null, from: '2026-09-01', to: '2027-06-30' },
            activityOptions: [
                { value: 'act-1', label: 'Apoio 5.º · Apoio a alunos' },
                {
                    value: 'title:Antiga',
                    label: 'Antiga · atividade já sem horário',
                },
            ],
            pagination: {
                current_page: 1,
                last_page: 1,
                total: sessions.length,
                previous_url: null,
                next_url: null,
            },
            exportUrl:
                '/activity-sessions/export?from=2026-09-01&to=2027-06-30',
            ...extra,
        },
        attachTo: document.body,
    });
}

beforeEach(() => {
    document.body.innerHTML = '';
    routerGet.mockClear();
});

describe('histórico das outras atividades', () => {
    it('lista as sessões com data, horas, atividade, número, estado e o sumário completo', () => {
        const wrapper = mountPage();
        const row = wrapper.get('[data-testid="activity-session-row"]');

        expect(row.text()).toContain('Apoio 5.º');
        expect(row.text()).toContain('10:30–11:20');
        expect(row.text()).toContain('Apoio a alunos');
        expect(row.text()).toContain('Sessão 2');
        expect(row.get('[data-testid="activity-session-state"]').text()).toBe(
            'Realizada',
        );
        expect(
            row.get('[data-testid="activity-session-summary-text"]').text(),
        ).toContain('Jogo final.');
        expect(
            row.get('[data-testid="activity-session-summary-text"]').classes(),
        ).toContain('whitespace-pre-line');
        expect(
            row.get('[data-testid="activity-session-summary-text"]').classes(),
        ).toContain('wrap-anywhere');
        wrapper.unmount();
    });

    it('sem registos diz-no, sem inventar linhas', () => {
        const wrapper = mountPage([], {
            pagination: {
                current_page: 1,
                last_page: 1,
                total: 0,
                previous_url: null,
                next_url: null,
            },
        });

        expect(
            wrapper.find('[data-testid="activity-session-row"]').exists(),
        ).toBe(false);
        expect(wrapper.text()).toContain('0 sessões com registos');
        wrapper.unmount();
    });

    it('o botão Exportar é uma ligação normal (descarregamento), com o filtro atual', () => {
        const wrapper = mountPage();
        const link = wrapper.get('[data-testid="activity-sessions-export"]');

        expect(link.element.tagName).toBe('A');
        expect(link.attributes('href')).toBe(
            '/activity-sessions/export?from=2026-09-01&to=2027-06-30',
        );
        wrapper.unmount();
    });

    it('filtrar pede ao servidor a atividade e o período escolhidos', async () => {
        const wrapper = mountPage();

        const select = wrapper.get('#activity-sessions-activity');
        await select.setValue('title:Antiga');
        await wrapper.get('form').trigger('submit');

        expect(routerGet).toHaveBeenCalledWith(
            '/activity-sessions',
            { activity: 'title:Antiga', from: '2026-09-01', to: '2027-06-30' },
            expect.anything(),
        );
        wrapper.unmount();
    });

    it('abrir uma sessão abre o registo, para consultar e editar', async () => {
        const wrapper = mountPage();

        await wrapper
            .get('[data-testid="activity-session-open"]')
            .trigger('click');

        expect(
            document.body.querySelector(
                '[data-testid="activity-session-dialog"]',
            ),
        ).not.toBeNull();
        expect(
            (
                document.body.querySelector(
                    '[data-testid="activity-session-summary"]',
                ) as HTMLTextAreaElement
            ).value,
        ).toContain('Fichas de revisão.');
        wrapper.unmount();
    });
});
