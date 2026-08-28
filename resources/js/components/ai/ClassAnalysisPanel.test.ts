import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ClassAnalysisPanel from './ClassAnalysisPanel.vue';

/**
 * The four blocks, the states around them, and the absence of any control
 * that would write something back.
 */

const post = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    router: {
        post: (...args: unknown[]) => post(...args),
    },
}));

type Props = InstanceType<typeof ClassAnalysisPanel>['$props'];

const analysis = {
    period_id: 3,
    period_label: '2.º Período',
    summary: 'Os resultados concentram-se no nível intermédio.',
    patterns: ['A distribuição é estreita.', 'A evolução é ligeiramente positiva.'],
    cautions: ['Alguns resultados assentam em cobertura parcial.'],
    suggestions: ['Pode ser útil considerar mais evidência.'],
};

const wrappers: VueWrapper[] = [];

function mountPanel(props: Partial<Props> = {}) {
    const wrapper = mount(ClassAnalysisPanel, {
        props: {
            ai: { available: true, reason: null },
            classUlid: '01JCLASS',
            ...props,
        } as Props,
    });

    wrappers.push(wrapper);

    return wrapper;
}

beforeEach(() => post.mockReset());

afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
});

describe('ClassAnalysisPanel — availability', () => {
    it('offers the action when the experience is available', () => {
        const wrapper = mountPanel();

        expect(wrapper.text()).toContain('Analisar com IA');
        expect(wrapper.findAll('button').some((b) => b.text().includes('Analisar com IA'))).toBe(true);
    });

    it('explains a plan lock and offers no button at all', () => {
        const wrapper = mountPanel({ ai: { available: false, reason: 'plan' } });

        expect(wrapper.text()).toContain('não está incluída no plano');
        expect(wrapper.findAll('button')).toHaveLength(0);
    });

    it('distinguishes a switched-off installation from a plan lock', () => {
        const wrapper = mountPanel({ ai: { available: false, reason: 'off' } });

        expect(wrapper.text()).toContain('não está ativada nesta instalação');
    });

    it('reports a half-configured engine without naming the missing setting', () => {
        for (const reason of ['credential_missing', 'model_missing', 'endpoint_missing', 'unknown_driver']) {
            const wrapper = mountPanel({ ai: { available: false, reason } });

            expect(wrapper.text()).toContain('não está configurada nesta instalação');
            expect(wrapper.text()).not.toContain(reason);
        }
    });

    it('never runs on its own — nothing is requested on mount', () => {
        mountPanel();

        expect(post).not.toHaveBeenCalled();
    });
});

describe('ClassAnalysisPanel — requesting', () => {
    it('posts to the class and period the page is showing', async () => {
        const wrapper = mountPanel({ periodUlid: '01JPERIOD' });

        await wrapper.findAll('button').find((b) => b.text().includes('Analisar'))!.trigger('click');

        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0][0]).toContain('01JCLASS');
        expect(post.mock.calls[0][0]).toContain('01JPERIOD');
    });

    it('carries «dados até» so a filtered page is analysed as filtered', async () => {
        const wrapper = mountPanel({ cutoff: '2026-03-15' });

        await wrapper.findAll('button').find((b) => b.text().includes('Analisar'))!.trigger('click');

        expect(post.mock.calls[0][1]).toEqual({ ate: '2026-03-15' });
    });

    it('sends no figures back to the server — the read model is rebuilt there', async () => {
        const wrapper = mountPanel({ analysis });

        await wrapper.findAll('button').find((b) => b.text().includes('Analisar'))!.trigger('click');

        expect(post.mock.calls[0][1]).toEqual({});
    });

    it('shows a skeleton and a busy live region while analysing', async () => {
        const wrapper = mountPanel();

        await wrapper.findAll('button').find((b) => b.text().includes('Analisar'))!.trigger('click');
        post.mock.calls[0][2].onStart();
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[aria-live="polite"]').attributes('aria-busy')).toBe('true');
        expect(wrapper.text()).toContain('A interpretar os resultados');
        expect(wrapper.findAll('.animate-pulse').length).toBeGreaterThan(0);
    });
});

describe('ClassAnalysisPanel — the four blocks', () => {
    it('renders síntese, padrões, atenção and sugestões under their own headings', () => {
        const wrapper = mountPanel({ analysis });

        const text = wrapper.text();

        expect(text).toContain('Síntese');
        expect(text).toContain('Padrões observados');
        expect(text).toContain('Pontos de atenção');
        expect(text).toContain('Sugestões pedagógicas');

        expect(text).toContain('nível intermédio');
        expect(text).toContain('A distribuição é estreita.');
        expect(text).toContain('cobertura parcial');
        expect(text).toContain('mais evidência');
    });

    it('never shows raw JSON to the teacher', () => {
        const wrapper = mountPanel({ analysis });

        expect(wrapper.text()).not.toContain('{');
        expect(wrapper.text()).not.toContain('"summary"');
        expect(wrapper.text()).not.toContain('period_id');
    });

    it('omits a block the analysis did not produce rather than showing an empty heading', () => {
        const wrapper = mountPanel({ analysis: { ...analysis, cautions: [] } });

        expect(wrapper.text()).not.toContain('Pontos de atenção');
        expect(wrapper.text()).toContain('Padrões observados');
    });

    it('says which period the reading is of', () => {
        const wrapper = mountPanel({ analysis });

        expect(wrapper.text()).toContain('Leitura de 2.º Período');
    });

    it('shows the disclosure, and says pseudonymised rather than anonymous', () => {
        const wrapper = mountPanel({ analysis });

        expect(wrapper.text()).toContain('A IA apoia a análise e pode cometer erros');
        expect(wrapper.text()).toContain('decisões pedagógicas continuam a ser do professor');
        expect(wrapper.text()).toContain('pseudonimizados');
        expect(wrapper.text().toLowerCase()).not.toContain('anónimo');
        expect(wrapper.text().toLowerCase()).not.toContain('anonimizado');
    });
});

describe('ClassAnalysisPanel — the AI suggests, the teacher decides', () => {
    it('offers no control that could write anything back', () => {
        const wrapper = mountPanel({ analysis });

        // No form, no inputs, no checkboxes: there is nothing here to submit.
        expect(wrapper.find('form').exists()).toBe(false);
        expect(wrapper.findAll('input')).toHaveLength(0);
        expect(wrapper.findAll('select')).toHaveLength(0);
        expect(wrapper.findAll('textarea')).toHaveLength(0);

        // The only button re-reads; nothing applies, saves or accepts.
        const labels = wrapper.findAll('button').map((b) => b.text());
        expect(labels.every((label) => /Analisar/.test(label))).toBe(true);
        expect(labels.join(' ')).not.toMatch(/Aplicar|Guardar|Aceitar|Lançar/);
    });
});

describe('ClassAnalysisPanel — failure', () => {
    it('shows a controlled message with a working retry', async () => {
        const wrapper = mountPanel({ error: { message: 'Não foi possível obter uma análise neste momento.' } });

        expect(wrapper.text()).toContain('Não foi possível obter uma análise');

        const retry = wrapper.findAll('button').find((b) => b.text().includes('Tentar novamente'));
        expect(retry).toBeDefined();

        await retry!.trigger('click');
        expect(post).toHaveBeenCalledTimes(1);
    });

    it('does not show a stale analysis next to an error', () => {
        const wrapper = mountPanel({ error: { message: 'Falhou.' } });

        expect(wrapper.text()).not.toContain('Padrões observados');
    });
});

describe('ClassAnalysisPanel — accessibility', () => {
    it('labels its region and hides decorative icons', () => {
        const wrapper = mountPanel({ analysis });

        expect(wrapper.find('section').attributes('aria-labelledby')).toBe('analise-ia');
        expect(wrapper.find('#analise-ia').exists()).toBe(true);

        wrapper.findAll('svg').forEach((icon) => {
            expect(icon.attributes('aria-hidden')).toBe('true');
        });
    });

    it('keeps the blocks as real headings and lists, not styled divs', () => {
        const wrapper = mountPanel({ analysis });

        expect(wrapper.findAll('h3').length).toBeGreaterThanOrEqual(3);
        expect(wrapper.findAll('ul').length).toBeGreaterThanOrEqual(3);
        expect(wrapper.findAll('li').length).toBe(
            analysis.patterns.length + analysis.cautions.length + analysis.suggestions.length,
        );
    });
});
