import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import Index from './Index.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    Link: defineComponent({ inheritAttrs: false, setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()) }),
}));

const classes = [
    {
        ulid: 'cls-1',
        label: '7.º A',
        subject: 'Português',
        academic_year: '2025/2026',
        teachers: ['Ana Martins', 'Bruno Costa'],
        periods: [
            { ulid: 'per-1', label: '1.º Semestre', published_count: 24, rectified_count: 2 },
            { ulid: 'per-2', label: '2.º Semestre', published_count: 1, rectified_count: 1 },
        ],
    },
    {
        ulid: 'cls-2',
        label: '8.º B',
        subject: null,
        academic_year: '2025/2026',
        teachers: [],
        periods: [{ ulid: 'per-9', label: '1.º Semestre', published_count: 10, rectified_count: 0 }],
    },
];

describe('Retificações — lista', () => {
    it('explica, com honestidade, o que a página faz', () => {
        const text = mount(Index, { props: { classes } }).text();

        expect(text).toContain('Retificações');
        expect(text).toContain('incluindo de turmas que não leciona');
        expect(text).toContain('Cada consulta fica registada.');
    });

    it('lista turmas, professores e os períodos encerrados com as contagens', () => {
        const wrapper = mount(Index, { props: { classes } });
        const cards = wrapper.findAll('[data-testid="rectification-class"]');

        expect(cards).toHaveLength(2);
        expect(cards[0].text()).toContain('7.º A');
        expect(cards[0].text()).toContain('Português');
        expect(cards[0].text()).toContain('2025/2026');
        expect(cards[0].text()).toContain('Ana Martins, Bruno Costa');
        expect(cards[0].text()).toContain('24 publicadas · 2 retificadas');
        expect(cards[0].text()).toContain('1 publicada · 1 retificada');
        expect(cards[1].text()).toContain('Sem professor atribuído');
    });

    it('cada período liga à sua tabela, com alvo táctil de 44px', () => {
        const links = mount(Index, { props: { classes } }).findAll('[data-testid="rectification-period-link"]');

        expect(links.map((link) => link.attributes('href'))).toEqual([
            '/classes/cls-1/retificacoes/per-1',
            '/classes/cls-1/retificacoes/per-2',
            '/classes/cls-2/retificacoes/per-9',
        ]);
        links.forEach((link) => expect(link.classes()).toEqual(expect.arrayContaining(['min-h-11', 'md:min-h-9'])));
    });

    it('sem nada a retificar diz-o, e não desenha lista', () => {
        const wrapper = mount(Index, { props: { classes: [] } });

        expect(wrapper.text()).toContain('Não há classificações publicadas em períodos encerrados.');
        expect(wrapper.find('[data-testid="rectification-classes"]').exists()).toBe(false);
    });
});
