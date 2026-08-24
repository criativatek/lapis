import { router, useForm } from '@inertiajs/vue3';
import { describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';

type SubmitOptions = { onSuccess?: (page: unknown) => Promise<unknown> };

/**
 * The unsaved-changes warning on lessons/Show is driven entirely by Inertia's
 * own `isDirty`. If that flag did not clear itself once a save succeeded, the
 * teacher would be interrogated immediately after every "Guardar" — so the
 * behaviour the page depends on is pinned here against the real useForm, not
 * against a stub of it.
 */
describe('the sumário form dirty state', () => {
    function summaryForm() {
        return useForm({
            content: 'Sumário inicial.',
            private_notes: '',
            resources: '',
            homework: '',
        });
    }

    it('starts clean and becomes dirty as soon as the teacher types', async () => {
        const form = summaryForm();

        expect(form.isDirty).toBe(false);

        form.content = 'Sumário reescrito.';
        await nextTick();

        expect(form.isDirty).toBe(true);
    });

    it('returns to clean once the save succeeds, so the warning does not fire afterwards', async () => {
        const form = summaryForm();
        let submitted: SubmitOptions | null = null;

        vi.spyOn(router, 'put').mockImplementation(((
            _url: string,
            _data: unknown,
            options: SubmitOptions,
        ) => {
            submitted = options;
        }) as unknown as typeof router.put);

        form.content = 'Sumário reescrito.';
        await nextTick();
        expect(form.isDirty).toBe(true);

        form.put('/lessons/lesson-a/summary', { preserveScroll: true });

        expect(submitted).not.toBeNull();
        await submitted!.onSuccess?.({});
        await nextTick();

        expect(form.isDirty).toBe(false);
    });

    it('stays dirty when the save fails, so the work is still protected', async () => {
        const form = summaryForm();

        vi.spyOn(router, 'put').mockImplementation(((() => {}) as unknown) as typeof router.put);

        form.content = 'Sumário reescrito.';
        await nextTick();

        form.put('/lessons/lesson-a/summary', { preserveScroll: true });
        await nextTick();

        expect(form.isDirty).toBe(true);
    });
});
