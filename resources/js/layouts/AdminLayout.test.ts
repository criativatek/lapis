import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import AdminLayout from './AdminLayout.vue';

vi.mock('@inertiajs/vue3', () => ({
    // Same reason as UserMenuContent.test.ts: resolve a Wayfinder route
    // definition to its url, exactly as the real Link does.
    Link: defineComponent({
        inheritAttrs: false,
        setup: (_, { attrs, slots }) => () => {
            const href = attrs.href as string | { url: string } | undefined;

            return h(
                'a',
                { ...attrs, href: typeof href === 'object' && href !== null ? href.url : href },
                slots.default?.(),
            );
        },
    }),
    router: { post: vi.fn() },
    usePage: () => ({ props: { auth: { user: { name: 'Nelson Matias' } } }, url: '/admin' }),
}));

vi.mock('@/components/ui/sonner', () => ({
    Toaster: defineComponent({ setup: () => () => h('div') }),
}));

const wrappers: VueWrapper[] = [];

function mountLayout() {
    const wrapper = mount(AdminLayout);

    wrappers.push(wrapper);

    return wrapper;
}

function linkTo(wrapper: VueWrapper, label: string) {
    return wrapper.findAll('a').find((anchor) => anchor.text().includes(label));
}

afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
});

describe('AdminLayout — the way back into the application', () => {
    it('offers «Voltar ao Lapispro», pointing at the dashboard', () => {
        const wrapper = mountLayout();

        const link = wrapper.find('[data-test="back-to-app"]');

        expect(link.exists()).toBe(true);
        expect(link.text()).toContain('Voltar ao Lapispro');
        expect(link.attributes('href')).toBe('/dashboard');
    });

    it('no longer calls it «Voltar à app»', () => {
        expect(mountLayout().text()).not.toContain('Voltar à app');
    });
});

describe('AdminLayout — the backoffice keeps its own navigation', () => {
    it('still lists Contas, Nova conta and Email (SMTP)', () => {
        const wrapper = mountLayout();

        expect(linkTo(wrapper, 'Contas')?.attributes('href')).toBe('/admin');
        expect(linkTo(wrapper, 'Nova conta')?.attributes('href')).toBe('/admin/accounts/create');
        expect(linkTo(wrapper, 'Email (SMTP)')?.attributes('href')).toBe('/admin/settings');
    });

    it('marks Contas as the active entry on /admin', () => {
        const wrapper = mountLayout();

        expect(linkTo(wrapper, 'Contas')?.classes()).toContain('bg-accent');
    });
});
