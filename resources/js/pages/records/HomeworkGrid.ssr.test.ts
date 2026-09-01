// @vitest-environment node

/**
 * Same class of bug as reports/Create.vue: `watch(..., { immediate: true })`
 * runs inside `setup()`, and `setup()` is exactly what SSR's `renderToString`
 * executes — so a relative-URL `fetch()` fired from it throws
 * `ERR_INVALID_URL` under Node instead of resolving against the page origin.
 */
import { afterEach, describe, expect, it, vi } from 'vitest';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';
import HomeworkGrid from './HomeworkGrid.vue';

// `@types/node` isn't in this project's `types` array (browser-first tsconfig),
// so the real Node `process` global is reached through `globalThis` rather than
// relying on ambient node typings that vue-tsc can't see here.
const nodeProcess = (globalThis as unknown as {
    process: { on: (event: 'unhandledRejection', listener: (reason: unknown) => void) => void; off: (event: 'unhandledRejection', listener: (reason: unknown) => void) => void };
}).process;

describe('records/HomeworkGrid.vue — SSR', () => {
    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('does not call fetch while rendering on the server', async () => {
        const fetchSpy = vi.spyOn(globalThis, 'fetch');

        const app = createSSRApp(HomeworkGrid, { classUlid: 'class-ulid', occurredAt: '2026-09-01' });
        await renderToString(app);

        expect(fetchSpy).not.toHaveBeenCalled();
    });

    it('never produces an unhandled rejection from a relative-URL fetch during SSR', async () => {
        const rejections: unknown[] = [];
        const onUnhandledRejection = (reason: unknown) => rejections.push(reason);
        nodeProcess.on('unhandledRejection', onUnhandledRejection);

        try {
            const app = createSSRApp(HomeworkGrid, { classUlid: 'class-ulid', occurredAt: '2026-09-01' });
            await renderToString(app);

            await new Promise((resolve) => setTimeout(resolve, 0));

            expect(rejections).toEqual([]);
        } finally {
            nodeProcess.off('unhandledRejection', onUnhandledRejection);
        }
    });
});
