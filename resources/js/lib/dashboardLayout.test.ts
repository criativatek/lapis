import { describe, expect, it, vi } from 'vitest';
import type { BlockId, BlockWidth } from './dashboardLayout';
import { BLOCK_WIDTH, DEFAULT_ORDER, layoutBlocks, layoutStorageKey, mergeVisibleOrder, moveBlock, parseStoredOrder, readStoredLayout, sanitizeOrder, visibleOrder, writeStoredLayout } from './dashboardLayout';

const spans = (order: BlockId[], widths: Readonly<Record<BlockId, BlockWidth>> = BLOCK_WIDTH) => layoutBlocks(order, widths).map((block) => `${block.id}:${block.span}`);

function permutations(items: BlockId[]): BlockId[][] {
    if (items.length <= 1) {
        return [items];
    }

    return items.flatMap((item, index) => permutations([...items.slice(0, index), ...items.slice(index + 1)]).map((rest) => [item, ...rest]));
}

describe('dashboardLayout — a ordem de origem', () => {
    it('são só quatro blocos, e «Hoje» não está entre eles', () => {
        expect([...DEFAULT_ORDER]).toEqual(['week', 'done', 'period', 'classes']);
        expect(DEFAULT_ORDER as readonly string[]).not.toContain('today');
    });

    it('Esta semana e As minhas turmas são largos; Arrumado hoje e Progresso do período, meios', () => {
        expect(BLOCK_WIDTH).toEqual({ week: 'wide', done: 'half', period: 'half', classes: 'wide' });
    });
});

describe('dashboardLayout — pares e meios sem par', () => {
    it('a composição de origem: dois meios consecutivos emparelham', () => {
        expect(spans(['week', 'done', 'period', 'classes'])).toEqual(['week:wide', 'done:half', 'period:half', 'classes:wide']);
    });

    it('um meio sem par (seguido de um largo) ocupa a linha toda', () => {
        expect(spans(['done', 'week', 'period', 'classes'])).toEqual(['done:wide', 'week:wide', 'period:wide', 'classes:wide']);
    });

    it('dois meios depois dos largos continuam a emparelhar, em qualquer ordem entre eles', () => {
        expect(spans(['week', 'classes', 'done', 'period'])).toEqual(['week:wide', 'classes:wide', 'done:half', 'period:half']);
        expect(spans(['classes', 'week', 'period', 'done'])).toEqual(['classes:wide', 'week:wide', 'period:half', 'done:half']);
    });

    it('um meio no fim, sozinho, ocupa a linha toda', () => {
        expect(spans(['classes', 'week', 'done'])).toEqual(['classes:wide', 'week:wide', 'done:wide']);
    });

    it('«Arrumado hoje» vazio (largo por conteúdo) deixa o período sozinho, também em linha inteira', () => {
        const widths: Record<BlockId, BlockWidth> = { ...BLOCK_WIDTH, done: 'wide' };

        expect(spans(['week', 'done', 'period', 'classes'], widths)).toEqual(['week:wide', 'done:wide', 'period:wide', 'classes:wide']);
    });

    it('para qualquer ordem possível: a ordem do DOM é a dada e nenhuma linha fica a meio', () => {
        for (const widths of [BLOCK_WIDTH, { ...BLOCK_WIDTH, done: 'wide' as const }]) {
            for (const order of permutations([...DEFAULT_ORDER])) {
                const placed = layoutBlocks(order, widths);
                let row = 0;

                for (const block of placed) {
                    row += block.span === 'wide' ? 2 : 1;

                    if (row === 2) {
                        row = 0;
                    }
                }

                expect(placed.map((block) => block.id)).toEqual(order);
                expect(row).toBe(0);
            }
        }
    });
});

describe('dashboardLayout — saneamento da ordem guardada', () => {
    it('descarta ids desconhecidos (incluindo o antigo «today») e duplicados', () => {
        expect(sanitizeOrder(['today', 'xpto', 'classes', 'classes', 'done', 7, null])).toEqual(['classes', 'done', 'week', 'period']);
    });

    it('ids novos no futuro entram no fim, pela ordem de origem', () => {
        expect(sanitizeOrder(['classes'])).toEqual(['classes', 'week', 'done', 'period']);
    });

    it('JSON inválido, vazio ou de outro tipo → ordem de origem', () => {
        expect(parseStoredOrder(null)).toEqual([...DEFAULT_ORDER]);
        expect(parseStoredOrder('{corrompido')).toEqual([...DEFAULT_ORDER]);
        expect(parseStoredOrder('"week"')).toEqual([...DEFAULT_ORDER]);
        expect(parseStoredOrder('{"a":1}')).toEqual([...DEFAULT_ORDER]);
        expect(parseStoredOrder('["classes","week","done","period"]')).toEqual(['classes', 'week', 'done', 'period']);
    });
});

describe('dashboardLayout — blocos indisponíveis mantêm o lugar', () => {
    const saved: BlockId[] = ['classes', 'period', 'week', 'done'];

    it('o que não está disponível não se vê nem se ordena', () => {
        expect(visibleOrder(saved, ['week', 'done', 'classes'])).toEqual(['classes', 'week', 'done']);
    });

    it('ao guardar sem o bloco ausente, os visíveis ocupam os lugares que já tinham e o ausente fica onde estava', () => {
        // «period» (2.º) está ausente; o professor passa «week» para a frente
        expect(mergeVisibleOrder(saved, ['week', 'classes', 'done'])).toEqual(['week', 'period', 'classes', 'done']);
    });

    it('quando o bloco volta, reaparece na posição guardada', () => {
        const after = mergeVisibleOrder(saved, ['week', 'classes', 'done']);

        expect(visibleOrder(after, [...DEFAULT_ORDER])).toEqual(['week', 'period', 'classes', 'done']);
    });
});

describe('dashboardLayout — mover e chave', () => {
    it('sobe e desce uma posição; nos extremos não muda', () => {
        expect(moveBlock(['week', 'done', 'period', 'classes'], 'done', -1)).toEqual(['done', 'week', 'period', 'classes']);
        expect(moveBlock(['week', 'done', 'period', 'classes'], 'done', 1)).toEqual(['week', 'period', 'done', 'classes']);
        expect(moveBlock(['week', 'done'], 'week', -1)).toEqual(['week', 'done']);
        expect(moveBlock(['week', 'done'], 'done', 1)).toEqual(['week', 'done']);
    });

    it('a chave é por utilizador e por organização', () => {
        expect(layoutStorageKey(7, 'org-a')).toBe('lapis.dashboard.layout.v1:7:org-a');
        expect(layoutStorageKey(7, 'org-b')).not.toBe(layoutStorageKey(7, 'org-a'));
        expect(layoutStorageKey(8, 'org-a')).not.toBe(layoutStorageKey(7, 'org-a'));
    });
});

describe('dashboardLayout — armazenamento', () => {
    it('guarda e lê', () => {
        window.localStorage.clear();

        expect(writeStoredLayout('k', ['classes', 'week', 'done', 'period'])).toBe(true);
        expect(readStoredLayout('k')).toEqual(['classes', 'week', 'done', 'period']);
    });

    it('sem storage (bloqueado) não rebenta: lê a ordem de origem e a escrita devolve falso', () => {
        const getItem = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
            throw new Error('blocked');
        });
        const setItem = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new Error('blocked');
        });

        expect(readStoredLayout('k')).toEqual([...DEFAULT_ORDER]);
        expect(writeStoredLayout('k', ['week'])).toBe(false);

        getItem.mockRestore();
        setItem.mockRestore();
    });
});
