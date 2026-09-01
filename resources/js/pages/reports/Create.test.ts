import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type * as VueModule from 'vue';
import { defineComponent, h } from 'vue';
import Create from './Create.vue';

type FormLike = { class_id: number; enrollment_id: number | null; academic_period_id: number | null };

// Captured as the mocked useForm() creates it, rather than reached for via
// wrapper.vm.$.setupState — the same reactive proxy Create.vue holds as `form`.
const created = vi.hoisted(() => ({ forms: [] as FormLike[] }));

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await vi.importActual<typeof VueModule>('vue');

    return {
        Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
        Link: defineComponent({
            inheritAttrs: false,
            setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()),
        }),
        router: { get: vi.fn() },
        useForm: (data: Record<string, unknown>) => {
            const form = reactive({
                ...data,
                errors: {},
                processing: false,
                post: vi.fn(),
            });

            created.forms.push(form as unknown as FormLike);

            return form;
        },
    };
});

const wrappers: VueWrapper[] = [];

function contextPayload(overrides: Record<string, unknown> = {}) {
    return {
        periods: [{ id: 10, label: '1º período' }],
        enrollments: [{ id: 20, class_number: 3, name: 'Ana Silva' }],
        interimAssessments: [],
        ...overrides,
    };
}

function baseProps(overrides: Record<string, unknown> = {}) {
    return {
        type: 'student',
        availableTypes: [{ value: 'student', label: 'Aluno' }],
        classes: [
            { id: 1, ulid: 'class-a', label: '5ºA', subject: 'Matemática', academic_year: '2025/2026' },
            { id: 2, ulid: 'class-b', label: '6ºB', subject: 'Matemática', academic_year: '2025/2026' },
        ],
        catalogue: [],
        tones: [{ value: 'objective', label: 'Objetivo' }],
        academicYears: [{ id: 1, label: '2025/2026' }],
        recordKinds: [],
        templates: [],
        preferredTemplate: null,
        preselected: null,
        hasSchoolLogo: false,
        ...overrides,
    };
}

describe('reports/Create.vue — client', () => {
    afterEach(() => {
        wrappers.forEach((wrapper) => wrapper.unmount());
        wrappers.length = 0;
        created.forms.length = 0;
        vi.restoreAllMocks();
    });

    it('loads the context for the starting class once mounted in the browser', async () => {
        const fetchSpy = vi.spyOn(globalThis, 'fetch').mockResolvedValue(
            new Response(JSON.stringify(contextPayload()), { status: 200 }),
        );

        const wrapper = mount(Create, { props: baseProps() });
        wrappers.push(wrapper);

        await vi.waitFor(() => expect(fetchSpy).toHaveBeenCalledTimes(1));

        expect(fetchSpy).toHaveBeenCalledWith('/reports/contexto/class-a', {
            headers: { Accept: 'application/json' },
        });
    });

    it('reloads the context and resets período/aluno when the class changes', async () => {
        const fetchSpy = vi
            .spyOn(globalThis, 'fetch')
            .mockResolvedValueOnce(new Response(JSON.stringify(contextPayload()), { status: 200 }))
            .mockResolvedValueOnce(
                new Response(
                    JSON.stringify(contextPayload({ periods: [{ id: 99, label: '2º período' }] })),
                    { status: 200 },
                ),
            );

        const wrapper = mount(Create, {
            props: baseProps({ preselected: { class_id: 1, enrollment_id: 20, academic_period_id: 10 } }),
        });
        wrappers.push(wrapper);

        await vi.waitFor(() => expect(fetchSpy).toHaveBeenCalledTimes(1));

        // The preselected fields survive the initial load — nothing resets them.
        const [form] = created.forms;
        expect(form.enrollment_id).toBe(20);
        expect(form.academic_period_id).toBe(10);

        form.class_id = 2;
        await wrapper.vm.$nextTick();

        expect(form.enrollment_id).toBeNull();
        expect(form.academic_period_id).toBeNull();

        await vi.waitFor(() => expect(fetchSpy).toHaveBeenCalledTimes(2));
        expect(fetchSpy).toHaveBeenLastCalledWith('/reports/contexto/class-b', {
            headers: { Accept: 'application/json' },
        });
    });
});
