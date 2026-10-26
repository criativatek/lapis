import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, ref } from 'vue';
import type { SharedNav, SharedNavItem } from '@/types';
import MobileNav from './MobileNav.vue';

/**
 * A navegação inferior do telemóvel nasce do menu que o servidor já filtrou:
 * quatro posições por chave, com recuo («Calendário» quando não há «Aulas»)
 * ou ausência, e «Menu» abre a sidebar.
 */
const currentUrl = ref('/dashboard');

const mocks = vi.hoisted(() => ({
    nav: { sections: [], footer: [] } as SharedNav,
    setOpenMobile: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    Link: defineComponent({
        inheritAttrs: false,
        setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()),
    }),
    usePage: () => ({ props: { nav: mocks.nav } }),
}));

vi.mock('@/components/ui/sidebar', () => ({
    useSidebar: () => ({ setOpenMobile: mocks.setOpenMobile }),
}));

vi.mock('@/composables/useCurrentUrl', () => ({
    useCurrentUrl: () => ({
        currentUrl,
        isCurrentUrl: (href: string) => currentUrl.value === href,
    }),
}));

function item(key: string, label: string, overrides: Partial<SharedNavItem> = {}): SharedNavItem {
    return { key, label, description: null, match: [], icon: 'LayoutGrid', priority: false, phase: 1, href: `/${key}`, built: true, ...overrides };
}

function nav(items: SharedNavItem[]) {
    mocks.nav = { sections: [{ label: null, items: items.slice(0, 1) }, { label: 'Resto', items: items.slice(1) }], footer: [] };

    return mount(MobileNav);
}

const pro = [
    item('dashboard', 'Painel do Professor'),
    item('classes', 'Turmas'),
    item('assessments', 'Grelhas de correção', { match: ['/classifications'] }),
    item('calendar', 'Calendário do Ano Letivo'),
    item('lessons', 'Aulas e Sumários', { icon: 'Presentation' }),
];

afterEach(() => {
    mocks.setOpenMobile.mockReset();
    currentUrl.value = '/dashboard';
});

describe('MobileNav', () => {
    it('no Pro: Painel, Aulas, Turmas, Grelhas e Menu', () => {
        const wrapper = nav(pro);
        const links = wrapper.findAll('a');

        expect(links.map((link) => link.text())).toEqual(['Painel', 'Aulas', 'Turmas', 'Grelhas']);
        expect(links.map((link) => link.attributes('href'))).toEqual(['/dashboard', '/lessons', '/classes', '/assessments']);
        expect(wrapper.find('[data-testid="mobile-nav-menu"]').text()).toBe('Menu');
    });

    it('na Base, sem «Aulas», a segunda posição recua para o Calendário', () => {
        const links = nav(pro.filter((entry) => entry.key !== 'lessons')).findAll('a');

        expect(links.map((link) => link.text())).toEqual(['Painel', 'Calendário', 'Turmas', 'Grelhas']);
        expect(links[1].attributes('href')).toBe('/calendar');
    });

    it('uma posição sem entrada no menu desaparece em vez de inventar um destino', () => {
        const wrapper = nav(pro.filter((entry) => !['lessons', 'calendar', 'assessments'].includes(entry.key)));

        expect(wrapper.findAll('a').map((link) => link.text())).toEqual(['Painel', 'Turmas']);
        expect(wrapper.find('ul').attributes('style')).toContain('repeat(3, minmax(0, 1fr))');
    });

    it('sem menu nenhum não desenha nada', () => {
        expect(nav([]).find('nav').exists()).toBe(false);
    });

    it('marca a posição atual pela rota exata ou pelos fragmentos do menu', () => {
        currentUrl.value = '/classes/01J/classifications';
        const links = nav(pro).findAll('a');

        expect(links[3].attributes('aria-current')).toBe('page');
        expect(links[0].attributes('aria-current')).toBeUndefined();
    });

    it('«Menu» abre a sidebar do telemóvel', async () => {
        await nav(pro).find('[data-testid="mobile-nav-menu"]').trigger('click');

        expect(mocks.setOpenMobile).toHaveBeenCalledWith(true);
    });
});
