import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';
import Show from './Show.vue';

type MockForm = Record<string, unknown> & { isDirty: boolean };

const mocks = vi.hoisted(() => ({
    forms: [] as MockForm[],
    beforeHandlers: [] as ((event: Event) => void)[],
    unsubscribe: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    Link: defineComponent({
        inheritAttrs: false,
        setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()),
    }),
    router: {
        on: (event: string, callback: (event: Event) => void) => {
            if (event === 'before') {
                mocks.beforeHandlers.push(callback);
            }

            return mocks.unsubscribe;
        },
    },
    useForm: (data: Record<string, unknown>) => {
        const form = reactive({
            ...data,
            errors: {},
            processing: false,
            recentlySuccessful: false,
            isDirty: false,
            put: vi.fn(),
            post: vi.fn(),
        }) as MockForm;

        mocks.forms.push(form);

        return form;
    },
}));

const wrappers: VueWrapper[] = [];

function mountPage(overrides: { starts_at?: string; ends_at?: string | null } = {}) {
    const wrapper = mount(Show, {
        props: {
            lesson: {
                ulid: 'lesson-a',
                starts_at: '2026-09-09T09:00:00+01:00',
                ends_at: '2026-09-09T09:50:00+01:00',
                status: 'preparation' as const,
                status_label: 'Por preparar',
                school_class: { ulid: 'class-a', label: '7.º A', subject: 'Matemática' },
                summary: null,
                ...overrides,
            },
        },
    });

    wrappers.push(wrapper);

    return wrapper;
}

/** The sumário form is the first one the page creates; "lecionada" the second. */
function summaryForm(): MockForm {
    return mocks.forms[0];
}

function fireBeforeUnload(): Event {
    const event = new Event('beforeunload', { cancelable: true });
    window.dispatchEvent(event);

    return event;
}

function fireInAppNavigation(): Event {
    const event = new Event('before', { cancelable: true });
    mocks.beforeHandlers.forEach((handler) => handler(event));

    return event;
}

beforeEach(() => {
    mocks.forms.length = 0;
    mocks.beforeHandlers.length = 0;
    mocks.unsubscribe.mockClear();
    vi.stubGlobal(
        'fetch',
        vi.fn(() => Promise.resolve({ status: 204 } as Response)),
    );
});

afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    vi.restoreAllMocks();
});

describe('lessons/Show — back link', () => {
    function backLinkHref(overrides: { starts_at?: string; ends_at?: string | null } = {}) {
        const link = mountPage(overrides)
            .findAll('a')
            .find((a) => a.text().includes('Voltar às aulas da semana'));

        expect(link).toBeTruthy();

        return link!.attributes('href');
    }

    it('returns to the weekly view instead of the class page', () => {
        expect(backLinkHref()).toBe('/lessons?week=2026-09-07');
    });

    /**
     * The target week is derived from the lesson's own starts_at, so it is
     * correct however the teacher reached this page — nothing is threaded in
     * from the weekly view as a query parameter.
     */
    it.each([
        ['2026-09-07T08:30:00+01:00', '2026-09-07'], // Monday — its own week start
        ['2026-09-13T18:00:00+01:00', '2026-09-07'], // Sunday — still the same ISO week
        ['2026-09-14T09:00:00+01:00', '2026-09-14'], // the following Monday
        ['2027-01-01T09:00:00+00:00', '2026-12-28'], // a Friday across the year boundary
    ])('derives the Monday of the lesson week for %s', (startsAt, expectedWeek) => {
        expect(backLinkHref({ starts_at: startsAt, ends_at: null })).toBe(
            `/lessons?week=${expectedWeek}`,
        );
    });

    /**
     * A lesson late on a Lisbon evening is already the next day in UTC; the
     * week has to follow the Lisbon calendar date, not the UTC one.
     */
    it('uses the Lisbon calendar date, not the UTC date', () => {
        expect(backLinkHref({ starts_at: '2026-09-13T23:30:00+01:00', ends_at: null })).toBe(
            '/lessons?week=2026-09-07',
        );
    });
});

describe('lessons/Show — unsaved changes warning', () => {
    it('does not warn on tab close while nothing has been typed', () => {
        mountPage();

        expect(fireBeforeUnload().defaultPrevented).toBe(false);
    });

    it('warns on tab close once the sumário has unsaved changes', () => {
        mountPage();
        summaryForm().isDirty = true;

        expect(fireBeforeUnload().defaultPrevented).toBe(true);
    });

    it('does not interrupt in-app navigation while nothing has been typed', () => {
        mountPage();
        const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true);

        expect(fireInAppNavigation().defaultPrevented).toBe(false);
        expect(confirmSpy).not.toHaveBeenCalled();
    });

    it('lets in-app navigation through when the teacher confirms losing the changes', () => {
        mountPage();
        summaryForm().isDirty = true;
        const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true);

        expect(fireInAppNavigation().defaultPrevented).toBe(false);
        expect(confirmSpy).toHaveBeenCalledOnce();
    });

    it('cancels in-app navigation when the teacher declines', () => {
        mountPage();
        summaryForm().isDirty = true;
        vi.spyOn(window, 'confirm').mockReturnValue(false);

        expect(fireInAppNavigation().defaultPrevented).toBe(true);
    });

    /**
     * The back link built in the previous fix is an ordinary Inertia visit, so
     * it goes through the same guard rather than around it.
     */
    it('guards the "Voltar às aulas da semana" link like any other in-app navigation', () => {
        const wrapper = mountPage();
        summaryForm().isDirty = true;
        vi.spyOn(window, 'confirm').mockReturnValue(false);

        const link = wrapper.findAll('a').find((a) => a.text().includes('Voltar às aulas da semana'));

        expect(link!.attributes('href')).toBe('/lessons?week=2026-09-07');
        expect(fireInAppNavigation().defaultPrevented).toBe(true);
    });

    /** Saving is how the work is kept — it must never be interrogated. */
    it('never interrogates the page\'s own "Guardar" submission', async () => {
        const wrapper = mountPage();
        summaryForm().isDirty = true;
        const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);

        await wrapper.find('form').trigger('submit');

        expect(summaryForm().put).toHaveBeenCalledOnce();
        expect(fireInAppNavigation().defaultPrevented).toBe(false);
        expect(fireBeforeUnload().defaultPrevented).toBe(false);
        expect(confirmSpy).not.toHaveBeenCalled();
    });

    it('never interrogates the "Marcar como lecionada" request', async () => {
        const wrapper = mountPage();
        summaryForm().isDirty = true;
        const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);

        const button = wrapper
            .findAll('button')
            .find((candidate) => candidate.text().includes('Marcar como lecionada'));

        await button!.trigger('click');

        expect(mocks.forms[1].post).toHaveBeenCalledOnce();
        expect(fireInAppNavigation().defaultPrevented).toBe(false);
        expect(confirmSpy).not.toHaveBeenCalled();
    });

    it('unregisters both listeners when the page goes away', () => {
        const wrapper = mountPage();
        summaryForm().isDirty = true;

        wrapper.unmount();
        wrappers.length = 0;

        expect(mocks.unsubscribe).toHaveBeenCalledOnce();
        expect(fireBeforeUnload().defaultPrevented).toBe(false);
    });
});
