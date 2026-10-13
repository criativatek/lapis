import { describe, expect, it } from 'vitest';
import { todayInTimeZone } from './appDate';

describe('todayInTimeZone', () => {
    it('in Lisbon summer time, 00:30 is already the new day (the UTC day is still yesterday)', () => {
        // 2026-07-15 23:30 UTC = 2026-07-16 00:30 em Lisboa (UTC+1).
        expect(todayInTimeZone('Europe/Lisbon', new Date('2026-07-15T23:30:00Z'))).toBe('2026-07-16');
    });

    it('in Lisbon winter time, the day changes at the same instant as UTC', () => {
        expect(todayInTimeZone('Europe/Lisbon', new Date('2026-12-10T00:30:00Z'))).toBe('2026-12-10');
        expect(todayInTimeZone('Europe/Lisbon', new Date('2026-12-09T23:30:00Z'))).toBe('2026-12-09');
    });

    it('puts a record made at 00:30 on the first day of a period inside that period', () => {
        // O 1.º Período começa a 14/09: às 00:30 de Lisboa desse dia o dia UTC
        // ainda é 13/09, fora de qualquer período.
        expect(todayInTimeZone('Europe/Lisbon', new Date('2026-09-13T23:30:00Z'))).toBe('2026-09-14');
    });

    it('follows the configured zone, not the device', () => {
        // Açores no inverno: UTC-1 — às 00:30 UTC ainda é o dia anterior lá.
        expect(todayInTimeZone('Atlantic/Azores', new Date('2026-12-10T00:30:00Z'))).toBe('2026-12-09');
    });

    it('falls back to the device day for a zone this browser does not know, never to the UTC day', () => {
        const now = new Date(2026, 6, 16, 0, 30);

        expect(todayInTimeZone('Not/AZone', now)).toBe('2026-07-16');
    });
});
