import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ACCUMULATED_NOT_AN_AVERAGE, ACCUMULATED_VERSUS_CONTINUOUS } from '@/lib/readings';
import AccumulatedBreakdownPanel from './AccumulatedBreakdownPanel.vue';
import type { Breakdown } from './AccumulatedBreakdownPanel.vue';

/**
 * O PAINEL QUE EXPLICA O NÚMERO, com o caso real que o motivou.
 *
 *   1.º semestre      85,00 / 125,00  =  68,0 %      peso efetivo 81,17 %
 *   2.º semestre       7,33 /  29,00  =  25,3 %      peso efetivo 18,83 %
 *   TOTAL             92,33 / 154,00  =  60,0 %      →  60 (half_up, 0)
 *
 * O QUE ESTES TESTES DEFENDEM é que a conta CHEGA AO ECRÃ inteira. Um painel
 * que mostrasse o resultado sem os pontos seria o tooltip genérico outra vez,
 * com mais píxeis; o que o torna uma explicação é 85 em 125 estar lá escrito.
 */

const breakdown: Breakdown = {
    scope: 'domain',
    period: { id: 2, ulid: 'p2', label: '2.º Semestre', kind_label: 'Semestre' },
    student: { enrollment_ulid: 'e1', class_number: 1 },
    reading: {
        name: 'Desempenho acumulado',
        long_name: 'Resultado acumulado dos elementos de avaliação',
        explanation: 'É uma leitura complementar.',
        not_an_average: ACCUMULATED_NOT_AN_AVERAGE,
        versus_continuous: ACCUMULATED_VERSUS_CONTINUOUS,
    },
    rounding: {
        mode: 'half_up',
        mode_label: 'meio para cima (half_up)',
        scale: 0,
        stage: 'final_only',
        stage_label: 'Uma única vez, na proposta final',
    },
    domain: { domain_id: 16, name: 'Educação Literária' },
    units: [
        {
            period_id: 1,
            label: '1.º Semestre',
            kind_label: 'Semestre',
            points_earned: '85.0000',
            points_possible: '125.0000',
            normalized_value: '68.000000',
            effective_weight_percent: '81.168831',
        },
        {
            period_id: 2,
            label: '2.º Semestre',
            kind_label: 'Semestre',
            points_earned: '7.3300',
            points_possible: '29.0000',
            normalized_value: '25.275862',
            effective_weight_percent: '18.831168',
        },
    ],
    total: {
        points_earned: '92.3300',
        points_possible: '154.0000',
        normalized_value: '59.954545',
        proposed_value: '60',
        level: { scale_level_id: 3, code: '3', label: 'Suficiente', sequence: 3, is_negative: false },
        coverage_warning: false,
    },
    elements: [
        {
            item_code: 'Q1',
            instrument_id: 28,
            instrument_title: 'ED. Lit. 1',
            instrument_status_label: 'Concluído',
            applied_on: '2026-12-16',
            period_label: '1.º Semestre',
            state: 'assessed',
            state_label: 'Avaliado',
            points_earned: '65.0000',
            points_possible: '100.0000',
            allocation_percent: '100.0000',
            is_bonus: false,
            contribution: '42.207792',
        },
        {
            item_code: 'G3',
            instrument_id: 32,
            instrument_title: 'Teste de Português Intuitivo',
            instrument_status_label: 'Concluído',
            applied_on: '2027-04-14',
            period_label: '2.º Semestre',
            state: 'assessed',
            state_label: 'Avaliado',
            points_earned: '7.3300',
            points_possible: '29.0000',
            allocation_percent: '100.0000',
            is_bonus: false,
            contribution: '4.759740',
        },
    ],
    excluded: [],
    domains: [],
    weight_total_applied: null,
};

const wrappers: ReturnType<typeof mount>[] = [];

function open(hasDeclaredPeriodWeights = false) {
    const wrapper = mount(AccumulatedBreakdownPanel, {
        props: {
            url: '/classes/c1/results/desempenho-acumulado/p2/e1/d16',
            studentName: 'Álvaro Simões',
            hasDeclaredPeriodWeights,
        },
    });

    wrappers.push(wrapper);

    return wrapper;
}

beforeEach(() => {
    vi.stubGlobal(
        'fetch',
        vi.fn(async () => ({ ok: true, json: async () => breakdown })),
    );
});

afterEach(() => {
    vi.unstubAllGlobals();
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
});

describe('AccumulatedBreakdownPanel', () => {
    it('mostra a conta por unidade, com os pontos e o peso efetivo', async () => {
        const wrapper = open();
        await flushPromises();

        const text = wrapper.text();

        // OS PONTOS SÃO A EXPLICAÇÃO. Sem eles isto era outra vez um tooltip.
        expect(text).toContain('85,00 / 125,00');
        expect(text).toContain('7,33 / 29,00');
        expect(text).toContain('92,33 / 154,00');

        // O peso efetivo é o que faz o 1.º semestre valer o que vale.
        expect(text).toContain('81,2 %');
        expect(text).toContain('18,8 %');
    });

    it('escreve a fração que reproduz o valor exibido', async () => {
        const wrapper = open();
        await flushPromises();

        // A LINHA QUE TORNA O NÚMERO RECONSTRUÍVEL: quem tem os pontos refaz a
        // divisão à mão e chega ao mesmo sítio.
        expect(wrapper.text()).toContain('92,33 ÷ 154,00 = 59,954545 %');
    });

    it('diz que não é a média dos semestres', async () => {
        const wrapper = open();
        await flushPromises();

        expect(wrapper.text()).toContain(ACCUMULATED_NOT_AN_AVERAGE);
    });

    it('contrasta com a avaliação contínua quando as unidades têm pesos declarados', async () => {
        const wrapper = open(true);
        await flushPromises();

        // Com pesos formais declarados a frase útil é outra: a diferença entre
        // as duas leituras deixa de ser «não é uma média» e passa a ser «são
        // pesos diferentes».
        expect(wrapper.text()).toContain(ACCUMULATED_VERSUS_CONTINUOUS);
    });

    it('guarda o arredondamento em «detalhes do cálculo» e não na conta principal', async () => {
        const wrapper = open();
        await flushPromises();

        // Fechado por omissão: 59,954545 % não é o que se lê primeiro.
        expect(wrapper.text()).not.toContain('meio para cima');

        const toggle = wrapper.findAll('button').find((button) => button.text().includes('detalhes do cálculo'));
        expect(toggle).toBeDefined();

        await toggle!.trigger('click');

        const text = wrapper.text();
        expect(text).toContain('59,954545 %');
        expect(text).toContain('meio para cima (half_up)');
        expect(text).toContain('Uma única vez, na proposta final');
    });

    it('abre os elementos a pedido, cada um com a sua contribuição', async () => {
        const wrapper = open();
        await flushPromises();

        const toggle = wrapper.findAll('button').find((button) => button.text().includes('2 elementos considerados'));
        expect(toggle).toBeDefined();

        await toggle!.trigger('click');

        const text = wrapper.text();
        expect(text).toContain('ED. Lit. 1');
        expect(text).toContain('65,00 / 100,00');
        expect(text).toContain('Teste de Português Intuitivo');
        expect(text).toContain('7,33 / 29,00');
        expect(text).toContain('42,21 %');
    });

    it('não pede nada enquanto ninguém abre uma célula', () => {
        const wrapper = mount(AccumulatedBreakdownPanel, {
            props: { url: null, studentName: '', hasDeclaredPeriodWeights: false },
        });
        wrappers.push(wrapper);

        // A DECOMPOSIÇÃO É A PEDIDO (§26): trezentas células não podem custar
        // trezentos pedidos por precaução.
        expect(fetch).not.toHaveBeenCalled();
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
    });
});
