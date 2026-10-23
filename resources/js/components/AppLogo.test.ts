import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import AppLogo from './AppLogo.vue';

/**
 * O slogan oficial tem 38 caracteres e a barra lateral 16rem: cabe em duas
 * linhas, não em uma. Cortá-lo com reticências (`truncate`) escondia a frase
 * que a marca quer dizer, por isso quebra entre as duas frases.
 */
describe('AppLogo', () => {
    it('diz o slogan oficial e já não o antigo', () => {
        const text = mount(AppLogo).text().replace(/\s+/g, ' ');

        expect(text).toContain('Lapispro');
        expect(text).toContain('A tecnologia apoia. O professor transforma.');
        expect(text).not.toContain('Mais simples');
        expect(text).not.toContain('Mais tempo');
    });

    it('o slogan não é truncado e quebra entre as duas frases', () => {
        const slogan = mount(AppLogo).get('[data-testid="brand-slogan"]');

        expect(slogan.classes()).not.toContain('truncate');
        expect(
            slogan.findAll('span.inline-block').map((s) => s.text()),
        ).toEqual(['A tecnologia apoia.', 'O professor transforma.']);
    });
});
