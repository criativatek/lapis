import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { DashboardData, QueueItem } from '@/lib/dashboard';
import DashboardHero from './DashboardHero.vue';

vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        Link: defineComponent({
            props: { href: { type: String, default: '' } },
            setup:
                (props, { slots }) =>
                () =>
                    h('a', { href: props.href }, slots.default?.()),
        }),
    };
});

const turma = { ulid: 'c', label: '7.º A', subject: null, tone: null };
const empty = {
    summaries: { available: true, recent: [], recent_count: 0, older_count: 0, older_href: null },
    classifications: [],
    corrections: { items: [], applied_without_correction: 0 },
    record_alerts: null,
    reviews: [],
    per_class: {},
    counts: { summaries_missing: 0, classifications_proposed: 0, classifications_confirmed: 0, corrections: 0, record_alerts: 0, reviews: 0 },
};

function data(): DashboardData {
    return {
        context: {
            date: '2026-10-26', weekday: 'segunda-feira', now: '2026-10-26T16:40:00+00:00', academic_year: null, period: { ulid: 'p', label: '1.º Período' }, week_number: 9,
            has_classes: true, class_count: 1,
            modules: { lessons: true, calendar: true, assessments: true, records: true, interventions: true, self_assessments: true },
        },
        day: { available: true, date: '2026-10-26', bounds: null, items: [], totals: { lessons: 2, summaries_registered: 1, sessions: 0, sessions_done: 0, absences_recorded: 0 } },
        week: { week: { start: '2026-10-26', end: '2026-10-30', is_current: true }, has_timetable: true, days: [], href: '/lessons' },
        upcoming: [],
        pending: empty,
        done: [],
        classes: [],
        period: null,
    } as DashboardData;
}

const q = (kind: QueueItem['kind'], key: string, href: string, action: string): QueueItem => ({ key, kind, title: `Título ${key}`, detail: 'detalhe', action, href, class: turma, emphasis: 'ink', count: 1 });
const queue: QueueItem[] = [q('summary', 'a', '/lessons/a', 'Registar'), q('correction', 'b', '/assessments/b', 'Corrigir'), q('classifications', 'c', '/c/classifications', 'Continuar')];

function mountHero(over: { d?: DashboardData; queue?: QueueItem[]; assessments?: boolean; records?: boolean } = {}) {
    return mount(DashboardHero, { props: { teacherName: 'Nelson Matias', data: over.d ?? data(), queue: over.queue ?? [], canOpenAssessments: over.assessments ?? true, canOpenRecords: over.records ?? true } });
}

describe('DashboardHero', () => {
    beforeEach(() => window.sessionStorage.clear());

    it('mostra eyebrow e saudação, sem frase de estado nem ação primária', () => {
        const wrapper = mountHero({ queue });

        expect(wrapper.find('h1').text()).toBe('Boa tarde, Nelson.');
        expect(wrapper.text()).toContain('Segunda-feira, 26 de outubro · 1.º Período · 9.ª semana');
        expect(wrapper.find('[data-testid="status-phrase"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="primary-action"]').exists()).toBe(false);
        expect(wrapper.text()).not.toMatch(/Agora|A seguir|Mais tarde|próximo passo|por onde começar/);
    });

    it('três cartões com os tons certos; Pendentes é um botão', () => {
        const wrapper = mountHero({ queue });

        expect(wrapper.findAll('[data-testid="hero-cards"] > *')).toHaveLength(3);
        expect(wrapper.find('[data-testid="hero-today"]').classes().join(' ')).toContain('bg-sky-50/60');
        expect(wrapper.find('[data-testid="hero-summaries"]').text()).toContain('1/2');
        expect(wrapper.find('[data-testid="hero-summaries"]').classes().join(' ')).toContain('bg-emerald-50/60');
        const pending = wrapper.find('[data-testid="hero-pending"]');
        expect(pending.element.tagName).toBe('BUTTON');
        // neutro: o âmbar é só o pequeno indicador junto ao título
        expect(pending.classes().join(' ')).toContain('bg-card');
        expect(pending.classes().join(' ')).not.toContain('bg-amber');
        expect(pending.find('[data-testid="hero-stat-attention"]').exists()).toBe(true);
        expect(pending.text()).toContain('3');
        expect(pending.text()).toContain('pendências');
    });

    it('sem pendências: «Tudo em dia», verde, sem botão nem painel', () => {
        const wrapper = mountHero();
        const pending = wrapper.find('[data-testid="hero-pending"]');

        expect(pending.element.tagName).toBe('DIV');
        expect(pending.text()).toContain('Tudo em dia');
        expect(pending.classes().join(' ')).toContain('bg-emerald-50/60');
        expect(pending.find('[data-testid="hero-stat-attention"]').exists()).toBe(false);
        expect(wrapper.find('button[aria-expanded]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="pending-panel"]').exists()).toBe(false);
    });

    it('aria-expanded alterna e o painel lista TODAS as linhas por ordem, com href e ação', async () => {
        const wrapper = mountHero({ queue });
        const button = wrapper.find('[data-testid="hero-pending"]');

        expect(button.attributes('aria-expanded')).toBe('false');
        expect(wrapper.find('[data-testid="pending-panel"]').exists()).toBe(false);

        await button.trigger('click');
        expect(button.attributes('aria-expanded')).toBe('true');
        const panel = wrapper.find('#' + button.attributes('aria-controls'));
        expect(panel.attributes('role')).toBe('region');
        expect(panel.attributes('aria-label')).toBe('Pendências');
        const links = panel.findAll('a');
        expect(links.map((link) => link.attributes('href'))).toEqual(['/lessons/a', '/assessments/b', '/c/classifications']);
        expect(links.map((link) => link.find('[data-testid="pending-action"]').text())).toEqual(['Registar', 'Corrigir', 'Continuar']);

        await button.trigger('click');
        expect(button.attributes('aria-expanded')).toBe('false');
        expect(wrapper.find('[data-testid="pending-panel"]').exists()).toBe(false);
    });

    it('o estado aberto persiste na sessão e é lido ao montar', async () => {
        const first = mountHero({ queue });
        await first.find('[data-testid="hero-pending"]').trigger('click');
        await first.vm.$nextTick();

        expect(window.sessionStorage.getItem('lapis.dashboard.pendencias')).toBe('1');
        expect(mountHero({ queue }).find('[data-testid="pending-panel"]').exists()).toBe(true);
    });

    it('funciona sem sessionStorage', async () => {
        const get = vi.spyOn(window.sessionStorage, 'getItem').mockImplementation(() => {
            throw new Error('bloqueado');
        });
        const set = vi.spyOn(window.sessionStorage, 'setItem').mockImplementation(() => {
            throw new Error('bloqueado');
        });
        const wrapper = mountHero({ queue });

        await wrapper.find('[data-testid="hero-pending"]').trigger('click');
        expect(wrapper.find('[data-testid="pending-panel"]').exists()).toBe(true);
        get.mockRestore();
        set.mockRestore();
    });

    it('o texto para leitores de ecrã anuncia o número de pendências', async () => {
        const wrapper = mountHero({ queue });
        const live = wrapper.find('[data-testid="pending-live"]');

        expect(live.attributes('aria-live')).toBe('polite');
        expect(live.text()).toBe('3 pendências');
        await wrapper.setProps({ queue: queue.slice(0, 1) });
        expect(live.text()).toBe('1 pendência');
    });

    it('sem turmas só há saudação e botões', () => {
        const d = data();
        d.context.has_classes = false;
        const wrapper = mountHero({ d });

        expect(wrapper.find('[data-testid="hero-cards"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="pending-live"]').exists()).toBe(false);
        expect(wrapper.find('a[href="/assessments"]').exists()).toBe(true);
        // a orientação do painel anterior mantém-se, numa linha e sem cartão
        expect(wrapper.get('[data-testid="no-classes"]').text()).toContain('Ainda sem turmas neste ano letivo.');
        expect(wrapper.get('[data-testid="no-classes"] a').attributes('href')).toBe('/classes');
    });

    it('com turmas não há a linha de «sem turmas»', () => {
        expect(mountHero({ queue }).find('[data-testid="no-classes"]').exists()).toBe(false);
    });

    it('sem o módulo das aulas há dois cartões em duas colunas', () => {
        const d = data();
        d.context.modules.lessons = false;
        const wrapper = mountHero({ d });

        expect(wrapper.find('[data-testid="hero-summaries"]').exists()).toBe(false);
        const grid = wrapper.find('[data-testid="hero-cards"]');
        expect(grid.classes()).toContain('@min-[26.5rem]:grid-cols-2');
        expect(grid.classes().join(' ')).not.toContain('sm:grid-cols');
    });

    it('três cartões: colunas por largura do invólucro (container query), nunca do ecrã', () => {
        const wrapper = mountHero({ queue });
        const grid = wrapper.get('[data-testid="hero-cards"]');

        expect(grid.element.parentElement?.classList.contains('@container')).toBe(true);
        expect(grid.classes()).toEqual(expect.arrayContaining(['grid-cols-1', '@min-[40.5rem]:auto-rows-fr', '@min-[40.5rem]:grid-cols-3']));
        expect(grid.classes().join(' ')).not.toMatch(/(^| )(sm|md|lg):/);
    });

    it('os cartões não cortam texto com reticências e partilham a mesma altura mínima', () => {
        const d = data();
        d.pending.summaries.older_count = 2;
        const wrapper = mountHero({ d, queue });

        for (const key of ['today', 'summaries', 'pending']) {
            const html = wrapper.get(`[data-testid="hero-${key}"]`).html();
            expect(html).not.toContain('truncate');
            expect(html).not.toContain('text-ellipsis');
        }

        const mins = ['today', 'summaries', 'pending'].map((key) => wrapper.get(`[data-testid="hero-${key}"]`).classes().filter((c) => c.includes('min-h-')));
        expect(new Set(mins.map((m) => m.join())).size).toBe(1);
        expect(mins[0]).toHaveLength(1);
    });

    it('«Nova grelha» e «Novo registo» têm alvo táctil de 44 px em ecrã pequeno', () => {
        const wrapper = mountHero();

        for (const href of ['/assessments', '/records']) {
            expect(wrapper.get(`a[href="${href}"]`).classes()).toEqual(expect.arrayContaining(['min-h-11', 'sm:min-h-9']));
        }
    });

    it('«Nova grelha» e «Novo registo» respeitam as permissões', () => {
        const both = mountHero();
        expect(both.find('a[href="/assessments"]').text()).toBe('Nova grelha');
        expect(both.find('a[href="/records"]').text()).toBe('Novo registo');

        const none = mountHero({ assessments: false, records: false });
        expect(none.find('a[href="/assessments"]').exists()).toBe(false);
        expect(none.find('a[href="/records"]').exists()).toBe(false);
        expect(mountHero({ records: false }).find('a[href="/records"]').exists()).toBe(false);
    });
});
