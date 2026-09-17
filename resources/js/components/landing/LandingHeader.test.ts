import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { dashboard, login, register } from '@/routes';
import LandingHeader from './LandingHeader.vue';

vi.mock('@inertiajs/vue3', () => ({
    // Resolve a Wayfinder definition to its url, as Inertia does, so the href
    // assertions prove where the link goes and not "[object Object]".
    Link: defineComponent({
        inheritAttrs: false,
        setup:
            (_, { attrs, slots }) =>
            () => {
                const href = attrs.href as string | { url: string } | undefined;

                return h(
                    'a',
                    {
                        ...attrs,
                        href:
                            typeof href === 'object' && href !== null
                                ? href.url
                                : href,
                    },
                    slots.default?.(),
                );
            },
    }),
    usePage: () => ({ url: '/' }),
}));

const wrappers: VueWrapper[] = [];

function mountHeader(authenticated: boolean) {
    const wrapper = mount(LandingHeader, {
        props: { authenticated },
        attachTo: document.body,
    });

    wrappers.push(wrapper);

    return wrapper;
}

const menu = () => document.querySelector('[data-test="landing-mobile-menu"]');
const inMenu = (selector: string) => menu()?.querySelector(selector) ?? null;

async function openMenu(wrapper: VueWrapper) {
    await wrapper.get('button[aria-label="Abrir menu"]').trigger('click');
    await flushPromises();
}

afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    document.body.innerHTML = '';
});

describe('LandingHeader — hamburger menu', () => {
    it('is closed at first, opens on the trigger and exposes its state', async () => {
        const wrapper = mountHeader(false);
        const trigger = wrapper.get('button[aria-label="Abrir menu"]');

        expect(menu()).toBeNull();
        expect(trigger.attributes('aria-expanded')).toBe('false');

        await openMenu(wrapper);

        expect(menu()).not.toBeNull();
        expect(trigger.attributes('aria-expanded')).toBe('true');
    });

    it('closes when a link inside it is followed', async () => {
        const wrapper = mountHeader(false);

        await openMenu(wrapper);
        (inMenu('[data-test="landing-mobile-login"]') as HTMLElement).click();
        await flushPromises();

        expect(
            wrapper
                .get('button[aria-label="Abrir menu"]')
                .attributes('aria-expanded'),
        ).toBe('false');
    });

    it('closes on Escape', async () => {
        const wrapper = mountHeader(false);

        await openMenu(wrapper);
        menu()!.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
        );
        await flushPromises();

        expect(
            wrapper
                .get('button[aria-label="Abrir menu"]')
                .attributes('aria-expanded'),
        ).toBe('false');
    });
});

describe('LandingHeader — guest', () => {
    it('offers «Entrar» at the top of the mobile menu, pointing at the real login', async () => {
        const wrapper = mountHeader(false);

        await openMenu(wrapper);

        const entrar = inMenu('[data-test="landing-mobile-login"]');
        expect(entrar?.textContent?.trim()).toBe('Entrar');
        expect(entrar?.getAttribute('href')).toBe(login().url);
        expect(inMenu('[data-test="landing-mobile-dashboard"]')).toBeNull();

        // Before the site's pages, not after them.
        const firstPage = inMenu('nav a');
        expect(
            entrar!.compareDocumentPosition(firstPage!) &
                Node.DOCUMENT_POSITION_FOLLOWING,
        ).toBeTruthy();
    });

    it('keeps the desktop bar: «Entrar» and «Experimentar», no panel', () => {
        const wrapper = mountHeader(false);

        expect(
            wrapper
                .get('[data-test="landing-header-login"]')
                .attributes('href'),
        ).toBe(login().url);
        expect(wrapper.find(`a[href="${register().url}"]`).text()).toContain(
            'Experimentar',
        );
        expect(
            wrapper.find('[data-test="landing-header-dashboard"]').exists(),
        ).toBe(false);
    });
});

describe('LandingHeader — authenticated', () => {
    it('shows «Ir para o painel» in the mobile menu and never «Entrar»', async () => {
        const wrapper = mountHeader(true);

        await openMenu(wrapper);

        const panel = inMenu('[data-test="landing-mobile-dashboard"]');
        expect(panel?.textContent?.trim()).toBe('Ir para o painel');
        expect(panel?.getAttribute('href')).toBe(dashboard().url);
        expect(inMenu('[data-test="landing-mobile-login"]')).toBeNull();
        expect(menu()!.textContent).not.toContain('Entrar');
    });

    it('keeps «Ir para o painel» in the bar and drops the guest CTAs', () => {
        const wrapper = mountHeader(true);

        expect(
            wrapper
                .get('[data-test="landing-header-dashboard"]')
                .attributes('href'),
        ).toBe(dashboard().url);
        expect(wrapper.text()).not.toContain('Entrar');
        expect(wrapper.text()).not.toContain('Experimentar');
    });
});
