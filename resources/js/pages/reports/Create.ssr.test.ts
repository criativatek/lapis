// @vitest-environment node

/**
 * Reproduces the SSR crash: `reports/novo` fetches its class context
 * (`/reports/contexto/{ulid}`) with a relative URL. In the browser that
 * resolves against the page origin; under Node's SSR renderer there is no
 * origin, so `fetch()` throws `TypeError: Failed to parse URL … ERR_INVALID_URL`.
 * The initial load fires from a `watch(..., { immediate: true })`, which runs
 * inside `setup()` — and setup() is exactly what `renderToString` executes.
 */
import { afterEach, describe, expect, it, vi } from 'vitest';
import { createSSRApp, defineComponent, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import Create from './Create.vue';

// `@types/node` isn't in this project's `types` array (browser-first tsconfig),
// so the real Node `process` global is reached through `globalThis` rather than
// relying on ambient node typings that vue-tsc can't see here.
const nodeProcess = (globalThis as unknown as {
    process: { on: (event: 'unhandledRejection', listener: (reason: unknown) => void) => void; off: (event: 'unhandledRejection', listener: (reason: unknown) => void) => void };
}).process;

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    Link: defineComponent({
        inheritAttrs: false,
        setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()),
    }),
    router: { get: vi.fn() },
    useForm: (data: Record<string, unknown>) => ({
        ...data,
        errors: {},
        processing: false,
        post: vi.fn(),
    }),
}));

function baseProps() {
    return {
        type: 'student',
        availableTypes: [{ value: 'student', label: 'Aluno' }],
        classes: [{ id: 1, ulid: 'class-ulid', label: '5ºA', subject: 'Matemática', academic_year: '2025/2026' }],
        catalogue: [],
        tones: [{ value: 'objective', label: 'Objetivo' }],
        academicYears: [{ id: 1, label: '2025/2026' }],
        recordKinds: [],
        templates: [],
        preferredTemplate: null,
        preselected: null,
        hasSchoolLogo: false,
    };
}

describe('reports/Create.vue — SSR', () => {
    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('does not call fetch while rendering on the server', async () => {
        const fetchSpy = vi.spyOn(globalThis, 'fetch');

        const app = createSSRApp(Create, baseProps());
        await renderToString(app);

        expect(fetchSpy).not.toHaveBeenCalled();
    });

    it('never produces an unhandled rejection from a relative-URL fetch during SSR', async () => {
        const rejections: unknown[] = [];
        const onUnhandledRejection = (reason: unknown) => rejections.push(reason);
        nodeProcess.on('unhandledRejection', onUnhandledRejection);

        try {
            const app = createSSRApp(Create, baseProps());
            await renderToString(app);

            // Give any fire-and-forget promise from setup() a chance to reject.
            await new Promise((resolve) => setTimeout(resolve, 0));

            expect(rejections).toEqual([]);
        } finally {
            nodeProcess.off('unhandledRejection', onUnhandledRejection);
        }
    });
});
