export type ParsedScore = { kind: 'empty' } | { kind: 'number'; value: number } | { kind: 'invalid' };

/**
 * Um número escrito à portuguesa ou à inglesa: «3,5» e «3.5» valem o mesmo, «,5» e «.5» são 0,5.
 * Sem separador de milhares, sem expoente, sem sinal «+». «3.» e «3,» (a meio da escrita) são inválidos
 * até haver um dígito depois do separador.
 */
const SCORE_PATTERN = /^-?(?:\d+(?:[.,]\d+)?|[.,]\d+)$/;

/**
 * Lê o texto de uma caixa de classificação. Vazio quer dizer «por avaliar» (nunca zero); qualquer outra
 * coisa que não seja um número devolve `invalid` e quem chama não deve guardá-la. Não arredonda.
 */
export function parseScore(raw: string): ParsedScore {
    const text = raw.trim();

    if (text === '') {
        return { kind: 'empty' };
    }

    if (! SCORE_PATTERN.test(text)) {
        return { kind: 'invalid' };
    }

    const value = Number(text.replace(',', '.'));

    return Number.isFinite(value) ? { kind: 'number', value } : { kind: 'invalid' };
}

/** O número com vírgula decimal e sem zeros à direita inúteis («3,5», «3,33», «4»); `null` é a caixa vazia. */
export function formatScoreInput(value: number | null): string {
    if (value === null || ! Number.isFinite(value)) {
        return '';
    }

    return String(value).replace('.', ',');
}
