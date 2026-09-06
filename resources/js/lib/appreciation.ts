import { qualitativeToneClasses, qualitativeToneFor } from '@/lib/qualitativeTone';
import type {
    EvaluationSheetClassification,
    EvaluationSheetOverall,
    EvaluationSheetStudentDomain,
} from '@/types';

/**
 * COMO SE LÊ UMA MENÇÃO — o único sítio onde essa pergunta é respondida.
 *
 * Uma menção de escala é sempre um PAR: o código («4») e a menção qualitativa
 * («Bom»). Que metade se escreve na célula depende de uma escolha do professor,
 * não da natureza da coisa:
 *
 *  - com os quantitativos à vista, a pauta fala a língua dos números — «4» é o
 *    que um documento de 2.º/3.º ciclo carrega, e a menção acompanha-o;
 *  - com os quantitativos desligados, o professor pediu uma pauta sem números.
 *    Um «4» sozinho continua a ser um número, e continuar a mostrá-lo seria
 *    ignorar o que ele pediu — nessa vista a apreciação é «Bom».
 *
 * A OUTRA METADE NUNCA DESAPARECE. Seja qual for a vista, a frase completa
 * chega a quem lê pelo `title` e pelo texto acessível — «4 — Bom» num caso,
 * «Bom — código 4» no outro. Nada de essencial fica dependente do rato (§14).
 *
 * NADA AQUI É INVENTADO. Não há tabela de conversão de códigos em menções neste
 * ficheiro: os dois valores vêm da escala configurada e viajam no modelo de
 * leitura desde o servidor. Uma pauta guardada antes de o código viajar traz só
 * a menção, e é ela que se lê — é isso que mantém o histórico legível.
 */

export type LevelReference = {
    code?: string | null;
    label?: string | null;
};

/** Um nível da escala configurada, o suficiente para o colocar e para o pintar. */
export type ToneableBand = { code: string; label: string; sequence: number; is_negative: boolean };

/** De quem é o juízo que a célula mostra. */
export type AppreciationOrigin = 'decided' | 'proposed' | 'none';

export type Appreciation = {
    /** O que se escreve na célula. «—» quando não há nada a escrever. */
    text: string;
    origin: AppreciationOrigin;
    /**
     * A frase inteira: de quem é o juízo e qual é, com as duas metades da
     * menção. Vai para o `title` E para o texto acessível.
     */
    description: string;
    /** A menção sozinha, sem dizer de quem é — para onde a origem já é óbvia. */
    detail: string | null;
    /**
     * O nível QUE ESTÁ A VALER, com as duas metades — para se poder pintar sem
     * ter de adivinhar qual delas ficou na célula. Null quando não há nível
     * nenhum a valer, e nesse caso não há cor nenhuma a dar.
     */
    level: LevelReference | null;
};

/** O que se escreve, conforme a vista. */
export function levelText(level: LevelReference | null, showQuantitative: boolean): string | null {
    if (level === null) {
        return null;
    }

    const code = level.code ?? null;
    const label = level.label ?? null;

    return (showQuantitative ? (code ?? label) : (label ?? code)) ?? null;
}

/**
 * A menção por inteiro, na ordem da vista: «4 — Bom» com os quantitativos à
 * vista, «Bom — código 4» sem eles. A metade que não está na célula vem sempre
 * a seguir, para que a informação não dependa de qual das vistas está ligada.
 */
export function levelDetail(level: LevelReference | null, showQuantitative: boolean): string | null {
    if (level === null) {
        return null;
    }

    const code = level.code ?? null;
    const label = level.label ?? null;

    if (code === null && label === null) {
        return null;
    }

    if (code === null) {
        return label;
    }

    if (label === null) {
        return code;
    }

    return showQuantitative ? `${code} — ${label}` : `${label} — código ${code}`;
}

const NO_APPRECIATION = 'Sem apreciação — nenhum elemento avaliado a produz.';

/**
 * A apreciação de UM DOMÍNIO: a decisão do professor quando existe, senão a
 * proposta do Lapispro.
 *
 * AS DUAS COISAS CONTINUAM A EXISTIR. Mostrar a decisão não apaga a proposta —
 * a frase de uma célula decidida diz o que o Lapispro tinha proposto, porque é
 * essa a informação que desapareceria da vista e é precisamente a que explica
 * por que motivo o professor interveio.
 */
export function domainAppreciation(
    domain: EvaluationSheetStudentDomain | null | undefined,
    showQuantitative: boolean,
): Appreciation {
    if (!domain) {
        return { text: '—', origin: 'none', description: NO_APPRECIATION, detail: null, level: null };
    }

    const proposed: LevelReference = {
        code: domain.scale_level_code,
        label: domain.scale_level_label,
    };
    const proposedDetail = levelDetail(proposed, showQuantitative);

    const decidedText = levelText(
        { code: domain.decided_scale_level_code, label: domain.decided_scale_level_label },
        showQuantitative,
    );

    if (decidedText !== null) {
        const decided: LevelReference = {
            code: domain.decided_scale_level_code,
            label: domain.decided_scale_level_label,
        };
        const decidedDetail = levelDetail(decided, showQuantitative);

        return {
            level: decided,
            text: decidedText,
            origin: 'decided',
            description:
                proposedDetail === null
                    ? `Decisão do professor: ${decidedDetail}.`
                    : `Decisão do professor: ${decidedDetail}. Proposta do Lapispro: ${proposedDetail}.`,
            detail: decidedDetail,
        };
    }

    const proposedText = levelText(proposed, showQuantitative);

    if (proposedText === null) {
        return { text: '—', origin: 'none', description: NO_APPRECIATION, detail: null, level: null };
    }

    return {
        level: proposed,
        text: proposedText,
        origin: 'proposed',
        description: `Proposta do Lapispro, ainda não decidida pelo professor: ${proposedDetail}.`,
        detail: proposedDetail,
    };
}

/**
 * A apreciação GLOBAL calculada — a banda em que o resultado do período caiu.
 *
 * Não tem decisão do professor: essa vive na coluna «Nível atribuído», que é
 * outra coluna e outra pergunta. Aqui há sempre e só a leitura do Lapispro.
 */
export function overallAppreciation(
    overall: EvaluationSheetOverall,
    showQuantitative: boolean,
): Appreciation {
    const level: LevelReference = {
        code: overall.scale_level_code,
        label: overall.scale_level_label,
    };
    const text = levelText(level, showQuantitative);

    if (text === null) {
        return { text: '—', origin: 'none', description: NO_APPRECIATION, detail: null, level: null };
    }

    const detail = levelDetail(level, showQuantitative);

    return {
        level,
        text,
        origin: 'proposed',
        description: `Leitura do Lapispro sobre o resultado global: ${detail}.`,
        detail,
    };
}

/**
 * O «Nível atribuído»: a decisão do professor quando existe, senão a proposta
 * do Lapispro — nunca com a mesma força visual, para que uma proposta não se
 * leia como uma decisão já tomada (§6).
 *
 * Numa escala de intervalo não há menção nenhuma a nomear: o valor escrito É a
 * resposta inteira, e é ele que passa. Aí a vista não muda coisa alguma — um
 * 16 é um 16 com ou sem quantitativos, porque não é a representação de uma
 * menção, é a classificação.
 */
export function assignedLevel(
    classification: EvaluationSheetClassification,
    showQuantitative: boolean,
): Appreciation {
    if (classification === null) {
        return { text: '—', origin: 'none', description: 'Sem classificação registada.', detail: null, level: null };
    }

    const decided: LevelReference = {
        code: classification.final_scale_level_code,
        label: classification.final_scale_level_label,
    };
    const decidedText = levelText(decided, showQuantitative) ?? classification.final_value;

    if (decidedText !== null) {
        const detail = levelDetail(decided, showQuantitative) ?? classification.final_value;

        return {
            level: decided,
            text: decidedText,
            origin: 'decided',
            description: `Decisão do professor: ${detail}.`,
            detail,
        };
    }

    const proposed: LevelReference = {
        code: classification.proposed_scale_level_code,
        label: classification.proposed_scale_level_label,
    };
    const proposedText = levelText(proposed, showQuantitative) ?? classification.proposed_value;

    if (proposedText !== null) {
        const detail = levelDetail(proposed, showQuantitative) ?? classification.proposed_value;

        return {
            level: proposed,
            text: proposedText,
            origin: 'proposed',
            description: `Proposta do Lapispro, ainda não decidida pelo professor: ${detail}.`,
            detail,
        };
    }

    return { text: '—', origin: 'none', description: 'Sem classificação registada.', detail: null, level: null };
}

/**
 * A COR DE UMA APRECIAÇÃO — pela POSIÇÃO do nível na escala, nunca pelo número
 * que ele calha ter (§24).
 *
 * O «5» de uma escala de 1 a 5 é verde por ser o nível mais alto, e não por ser
 * cinco: uma escala «Insuficiente/Suficiente/Bom», sem número nenhum, pinta-se
 * exatamente da mesma maneira, e uma escala invertida — que nada proíbe —
 * pinta-se ao contrário sem que uma linha de código saiba que existe. Quem faz
 * essa leitura é `qualitativeToneFor`, que já existia e que Resultados e
 * Avaliações usam; aqui só se encontra o nível de que se está a falar.
 *
 * PELO CÓDIGO PRIMEIRO, PELO RÓTULO DEPOIS — a mesma ordem de preferência que o
 * resto do modelo de leitura já usa, e a que mantém legível uma pauta guardada
 * quando só um dos dois viajou.
 *
 * SEM BANDAS, SEM COR, E É ASSIM QUE TEM DE SER. Uma pauta guardada não recebe
 * bandas nenhumas de propósito: abrir uma fotografia não pode ir buscar nada ao
 * presente, e a escala pode ter mudado desde então (§15). Fica sem cor, que é
 * honesto, em vez de ficar com uma cor que ninguém escolheu.
 */
export function appreciationTone(
    appreciation: Appreciation,
    bands: ToneableBand[],
): string {
    if (appreciation.level === null || bands.length === 0) {
        return '';
    }

    const code = appreciation.level.code ?? null;
    const label = appreciation.level.label ?? null;

    const band =
        (code === null ? undefined : bands.find((candidate) => candidate.code === code)) ??
        (label === null ? undefined : bands.find((candidate) => candidate.label === label));

    if (band === undefined) {
        return '';
    }

    return qualitativeToneClasses[qualitativeToneFor(band, bands)];
}
