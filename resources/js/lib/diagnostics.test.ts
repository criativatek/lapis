import { describe, expect, it } from 'vitest';
import { detectBrowser, detectPlatform } from '@/lib/diagnostics';

/**
 * As listas fechadas são as mesmas de `App\Support\Support\ClientContext`, e o
 * servidor recusa o que não estiver nelas. Estes casos existem para o cliente
 * não mandar coisas que só lá vão ser descartadas — e, sobretudo, para provar
 * que nunca sai a string original do User-Agent.
 */
describe('detectBrowser', () => {
    it('reconhece o Chrome', () => {
        expect(
            detectBrowser('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/151.0.0.0 Safari/537.36'),
        ).toEqual({ browser: 'chrome', browser_major: 151 });
    });

    it('não confunde o Edge com o Chrome, que ele também diz ser', () => {
        expect(
            detectBrowser('Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/151.0.0.0 Safari/537.36 Edg/151.0.0.0'),
        ).toEqual({ browser: 'edge', browser_major: 151 });
    });

    it('nem o Opera', () => {
        expect(detectBrowser('Mozilla/5.0 AppleWebKit/537.36 Chrome/150.0.0.0 Safari/537.36 OPR/136.0.0.0')).toEqual({
            browser: 'opera',
            browser_major: 136,
        });
    });

    it('reconhece o Safari, que se anuncia ao contrário dos outros', () => {
        expect(detectBrowser('Mozilla/5.0 (Macintosh) AppleWebKit/605.1.15 Version/18.2 Safari/605.1.15')).toEqual({
            browser: 'safari',
            browser_major: 18,
        });
    });

    it('devolve unknown, e nunca a string original, para o que não conhece', () => {
        const exotico = 'MeuBrowserSecreto/9.9 (identificador-unico-do-dispositivo-12345)';

        const resultado = detectBrowser(exotico);

        expect(resultado).toEqual({ browser: 'unknown', browser_major: null });
        // A asserção que interessa: nada do User-Agent sobrevive.
        expect(JSON.stringify(resultado)).not.toContain('identificador');
    });
});

describe('detectPlatform', () => {
    it('põe o Android antes do Linux, que ele também diz ser', () => {
        expect(detectPlatform('Mozilla/5.0 (Linux; Android 15; Pixel 9)')).toBe('android');
    });

    it('reconhece iOS, Windows e macOS', () => {
        expect(detectPlatform('Mozilla/5.0 (iPhone; CPU iPhone OS 18_2)')).toBe('ios');
        expect(detectPlatform('Mozilla/5.0 (Windows NT 10.0; Win64; x64)')).toBe('windows');
        expect(detectPlatform('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)')).toBe('macos');
    });

    it('devolve unknown para o que não conhece', () => {
        expect(detectPlatform('qualquer coisa')).toBe('unknown');
    });
});
