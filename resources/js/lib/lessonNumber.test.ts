import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    canSetLessonNumber,
    draftNumber,
    effectLine,
    numberDraftDirty,
    numberDraftFromLesson,
    numberSavePayload,
    previewNeedsConfirmation,
    requestNumberPreview,
    validateNumberDraft,
} from '@/lib/lessonNumber';
import type { LessonNumberDraft, LessonNumberEffect, LessonNumberPreview } from '@/lib/lessonNumber';

const original = numberDraftFromLesson({ lesson_number: 11, lesson_number_end: null });

function draft(overrides: Partial<LessonNumberDraft> = {}): LessonNumberDraft {
    return { ...original, ...overrides };
}

function effect(overrides: Partial<LessonNumberEffect> = {}): LessonNumberEffect {
    return {
        ulid: 'e1',
        starts_at: '2026-10-22T09:30:00+01:00',
        context_label: '8.º F',
        from: 2,
        from_end: null,
        to: 12,
        to_end: null,
        recorded: true,
        ...overrides,
    };
}

function preview(overrides: Partial<LessonNumberPreview> = {}): LessonNumberPreview {
    return {
        changed: true,
        result: { start: 11, end: null, manual: true },
        conflicts: [],
        effects: [],
        warnings: [],
        requires_confirmation: false,
        confirmation: null,
        following_from: 12,
        ...overrides,
    };
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('o rascunho do número', () => {
    it('parte do número da aula; o fim de uma linha antiga fica como legado', () => {
        expect(numberDraftFromLesson({ lesson_number: 11, lesson_number_end: 12 })).toEqual({
            start: '11',
            legacyEnd: 12,
            automatic: false,
        });
        expect(numberDraftFromLesson({ lesson_number: null, lesson_number_end: null })).toEqual({
            start: '',
            legacyEnd: null,
            automatic: false,
        });
    });

    it('só é alteração quando o número difere do guardado', () => {
        expect(numberDraftDirty(draft(), original)).toBe(false);
        expect(numberDraftDirty(draft({ start: '12' }), original)).toBe(true);
        expect(draftNumber(draft({ start: ' 11 ' }))).toBe(11);
        expect(draftNumber(draft({ start: 'x' }))).toBeNull();
    });

    it('abrir uma aula legada não é alteração; editar-lhe o número, mesmo para o mesmo valor, é', () => {
        const legacy = numberDraftFromLesson({ lesson_number: 12, lesson_number_end: 13 });

        expect(numberDraftDirty(legacy, legacy)).toBe(false);
        // O campo emite `legacyEnd: null` ao escrever, ainda que o valor volte a ser 12.
        expect(numberDraftDirty({ ...legacy, start: '12', legacyEnd: null }, legacy)).toBe(true);
        expect(numberDraftDirty({ ...legacy, start: '13', legacyEnd: null }, legacy)).toBe(true);
    });

    it('apagar o número de uma aula que o tinha conta como alteração; numa aula sem número, não', () => {
        expect(numberDraftDirty(draft({ start: '' }), original)).toBe(true);

        const none = numberDraftFromLesson({ lesson_number: null, lesson_number_end: null });
        expect(numberDraftDirty(none, none)).toBe(false);
        expect(numberDraftDirty({ ...none, start: '5' }, none)).toBe(true);
    });

    it('«repor automática» é sempre uma alteração', () => {
        expect(numberDraftDirty(draft({ automatic: true }), original)).toBe(true);
    });

    it('professor ausente não admite número', () => {
        expect(canSetLessonNumber({ outcome: 'teacher_absent' })).toBe(false);
        expect(canSetLessonNumber({ outcome: 'taught' })).toBe(true);
        expect(canSetLessonNumber({ outcome: null })).toBe(true);
    });
});

describe('validação local', () => {
    it('recusa vazio, zero, decimais e texto', () => {
        for (const start of ['', '0', '1.5', 'abc', '-3']) {
            expect(validateNumberDraft(draft({ start })).number).not.toBeNull();
        }
    });

    it('valida só o número: um número válido não tem erros', () => {
        expect(validateNumberDraft(draft({ start: '20' }))).toEqual({ number: null });
        expect(validateNumberDraft(draft({ start: '10000' })).number).not.toBeNull();
        expect(validateNumberDraft(draft({ automatic: true, start: '' }))).toEqual({ number: null });
    });
});

describe('o pedido de gravação', () => {
    it('manual: só o número e a confirmação — nunca a chave do fim', () => {
        const plain = numberSavePayload(draft({ start: '12' }), null);
        expect(plain).toEqual({ lesson_number: 12 });
        expect('lesson_number_end' in plain).toBe(false);

        // Uma linha legada que o professor editou: o fim legado não viaja.
        const legacy = numberSavePayload(draft({ start: '12', legacyEnd: 13 }), 'abc');
        expect(legacy).toEqual({ lesson_number: 12, lesson_number_confirmation: 'abc' });
        expect('lesson_number_end' in legacy).toBe(false);
    });

    it('repor a automática envia só a bandeira', () => {
        expect(numberSavePayload(draft({ automatic: true }), null)).toEqual({ lesson_number_automatic: true });
    });
});

describe('a pré-visualização', () => {
    it('pede confirmação com efeitos já registados ou com avisos, e só então', () => {
        expect(previewNeedsConfirmation(preview())).toBe(false);
        expect(previewNeedsConfirmation(preview({ effects: [effect({ recorded: false })] }))).toBe(false);
        expect(previewNeedsConfirmation(preview({ effects: [effect()] }))).toBe(true);
        expect(previewNeedsConfirmation(preview({ warnings: ['Aviso.'] }))).toBe(true);
    });

    it('escreve cada efeito como «22/10 · 8.º F · Lição 2 → Lição 12»', () => {
        expect(effectLine(effect())).toBe('22/10 · 8.º F · Lição 2 → Lição 12');
        expect(effectLine(effect({ to: 12, to_end: 13 }))).toBe('22/10 · 8.º F · Lição 2 → Lições 12–13');
        expect(effectLine(effect({ to: null, to_end: null }))).toBe('22/10 · 8.º F · Lição 2 → fica sem número');
    });

    it('envia o corpo certo, com credenciais, e devolve a resposta', async () => {
        const fetchMock = vi.fn().mockResolvedValue({ ok: true, status: 200, json: () => Promise.resolve(preview()) });
        vi.stubGlobal('fetch', fetchMock);

        const result = await requestNumberPreview('aula-a', draft({ start: '12', legacyEnd: 13 }));

        expect(result.kind).toBe('ok');
        const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
        expect(url).toBe('/lessons/aula-a/number/preview');
        expect(init.method).toBe('POST');
        expect(init.credentials).toBe('same-origin');
        expect(JSON.parse(init.body as string)).toEqual({ mode: 'manual', start: 12 });
    });

    it('um 422 vira erros do campo; outra falha vira uma mensagem', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: false,
                status: 422,
                json: () => Promise.resolve({ errors: { lesson_number: ['Sem lição.'] } }),
            }),
        );
        expect(await requestNumberPreview('aula-a', draft())).toEqual({
            kind: 'invalid',
            errors: { number: 'Sem lição.' },
        });

        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')));
        expect((await requestNumberPreview('aula-a', draft())).kind).toBe('failed');
    });
});
