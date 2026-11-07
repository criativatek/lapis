/**
 * Siglas para o papel: uma regra só, igual à de `App\Support\Documents\Abbreviations`
 * (PHP). Os casos de teste são os mesmos dos dois lados; quem mudar um, muda o outro.
 */

/** Palavras que não contam para uma sigla. */
const PARTICLES = new Set([
    'de',
    'da',
    'do',
    'das',
    'dos',
    'e',
    'a',
    'o',
    'as',
    'os',
    'em',
    'no',
    'na',
    'nos',
    'nas',
    'com',
    'para',
    'por',
]);

function words(text: string): string[] {
    return text
        .trim()
        .split(/[\s\-–—/]+/)
        .map((word) => word.replace(/[^\p{L}\p{N}]/gu, ''))
        .filter((word) => word !== '' && !PARTICLES.has(word.toLocaleLowerCase('pt-PT')));
}

function capitalize(word: string): string {
    return word.charAt(0).toLocaleUpperCase('pt-PT') + word.slice(1);
}

/** «Q12» → «12», «Item 3» → «3», «2.1» → «2.1», «A» → «A». */
export function questionNumber(code: string): string {
    const match = /^(?:q|item|pergunta|p)\s*[.\-]?\s*(\d.*)$/iu.exec(code.trim());

    return match ? match[1].trim() : code.trim();
}

/** «GRUPO V - ESCRITA» → «G.V»; «Expressão escrita» → «EE»; vazio → «». */
export function groupShort(label: string | null | undefined): string {
    if (!label || label.trim() === '') {
        return '';
    }

    const numeral = /\b([IVXLC]+|\d+)\b/iu.exec(label);

    if (numeral) {
        return `G.${numeral[1].toLocaleUpperCase('pt-PT')}`;
    }

    return words(label)
        .slice(0, 4)
        .map((word) => word.charAt(0).toLocaleUpperCase('pt-PT'))
        .join('');
}

/**
 * «Oralidade» → «Oral.», «Educação Literária» → «Edu. Lit.». Com `taken`, acrescenta
 * letras à primeira palavra até não colidir.
 */
export function domainShort(name: string, taken: string[] = []): string {
    const parts = words(name);

    if (parts.length === 0) {
        return '';
    }

    const piece = (word: string, length: number): string =>
        word.length <= length ? capitalize(word) : `${capitalize(word.slice(0, length))}.`;

    const build = (firstLength: number): string => {
        if (parts.length === 1) {
            return piece(parts[0], firstLength);
        }

        return parts.map((word, index) => piece(word, index === 0 ? firstLength : 3)).join(' ');
    };

    let length = parts.length === 1 ? 4 : 3;
    let candidate = build(length);

    while (taken.includes(candidate) && length < parts[0].length) {
        length++;
        candidate = build(length);
    }

    return candidate;
}

/** «Muito Bom» → «MB», «Insuficiente» → «I». */
export function mentionShort(label: string): string {
    return words(label)
        .map((word) => word.charAt(0).toLocaleUpperCase('pt-PT'))
        .join('');
}

/**
 * A sigla de cada menção da escala — ou, se duas colidirem, o CÓDIGO de todas,
 * para nunca haver duas menções com a mesma letra no papel.
 */
export function uniqueMentionShorts(bands: { code: string; label: string }[]): Map<string, string> {
    const shorts = bands.map((band) => mentionShort(band.label));
    const collides = new Set(shorts).size !== shorts.length;

    return new Map(bands.map((band, index) => [band.label, collides ? band.code : shorts[index]]));
}

/** Colunas-resumo com sigla fixa, pela sua chave. */
export const SUMMARY_SHORT: Record<string, string> = {
    raw: 'Bruta',
    result: 'Res.',
    level: 'Nív.',
    weighted_average: 'Média',
    assigned_level: 'Nível',
};
