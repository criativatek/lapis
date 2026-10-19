import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { numberDraftFromLesson } from '@/lib/lessonNumber';
import type { WeekLesson } from '@/lib/lessons';
import LessonSummaryEditor from './LessonSummaryEditor.vue';

const wrappers: VueWrapper[] = [];

function lessonWith(ulid: string): WeekLesson {
    return {
        ulid,
        status: 'preparation',
        outcome: null,
        day_events: [],
        lesson_number: 3,
        lesson_number_end: null,
        lesson_number_manual: false,
    } as unknown as WeekLesson;
}

const entry = (ulid: string, overrides: Record<string, unknown> = {}) => ({
    ulid,
    class_ulid: 'turma-1',
    starts_at: '2026-10-05T09:00:00+01:00',
    ends_at: null,
    lesson_number: null,
    lesson_number_end: null,
    context_label: '8.º F',
    state: 'taught',
    state_label: 'Lecionada',
    content: 'Primeira linha.\nSegunda linha.',
    plan: null,
    resources: null,
    homework: null,
    ...overrides,
});

function respond(body: unknown): Promise<Response> {
    return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(body) } as Response);
}

function mountEditor(ulid = 'aula-a', lesson: WeekLesson = lessonWith(ulid), modelValue = '') {
    const wrapper = mount(LessonSummaryEditor, {
        props: {
            lesson,
            modelValue,
            original: '',
            saving: false,
            error: null,
            conflict: null,
            number: numberDraftFromLesson({ lesson_number: 3, lesson_number_end: null }),
            numberOriginal: numberDraftFromLesson({ lesson_number: 3, lesson_number_end: null }),
            numberErrors: { number: null },
            numberPreview: null,
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

describe('LessonSummaryEditor — «Aula anterior»', () => {
    it('carrega ao abrir, recolhida, e mostra UM cartão com o conteúdo completo', async () => {
        const fetchMock = vi.mocked(fetch);
        fetchMock.mockImplementation(() =>
            respond({
                lesson: entry('seg', { state: 'prepared', state_label: 'Preparada — por lecionar', content: 'Plano de terça.\nSegunda linha.' }),
            }),
        );

        const wrapper = mountEditor();
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(String(fetchMock.mock.calls[0][0])).toContain('/lessons/aula-a/preparation-context');
        const toggle = wrapper.get('[data-testid="lesson-context-toggle"]');
        expect(toggle.attributes('aria-expanded')).toBe('false');
        expect(toggle.text()).toBe('Aula anterior');

        expect(wrapper.findAll('[data-testid="lesson-context-entry"]')).toHaveLength(1);
        expect(wrapper.get('[data-testid="lesson-context-state"]').text()).toBe('Preparada — por lecionar');
        // Já não há versão de uma linha: o conteúdo vem inteiro.
        expect(wrapper.get('[data-testid="lesson-context-content"] p:last-child').text()).toBe('Plano de terça.\nSegunda linha.');
        expect(wrapper.find('[data-testid="lesson-context-more"]').exists()).toBe(false);

        await toggle.trigger('click');
        expect(toggle.attributes('aria-expanded')).toBe('true');
    });

    it('sem aula anterior elegível o bloco não aparece no cartão', async () => {
        vi.mocked(fetch).mockImplementation(() => respond({ lesson: null }));

        const wrapper = mountEditor();
        await flushPromises();

        expect(wrapper.find('[data-testid="lesson-context-compact"]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Aula anterior');
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
        pending[1]({ ok: true, status: 200, json: () => Promise.resolve({ lesson: entry('da-b', { content: 'Contexto de B.' }) }) } as Response);
        await flushPromises();
        pending[0]({ ok: true, status: 200, json: () => Promise.resolve({ lesson: entry('da-a', { content: 'Contexto de A.' }) }) } as Response);
        await flushPromises();

        const contents = wrapper.findAll('[data-testid="lesson-context-content"] p:last-child').map((line) => line.text());
        expect(contents).toEqual(['Contexto de B.']);
    });
});

describe('LessonSummaryEditor — atividades do calendário (0.171.0)', () => {
    const coinciding = { ulid: 'ev-1', title: 'Teste de Física', starts_at: '09:30', ends_at: '10:30', all_day: false, notes: null, type_label: 'Teste', coincides: true, coverage: 'partial' };
    const other = { ulid: 'ev-2', title: 'Reunião', starts_at: '14:00', ends_at: '15:00', all_day: false, notes: null, coincides: false, coverage: null };

    function withEvents(overrides: Record<string, unknown> = {}): WeekLesson {
        return { ...lessonWith('aula-a'), day_events: [other, coinciding], ...overrides } as unknown as WeekLesson;
    }

    beforeEach(() => {
        vi.mocked(fetch).mockImplementation(() => respond({ lesson: null }));
    });

    it('mostra os que coincidem primeiro, com «Incluir no sumário» e «Registar e adiar a preparação»', () => {
        const wrapper = mountEditor('aula-a', withEvents());
        const block = wrapper.get('[data-testid="editor-coinciding-events"]');

        expect(block.text()).toContain('Coincide com a aula');
        expect(block.text()).toContain('Teste de Física');
        expect(block.text()).toContain('parte da aula');
        expect(block.get('[data-testid="editor-include"]').text()).toContain('Incluir no sumário');
        expect(block.get('[data-testid="editor-postpone"]').text()).toContain('Registar e adiar a preparação');

        const items = wrapper.findAll('[data-testid="editor-day-event"]');

        expect(items[0].text()).toContain('Teste de Física');
        expect(items[1].text()).toContain('Reunião');
        // Os outros acontecimentos só têm «Incluir no sumário».
        expect(items[1].text()).not.toContain('Registar e adiar a preparação');
    });

    it('«Registar e adiar a preparação» emite postpone com o acontecimento', async () => {
        const wrapper = mountEditor('aula-a', withEvents());

        await wrapper.get('[data-testid="editor-postpone"]').trigger('click');

        expect(wrapper.emitted('postpone')?.[0]).toEqual([coinciding]);
        // Não escreve no texto.
        expect(wrapper.emitted('update:modelValue')).toBeUndefined();
    });

    it('«Incluir no sumário» acrescenta a linha ao texto do editor e depois diz «Já no sumário»', async () => {
        const wrapper = mountEditor('aula-a', withEvents(), 'Plano.');

        await wrapper.get('[data-testid="editor-coinciding-events"] [data-testid="editor-include"]').trigger('click');

        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['Plano.\nTeste de Física']);

        await wrapper.setProps({ modelValue: 'Plano.\nTeste de Física' });

        const button = wrapper.get('[data-testid="editor-coinciding-events"] [data-testid="editor-include"]');
        expect(button.text()).toContain('Já no sumário');
        expect(button.attributes('disabled')).toBeDefined();
    });

    it('numa aula já fechada não oferece «Registar e adiar a preparação»', () => {
        const wrapper = mountEditor('aula-a', withEvents({ outcome: 'class_external_activity' }));

        expect(wrapper.find('[data-testid="editor-postpone"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="editor-include"]').exists()).toBe(true);
    });
});
