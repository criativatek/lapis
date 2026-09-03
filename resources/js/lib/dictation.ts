/**
 * Ditar o reporte em vez de o escrever.
 *
 * DOIS MODOS, E A DIFERENÇA ENTRE ELES É PARA ONDE VAI A VOZ. A API de
 * reconhecimento do browser é, por omissão, um serviço remoto — a MDN di-lo por
 * palavras suas: «your audio is sent to a web service for recognition
 * processing». Desde o Chrome 138 há um modo no dispositivo (`processLocally`),
 * e é esse que se tenta primeiro.
 *
 * O QUE A SONDA DEVOLVEU NO CHROME 152 (2026-09-03, medido, não suposto):
 *
 *   pt-PT · local  · dictation → unavailable
 *   pt-PT · local  · command   → downloadable
 *   pt-PT · remoto · dictation → available
 *
 * Igual em pt-BR, en-US, es-ES e fr-FR. Ou seja: **ditado contínuo no
 * dispositivo não existe hoje**, em língua nenhuma. Só existe o nível `command`
 * — «frases curtas de vocabulário limitado» — que não serve para descrever um
 * defeito em fala corrida.
 *
 * Por isso o modo local não é uma condição para haver botão; é uma preferência
 * que se verifica em cada abertura. Enquanto não existir, usa-se o remoto — e
 * **quem vai falar tem de o saber antes de falar**. Esse aviso não é cortesia:
 * é a única coisa que distingue isto do sistema equivalente do Plaanly, que usa
 * o motor remoto sem o dizer em sítio nenhum. Quem reporta um defeito do
 * Lapispro descreve o ecrã que tem à frente, e esse ecrã é uma lista de
 * crianças contra classificações: «quando abro a turma da Ana Martins dá erro» é
 * a frase natural, não a excepção.
 *
 * `processLocally` SÓ FICA TRUE QUANDO FOI VERIFICADO. Num browser sem o
 * estático `available()` não há como saber se a propriedade é respeitada, e
 * atribuí-la ali é um no-op silencioso: o áudio vai para a rede exactamente
 * como iria sem ela. Nesse caso o modo é `remote` — que avisa — e nunca `local`,
 * que prometeria o que não pode cumprir.
 *
 * ponytail: não há caminho de instalação de modelo local. O estado
 * `downloadable` nunca aparece para a qualidade que este ecrã pede, e escrever
 * o botão para um estado que não ocorre é adivinhar. No dia em que a sonda
 * devolver `local`, este ficheiro passa a preferi-lo sem mais nada mudar.
 */

/** Português europeu. O produto é para escolas portuguesas. */
const LANGUAGE = 'pt-PT';

/**
 * `dictation`, e não `command`.
 *
 * Os três níveis da API distinguem-se pelo que aguentam: `command` é para
 * frases curtas de vocabulário fechado, `dictation` para fala contínua. Um
 * relato de defeito é fala contínua — pedir o nível errado devolveria
 * «available» para um modelo que corta a meio da segunda frase.
 */
const QUALITY = 'dictation';

/**
 * Onde a voz é transcrita.
 *
 * - `local`  — no dispositivo, verificado. Não sai nada.
 * - `remote` — pelo serviço do fornecedor do browser. **Exige aviso no ecrã.**
 * - `none`   — não há ditado neste browser.
 */
export type DictationMode = 'local' | 'remote' | 'none';

type AvailabilityOptions = {
    langs: string[];
    quality?: string;
    processLocally?: boolean;
};

type AvailabilityState = 'available' | 'downloading' | 'downloadable' | 'unavailable';

type RecognitionAlternative = { transcript: string };
type RecognitionResult = { isFinal: boolean; 0: RecognitionAlternative };

type Recognition = {
    lang: string;
    continuous: boolean;
    interimResults: boolean;
    processLocally?: boolean;
    start(): void;
    stop(): void;
    abort(): void;
    onresult: ((event: { resultIndex: number; results: ArrayLike<RecognitionResult> }) => void) | null;
    onerror: ((event: { error: string }) => void) | null;
    onend: (() => void) | null;
};

type RecognitionConstructor = {
    new (): Recognition;
    available?: (options: AvailabilityOptions) => Promise<AvailabilityState>;
};

function constructor(): RecognitionConstructor | null {
    if (typeof window === 'undefined') {
        return null;
    }

    const candidate = (window as unknown as Record<string, unknown>).SpeechRecognition
        ?? (window as unknown as Record<string, unknown>).webkitSpeechRecognition;

    return (candidate as RecognitionConstructor | undefined) ?? null;
}

async function probe(Recognition: RecognitionConstructor, processLocally: boolean): Promise<AvailabilityState> {
    try {
        return await Recognition.available!({
            langs: [LANGUAGE],
            quality: QUALITY,
            processLocally,
        });
    } catch {
        // Uma sonda que estoura não autoriza nada.
        return 'unavailable';
    }
}

/**
 * Onde é que a voz seria transcrita, se o botão fosse premido agora.
 *
 * Chamada a cada abertura do diálogo, e não uma vez no arranque: o estado do
 * modelo local muda com o browser e com o sistema, e um valor guardado no
 * arranque prometeria o que já não é verdade.
 */
export async function dictationMode(): Promise<DictationMode> {
    const Recognition = constructor();

    if (!Recognition) {
        return 'none';
    }

    if (!Recognition.available) {
        // Browser antigo: há construtor e não há forma de sondar. O único modo
        // que se pode afirmar sem mentir é o remoto, que avisa.
        return 'remote';
    }

    if ((await probe(Recognition, true)) === 'available') {
        return 'local';
    }

    return (await probe(Recognition, false)) === 'available' ? 'remote' : 'none';
}

/**
 * O que se diz a quem vai falar, antes de falar.
 *
 * VIVE AQUI, E NÃO NO TEMPLATE, pela mesma razão por que o aviso da captura de
 * ecrã vive no `screenshot.ts`: assim há um sítio onde se pode afirmar, num
 * teste, que o modo `remote` nunca fica sem dizer para onde vai o áudio. Um
 * aviso escrito só no `.vue` desaparece no primeiro refactor de template e não
 * há nada que se queixe.
 *
 * O modo `local` não menciona destino porque não há destino — e dizer «não sai
 * daqui» é a informação que interessa a quem já leu a outra versão.
 */
export function dictationNotice(mode: DictationMode): string | null {
    if (mode === 'remote') {
        return 'A transcrição é feita pelo seu browser, que envia o áudio para a Google. '
            + 'Não passa pelo Lapispro e não guardamos som nenhum. O texto fica aqui para '
            + 'corrigir antes de enviar. Não dite nomes de alunos.';
    }

    if (mode === 'local') {
        return 'A transcrição é feita no seu dispositivo — a sua voz não sai dele. O texto '
            + 'fica aqui para corrigir antes de enviar.';
    }

    return null;
}

export type DictationSession = {
    /** Termina por vontade de quem fala. Não dispara `onError`. */
    stop(): void;
};

export type DictationHandlers = {
    /**
     * O texto reconhecido até agora, do início desta sessão.
     *
     * Vem sempre completo, e não em pedaços, porque os resultados provisórios
     * são reescritos pelo próprio browser à medida que ele muda de ideias: quem
     * acumulasse fragmentos ficaria com a hesitação toda no ecrã.
     */
    onText(text: string): void;
    onError(error: string): void;
    onEnd(): void;
};

/**
 * Começa a ouvir no modo que `dictationMode()` devolveu.
 *
 * O `mode` é argumento e não é aqui redescoberto: verificar em dois sítios é a
 * forma de os dois discordarem, e o ecrã que avisou já decidiu com base no
 * primeiro.
 */
export function startDictation(
    mode: Exclude<DictationMode, 'none'>,
    handlers: DictationHandlers,
): DictationSession | null {
    const Recognition = constructor();

    if (!Recognition) {
        return null;
    }

    const recognition = new Recognition();
    recognition.lang = LANGUAGE;
    recognition.continuous = true;
    recognition.interimResults = true;

    // Só se afirma o que foi verificado. Em `remote`, a propriedade fica no seu
    // valor de origem — pôr-lhe `false` não muda nada e sugeriria uma escolha
    // onde não houve nenhuma.
    if (mode === 'local') {
        recognition.processLocally = true;
    }

    let settled = '';
    let stopped = false;

    recognition.onresult = (event) => {
        let pending = '';

        for (let index = event.resultIndex; index < event.results.length; index += 1) {
            const result = event.results[index];

            if (result.isFinal) {
                settled += `${result[0].transcript.trim()} `;

                continue;
            }

            pending += result[0].transcript;
        }

        handlers.onText(`${settled}${pending}`.trimStart());
    };

    recognition.onerror = (event) => {
        // `aborted` é o que o próprio `stop()` provoca. Não é erro de ninguém.
        if (event.error !== 'aborted') {
            handlers.onError(event.error);
        }

        stopped = true;
    };

    recognition.onend = () => {
        // O browser desliga-se sozinho ao fim de um silêncio, mesmo com
        // `continuous`. Quem está a ditar não sabe disso e continua a falar: sem
        // este reatamento, perde a segunda metade da frase e culpa o botão.
        if (stopped) {
            handlers.onEnd();

            return;
        }

        try {
            recognition.start();
        } catch {
            stopped = true;
            handlers.onEnd();
        }
    };

    try {
        recognition.start();
    } catch {
        return null;
    }

    return {
        stop(): void {
            stopped = true;
            recognition.stop();
        },
    };
}
