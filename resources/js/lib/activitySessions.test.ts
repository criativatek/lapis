import { describe, expect, it } from 'vitest';
import {
    activitySessionDateLabel,
    activitySessionNumberLabel,
    activitySessionToneKey,
    compareActivitySessions,
} from '@/lib/activitySessions';
import { statusTone } from '@/lib/statusTone';

describe('compareActivitySessions', () => {
    const base = { title: 'A', key: 'a' };

    it('ordena pelo instante real, não pelo texto', () => {
        // Na madrugada de 25/10/2026 a hora de Lisboa recua: 01:30+01:00 é o
        // instante 00:30 UTC, ANTES de 01:10+00:00 — mas como texto vem depois.
        const earlier = {
            ...base,
            key: 'earlier',
            starts_at: '2026-10-25T01:30:00+01:00',
        };
        const later = {
            ...base,
            key: 'later',
            starts_at: '2026-10-25T01:10:00+00:00',
        };

        expect(
            [later, earlier]
                .sort(compareActivitySessions)
                .map((item) => item.key),
        ).toEqual(['earlier', 'later']);
    });

    it('desempata por título e depois por chave, sempre pela mesma ordem', () => {
        const at = '2026-10-08T10:00:00+01:00';
        const items = [
            { title: 'Reunião', key: 'c', starts_at: at },
            { title: 'Apoio', key: 'b', starts_at: at },
            { title: 'Apoio', key: 'a', starts_at: at },
        ];

        expect(
            [...items].sort(compareActivitySessions).map((item) => item.key),
        ).toEqual(['a', 'b', 'c']);
        expect(
            [...items]
                .reverse()
                .sort(compareActivitySessions)
                .map((item) => item.key),
        ).toEqual(['a', 'b', 'c']);
    });
});

describe('apresentação das sessões', () => {
    it('só numera quando há número: sem numeração, só a data', () => {
        expect(activitySessionNumberLabel({ session_number: null })).toBeNull();
        expect(activitySessionNumberLabel({ session_number: 3 })).toBe(
            'Sessão 3',
        );
    });

    it('a data escreve-se em pt-PT a partir de Y-m-d, sem desvio de fuso', () => {
        expect(activitySessionDateLabel('2026-10-13')).toMatch(/13/);
    });

    it('cada estado tem o seu tom: não realizada âmbar, nunca vermelha', () => {
        expect(statusTone(activitySessionToneKey('planned'))).toBe('neutral');
        expect(statusTone(activitySessionToneKey('prepared'))).toBe('blue');
        expect(statusTone(activitySessionToneKey('done'))).toBe('green');
        expect(statusTone(activitySessionToneKey('not_held'))).toBe('amber');
    });
});
