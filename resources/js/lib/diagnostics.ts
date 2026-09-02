/**
 * O que a aplicação sabe sobre o ecrã de onde um reporte saiu.
 *
 * O PAR NO SERVIDOR É `App\Support\Support\ClientContext`, e é ele que manda:
 * declara as mesmas chaves, os mesmos vocabulários, e descarta o que não
 * reconhecer. Isto existe para o reporte chegar com contexto e para quem envia
 * poder ver o que envia — não para decidir o que sai.
 *
 * FAMÍLIA E VERSÃO MAIOR, NUNCA O USER-AGENT. A string completa é uma impressão
 * digital; «chrome 151» responde à única pergunta que um diagnóstico faz. Ler a
 * primeira para responder à segunda seria recolher mais do que o fim declarado
 * justifica — e é exactamente o que a avaliação de interesse legítimo do
 * suporte promete não fazer.
 *
 * O FUSO NÃO ENTRA. A aplicação inteira corre em `Europe/Lisbon`: o fuso do
 * cliente não distingue ninguém, excepto quem está fora dele — e aí distingue
 * demais.
 */

import { maskRoute } from '@/lib/routeMask';

export type Environment = {
    browser: string;
    browser_major: number | null;
    platform: string;
    viewport: string;
    language: string;
};

export type ConsoleEntry = { level: string; text: string; at: string };
export type NetworkEntry = { method: string; route: string; status: number; at: string };
export type ErrorEntry = { name: string; message?: string; where?: string; at: string };

export type ClientContext = {
    page_component?: string;
    environment?: Environment;
    console?: ConsoleEntry[];
    network?: NetworkEntry[];
    errors?: ErrorEntry[];
};

/** Os mesmos tectos de `App\Support\Support\ClientContext`. */
const MAX_CONSOLE = 100;
const MAX_NETWORK = 50;
const MAX_ERRORS = 10;
const MAX_LINE = 500;

const consoleRing: ConsoleEntry[] = [];
const networkRing: NetworkEntry[] = [];
const errorRing: ErrorEntry[] = [];

let listening = false;

function now(): string {
    return new Date().toISOString();
}

/** Empurra mantendo o tecto: o anel esquece o princípio, não o fim. */
function push<T>(ring: T[], entry: T, limit: number): void {
    ring.push(entry);

    if (ring.length > limit) {
        ring.splice(0, ring.length - limit);
    }
}

/**
 * Os argumentos de uma chamada de consola, reduzidos a uma linha.
 *
 * Um objecto grande é resumido em vez de serializado: uma prop do Inertia
 * inteira dentro de um `console.log` seria uma pauta a viajar, e o que ajuda a
 * reproduzir um defeito é saber que houve um objecto daquela forma, não o que
 * ele continha.
 */
function line(args: unknown[]): string {
    return args
        .map((argument) => {
            if (typeof argument === 'string') {
                return argument;
            }

            if (argument instanceof Error) {
                return `${argument.name}: ${argument.message}`;
            }

            if (argument === null || argument === undefined) {
                return String(argument);
            }

            if (typeof argument === 'object') {
                const keys = Object.keys(argument as Record<string, unknown>);

                return Array.isArray(argument)
                    ? `[Array(${argument.length})]`
                    : `{${keys.slice(0, 8).join(',')}${keys.length > 8 ? ',…' : ''}}`;
            }

            return String(argument);
        })
        .join(' ')
        .slice(0, MAX_LINE);
}

/** As mesmas listas de `ClientContext::BROWSERS` e `::PLATFORMS`. */
const BROWSERS: { name: string; pattern: RegExp }[] = [
    // A ordem importa: o Edge e o Opera anunciam-se também como Chrome, e o
    // Chrome anuncia-se como Safari. O primeiro que casar ganha.
    { name: 'edge', pattern: /Edg\/(\d+)/ },
    { name: 'opera', pattern: /OPR\/(\d+)/ },
    { name: 'samsung', pattern: /SamsungBrowser\/(\d+)/ },
    { name: 'chrome', pattern: /Chrome\/(\d+)/ },
    { name: 'firefox', pattern: /Firefox\/(\d+)/ },
    { name: 'safari', pattern: /Version\/(\d+).+Safari/ },
];

const PLATFORMS: { name: string; pattern: RegExp }[] = [
    // Android antes de Linux: um Android anuncia as duas coisas.
    { name: 'android', pattern: /Android/i },
    { name: 'ios', pattern: /iPhone|iPad|iPod/i },
    { name: 'windows', pattern: /Windows/i },
    { name: 'macos', pattern: /Macintosh|Mac OS X/i },
    { name: 'linux', pattern: /Linux/i },
];

/**
 * A família e a versão maior, ou `unknown` — nunca a string original. Um
 * browser que não esteja na lista é um browser que não sabemos nomear, e não
 * um motivo para guardar o que ele disse de si.
 */
export function detectBrowser(userAgent: string): { browser: string; browser_major: number | null } {
    for (const candidate of BROWSERS) {
        const match = userAgent.match(candidate.pattern);

        if (match) {
            return { browser: candidate.name, browser_major: Number(match[1]) };
        }
    }

    return { browser: 'unknown', browser_major: null };
}

export function detectPlatform(userAgent: string): string {
    return PLATFORMS.find((candidate) => candidate.pattern.test(userAgent))?.name ?? 'unknown';
}

export function environment(): Environment | null {
    if (typeof window === 'undefined') {
        return null;
    }

    const userAgent = window.navigator.userAgent ?? '';

    return {
        ...detectBrowser(userAgent),
        platform: detectPlatform(userAgent),
        viewport: `${window.innerWidth}x${window.innerHeight}`,
        // Só a etiqueta de idioma, que já viaja em todos os pedidos no
        // `Accept-Language`. Não acrescenta nada que o servidor não veja.
        language: (window.navigator.language ?? '').slice(0, 12),
    };
}

/**
 * Liga os anéis. Corre uma vez, no arranque da aplicação.
 *
 * DESDE O CARREGAMENTO, E NÃO NO MOMENTO DO CLIQUE. É a única coisa que o
 * Plaanly faz aqui que não se pode fazer de outra maneira: quando alguém carrega
 * no botão, o erro já aconteceu há dez segundos e a consola já se perdeu. Um
 * anél ligado a partir do clique chega sempre tarde.
 *
 * O QUE ISTO NÃO FAZ: não lê cookies, não lê `localStorage`, não guarda URLs (a
 * rota vai mascarada) e não envia nada sozinho. Os anéis vivem em memória e só
 * saem se alguem abrir o formulário e carregar em enviar.
 */
export function startDiagnostics(): void {
    if (typeof window === 'undefined' || listening) {
        return;
    }

    listening = true;

    // 1 --- a consola, sem lhe roubar o comportamento.
    (['log', 'info', 'warn', 'error', 'debug'] as const).forEach((level) => {
        const original = console[level].bind(console);

        console[level] = (...args: unknown[]): void => {
            push(consoleRing, { level, text: line(args), at: now() }, MAX_CONSOLE);
            original(...args);
        };
    });

    // 2 --- os erros que ninguém apanhou.
    window.addEventListener('error', (event) => {
        push(
            errorRing,
            {
                name: event.error?.name ?? 'Error',
                message: String(event.message ?? '').slice(0, MAX_LINE),
                // Ficheiro do bundle e linha — um artefacto compilado, não um
                // caminho da aplicação nem um identificador de ninguém.
                where: `${(event.filename ?? '').split('/').pop() ?? ''}:${event.lineno ?? 0}`,
                at: now(),
            },
            MAX_ERRORS,
        );
    });

    window.addEventListener('unhandledrejection', (event) => {
        const reason = event.reason;

        push(
            errorRing,
            {
                name: reason instanceof Error ? reason.name : 'UnhandledRejection',
                message: (reason instanceof Error ? reason.message : String(reason ?? '')).slice(0, MAX_LINE),
                at: now(),
            },
            MAX_ERRORS,
        );
    });

    // 3 --- a rede. MÉTODO, ROTA MASCARADA E ESTADO — nunca o URL.
    const originalFetch = window.fetch.bind(window);

    window.fetch = async (input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
        const method = (init?.method ?? (input instanceof Request ? input.method : 'GET')).toUpperCase();
        const url = input instanceof Request ? input.url : String(input);

        try {
            const response = await originalFetch(input as RequestInfo, init);
            recordRequest(method, url, response.status);

            return response;
        } catch (failure) {
            // Zero quer dizer «nem chegou a haver resposta»: rede em baixo,
            // pedido cancelado, CORS. É diferente de um 500 e diz-se diferente.
            recordRequest(method, url, 0);

            throw failure;
        }
    };
}

/** @internal exportado para o teste poder observar sem abrir o formulário. */
export function recordRequest(method: string, url: string, status: number): void {
    let path = url;

    try {
        path = new URL(url, window.location.origin).pathname;
    } catch {
        // Um URL que nem o browser consegue interpretar não vale a pena guardar
        // em bruto: fica o que dele se conseguiu tirar, mascarado à mesma.
    }

    push(
        networkRing,
        {
            method: method.toUpperCase(),
            route: maskRoute(path) ?? '/',
            status,
            at: now(),
        },
        MAX_NETWORK,
    );
}

/** O contexto a enviar com um reporte. */
export function clientContext(pageComponent: string | null): ClientContext | null {
    const detected = environment();

    if (detected === null) {
        return null;
    }

    return {
        ...(pageComponent ? { page_component: pageComponent } : {}),
        environment: detected,
        ...(consoleRing.length > 0 ? { console: [...consoleRing] } : {}),
        ...(networkRing.length > 0 ? { network: [...networkRing] } : {}),
        ...(errorRing.length > 0 ? { errors: [...errorRing] } : {}),
    };
}

/** @internal apenas para os testes. */
export function resetDiagnostics(): void {
    consoleRing.length = 0;
    networkRing.length = 0;
    errorRing.length = 0;
}
