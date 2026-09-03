import { afterEach, describe, expect, it, vi } from 'vitest';
import { dictationMode, dictationNotice, startDictation } from '@/lib/dictation';

/**
 * O QUE ESTE FICHEIRO GUARDA. O ditado tem dois modos e a diferença entre eles
 * é para onde vai a voz de quem fala sobre alunos. `local` é uma afirmação
 * forte — «não sai daqui» — e só pode ser feita quando foi verificada; `remote`
 * é a que obriga o ecrã a avisar.
 *
 * O erro que estes casos existem para impedir é o silencioso: num browser que
 * não conheça `processLocally`, atribuí-la é um no-op e o áudio segue para a
 * rede exactamente como seguiria sem ela. Nada falha, nada avisa — e o modo
 * teria dito `local`.
 */

type Instance = {
    lang: string;
    continuous: boolean;
    interimResults: boolean;
    processLocally?: boolean;
    start: () => void;
    stop: () => void;
    abort: () => void;
    onresult: ((event: unknown) => void) | null;
    onerror: ((event: { error: string }) => void) | null;
    onend: (() => void) | null;
};

const instances: Instance[] = [];

function install(statics: Record<string, unknown> = {}): void {
    const Recognition = function (this: Instance) {
        this.lang = '';
        this.continuous = false;
        this.interimResults = false;
        this.start = vi.fn();
        this.stop = vi.fn();
        this.abort = vi.fn();
        this.onresult = null;
        this.onerror = null;
        this.onend = null;
        instances.push(this);
    } as unknown as Record<string, unknown>;

    Object.assign(Recognition, statics);

    (window as unknown as Record<string, unknown>).SpeechRecognition = Recognition;
}

/** A sonda tal como o Chrome 152 respondeu a 2026-09-03. */
function chrome152(): void {
    install({
        available: vi.fn(async ({ processLocally }: { processLocally?: boolean }) =>
            processLocally ? 'unavailable' : 'available',
        ),
    });
}

afterEach(() => {
    delete (window as unknown as Record<string, unknown>).SpeechRecognition;
    delete (window as unknown as Record<string, unknown>).webkitSpeechRecognition;
    instances.length = 0;
});

describe('dictationMode', () => {
    it('não oferece ditado quando não há API nenhuma', async () => {
        await expect(dictationMode()).resolves.toBe('none');
    });

    it('prefere o dispositivo quando o modelo local existe', async () => {
        install({ available: vi.fn().mockResolvedValue('available') });

        await expect(dictationMode()).resolves.toBe('local');
    });

    it('cai no remoto quando só o remoto existe — o Chrome de hoje', async () => {
        chrome152();

        await expect(dictationMode()).resolves.toBe('remote');
    });

    it('nunca diz local quando o browser não sabe verificar o modo local', async () => {
        // O CASO QUE IMPORTA. Um browser antigo tem o construtor e não tem o
        // estático. Ali, `processLocally = true` não faz nada e o áudio segue
        // para a rede — portanto a resposta certa é `remote`, que obriga o ecrã
        // a avisar, e não `local`, que prometeria o que não pode cumprir.
        install();

        await expect(dictationMode()).resolves.toBe('remote');
    });

    it('pergunta pelo nível de ditado, em pt-PT, primeiro no dispositivo', async () => {
        const available = vi.fn().mockResolvedValue('available');
        install({ available });

        await dictationMode();

        expect(available).toHaveBeenCalledWith({
            langs: ['pt-PT'],
            quality: 'dictation',
            processLocally: true,
        });
    });

    it('não oferece ditado quando nem local nem remoto servem', async () => {
        install({ available: vi.fn().mockResolvedValue('unavailable') });

        await expect(dictationMode()).resolves.toBe('none');
    });

    it('trata uma sonda que estoura como indisponível', async () => {
        install({ available: vi.fn().mockRejectedValue(new Error('boom')) });

        await expect(dictationMode()).resolves.toBe('none');
    });
});

describe('dictationNotice', () => {
    it('diz para onde vai o áudio quando ele sai do dispositivo', () => {
        // A ASSERÇÃO QUE JUSTIFICA A FUNCIONALIDADE. O modo remoto é aceitável
        // porque quem fala sabe, antes de falar, que a voz sai. Se este aviso
        // deixar de nomear o destinatário, o botão passa a ser o do Plaanly:
        // funciona, e não diz a ninguém para onde vai a voz.
        const aviso = dictationNotice('remote');

        expect(aviso).not.toBeNull();
        expect(aviso).toContain('envia o áudio para a Google');
        expect(aviso).toContain('Não dite nomes de alunos');
    });

    it('não inventa destino nenhum quando a voz fica no dispositivo', () => {
        const aviso = dictationNotice('local');

        expect(aviso).toContain('não sai dele');
        expect(aviso).not.toContain('Google');
    });

    it('não avisa de nada quando não há ditado', () => {
        expect(dictationNotice('none')).toBeNull();
    });
});

describe('startDictation', () => {
    it('impõe o modo local na instância que vai ouvir', () => {
        install();

        startDictation('local', { onText: vi.fn(), onError: vi.fn(), onEnd: vi.fn() });

        expect(instances[0].processLocally).toBe(true);
        expect(instances[0].lang).toBe('pt-PT');
        expect(instances[0].continuous).toBe(true);
    });

    it('não afirma nada sobre o modo local quando é remoto', () => {
        install();

        startDictation('remote', { onText: vi.fn(), onError: vi.fn(), onEnd: vi.fn() });

        // Deixar a propriedade intocada é diferente de lhe pôr `false`: o
        // segundo sugeriria uma escolha onde não houve nenhuma.
        expect(instances[0].processLocally).toBeUndefined();
    });

    it('entrega o texto todo, não os pedaços', () => {
        install();
        const onText = vi.fn();

        startDictation('remote', { onText, onError: vi.fn(), onEnd: vi.fn() });

        instances[0].onresult?.({
            resultIndex: 0,
            results: [{ isFinal: true, 0: { transcript: 'A turma não abre' } }],
        });
        instances[0].onresult?.({
            resultIndex: 1,
            results: [
                { isFinal: true, 0: { transcript: 'A turma não abre' } },
                { isFinal: false, 0: { transcript: 'e dá erro' } },
            ],
        });

        // Os resultados provisórios são reescritos pelo browser à medida que ele
        // muda de ideias. Quem acumulasse fragmentos ficava com a hesitação toda
        // no ecrã — daí o texto vir sempre completo.
        expect(onText).toHaveBeenLastCalledWith('A turma não abre e dá erro');
    });

    it('reata quando o browser se desliga sozinho no silêncio', () => {
        install();
        const onEnd = vi.fn();

        startDictation('remote', { onText: vi.fn(), onError: vi.fn(), onEnd });

        instances[0].onend?.();

        // `continuous` não impede o corte por silêncio. Sem reatamento, perde-se
        // a segunda metade da frase e a culpa fica do botão.
        expect(instances[0].start).toHaveBeenCalledTimes(2);
        expect(onEnd).not.toHaveBeenCalled();
    });

    it('para de vez quando é quem fala a parar', () => {
        install();
        const onEnd = vi.fn();

        const session = startDictation('remote', { onText: vi.fn(), onError: vi.fn(), onEnd });
        session?.stop();
        instances[0].onend?.();

        expect(instances[0].start).toHaveBeenCalledTimes(1);
        expect(onEnd).toHaveBeenCalledTimes(1);
    });

    it('não chama onError no aborto que o próprio stop provoca', () => {
        install();
        const onError = vi.fn();

        startDictation('remote', { onText: vi.fn(), onError, onEnd: vi.fn() });
        instances[0].onerror?.({ error: 'aborted' });

        expect(onError).not.toHaveBeenCalled();
    });
});
