import { mount } from '@vue/test-utils';
import { beforeAll, describe, expect, it, vi } from 'vitest';
import DashboardLayoutEditor from './DashboardLayoutEditor.vue';

// jsdom não implementa scrollIntoView.
beforeAll(() => {
    Element.prototype.scrollIntoView = vi.fn();
});

const order = ['week', 'done', 'period', 'classes'] as const;

function editor(announcement = '') {
    return mount(DashboardLayoutEditor, { attachTo: document.body, props: { order: [...order], announcement } });
}

describe('DashboardLayoutEditor', () => {
    it('lista os blocos por ordem, com Subir/Descer nomeados e os extremos desativados', () => {
        const wrapper = editor();
        const rows = wrapper.findAll('ol > li');

        expect(rows.map((row) => row.text())).toEqual([
            expect.stringContaining('Esta semana'),
            expect.stringContaining('Arrumado hoje'),
            expect.stringContaining('Progresso do período'),
            expect.stringContaining('As minhas turmas'),
        ]);
        expect(wrapper.find('[aria-label="Subir Esta semana"]').attributes('disabled')).toBeDefined();
        expect(wrapper.find('[aria-label="Descer As minhas turmas"]').attributes('disabled')).toBeDefined();
        expect(wrapper.find('[aria-label="Descer Esta semana"]').attributes('disabled')).toBeUndefined();
    });

    it('cada botão tem pelo menos 44 px em ecrãs pequenos (min-h-11) e a região live é um status', () => {
        const wrapper = editor('Esta semana: posição 2 de 4');

        for (const button of wrapper.findAll('button')) {
            expect(button.classes()).toContain('min-h-11');
        }

        const live = wrapper.find('[data-testid="layout-live"]');

        expect(live.attributes('role')).toBe('status');
        expect(live.attributes('aria-live')).toBe('polite');
        expect(live.text()).toBe('Esta semana: posição 2 de 4');
    });

    it('emite mover, guardar, repor e cancelar', async () => {
        const wrapper = editor();

        await wrapper.find('[data-move="done:-1"]').trigger('click');
        await wrapper.find('[data-testid="layout-save"]').trigger('click');
        await wrapper.find('[data-testid="layout-reset"]').trigger('click');
        await wrapper.find('[data-testid="layout-cancel"]').trigger('click');

        expect(wrapper.emitted('move')?.[0]).toEqual(['done', -1]);
        expect(wrapper.emitted('save')).toHaveLength(1);
        expect(wrapper.emitted('reset')).toHaveLength(1);
        expect(wrapper.emitted('cancel')).toHaveLength(1);
    });

    it('«Escape» cancela', async () => {
        const wrapper = editor();

        await wrapper.trigger('keydown', { key: 'Escape' });

        expect(wrapper.emitted('cancel')).toHaveLength(1);
    });
});
