import { afterEach, describe, expect, it, vi } from 'vitest';
import { capture, warnAbout } from '@/lib/screenshot';

vi.mock('modern-screenshot', () => ({
    domToBlob: vi.fn(async () => new Blob(['x'], { type: 'image/jpeg' })),
}));

/**
 * O aviso é o que separa uma certificação informada de uma às cegas. Se ele
 * deixar de disparar numa página cheia de dados, a caixa passa a ser uma
 * formalidade — e a formalidade é o que se marca sem olhar.
 */
describe('warnAbout', () => {
    it('avisa quando a página mostra o que a aplicação sabe reconhecer', () => {
        const aviso = warnAbout('Contacto: ana.martins@escola.pt · telefone 912 345 678');

        expect(aviso).not.toBeNull();
        expect(aviso).toMatch(/^Esta página mostra /);
    });

    it('avisa NA MESMA quando não reconhece nada', () => {
        // A asserção mais importante deste ficheiro. O detector reconhece
        // formatos, e um nome próprio não tem formato: a página de resultados de
        // uma turma — seis nomes de crianças contra as suas classificações —
        // não dispara nada. Se o aviso desaparecesse aí, o ecrã mais perigoso do
        // produto seria o único a não avisar de coisa nenhuma.
        const aviso = warnAbout('Média da turma: 71,7 por cento no 2.º semestre.');

        expect(aviso).not.toBeNull();
        expect(aviso).toContain('não consegue reconhecer nomes de alunos');
    });

    it('não repete a mesma espécie duas vezes', () => {
        const aviso = warnAbout('ana@escola.pt e nuno@escola.pt e rita@escola.pt');

        expect(aviso).not.toBeNull();
        // Três emails, uma espécie: o aviso conta o que há, não quantas vezes.
        expect(aviso!.split('Esta página mostra ')[1]?.split('.')[0]).not.toContain(',');
    });

    it('nunca inclui o valor encontrado', () => {
        // A regra que o detector já impõe e que aqui não se pode perder: o
        // aviso diz a ESPÉCIE, nunca o que encontrou. Um aviso que citasse o
        // email estaria a repetir o problema no ecrã.
        const aviso = warnAbout('escrever a ana.martins@escola.pt');

        expect(aviso).not.toContain('ana.martins');
        expect(aviso).not.toContain('@');
    });
});

/**
 * A via silenciosa existe por causa da captura automática: o clique em
 * «Reportar problema» captura logo, e um clique para reportar NÃO é um clique
 * para responder ao pedido de partilha de ecrã do browser. Se `silent` deixar
 * de ser respeitado, cada reporte passa a abrir esse pedido — e ninguém
 * reporta duas vezes.
 */
describe('capture — a via silenciosa', () => {
    afterEach(() => {
        delete (navigator as unknown as Record<string, unknown>).mediaDevices;
        vi.restoreAllMocks();
    });

    function stubDisplayMedia(): ReturnType<typeof vi.fn> {
        const getDisplayMedia = vi.fn().mockRejectedValue(new Error('recusado'));

        Object.defineProperty(navigator, 'mediaDevices', {
            configurable: true,
            value: { getDisplayMedia },
        });

        // jsdom não tem createObjectURL; a pré-visualização não interessa aqui.
        URL.createObjectURL = vi.fn(() => 'blob:teste');

        return getDisplayMedia;
    }

    it('em silent nunca toca no getDisplayMedia', async () => {
        const getDisplayMedia = stubDisplayMedia();

        const result = await capture([], { silent: true });

        expect(getDisplayMedia).not.toHaveBeenCalled();
        expect(result).not.toBeNull();
    });

    it('sem silent tenta primeiro o ecrã verdadeiro', async () => {
        const getDisplayMedia = stubDisplayMedia();

        await capture([]);

        expect(getDisplayMedia).toHaveBeenCalledTimes(1);
    });
});
