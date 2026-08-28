import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import type { Auth, Organization, User } from '@/types';
import UserMenuContent from './UserMenuContent.vue';

const mocks = vi.hoisted(() => ({
    auth: null as unknown as Auth,
}));

vi.mock('@inertiajs/vue3', () => ({
    // Inertia resolves an object `href` (a Wayfinder route definition) down to
    // its url. The stub has to as well, or every assertion below reads
    // "[object Object]" and the test proves nothing about where the link goes.
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
    router: { post: vi.fn(), flushAll: vi.fn() },
    usePage: () => ({ props: mocks }),
}));

// Reka's dropdown primitives need a menu root in context, which this component
// never provides — it IS the content. Flatten them; the assertions are about
// which entries exist and where they point, not about menu mechanics.
vi.mock('@/components/ui/dropdown-menu', () => {
    const passthrough = (tag: string) =>
        defineComponent({ inheritAttrs: false, setup: (_, { slots }) => () => h(tag, slots.default?.()) });

    return {
        DropdownMenuGroup: passthrough('div'),
        DropdownMenuItem: passthrough('div'),
        DropdownMenuLabel: passthrough('div'),
        DropdownMenuSeparator: passthrough('hr'),
    };
});

vi.mock('@/components/UserInfo.vue', () => ({
    default: defineComponent({ props: ['user', 'showEmail'], setup: () => () => h('span') }),
}));

const user: User = {
    id: 1,
    name: 'Nelson Matias',
    email: 'nelson@example.test',
    email_verified_at: '2026-08-01T00:00:00Z',
    created_at: '2026-08-01T00:00:00Z',
    updated_at: '2026-08-01T00:00:00Z',
};

const personalOrganization: Organization = { ulid: 'ORG1', name: 'A minha conta', type: 'personal', is_owner: true };
const institution: Organization = { ulid: 'ORG2', name: 'Escola Secundária X', type: 'institutional', is_owner: true };

const wrappers: VueWrapper[] = [];

function mountMenu(auth: Partial<Auth> = {}) {
    mocks.auth = {
        user,
        is_platform_admin: false,
        organization: personalOrganization,
        organizations: [personalOrganization],
        ...auth,
    };

    const wrapper = mount(UserMenuContent, { props: { user } });

    wrappers.push(wrapper);

    return wrapper;
}

function backofficeLink(wrapper: VueWrapper) {
    return wrapper.find('[data-test="platform-admin-link"]');
}

afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
});

describe('UserMenuContent — platform backoffice entry point', () => {
    it('offers «Administração da plataforma» to a platform admin, pointing at /admin', () => {
        const wrapper = mountMenu({ is_platform_admin: true });

        const link = backofficeLink(wrapper);

        expect(link.exists()).toBe(true);
        expect(link.text()).toContain('Administração da plataforma');
        // The canonical route (admin.accounts.index), resolved through Wayfinder
        // rather than a literal typed into the template.
        expect(link.attributes('href')).toBe('/admin');
    });

    it('never shows it to an ordinary teacher', () => {
        const wrapper = mountMenu({ is_platform_admin: false });

        expect(backofficeLink(wrapper).exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Administração da plataforma');
        expect(wrapper.findAll('a').some((anchor) => anchor.attributes('href') === '/admin')).toBe(false);
    });

    /**
     * The likeliest way to get this wrong: owning an institutional organization
     * looks like administration, and the sidebar even calls one of its modules
     * «Administração Institucional». Neither fact touches the platform.
     */
    it('never shows it to an institutional administrator who lacks the flag', () => {
        const wrapper = mountMenu({
            is_platform_admin: false,
            organization: institution,
            organizations: [personalOrganization, institution],
        });

        expect(backofficeLink(wrapper).exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Administração da plataforma');
    });

    it('does not borrow the tenant module\'s wording', () => {
        const wrapper = mountMenu({ is_platform_admin: true });

        // «Administração Institucional» is a module of the organization and
        // belongs to the sidebar, not here. The two labels must stay tellable
        // apart at a glance.
        expect(wrapper.text()).not.toContain('Administração Institucional');
    });

    it('keeps the entries the menu already had', () => {
        const wrapper = mountMenu({ is_platform_admin: true });

        expect(wrapper.text()).toContain('Configurações');
        expect(wrapper.find('[data-test="logout-button"]').exists()).toBe(true);
    });
});

describe('UserMenuContent — Central de Ajuda entry', () => {
    function helpLink(wrapper: VueWrapper) {
        return wrapper.find('[data-test="help-link"]');
    }

    it('offers «Central de Ajuda», pointing at /help, to an ordinary teacher on any plan', () => {
        const wrapper = mountMenu({ is_platform_admin: false });

        const link = helpLink(wrapper);

        expect(link.exists()).toBe(true);
        expect(link.text()).toContain('Central de Ajuda');
        // The canonical route (help.index), resolved through Wayfinder rather
        // than a literal typed into the template.
        expect(link.attributes('href')).toBe('/help');
    });

    it('offers it just the same to a platform admin', () => {
        const wrapper = mountMenu({ is_platform_admin: true });

        expect(helpLink(wrapper).exists()).toBe(true);
    });

    it('offers it just the same to an institutional organization owner', () => {
        const wrapper = mountMenu({ organization: institution, organizations: [personalOrganization, institution] });

        expect(helpLink(wrapper).exists()).toBe(true);
    });

    it('sits its own group, between «Configurações» and the platform-admin block', () => {
        const wrapper = mountMenu({ is_platform_admin: true });
        const html = wrapper.html();

        const settingsIndex = html.indexOf('Configurações');
        const helpEntryIndex = html.indexOf('Central de Ajuda');
        const adminIndex = html.indexOf('Administração da plataforma');

        expect(settingsIndex).toBeGreaterThan(-1);
        expect(helpEntryIndex).toBeGreaterThan(settingsIndex);
        expect(adminIndex).toBeGreaterThan(helpEntryIndex);
    });
});
