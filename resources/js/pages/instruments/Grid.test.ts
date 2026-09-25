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
        scaleBands: [
            { label: 'Insuficiente', band_min: '0', band_max: '49.4', sequence: 1, is_negative: true },
            { label: 'Suficiente', band_min: '49.5', band_max: '100', sequence: 2, is_negative: false },
        ],
        official: {
            status: 'official' as 'official' | 'provisional',
            label: 'Classificação oficial',
            threshold: '49.5' as string | null,
            domains: [{ key: 'd4', id: 4, name: 'Conhecimento' }],
            students: {
                11: {
                    status: 'classified',
                    status_label: 'Classificado',
                    global: { value: '72.4', value_precise: null as string | null, exact: '72.399123', band: { key: 'suf', code: 'Suf', label: 'Suficiente', sequence: 2, is_negative: false }, below_threshold: false, is_partial: false },
                    domains: {
                        d4: { value: '72.4', value_precise: null as string | null, exact: '72.399123', band: { key: 'suf', code: 'Suf', label: 'Suficiente', sequence: 2, is_negative: false }, below_threshold: false, is_partial: false } as { value: string | null; value_precise: string | null; exact: string | null; band: { key: string; code: string; label: string; sequence: number; is_negative: boolean } | null; below_threshold: boolean | null; is_partial: boolean } | null,
                    },
                },
            },
        },
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

        expect(link?.text()).toContain('← Voltar a Grelhas de correção');
        expect(link?.attributes('href')).toBe('/assessments/instrument-a');
    });

    /**
     * A porta de entrada que já existia continua a ganhar: «Avaliações» não é
     * perturbada por nada do que a entrada do Calendário acrescenta.
     */
    it('keeps Avaliações ahead of the Calendário when both are somehow asked for', () => {
        arriveAt('?from=assessments&month=2026-09');

        expect(backLink(mount(Grid, { props: props() }))?.text()).toContain(
            '← Voltar a Grelhas de correção',
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

/**
 * Uma correção concluída é consultada, não editada: os campos ficam
 * `readonly`/`disabled` e o cabeçalho di-lo por extenso — nunca por cor
 * sozinha (§WCAG).
 */
describe('instruments/Grid — coerência dos resultados com o motor', () => {
    it('a coluna «Pontuação bruta» mostra só pontos/percentagem, sem banda qualitativa', () => {
        const wrapper = mount(Grid, { props: props() });

        const rawColumnHeader = wrapper.findAll('th').find((th) => th.text().includes('Pontuação bruta'));
        expect(rawColumnHeader).toBeTruthy();
        expect(rawColumnHeader?.text()).toContain('pontos obtidos / cotação');

        const rawCell = wrapper.find('td.font-semibold.tabular-nums');
        expect(rawCell.find('.badge, [class*="bg-emerald"], [class*="bg-amber"], [class*="bg-red"]').exists()).toBe(false);
    });

    it('mostra o valor e a banda do servidor na coluna oficial', () => {
        const wrapper = mount(Grid, { props: props() });

        expect(wrapper.text()).toContain('Classificação oficial');
        expect(wrapper.text()).toContain('72,4 %');
        expect(wrapper.text()).toContain('Suficiente');
    });

    it('mostra o valor preciso a duas casas quando o servidor o envia — caso 49,46 %', () => {
        const overrides = props();
        overrides.official.students[11].global = {
            value: '49.5', value_precise: '49.46', exact: '49.459999',
            band: { key: 'insuf', code: 'Insuf', label: 'Insuficiente', sequence: 1, is_negative: true },
            below_threshold: true, is_partial: false,
        };

        const wrapper = mount(Grid, { props: overrides });

        expect(wrapper.text()).toContain('49,46 %');
        expect(wrapper.text()).toContain('Insuficiente');
    });

    it('mostra a etiqueta «provisória» quando os resultados ainda não são oficiais', () => {
        const overrides = props();
        overrides.official.status = 'provisional';
        overrides.official.label = 'Classificação provisória';

        const wrapper = mount(Grid, { props: overrides });

        expect(wrapper.text()).toContain('provisória');
        expect(wrapper.text()).toContain('Classificação provisória');
        expect(wrapper.text()).toContain('Valores provisórios: a correção ainda não está concluída.');
    });

    it('mostra «guarde para recalcular» junto ao valor oficial depois de editar uma célula por guardar', async () => {
        const wrapper = mount(Grid, { props: props() });

        expect(wrapper.text()).not.toContain('guarde para recalcular');

        const input = wrapper.find('input[type="number"]');
        await input.setValue('15');

        expect(wrapper.text()).toContain('guarde para recalcular');
    });
});

describe('instruments/Grid — correção concluída', () => {
    it('mostra a grelha em modo de consulta: pontos readonly, estado disabled, aviso presente', () => {
        const wrapper = mount(Grid, {
            props: props({ is_completed: true, can_complete: false, pending_count: 0 }),
        });

        const pointsInput = wrapper.find('input[type="number"]');
        expect(pointsInput.attributes('readonly')).toBeDefined();

        const stateSelect = wrapper
            .findAll('select')
            .find((select) => select.attributes('aria-label')?.startsWith('Estado de'));
        expect(stateSelect?.attributes('disabled')).toBeDefined();

        expect(wrapper.text()).toContain('Correção concluída — em modo de consulta.');

        // Recuperação de rascunho: omitido — plantar um rascunho aqui exige
        // escrever directamente no localStorage com a chave que useGridDraft
        // deriva (organização/utilizador/instrumento/fingerprint), o que este
        // ficheiro não faz em nenhum teste existente.
    });
});
