import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { WeekLesson } from '@/lib/lessons';
import LessonSummaryEditor from './LessonSummaryEditor.vue';

const wrappers: VueWrapper[] = [];

function lessonWith(ulid: string): WeekLesson {
    return { ulid, status: 'preparation', outcome: null, day_events: [] } as unknown as WeekLesson;
}

const entry = (ulid: string, overrides: Record<string, unknown> = {}) => ({
    ulid,
    starts_at: '2026-10-05T09:00:00+01:00',
    ends_at: null,
    lesson_number: null,
    context_label: '8.º F',
    state: 'taught',
    state_label: 'Lecionada',
    content: 'Primeira linha.\nSegunda linha.',
    resources: null,
    homework: null,
    ...overrides,
});

function respond(body: unknown): Promise<Response> {
    return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(body) } as Response);
}

function mountEditor(ulid = 'aula-a') {
    const wrapper = mount(LessonSummaryEditor, {
        props: {
            lesson: lessonWith(ulid),
            modelValue: '',
            original: '',
            saving: false,
            error: null,
            conflict: null,
        },
    });
    wrappers.push(wrapper);

    return wrapper;
}

beforeEach(() => {
    vi.stubGlobal('fetch', vi.fn());
});

afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    vi.unstubAllGlobals();
});

describe('LessonSummaryEditor — «Antes desta aula»', () => {
    it('carrega ao abrir, recolhida, e mostra data, estado e primeira linha', async () => {
        const fetchMock = vi.mocked(fetch);
        fetchMock.mockImplementation(() =>
            respond({
                lessons: [entry('seg'), entry('ter', { starts_at: '2026-10-06T09:00:00+01:00', state: 'prepared', state_label: 'Preparada — por lecionar', content: 'Plano de terça.' })],
                has_more: false,
            }),
        );

        const wrapper = mountEditor();
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(String(fetchMock.mock.calls[0][0])).toContain('/lessons/aula-a/preparation-context');
        const toggle = wrapper.get('[data-testid="lesson-context-toggle"]');
        expect(toggle.attributes('aria-expanded')).toBe('false');
        expect(toggle.text()).toContain('(2)');

        const states = wrapper.findAll('[data-testid="lesson-context-state"]').map((badge) => badge.text());
        expect(states).toEqual(['Lecionada', 'Preparada — por lecionar']);
        expect(wrapper.findAll('[data-testid="lesson-context-line"]')[0].text()).toBe('Primeira linha.');

        await toggle.trigger('click');
        expect(toggle.attributes('aria-expanded')).toBe('true');
    });

    it('não bloqueia a escrita quando o carregamento falha', async () => {
        vi.mocked(fetch).mockImplementation(() => Promise.resolve({ ok: false, status: 500 } as Response));

        const wrapper = mountEditor();
        await flushPromises();

        expect(wrapper.get('[data-testid="lesson-context-error"]').text()).toContain('Não foi possível carregar');
        expect(wrapper.get('textarea').attributes('readonly')).toBeUndefined();
    });

    it('ignora a resposta tardia de uma aula que já não é a atual', async () => {
        const pending: Array<(response: Response) => void> = [];
        vi.mocked(fetch).mockImplementation(() => new Promise<Response>((resolve) => pending.push(resolve)));

        const wrapper = mountEditor('aula-a');
        await wrapper.setProps({ lesson: lessonWith('aula-b') });
        expect(pending).toHaveLength(2);

        // Responde primeiro à aula B (a atual) e só depois, atrasada, à aula A.
        pending[1]({ ok: true, status: 200, json: () => Promise.resolve({ lessons: [entry('da-b', { content: 'Contexto de B.' })], has_more: false }) } as Response);
        await flushPromises();
        pending[0]({ ok: true, status: 200, json: () => Promise.resolve({ lessons: [entry('da-a', { content: 'Contexto de A.' })], has_more: false }) } as Response);
        await flushPromises();

        const lines = wrapper.findAll('[data-testid="lesson-context-line"]').map((line) => line.text());
        expect(lines).toEqual(['Contexto de B.']);
    });
});
