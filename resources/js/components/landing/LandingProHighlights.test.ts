import { mount } from '@vue/test-utils';
import { beforeAll, describe, expect, it, vi } from 'vitest';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { PRO_HIGHLIGHTS, PRO_HIGHLIGHTS_SHORT } from './commercial';
import LandingProHighlights from './LandingProHighlights.vue';

vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        Link: defineComponent({
            props: { href: { type: [String, Object], default: '' } },
            setup:
                (props, { slots }) =>
                () =>
                    h(
                        'a',
                        {
                            href:
                                typeof props.href === 'string'
                                    ? props.href
                                    : ((props.href as { url?: string }).url ??
                                      ''),
                        },
                        slots.default?.(),
                    ),
        }),
    };
});

beforeAll(() => {
    Object.defineProperty(window, 'matchMedia', {
        configurable: true,
        value: (query: string) => ({
            matches: false,
            media: query,
            addEventListener: () => {},
            removeEventListener: () => {},
        }),
    });
});

const squish = (text: string): string => text.replace(/\s+/g, ' ');

/**
 * «Pro em destaque»: five blocks on /planos, three on the landing, one honesty
 * line on both, and — until a real capture exists — no picture at all.
 */
describe('the «Pro em destaque» section', () => {
    it('tells the five blocks in order, each with its sentence and its evidence', () => {
        const wrapper = mount(LandingProHighlights);
        const text = squish(wrapper.text());

        expect(text).toContain(PRO_HIGHLIGHTS.title);
        expect(text).toContain(PRO_HIGHLIGHTS.lead);

        const blocks = wrapper.findAll(
            '[data-highlight]:not([data-highlight="honesty"])',
        );

        expect(blocks.map((b) => b.attributes('data-highlight'))).toEqual([
            'compare',
            'student',
            'strengths',
            'trends',
            'document',
        ]);

        PRO_HIGHLIGHTS.blocks.forEach((block, index) => {
            const own = squish(blocks[index].text());

            expect(own).toContain(block.title);
            expect(own).toContain(block.sentence);
            expect(own).toContain(block.evidence);
        });
    });

    it('says where the Análise stops, in the section itself', () => {
        const wrapper = mount(LandingProHighlights);

        expect(squish(wrapper.find('[data-highlight="honesty"]').text())).toBe(
            'A Análise da Avaliação descreve notas e momentos. Não é um diagnóstico, não faz previsões e não sugere medidas — a leitura pedagógica continua a ser sua.',
        );
    });

    it('keeps the heading hierarchy: one h2 for the section, an h3 per block, no h1', () => {
        const wrapper = mount(LandingProHighlights);

        expect(wrapper.findAll('h1')).toHaveLength(0);
        expect(wrapper.findAll('h2')).toHaveLength(1);
        expect(wrapper.find('h2').text()).toBe(PRO_HIGHLIGHTS.title);
        expect(wrapper.findAll('h3')).toHaveLength(5);
        expect(wrapper.find('section').attributes('id')).toBe(
            'pro-em-destaque',
        );
        expect(wrapper.find('section').attributes('aria-labelledby')).toBe(
            'pro-em-destaque-title',
        );
    });

    it('shows the real capture on /planos, with alt text and dimensions so nothing shifts', () => {
        const images = mount(LandingProHighlights).findAll('img');

        expect(images).toHaveLength(1);
        expect(images[0].attributes('src')).toBe(PRO_HIGHLIGHTS.screenshot.src);
        expect(images[0].attributes('alt')).toBe(PRO_HIGHLIGHTS.screenshot.alt);
        expect(images[0].attributes('alt')).not.toBe('');
        expect(images[0].attributes('width')).toBe('1600');
        expect(images[0].attributes('height')).toBe('950');
        expect(mount(LandingProHighlights).text()).toContain('dados fictícios');
    });

    it('keeps the landing light: no picture in the short version', () => {
        expect(
            mount(LandingProHighlights, { props: { compact: true } }).findAll(
                'img',
            ),
        ).toHaveLength(0);
    });

    it('is shorter on the landing: three blocks, the same honesty line and the way to /planos', () => {
        const wrapper = mount(LandingProHighlights, {
            props: { compact: true },
        });

        const keys = wrapper
            .findAll('li[data-highlight]')
            .map((li) => li.attributes('data-highlight'));

        expect(keys).toEqual([...PRO_HIGHLIGHTS_SHORT]);
        expect(keys).not.toContain('student');
        expect(keys).not.toContain('document');

        const text = squish(wrapper.text());

        expect(text).toContain(PRO_HIGHLIGHTS.title);
        expect(text).toContain(PRO_HIGHLIGHTS.honesty);

        const link = wrapper.find('a');

        expect(link.attributes('href')).toBe('/planos#pro-em-destaque');
        expect(link.text()).toContain(PRO_HIGHLIGHTS.cta);

        // A página tem um H1 (o hero) e os h2 das secções: o id da versão
        // curta não pode colidir com o da completa.
        expect(wrapper.find('section').attributes('id')).not.toBe(
            PRO_HIGHLIGHTS.id,
        );
    });

    /**
     * O CRAWLER LÊ O HTML DO SERVIDOR. A secção tem de estar no que o
     * `renderToString` entrega, com texto visível (RevealOnScroll começa
     * visível por isso mesmo) e sem ficar presa a nada que só o cliente cria.
     */
    it('is in the server-rendered HTML, text and all', async () => {
        const app = createSSRApp(LandingProHighlights);
        const html = await renderToString(app);

        expect(html).toContain('id="pro-em-destaque"');
        expect(html).toContain(PRO_HIGHLIGHTS.title);

        for (const block of PRO_HIGHLIGHTS.blocks) {
            expect(html).toContain(block.title);
        }

        expect(html).not.toContain('opacity-0');
    });
});
