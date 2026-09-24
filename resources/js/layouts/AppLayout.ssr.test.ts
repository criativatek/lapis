// @vitest-environment node

/**
 * The authenticated shell under `renderToString` — what the Node SSR server
 * did to every page from 0.93.0 to 0.152.0.
 *
 * Two things are pinned here. First, the shell's contract: given the shared
 * props `HandleInertiaRequests` puts on every page, the shell renders in Node
 * without throwing, whatever the menu happens to contain — a footer with
 * entries, an empty footer, no sections, no academic years. Second, the
 * mechanism behind production's ssr.log: a page object WITHOUT those props
 * fails with exactly «Cannot read properties of undefined (reading
 * 'sections' / 'footer' / 'length')». That is not a bug in the shell, which
 * is entitled to its contract — it is why SSR is now scoped to the pages
 * without a shell (App\Support\Ssr\ServerRenderedPages). The tests keep the
 * diagnosis executable.
 */
import { describe, expect, it, vi } from 'vitest';
import { createSSRApp, defineComponent, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import AppSidebar from '@/components/AppSidebar.vue';
import ContextBar from '@/components/ContextBar.vue';
import { SidebarProvider } from '@/components/ui/sidebar';
import AppLayout from './AppLayout.vue';

type SharedProps = Record<string, unknown>;

const page = vi.hoisted(() => ({ props: {} as Record<string, unknown>, url: '/reports/01JFICTICIO', component: 'reports/Show' }));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => page,
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    Link: defineComponent({
        inheritAttrs: false,
        setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()),
    }),
    router: { get: vi.fn(), post: vi.fn(), visit: vi.fn(), reload: vi.fn(), on: vi.fn(() => () => undefined) },
    useForm: (data: Record<string, unknown>) => ({ ...data, errors: {}, processing: false, post: vi.fn(), put: vi.fn(), reset: vi.fn() }),
}));

// `@types/node` isn't in this project's `types` array (browser-first
// tsconfig), so the Node `process` global is reached through `globalThis`,
// the way Create.ssr.test.ts does.
type RejectionListener = (reason: unknown) => void;
const nodeProcess = (globalThis as unknown as {
    process: {
        listeners: (event: 'unhandledRejection') => RejectionListener[];
        on: (event: 'unhandledRejection', listener: RejectionListener) => void;
        removeAllListeners: (event: 'unhandledRejection') => void;
    };
}).process;

function navItem(key: string, label: string) {
    return {
        key,
        label,
        description: null,
        match: [],
        icon: 'LayoutGrid',
        priority: false,
        phase: 1,
        href: `/${key}`,
        built: true,
    };
}

/** Everything HandleInertiaRequests shares that the shell reads. */
function sharedProps(overrides: SharedProps = {}): SharedProps {
    return {
        name: 'Lapispro',
        appVersion: '0.153.0',
        auth: {
            user: { name: 'Docente Fictícia', email: 'docente@exemplo.test' },
            is_platform_admin: false,
            is_support_technician: false,
            should_see_privacy_notice: false,
            organization: { ulid: '01JORG', name: 'Escola Fictícia', type: 'personal', is_owner: true },
            organizations: [],
        },
        nav: {
            sections: [{ label: 'Trabalho', items: [navItem('dashboard', 'Início'), navItem('classes', 'Turmas')] }],
            footer: [navItem('settings', 'Configurações')],
        },
        modules: [],
        readOnlyModules: [],
        selectableAcademicYears: [{ ulid: '01JAY', label: '2025/2026', is_current: true }],
        scope: { academicYear: '2025/2026', subject: null, hasSubjects: false, gradeLevel: null, class: null, period: null },
        sidebarOpen: true,
        supportAwaitingReply: 0,
        impersonating: null,
        accountClosure: null,
        organizationClosure: null,
        ...overrides,
    };
}

async function render(props: SharedProps): Promise<string> {
    page.props = props;

    return renderToString(createSSRApp(AppLayout, { breadcrumbs: [] }));
}

async function failure(props: SharedProps, factory: () => ReturnType<typeof createSSRApp>): Promise<string> {
    page.props = props;

    try {
        await renderToString(factory());
    } catch (error) {
        return (error as Error).message;
    }

    return 'NO ERROR';
}

/** AppSidebar the way the shell mounts it — inside the provider its UI needs. */
function sidebar() {
    return createSSRApp(defineComponent({ setup: () => () => h(SidebarProvider, null, { default: () => h(AppSidebar) }) }));
}

describe('AppLayout under SSR, with the shared props the shell is promised', () => {
    it('renders the shell — sidebar, menu and footer entry included', async () => {
        const html = await render(sharedProps());

        expect(html).toContain('Início');
        expect(html).toContain('Turmas');
        expect(html).toContain('Configurações');
        expect(html).toContain('2025/2026');
    });

    it('renders with an empty footer', async () => {
        const html = await render(sharedProps({ nav: { sections: [{ label: 'Trabalho', items: [navItem('dashboard', 'Início')] }], footer: [] } }));

        expect(html).toContain('Início');
        expect(html).not.toContain('Configurações');
    });

    it('renders with no sections at all — the menu an organization-less request gets', async () => {
        const html = await render(sharedProps({ nav: { sections: [], footer: [] } }));

        expect(html).toContain('Docente Fictícia');
    });

    it('renders with no academic years configured', async () => {
        const html = await render(sharedProps({ selectableAcademicYears: [], scope: { academicYear: null, subject: null, hasSubjects: false, gradeLevel: null, class: null, period: null } }));

        expect(html).toContain('Sem anos letivos configurados');
    });
});

describe('the shell under SSR without its shared props — the ssr.log mechanism', () => {
    it("the context bar fails on 'length' when the academic years are missing", async () => {
        const { selectableAcademicYears: _dropped, ...withoutAcademicYears } = sharedProps();
        void _dropped;

        expect(await failure(withoutAcademicYears, () => createSSRApp(ContextBar))).toBe("Cannot read properties of undefined (reading 'length')");
    });

    it("the sidebar fails on 'length' when the menu has no footer list", async () => {
        expect(await failure(sharedProps({ nav: { sections: [] } }), sidebar)).toBe("Cannot read properties of undefined (reading 'length')");
    });

    it("the sidebar fails on BOTH 'sections' and 'footer' when the menu is missing — one prop, two log lines", async () => {
        // Vue's server renderer runs the sidebar's content and footer slots
        // as separate buffers. With `nav` undefined both throw: the content
        // branch's `nav.sections` is the rejection `renderToString` reports,
        // and the footer branch's `nav.value.footer` rejects a promise nobody
        // is awaiting any more — an unhandled rejection. That second message
        // is why production's ssr.log carried 'footer' next to 'sections'
        // for the same page. It is captured here on purpose, with Vitest's
        // own listeners stepped aside for the duration and restored after.
        const { nav: _dropped, ...withoutNav } = sharedProps();
        void _dropped;

        const dangling: string[] = [];
        const listeners = nodeProcess.listeners('unhandledRejection');
        nodeProcess.removeAllListeners('unhandledRejection');
        nodeProcess.on('unhandledRejection', (reason) => dangling.push((reason as Error).message));

        let caught: string;

        try {
            caught = await failure(withoutNav, sidebar);
            // Node reports unhandled rejections once the microtask queue has
            // drained — before any timer fires.
            await new Promise((resolve) => setTimeout(resolve, 0));
        } finally {
            nodeProcess.removeAllListeners('unhandledRejection');
            listeners.forEach((listener) => nodeProcess.on('unhandledRejection', listener));
        }

        expect(caught).toBe("Cannot read properties of undefined (reading 'sections')");
        expect(dangling).toEqual(["Cannot read properties of undefined (reading 'footer')"]);
    });
});
