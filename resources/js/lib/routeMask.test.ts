import { describe, expect, it } from 'vitest';
import { maskRoute } from '@/lib/routeMask';

/**
 * O par no servidor é `App\Support\Support\RouteMask` e
 * `tests/Feature/Support/TechnicalRouteMaskingTest.php` afirma o mesmo sobre
 * ele. Os dois ficheiros de teste dizem a mesma coisa de propósito: no dia em
 * que divergirem, é porque um dos lados mudou sozinho.
 */
describe('maskRoute', () => {
    it('substitui ULIDs, mantendo o nome do ecrã', () => {
        expect(maskRoute('/classes/01M1CP8P9936KY71CD6GVSJV60/alunos/01M17J5WWA2ZJE568VQ4YC0AXW/edit')).toBe(
            '/classes/:id/alunos/:id/edit',
        );
    });

    it('substitui UUIDs e números longos', () => {
        expect(maskRoute('/reports/3f2504e0-4f89-11d3-9a0c-0305e82c3301/seccoes/123456')).toBe('/reports/:id/seccoes/:id');
    });

    it('deixa intacto o que responde onde a pessoa estava', () => {
        // A asserção que impede uma máscara gulosa: se isto passar a `/:id/:id`,
        // a rota deixou de servir para aquilo por que existe.
        expect(maskRoute('/assessment-profiles/versoes/comparar')).toBe('/assessment-profiles/versoes/comparar');
    });

    it('não deixa passar a query string nem o fragmento', () => {
        expect(maskRoute('/relatorios?token=abc&aluno=Marta#seccao-3')).toBe('/relatorios');
    });

    it('não confunde um ano com um identificador', () => {
        expect(maskRoute('/calendario/2026/periodos')).toBe('/calendario/2026/periodos');
    });

    it('devolve null quando não há rota', () => {
        expect(maskRoute(null)).toBeNull();
        expect(maskRoute('   ')).toBeNull();
    });
});
