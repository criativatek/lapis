import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import DashboardLayoutEditor from './DashboardLayoutEditor.vue';

// O diálogo (reka-ui) teleporta para o <body>: lê-se de lá.
const q = (selector: string) => document.body.querySelector<HTMLElement>(selector);
const all = (selector: string) => Array.from(document.body.querySelectorAll<HTMLElement>(selector));

const order = ['week', 'done', 'period', 'classes'] as const;
const mounted: VueWrapper[] = [];

async function editor(props: { open?: boolean; announcement?: string } = {}): Promise<VueWrapper> {
    const wrapper = mount(DashboardLayoutEditor, { attachTo: document.body, props: { open: true, order: [...order], announcement: '', ...props } });

    mounted.push(wrapper);
    await flushPromises();

    return wrapper;
}

afterEach(() => {
    mounted.splice(0).forEach((wrapper) => wrapper.unmount());
    document.body.innerHTML = '';
    document.body.removeAttribute('style');
});

describe('DashboardLayoutEditor', () => {
    it('fechado não renderiza nada', async () => {
        await editor({ open: false });

        expect(q('[role="dialog"]')).toBeNull();
    });

    it('é um diálogo com título, instrução e a lista numerada por ordem', async () => {
        await editor();

        const dialog = q('[role="dialog"]');

        expect(dialog).not.toBeNull();
        expect(document.getElementById(dialog?.getAttribute('aria-labelledby') ?? '')?.textContent).toBe('Organizar painel');
        expect(dialog?.textContent).toContain('Escolha a ordem dos blocos do seu painel.');
        expect(all('ol > li').map((li) => li.textContent)).toEqual([
            expect.stringMatching(/1\.\s*Esta semana/),
            expect.stringMatching(/2\.\s*Arrumado hoje/),
            expect.stringMatching(/3\.\s*Progresso do período/),
            expect.stringMatching(/4\.\s*As minhas turmas/),
        ]);
    });

    it('Subir/Descer têm nome por bloco e os extremos estão desativados', async () => {
        await editor();

        expect(q('[aria-label="Subir Esta semana"]')?.hasAttribute('disabled')).toBe(true);
        expect(q('[aria-label="Descer As minhas turmas"]')?.hasAttribute('disabled')).toBe(true);
        expect(q('[aria-label="Descer Esta semana"]')?.hasAttribute('disabled')).toBe(false);
    });

    it('todos os controlos têm pelo menos 44 px em ecrãs pequenos (min-h-11) e a região live é um status', async () => {
        await editor({ announcement: 'Esta semana: posição 2 de 4' });

        for (const button of all('[role="dialog"] button')) {
            expect(button.className).toMatch(/min-h-11|size-11/);
        }

        const status = q('[data-testid="layout-live"]');

        expect(status?.getAttribute('role')).toBe('status');
        expect(status?.getAttribute('aria-live')).toBe('polite');
        expect(status?.textContent).toBe('Esta semana: posição 2 de 4');
    });

    it('o foco inicial vai para o primeiro controlo utilizável da lista', async () => {
        await editor();

        expect(document.activeElement?.getAttribute('data-move')).toBe('week:1');
    });

    it('emite mover, guardar, repor e cancelar', async () => {
        const wrapper = await editor();

        q('[data-move="done:-1"]')?.click();
        q('[data-testid="layout-save"]')?.click();
        q('[data-testid="layout-reset"]')?.click();
        q('[data-testid="layout-cancel"]')?.click();
        await flushPromises();

        expect(wrapper.emitted('move')?.[0]).toEqual(['done', -1]);
        expect(wrapper.emitted('save')).toHaveLength(1);
        expect(wrapper.emitted('reset')).toHaveLength(1);
        expect(wrapper.emitted('cancel')).toHaveLength(1);
    });

    it('«Fechar» e Escape pedem o mesmo que «Cancelar»', async () => {
        const wrapper = await editor();

        q('[data-testid="layout-close"]')?.click();
        await flushPromises();

        expect(wrapper.emitted('cancel')).toHaveLength(1);

        q('[role="dialog"]')?.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        await flushPromises();

        expect(wrapper.emitted('cancel')).toHaveLength(2);
    });

    it('clicar fora não descarta (evita perder uma ordem por engano)', async () => {
        const wrapper = await editor();

        document.body.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true }));
        await flushPromises();

        expect(wrapper.emitted('cancel')).toBeUndefined();
    });

    it('ao fechar avisa quem o abriu, para devolver o foco', async () => {
        const wrapper = await editor();

        await wrapper.setProps({ open: false });
        await flushPromises();
        await new Promise((resolve) => setTimeout(resolve, 50));

        expect(wrapper.emitted('closed')).toHaveLength(1);
    });
});
