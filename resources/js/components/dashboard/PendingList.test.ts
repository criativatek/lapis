import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import type { QueueItem } from '@/lib/dashboard';
import PendingList from './PendingList.vue';

vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        Link: defineComponent({
            props: { href: { type: String, default: '' } },
            setup:
                (props, { slots, attrs }) =>
                () =>
                    h('a', { href: props.href, class: attrs.class }, slots.default?.()),
        }),
    };
});

const turma = { ulid: 'c', label: '7.º A', subject: null, tone: null };
const items: QueueItem[] = [
    { key: 'a', kind: 'summary', title: 'Registar o sumário — 7.º A', detail: 'Hoje, 10:00', action: 'Registar', href: '/lessons/1', class: turma, emphasis: 'ink', count: 1 },
    { key: 'b', kind: 'alerts', title: 'Tratar um alerta de registos', detail: 'TPC', action: 'Abrir', href: '/records', class: null, emphasis: 'amber', count: 1 },
    { key: 'c', kind: 'correction', title: 'Corrigir «Ficha» — 7.º A', detail: '8 de 24', action: 'Corrigir', href: '/assessments/x', class: turma, emphasis: 'ink', count: 8 },
];

describe('PendingList', () => {
    const wrapper = mount(PendingList, { props: { items } });

    it('mostra todas as linhas, na ordem dada, cada uma uma ligação com a ação', () => {
        const links = wrapper.findAll('li a');

        expect(links.map((link) => link.attributes('href'))).toEqual(['/lessons/1', '/records', '/assessments/x']);
        expect(links.map((link) => link.find('[data-testid="pending-action"]').text())).toEqual(['Registar', 'Abrir', 'Corrigir']);
        expect(links.every((link) => link.classes().includes('min-h-11') && link.classes().includes('sm:min-h-9'))).toBe(true);
    });

    it('a turma é um rótulo próprio e não se repete no título; sem turma, sem rótulo', () => {
        const lines = wrapper.findAll('li');

        expect(lines[0]!.find('[data-testid="pending-class"]').text()).toBe('7.º A');
        expect(lines[0]!.text().match(/7\.º A/g)).toHaveLength(1);
        expect(lines[0]!.text()).toContain('Registar o sumário');
        expect(lines[1]!.find('[data-testid="pending-class"]').exists()).toBe(false);
        expect(lines[2]!.text()).toContain('Corrigir «Ficha»');
    });

    it('não trunca nem oferece «mostrar mais»', () => {
        const many = Array.from({ length: 12 }, (_, index) => ({ ...items[1]!, key: `k${index}`, href: `/r/${index}` }));

        expect(mount(PendingList, { props: { items: many } }).findAll('li')).toHaveLength(12);
        expect(wrapper.text()).not.toMatch(/mostrar mais/i);
    });
});
