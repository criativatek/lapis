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

export type Environment = {
    browser: string;
    browser_major: number | null;
    platform: string;
    viewport: string;
    language: string;
};

export type ClientContext = {
    page_component?: string;
    environment?: Environment;
};

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

/** O contexto a enviar com um reporte. */
export function clientContext(pageComponent: string | null): ClientContext | null {
    const detected = environment();

    if (detected === null) {
        return null;
    }

    return {
        ...(pageComponent ? { page_component: pageComponent } : {}),
        environment: detected,
    };
}
