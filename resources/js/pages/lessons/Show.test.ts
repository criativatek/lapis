import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';
import Show from './Show.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    Link: defineComponent({
        inheritAttrs: false,
        setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()),
    }),
    useForm: (data: Record<string, unknown>) =>
        reactive({
            ...data,
            errors: {},
            processing: false,
            recentlySuccessful: false,
            isDirty: false,
            put: vi.fn(),
            post: vi.fn(),
        }),
}));

function mountPage(overrides: { starts_at?: string; ends_at?: string | null } = {}) {
    return mount(Show, {
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
}

function backLinkHref(overrides: { starts_at?: string; ends_at?: string | null } = {}) {
    const link = mountPage(overrides)
        .findAll('a')
        .find((a) => a.text().includes('Voltar às aulas da semana'));

    expect(link).toBeTruthy();

    return link!.attributes('href');
}

describe('lessons/Show — back link', () => {
    beforeEach(() => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() => Promise.resolve({ status: 204 } as Response)),
        );
    });

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
