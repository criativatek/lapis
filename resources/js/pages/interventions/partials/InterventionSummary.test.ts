import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import type { SummarisableIntervention } from '@/lib/interventionPresentation';
import InterventionSummary from './InterventionSummary.vue';

const TODAY = '2026-09-20';

function row(overrides: Partial<SummarisableIntervention> = {}): SummarisableIntervention {
    return { is_closed: false, review_on: null, needs_review: false, legal_framing: null, support_measures: [], ...overrides };
}

function summary(interventions: SummarisableIntervention[]) {
    return mount(InterventionSummary, { props: { interventions, today: TODAY } as never });
}

describe('InterventionSummary', () => {
    it('does not exist at all on a class with nothing recorded', () => {
        expect(summary([]).find('section').exists()).toBe(false);
    });

    it('leads with how many are active, in words and not just a number', () => {
        expect(summary([row(), row()]).text()).toContain('2 estratégias e medidas ativas');
    });

    it('says «ativa» in the singular for one', () => {
        expect(summary([row()]).text()).toContain('1 estratégia ou medida ativa');
    });

    it('distinguishes the active ones from the total when some are closed', () => {
        const text = summary([row(), row({ is_closed: true })]).text();

        expect(text).toContain('1 estratégia ou medida ativa');
        expect(text).toContain('de 2 registadas');
    });

    /**
     * §4 do pedido, à letra: se o nível legal ainda não estiver disponível,
     * não inventar — esconder graciosamente.
     */
    it('shows no level pills at all when no intervention carries a level', () => {
        const wrapper = summary([row(), row()]);

        expect(wrapper.findAll('li')).toHaveLength(0);
        expect(wrapper.text()).not.toContain('Medida universal');
    });

    it('names each level in full beside its colour, and counts it', () => {
        const wrapper = summary([
            row({ support_measures: [{ level: 'universal', level_label: 'Medida universal' }] }),
            row({ support_measures: [{ level: 'universal', level_label: 'Medida universal' }] }),
            row({ support_measures: [{ level: 'selective', level_label: 'Medida seletiva' }] }),
        ]);

        const pills = wrapper.findAll('li').map((item) => item.text());

        expect(pills).toEqual(['Medida universal · 2', 'Medida seletiva · 1']);
    });

    it('offers the next review date the teacher chose, spelled out', () => {
        const wrapper = summary([row({ review_on: '2026-11-15' })]);

        expect(wrapper.text()).toContain('Próxima revisão:');
        expect(wrapper.text()).toContain('15 de novembro de 2026');
    });

    it('shows no next review when every chosen date has already passed', () => {
        expect(summary([row({ review_on: '2026-01-05' })]).text()).not.toContain('Próxima revisão');
    });

    it('surfaces the pending reviews with an icon and words, never a colour alone', () => {
        const wrapper = summary([row({ needs_review: true }), row({ needs_review: true })]);

        expect(wrapper.text()).toContain('2');
        expect(wrapper.text()).toContain('com revisão pendente');
        expect(wrapper.find('svg').exists()).toBe(true);
    });

    it('asks the page to filter down to the pending ones, rather than filtering by itself', async () => {
        const wrapper = summary([row({ needs_review: true })]);

        await wrapper.find('button').trigger('click');

        expect(wrapper.emitted('show-pending')).toHaveLength(1);
    });

    it('offers no pending-review button when nothing is pending', () => {
        expect(summary([row()]).findAll('button')).toHaveLength(0);
    });

    it('is a landmark with a heading, so it can be reached and skipped', () => {
        const wrapper = summary([row()]);

        expect(wrapper.find('section').attributes('aria-labelledby')).toBe('interventions-summary-heading');
        expect(wrapper.find('#interventions-summary-heading').text()).toBe('Resumo');
    });
});
