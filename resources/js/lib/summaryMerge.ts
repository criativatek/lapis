/**
 * COMPARAR E COMBINAR dois sumários (0.158.0) — o que o cartão oferece quando
 * a gravação é recusada porque outra janela gravou o mesmo sumário entretanto.
 *
 * A comparação é por LINHAS, porque é assim que um sumário se escreve (uma
 * atividade por linha) e é assim que o professor o relê. Não há aqui nenhuma
 * fusão automática que grave: `combineSummaries` só produz um texto para o
 * editor, que o professor revê e guarda — e essa gravação volta a verificar a
 * versão no servidor.
 */

function normalizedLines(text: string): string[] {
    return text.replace(/\r\n/g, '\n').split('\n');
}

function key(line: string): string {
    return line.trim().replace(/\s+/g, ' ');
}

export type ComparedLine = {
    text: string;
    /** A linha não existe (igual) no outro texto. */
    onlyHere: boolean;
};

/** As linhas de `text`, cada uma marcada quando não existe no `other`. */
export function compareLines(text: string, other: string): ComparedLine[] {
    const otherKeys = new Set(
        normalizedLines(other)
            .map(key)
            .filter((line) => line !== ''),
    );

    return normalizedLines(text)
        .filter(
            (line, index, lines) =>
                !(
                    line.trim() === '' &&
                    (index === 0 || index === lines.length - 1)
                ),
        )
        .map((line) => ({
            text: line,
            onlyHere: key(line) !== '' && !otherKeys.has(key(line)),
        }));
}

/**
 * O texto do professor, mais as linhas do texto gravado que ele ainda não tem,
 * acrescentadas no fim pela ordem em que lá estão. O rascunho nunca perde nada.
 */
export function combineSummaries(draft: string, stored: string): string {
    const draftKeys = new Set(
        normalizedLines(draft)
            .map(key)
            .filter((line) => line !== ''),
    );
    const missing = normalizedLines(stored).filter(
        (line) => key(line) !== '' && !draftKeys.has(key(line)),
    );

    if (missing.length === 0) {
        return draft;
    }

    const base = draft.replace(/\s+$/, '');

    return base === '' ? missing.join('\n') : `${base}\n${missing.join('\n')}`;
}
