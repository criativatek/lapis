/**
 * Cor semântica de um resultado percentual: vermelho abaixo de 49,5 %, verde
 * acima da média da turma. Decide-se sempre sobre valores exatos (nunca
 * arredondados): 49,5 não é negativo, e um valor igual à média não é «acima».
 * A cor nunca é o único portador do significado — quem a usa acrescenta
 * `title` e um texto `sr-only` (ver resultToneTitle).
 */

export type ResultTone = 'negative' | 'above';

export const NEGATIVE_BELOW_PERCENT = 49.5;

/** Vermelho prevalece sobre verde; null quando não há valor ou não se destaca. */
export function resultTone(percentExact: number | null, classMeanExact: number | null): ResultTone | null {
    if (percentExact === null) {
        return null;
    }

    if (percentExact < NEGATIVE_BELOW_PERCENT) {
        return 'negative';
    }

    if (classMeanExact !== null && percentExact > classMeanExact) {
        return 'above';
    }

    return null;
}

/** Média dos valores não nulos (o zero conta; null = sem avaliação, excluído). Sem arredondar. */
export function meanOf(values: Array<number | null>): number | null {
    const present = values.filter((value): value is number => value !== null);

    if (present.length === 0) {
        return null;
    }

    return present.reduce((sum, value) => sum + value, 0) / present.length;
}

export const resultToneClasses: Record<ResultTone, string> = {
    negative: 'text-red-700 dark:text-red-400',
    above: 'text-green-700 dark:text-green-400',
};

export const resultToneTitle: Record<ResultTone, string> = {
    negative: 'Inferior a 49,5 %',
    above: 'Superior à média da turma',
};
