import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import Grid from './Grid.vue';

const inertia = vi.hoisted(() => ({
    page: {
        props: {
            auth: {
                user: { id: 17 },
                organization: { ulid: 'organization-a' },
            },
        },
    },
    post: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    Link: defineComponent({ inheritAttrs: false, setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()) }),
    router: { post: inertia.post },
    useForm: () => ({
        reason: '',
        errors: {},
        processing: false,
        reset: vi.fn(),
        clearErrors: vi.fn(),
        post: vi.fn(),
    }),
    usePage: () => inertia.page,
}));

const item = {
    id: 31,
    code: 'Q1',
    label: 'Questão 1',
    points_possible: 20,
    is_bonus: false,
    domains: [{ domain_id: 4, name: 'Conhecimento', percent: 100 }],
};

function props(overrides: Record<string, unknown> = {}) {
    return {
        instrument: {
            ulid: 'instrument-a',
            title: 'Teste de Frações',
            applied_on: '2026-09-13',
            status_label: 'Em correção',
            is_completed: false,
            can_complete: false,
            pending_count: 1,
            applicable_count: 1,
            completed_count: 0,
            completed_at: null,
            pending_students: ['Aluno Teste'],
            status: 'applied',
            cancellation_reason: null,
            total_points: 20,
            class_label: '7.º C',
            class_ulid: 'class-a',
            period: '1.º período',
            ...overrides,
        },
        items: [item],
        students: [{
            enrollment_id: 11,
            name: 'Aluno Teste',
            photo_url: null,
            class_number: 1,
            enrolled_on: '2026-01-01',
            is_late_entry: false,
            joined_after_instrument: false,
        }],
        scores: [{
            enrollment_id: 11,
            instrument_item_id: 31,
            result_state: 'pending',
            points_earned: null,
            state_reason: null,
            lock_version: 7,
        }],
        states: [
            { value: 'pending', label: 'Por avaliar', carries_value: false, resolves: false },
            { value: 'assessed', label: 'Avaliado', carries_value: true, resolves: true },
            { value: 'absent', label: 'Faltou', carries_value: false, resolves: true },
        ],
        scaleBands: [],
    };
}

/** The address the teacher arrived at this page by — the whole mechanism. */
function arriveAt(query: string): void {
    window.history.replaceState({}, '', `/instruments/instrument-a${query}`);
}

function backLink(wrapper: VueWrapper) {
    return wrapper
        .findAll('a')
        .find((anchor) => anchor.text().startsWith('←'));
}

/**
 * A grelha de um elemento de avaliação, e as duas coisas que o cabeçalho tem de
 * dizer bem: POR ONDE SE VOLTA, e QUE DIA É ESTE.
 *
 * O caminho de volta é lido do próprio endereço — o mesmo mecanismo que
 * «Avaliações» já usava (?from=assessments) — e não de uma prop servida pelo
 * servidor: cada porta de entrada escreve no link por onde se voltou a entrar,
 * e nenhuma delas precisa que o InstrumentController saiba de nada.
 */
describe('instruments/Grid — o cabeçalho', () => {
    beforeEach(() => {
        arriveAt('');
    });

    afterEach(() => {
        arriveAt('');
    });

    it('returns to the Calendário, and to the very month it was opened from', () => {
        arriveAt('?from=calendar&month=2026-09');

        const link = backLink(mount(Grid, { props: props() }));

        expect(link?.text()).toContain('← Voltar ao Calendário');
        expect(link?.attributes('href')).toBe('/calendar?month=2026-09');
    });

    it('still returns to Avaliações when that is where it was opened from', () => {
        arriveAt('?from=assessments');

        const link = backLink(mount(Grid, { props: props() }));

        expect(link?.text()).toContain('← Voltar a Avaliações');
        expect(link?.attributes('href')).toBe('/assessments/instrument-a');
    });

    /**
     * A porta de entrada que já existia continua a ganhar: «Avaliações» não é
     * perturbada por nada do que a entrada do Calendário acrescenta.
     */
    it('keeps Avaliações ahead of the Calendário when both are somehow asked for', () => {
        arriveAt('?from=assessments&month=2026-09');

        expect(backLink(mount(Grid, { props: props() }))?.text()).toContain(
            '← Voltar a Avaliações',
        );
    });

    it('returns to the turma when it was opened from anywhere else', () => {
        const link = backLink(mount(Grid, { props: props() }));

        expect(link?.text()).toContain('← Voltar à turma');
        expect(link?.attributes('href')).toBe('/classes/class-a');
    });

    /**
     * Um «month» que não é um mês não constrói link nenhum para o calendário: a
     * rota de destino volta a validá-lo no servidor, e isto é apenas a sanidade
     * que evita escrever um endereço visivelmente inválido.
     */
    it('falls back to the turma when the month in the address is not a month', () => {
        arriveAt('?from=calendar&month=outubro');

        expect(backLink(mount(Grid, { props: props() }))?.text()).toContain(
            '← Voltar à turma',
        );
    });

    it('falls back to the turma when the calendar sent no month at all', () => {
        arriveAt('?from=calendar');

        expect(backLink(mount(Grid, { props: props() }))?.text()).toContain(
            '← Voltar à turma',
        );
    });

    // ----------------------------------------------------------- a data

    /**
     * A DATA COMO SE ESCREVE EM PORTUGAL, e não como o servidor a transporta:
     * «2026-09-13» é o formato de uma coluna, não o de uma frase.
     */
    it('writes the date of the avaliação in Portuguese, never as the raw ISO string', () => {
        const wrapper = mount(Grid, { props: props() });

        const description = wrapper.find('header p');

        expect(description.text()).toBe('7.º C · 1.º período · 13/09/2026');
        expect(wrapper.text()).not.toContain('2026-09-13');
    });

    /**
     * O primeiro dia do mês não escorrega para o dia anterior: uma data «Y-m-d»
     * é lida em UTC, e não à meia-noite do fuso de quem a lê.
     */
    it('reads a bare date without letting the timezone move it a day back', () => {
        const wrapper = mount(Grid, { props: props({ applied_on: '2026-01-01' }) });

        expect(wrapper.find('header p').text()).toContain('01/01/2026');
    });

    /**
     * A MESMA data, escrita da mesma maneira, em toda a página: a explicação de
     * porque é que a correção não pode ser concluída fala da mesma data do
     * cabeçalho, e não pode escrevê-la de outra forma.
     */
    it('writes the same date the same way where it explains an avaliação that applies to nobody', () => {
        const wrapper = mount(Grid, {
            props: props({ applicable_count: 0, pending_count: 0, pending_students: [] }),
        });

        expect(wrapper.text()).toContain('Esta avaliação está datada de 13/09/2026');
        expect(wrapper.text()).not.toContain('2026-09-13');
    });
});
