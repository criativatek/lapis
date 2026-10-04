import { describe, expect, it } from 'vitest';
import { assignTurmaTones, resolveTurmaTones } from '@/lib/turmaTones';

describe('resolveTurmaTones — o tom guardado manda', () => {
    it('usa o tom guardado de cada turma, seja qual for a posição na lista', () => {
        const tones = resolveTurmaTones([
            { ulid: 'b', identity_tone: 'rose' },
            { ulid: 'a', identity_tone: 'blue' },
        ]);

        expect(tones.get('a')).toBe('blue');
        expect(tones.get('b')).toBe('rose');
    });

    it('acrescentar, tirar ou filtrar turmas não muda o tom das outras', () => {
        const before = resolveTurmaTones([
            { ulid: 'a', identity_tone: 'blue' },
            { ulid: 'b', identity_tone: 'emerald' },
        ]);
        const after = resolveTurmaTones([
            { ulid: '0-new', identity_tone: 'violet' },
            { ulid: 'b', identity_tone: 'emerald' },
        ]);

        expect(after.get('b')).toBe(before.get('b'));
    });

    it('só uma turma sem tom guardado (ou com um tom inválido) cai no tom de recurso', () => {
        const tones = resolveTurmaTones([
            { ulid: 'a', identity_tone: null },
            { ulid: 'b', identity_tone: 'fuchsia' },
        ]);

        expect(tones.get('a')).toBe(assignTurmaTones(['a', 'b']).get('a'));
        expect(tones.get('b')).toBe(assignTurmaTones(['a', 'b']).get('b'));
    });
});
