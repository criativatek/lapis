/**
 * A disposição do Painel do Professor: quais são os blocos que o professor pode
 * ordenar, que largura têm e como a ordem dele se transforma em linhas sem
 * vazios evitáveis. Tudo puro — a persistência é só `localStorage`, por
 * utilizador e organização, e nunca é essencial.
 *
 * O cabeçalho (saudação, atalhos, três cartões) e a régua «Hoje» NÃO entram:
 * ficam fixos, por esta ordem, antes dos blocos.
 *
 * Regra de ouro: a ordem do DOM é a ordem visual. A grelha preenche por linhas
 * (nunca `dense`). Blocos «largos» ocupam a linha toda; os «meios» emparelham
 * com o meio seguinte; um meio sem par ocupa a linha toda em vez de deixar
 * metade dela vazia.
 */

export type BlockId = 'week' | 'done' | 'period' | 'classes';
export type BlockWidth = 'wide' | 'half';

/** A ordem de origem — e a lista branca: nenhum outro id é aceite. */
export const DEFAULT_ORDER: readonly BlockId[] = ['week', 'done', 'period', 'classes'];

export const BLOCK_WIDTH: Readonly<Record<BlockId, BlockWidth>> = { week: 'wide', done: 'half', period: 'half', classes: 'wide' };

export const BLOCK_LABEL: Readonly<Record<BlockId, string>> = {
    week: 'Esta semana',
    done: 'Arrumado hoje',
    period: 'Progresso do período',
    classes: 'As minhas turmas',
};

export type PlacedBlock = { id: BlockId; span: BlockWidth };

const KEY_PREFIX = 'lapis.dashboard.layout.v1';

/** Uma chave por pessoa e por organização: a ordem de uma não vaza para a outra nem para outro utilizador do mesmo browser. */
export function layoutStorageKey(userId: number | string | null | undefined, organizationUlid: string | null | undefined): string {
    return `${KEY_PREFIX}:${userId ?? 'x'}:${organizationUlid ?? 'x'}`;
}

/**
 * Lista branca e saneamento: ids desconhecidos e duplicados descartam-se; os ids
 * que faltam (blocos novos no futuro) entram no fim, pela ordem de origem.
 */
export function sanitizeOrder(saved: unknown): BlockId[] {
    const result: BlockId[] = [];

    if (Array.isArray(saved)) {
        for (const id of saved) {
            if (typeof id === 'string' && (DEFAULT_ORDER as readonly string[]).includes(id) && !result.includes(id as BlockId)) {
                result.push(id as BlockId);
            }
        }
    }

    for (const id of DEFAULT_ORDER) {
        if (!result.includes(id)) {
            result.push(id);
        }
    }

    return result;
}

/** A ordem guardada, lida do texto do storage; JSON inválido → ordem de origem. */
export function parseStoredOrder(raw: string | null): BlockId[] {
    if (raw === null) {
        return [...DEFAULT_ORDER];
    }

    try {
        return sanitizeOrder(JSON.parse(raw));
    } catch {
        return [...DEFAULT_ORDER];
    }
}

/** A ordem do que está DISPONÍVEL agora: um bloco ausente (plano, sem dados) mantém a posição guardada para quando voltar. */
export function visibleOrder(full: readonly BlockId[], available: readonly BlockId[]): BlockId[] {
    return full.filter((id) => available.includes(id));
}

/**
 * Aplica a nova ordem dos blocos visíveis à ordem completa guardada, sem mexer
 * nos ausentes: os visíveis ocupam, pela ordem nova, as posições que já ocupavam.
 */
export function mergeVisibleOrder(full: readonly BlockId[], visible: readonly BlockId[]): BlockId[] {
    const queue = [...visible];

    return full.map((id) => (visible.includes(id) ? (queue.shift() ?? id) : id));
}

/**
 * Cada bloco com a largura efetiva: um «meio» sem par passa a «largo».
 * `widths` deixa o conteúdo decidir (um «Arrumado hoje» vazio é uma linha curta
 * e passa a largo, em vez de ficar ao lado de um bloco alto).
 */
export function layoutBlocks(order: readonly BlockId[], widths: Readonly<Record<BlockId, BlockWidth>> = BLOCK_WIDTH): PlacedBlock[] {
    const placed: PlacedBlock[] = [];
    let index = 0;

    while (index < order.length) {
        const id = order[index];
        const next = order[index + 1];

        if (widths[id] === 'half' && next !== undefined && widths[next] === 'half') {
            placed.push({ id, span: 'half' }, { id: next, span: 'half' });
            index += 2;
        } else {
            placed.push({ id, span: 'wide' });
            index += 1;
        }
    }

    return placed;
}

export function moveBlock(order: readonly BlockId[], id: BlockId, delta: -1 | 1): BlockId[] {
    const from = order.indexOf(id);
    const to = from + delta;

    if (from < 0 || to < 0 || to >= order.length) {
        return [...order];
    }

    const next = [...order];
    [next[from], next[to]] = [next[to], next[from]];

    return next;
}

/** Leitura segura: sem storage (janela privada, bloqueado) a página funciona com a ordem de origem. */
export function readStoredLayout(key: string): BlockId[] {
    try {
        return parseStoredOrder(window.localStorage.getItem(key));
    } catch {
        return [...DEFAULT_ORDER];
    }
}

/** Escrita segura; devolve se ficou guardada (a UI continua a funcionar na sessão mesmo que não). */
export function writeStoredLayout(key: string, order: readonly BlockId[]): boolean {
    try {
        window.localStorage.setItem(key, JSON.stringify(order));

        return true;
    } catch {
        return false;
    }
}
