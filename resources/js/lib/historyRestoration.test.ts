import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    consumeRestoration,
    onPersistedRestore,
    resetHistoryRestorationForTests,
    restoredRecently,
    RESTORATION_WINDOW_MS,
    startHistoryRestorationTracking,
} from './historyRestoration';

function pageshow(persisted: boolean): void {
    const event = new Event('pageshow') as PageTransitionEvent;
    Object.defineProperty(event, 'persisted', { value: persisted });
    window.dispatchEvent(event);
}

describe('historyRestoration', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        resetHistoryRestorationForTests();
        startHistoryRestorationTracking();
    });
    afterEach(() => {
        resetHistoryRestorationForTests();
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    it('sem nenhuma reposição não há nada a consumir', () => {
        expect(restoredRecently()).toBe(false);
        expect(consumeRestoration()).toBe(false);
    });

    it('o popstate marca a reposição', () => {
        window.dispatchEvent(new PopStateEvent('popstate'));

        expect(restoredRecently()).toBe(true);
    });

    it('o pageshow com persisted marca; o normal não', () => {
        pageshow(false);
        expect(restoredRecently()).toBe(false);

        pageshow(true);
        expect(restoredRecently()).toBe(true);
    });

    it('consumir vale uma só vez por reposição', () => {
        window.dispatchEvent(new PopStateEvent('popstate'));

        expect(consumeRestoration()).toBe(true);
        expect(consumeRestoration()).toBe(false);

        window.dispatchEvent(new PopStateEvent('popstate'));
        expect(consumeRestoration()).toBe(true);
    });

    it('a janela expira, e consumir fora dela também gasta o carimbo', () => {
        window.dispatchEvent(new PopStateEvent('popstate'));
        vi.advanceTimersByTime(RESTORATION_WINDOW_MS - 100);
        expect(restoredRecently()).toBe(true);

        vi.advanceTimersByTime(200);
        expect(restoredRecently()).toBe(false);
        expect(consumeRestoration()).toBe(false);
    });

    it('aceita uma janela mais curta', () => {
        window.dispatchEvent(new PopStateEvent('popstate'));
        vi.advanceTimersByTime(1_600);

        expect(restoredRecently(1_500)).toBe(false);
    });

    it('arrancar duas vezes não duplica ouvintes', () => {
        startHistoryRestorationTracking();
        const listener = vi.fn();
        onPersistedRestore(listener);

        pageshow(true);
        expect(listener).toHaveBeenCalledTimes(1);
    });

    it('só o bfcache avisa quem já está montado, e a subscrição cancela-se', () => {
        const listener = vi.fn();
        const stop = onPersistedRestore(listener);

        window.dispatchEvent(new PopStateEvent('popstate'));
        pageshow(false);
        expect(listener).not.toHaveBeenCalled();

        pageshow(true);
        expect(listener).toHaveBeenCalledTimes(1);

        stop();
        pageshow(true);
        expect(listener).toHaveBeenCalledTimes(1);
    });

    it('sem window não rebenta nem regista nada', () => {
        resetHistoryRestorationForTests();
        vi.stubGlobal('window', undefined);

        expect(() => startHistoryRestorationTracking()).not.toThrow();
        expect(restoredRecently()).toBe(false);
        expect(consumeRestoration()).toBe(false);
    });
});
