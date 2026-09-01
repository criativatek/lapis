import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import HomeworkGrid from './HomeworkGrid.vue';

vi.mock('@inertiajs/vue3', () => ({
    router: { get: vi.fn(), delete: vi.fn() },
    useForm: (data: Record<string, unknown>) =>
        reactive({
            ...data,
            errors: {},
            processing: false,
            put: vi.fn(),
        }),
}));

const wrappers: VueWrapper[] = [];

function batchPayload(overrides: Record<string, unknown> = {}) {
    return {
        enrollments: [
            { id: 1, name: 'Ana Silva', homework_status: null, description: '' },
        ],
        ...overrides,
    };
}

describe('records/HomeworkGrid.vue — client', () => {
    afterEach(() => {
        wrappers.forEach((wrapper) => wrapper.unmount());
        wrappers.length = 0;
        vi.restoreAllMocks();
    });

    it('loads the batch for the starting date once mounted in the browser', async () => {
        const fetchSpy = vi.spyOn(globalThis, 'fetch').mockResolvedValue(
            new Response(JSON.stringify(batchPayload()), { status: 200 }),
        );

        const wrapper = mount(HomeworkGrid, {
            props: { classUlid: 'class-a', occurredAt: '2026-09-01' },
        });
        wrappers.push(wrapper);

        await vi.waitFor(() => expect(fetchSpy).toHaveBeenCalledTimes(1));

        expect(fetchSpy).toHaveBeenCalledWith(
            '/classes/class-a/records/homework-batch?occurred_at=2026-09-01',
            { headers: { Accept: 'application/json' } },
        );
    });

    it('reloads the batch when the date changes', async () => {
        const fetchSpy = vi.spyOn(globalThis, 'fetch').mockResolvedValue(
            new Response(JSON.stringify(batchPayload()), { status: 200 }),
        );

        const wrapper = mount(HomeworkGrid, {
            props: { classUlid: 'class-a', occurredAt: '2026-09-01' },
        });
        wrappers.push(wrapper);

        await vi.waitFor(() => expect(fetchSpy).toHaveBeenCalledTimes(1));

        await wrapper.setProps({ occurredAt: '2026-09-02' });

        await vi.waitFor(() => expect(fetchSpy).toHaveBeenCalledTimes(2));
        expect(fetchSpy).toHaveBeenLastCalledWith(
            '/classes/class-a/records/homework-batch?occurred_at=2026-09-02',
            { headers: { Accept: 'application/json' } },
        );
    });
});
