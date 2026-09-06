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
        return { text: '—', origin: 'none', description: NO_APPRECIATION, detail: null };
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
        const decidedDetail = levelDetail(
            { code: domain.decided_scale_level_code, label: domain.decided_scale_level_label },
            showQuantitative,
        );

        return {
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
        return { text: '—', origin: 'none', description: NO_APPRECIATION, detail: null };
    }

    return {
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
        return { text: '—', origin: 'none', description: NO_APPRECIATION, detail: null };
    }

    const detail = levelDetail(level, showQuantitative);

    return {
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
        return { text: '—', origin: 'none', description: 'Sem classificação registada.', detail: null };
    }

    const decided: LevelReference = {
        code: classification.final_scale_level_code,
        label: classification.final_scale_level_label,
    };
    const decidedText = levelText(decided, showQuantitative) ?? classification.final_value;

    if (decidedText !== null) {
        const detail = levelDetail(decided, showQuantitative) ?? classification.final_value;

        return {
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
            text: proposedText,
            origin: 'proposed',
            description: `Proposta do Lapispro, ainda não decidida pelo professor: ${detail}.`,
            detail,
        };
    }

    return { text: '—', origin: 'none', description: 'Sem classificação registada.', detail: null };
}
