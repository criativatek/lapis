import { beforeEach, describe, expect, it } from 'vitest';
import { clientContext, recordRequest, resetDiagnostics, startDiagnostics } from '@/lib/diagnostics';

/**
 * Os anéis guardam o suficiente para reproduzir um defeito e nada que
 * identifique alguém. Estes casos afirmam as duas metades — o que fica e o que
 * nunca chega a entrar —, porque uma delas sozinha não prova nada.
 */
describe('anéis de diagnóstico', () => {
    beforeEach(() => {
        resetDiagnostics();
        startDiagnostics();
    });

    it('guarda o que a consola disse', () => {
        console.warn('a grelha não carregou');

        const entries = clientContext(null)?.console ?? [];

        expect(entries.at(-1)?.level).toBe('warn');
        expect(entries.at(-1)?.text).toBe('a grelha não carregou');
    });

    it('resume um objecto à sua forma, em vez de o serializar', () => {
        // O caso que interessa: uma prop do Inertia dentro de um `console.log`
        // seria uma pauta inteira a viajar. O que ajuda a reproduzir é saber que
        // houve um objecto com aquelas chaves.
        console.log('props', { alunos: [{ nome: 'Marta Tomás', nota: 63 }], turma: '7.º A' });

        const texto = clientContext(null)?.console?.at(-1)?.text ?? '';

        expect(texto).toContain('{alunos,turma}');
        expect(texto).not.toContain('Marta');
        expect(texto).not.toContain('7.º A');
    });

    it('guarda a rota do pedido, nunca o URL', () => {
        recordRequest('get', 'https://lapispro.com/classes/01M1CP8P9936KY71CD6GVSJV60/alunos?token=abc', 500);

        const entry = clientContext(null)?.network?.at(-1);

        expect(entry).toEqual(expect.objectContaining({ method: 'GET', route: '/classes/:id/alunos', status: 500 }));
        expect(JSON.stringify(entry)).not.toContain('token');
        expect(JSON.stringify(entry)).not.toContain('01M1CP8P');
    });

    it('distingue não ter havido resposta de uma resposta de erro', () => {
        recordRequest('POST', '/support', 0);

        expect(clientContext(null)?.network?.at(-1)?.status).toBe(0);
    });

    it('esquece o princípio quando enche, e não o fim', () => {
        resetDiagnostics();

        for (let index = 0; index < 120; index++) {
            console.log(`linha ${index}`);
        }

        const entries = clientContext(null)?.console ?? [];

        expect(entries).toHaveLength(100);
        expect(entries.at(-1)?.text).toBe('linha 119');
        expect(entries.at(0)?.text).toBe('linha 20');
    });

    it('não inclui os anéis quando estão vazios', () => {
        resetDiagnostics();

        const context = clientContext('classes/Show');

        expect(context?.console).toBeUndefined();
        expect(context?.network).toBeUndefined();
        expect(context?.page_component).toBe('classes/Show');
    });
});
