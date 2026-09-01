/**
 * A rota actual, sem o que identifica alguém.
 *
 * O par desta função vive no servidor (`App\Support\Support\RouteMask`) e é lá
 * que a regra é imposta — isto existe para quem envia poder VER o que envia,
 * não para decidir o que sai. As duas têm de concordar; se divergirem, ganha o
 * servidor e o ecrã passa a mentir sobre o que mostrou.
 *
 * A fronteira dos seis algarismos é a mesma do `AiPayloadSanitizer`: abaixo
 * disso um número é um ano ou um período, acima disso não é mais nada.
 */

const ULID = /^[0-9A-HJKMNP-TV-Z]{26}$/i;
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const LONG_NUMBER = /^\d{6,}$/;

export const PLACEHOLDER = ':id';

function identifies(segment: string): boolean {
    return ULID.test(segment) || UUID.test(segment) || LONG_NUMBER.test(segment);
}

/** Mascara os segmentos identificadores de um caminho. */
export function maskRoute(route: string | null | undefined): string | null {
    if (!route || route.trim() === '') {
        return null;
    }

    // A query string e o fragmento nunca seguem: é onde vivem os tokens e os
    // filtros que alguém colou na barra de endereço.
    const path = route.trim().replace(/[?#].*$/, '');

    return path
        .split('/')
        .map((segment) => (identifies(segment) ? PLACEHOLDER : segment))
        .join('/')
        .slice(0, 200);
}

/** A rota em que a pessoa está agora, já mascarada. */
export function currentMaskedRoute(): string | null {
    if (typeof window === 'undefined') {
        return null;
    }

    return maskRoute(window.location.pathname);
}
