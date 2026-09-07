/**
 * O VOCABULÁRIO DO QUADRO SÍNTESE, num sítio só.
 *
 * A tabela do ecrã, a legenda por baixo dela e os testes leem daqui — duas
 * cópias destas regras seriam a maneira mais rápida de a tabela dizer uma coisa
 * e a legenda outra sobre a mesma célula.
 */

import { qualitativeToneClasses, qualitativeToneFor } from '@/lib/qualitativeTone';

/**
 * Um nível da escala, tal como a apreciação vigente o carrega.
 *
 * O TIPO PARTE-SE EM DOIS de propósito. `Appreciation` admite `null` porque uma
 * célula sem apreciação é o caso normal e quem lê tem de o tratar; mas a FORMA
 * de uma apreciação é uma coisa por si, e sem um nome próprio ninguém consegue
 * escrever `Appreciation['origin']` sem esbarrar no `null`.
 */
export type AppreciationValue = {
    origin: 'decided' | 'proposed' | 'none';
    code: string | null;
    label: string | null;
    text: string | null;
    sequence: number | null;
    is_negative: boolean | null;
};

export type Appreciation = AppreciationValue | null;

export type SynopticTrend = {
    direction: 'up' | 'flat' | 'down';
    label: string;
    from: { code: string | null; label: string | null };
    to: { code: string | null; label: string | null };
    from_moment: string | null;
} | null;

export type ScaleBand = { code: string; label: string; sequence: number; is_negative: boolean };

/**
 * A COR DE UMA APRECIAÇÃO SAI DA POSIÇÃO DELA NA ESCALA (§24).
 *
 * Nunca do número. Numa escala de 1 a 5 o «5» calha ser verde porque é o nível
 * mais alto, não porque é o cinco: uma escala «Insuficiente/Suficiente/Bom» sem
 * números nenhuns pinta-se exatamente da mesma maneira, e uma escala invertida
 * — que nada proíbe — pinta-se ao contrário sem que uma linha de código saiba
 * que existe. O resolvedor que faz esta leitura já existia e é o mesmo que
 * Resultados e Avaliações usam.
 *
 * SEM POSIÇÃO, SEM COR. Uma fotografia guardada com uma escala que entretanto
 * mudou pode trazer um nível que hoje já não existe; nesse caso mostra-se o
 * texto guardado sem cor nenhuma, que é honesto, em vez de uma cor adivinhada.
 */
export function appreciationClasses(appreciation: Appreciation, bands: ScaleBand[]): string {
    if (appreciation === null || appreciation.sequence === null || appreciation.is_negative === null) {
        return 'text-muted-foreground';
    }

    return qualitativeToneClasses[
        qualitativeToneFor({ sequence: appreciation.sequence, is_negative: appreciation.is_negative }, bands)
    ];
}

/**
 * A FRASE INTEIRA de uma apreciação — quem a disse, e o quê.
 *
 * A COR NUNCA É A ÚNICA INFORMAÇÃO (§25). O que a célula mostra é o código ou o
 * rótulo; o que um leitor de ecrã e um `title` recebem é esta frase, que diz
 * também se está a valer a decisão do professor ou a proposta do Lapispro —
 * uma distinção que no ecrã se faz por tipografia e que, sem isto, não chegaria
 * a quem não vê o itálico.
 */
export function appreciationTitle(appreciation: Appreciation, subject?: string): string {
    if (appreciation === null || appreciation.text === null) {
        return subject === undefined ? 'Sem apreciação neste momento.' : `${subject}: sem apreciação neste momento.`;
    }

    const named = appreciation.label && appreciation.code ? `${appreciation.code} — ${appreciation.label}` : appreciation.text;
    // «VIGENTE», e não «ainda não alterada»: uma proposta por domínio que
    // ninguém mexeu não está à espera de nada — é a apreciação que vale.
    const who =
        appreciation.origin === 'decided'
            ? 'Decisão do professor'
            : 'Proposta do Lapispro, vigente';

    return subject === undefined ? `${who}: ${named}` : `${subject} · ${who}: ${named}`;
}

/**
 * ↑ · → · ↓ — e nunca só isso.
 *
 * A seta é um atalho para quem já sabe o que está a ver. A palavra («Evolução»,
 * «Manutenção», «Regressão») e o trajeto («Suficiente → Bom») vão no texto
 * acessível, porque uma seta sozinha não diz de onde para onde nem desde
 * quando (§27).
 */
export function trendGlyph(trend: SynopticTrend): string {
    if (trend === null) {
        return '';
    }

    return trend.direction === 'up' ? '↑' : trend.direction === 'down' ? '↓' : '→';
}

export function trendTone(trend: SynopticTrend): string {
    if (trend === null) {
        return 'text-muted-foreground';
    }

    // TENDÊNCIA, E NUNCA DESEMPENHO. Um aluno que subiu de Insuficiente para
    // Suficiente evoluiu e continua a precisar de apoio; outro que caiu de
    // Muito Bom para Bom regrediu e continua excelente. As duas tintas têm de
    // ser diferentes, e são: esta é discreta e a do nível é a do desempenho.
    return trend.direction === 'up'
        ? 'text-emerald-600 dark:text-emerald-400'
        : trend.direction === 'down'
          ? 'text-rose-600 dark:text-rose-400'
          : 'text-muted-foreground';
}

export function trendTitle(trend: SynopticTrend): string | undefined {
    if (trend === null) {
        return undefined;
    }

    const from = trend.from.label ?? trend.from.code;
    const to = trend.to.label ?? trend.to.code;
    const since = trend.from_moment === null ? 'desde o momento anterior' : `desde ${trend.from_moment}`;

    if (from === null || to === null) {
        return `${trend.label} ${since}.`;
    }

    return `${trend.label}: ${from} → ${to} ${since}.`;
}

/** «91,3 %» — e «—» para uma ausência, que nunca é um zero. */
export function percent(value: string | null | undefined): string {
    if (value === null || value === undefined) {
        return '—';
    }

    return `${Number(value).toFixed(1).replace('.', ',')} %`;
}

/**
 * O nome de um aluno reduzido ao primeiro e ao último — a forma como uma pessoa
 * chama outra numa reunião de conselho de turma.
 *
 * Um nome de um único elemento fica como está: encurtá-lo não teria o que
 * encurtar.
 */
export function shortName(name: string): string {
    const parts = name.trim().split(/\s+/u).filter((part) => part.length > 0);

    if (parts.length <= 1) {
        return name.trim();
    }

    return `${parts[0]} ${parts[parts.length - 1]}`;
}

// --------------------------------------------------------------- a forma toda
//
// O TIPO VIVE AQUI E NÃO NO COMPONENTE porque a página que o recebe do servidor
// e o componente que o desenha têm de concordar sobre ele. Declará-lo duas vezes
// é como as duas metades de um ecrã acabam a discordar sobre o que é uma célula.

export type SynopticLevel = { code: string; label: string; sequence: number; is_negative: boolean };

export type SynopticDomainCell = {
    domain_id: number;
    available: boolean;
    normalized_value: string | null;
    proposed: { code: string | null; label: string | null } | null;
    decided: { code: string | null; label: string | null } | null;
    current: Appreciation;
    trend: SynopticTrend;
    has_coverage_warning: boolean;
    self_assessment: { code: string; label: string } | null;
};

export type SynopticReadingRow = {
    moment_key: string;
    available: boolean;
    overall: {
        normalized_value: string | null;
        scale_value: string | null;
        has_coverage_warning: boolean;
        current: Appreciation;
    } | null;
    domains: SynopticDomainCell[];
    self_assessment: { code: string; label: string } | null;
    trend: SynopticTrend;
};

export type SynopticElementResult = {
    instrument_id: number;
    applicable: boolean;
    normalized_value: string | null;
    state_label: string;
    level: SynopticLevel | null;
};

export type SynopticContinuousUnit = {
    period_id: number;
    label: string;
    kind_label: string;
    weight_percent: string;
    normalized_value: string | null;
    counted: boolean;
    level: SynopticLevel | null;
};

export type SynopticStudent = {
    enrollment_id: number;
    enrollment_ulid: string | null;
    class_number: number | null;
    name: string;
    moments: SynopticReadingRow[];
    continuous: {
        units: SynopticContinuousUnit[];
        counted_units: number;
        normalized_value: string | null;
        proposal: { value: string | null; state: string; is_percentage: boolean };
        level: SynopticLevel | null;
        decision: { final: { code: string; label: string } | null; final_value: string | null } | null;
    } | null;
    elements: SynopticElementResult[];
};

export type SynopticMoment = {
    key: string;
    period_id: number;
    period_ulid: string;
    period_label: string;
    kind: 'interim' | 'final';
    label: string;
    moment_label: string;
    kind_label: string;
    is_formal: boolean;
    source: 'live' | 'snapshot' | 'none';
    snapshot: { ulid: string; moment_label: string; effective_at: string | null; kept_at: string } | null;
};

export type SynopticElement = {
    instrument_id: number;
    ulid: string;
    title: string;
    type: string | null;
    purpose_label: string;
    applied_on: string;
    academic_period_id: number;
    status_label: string;
    counts_toward_classification: boolean;
    weight: string | null;
    total_points: string | null;
    domains: { domain_id: number; name: string }[];
};

export type SynopticDomain = { domain_id: number; name: string; sequence: number; color: string; weight_percent: string };

export type Synopsis = {
    moments: SynopticMoment[];
    domains: SynopticDomain[];
    students: SynopticStudent[];
    continuous: {
        units: { period_id: number; label: string; kind_label: string; weight_percent: string }[];
        /**
         * Se os pesos das unidades foram DECLARADOS pela escola, ou se são a
         * igualdade que a avaliação contínua usa quando ninguém declarou
         * nenhum. O ecrã escreve frases diferentes num caso e no outro, e não
         * tem como distinguir os dois a partir dos números.
         */
        weights_declared: boolean;
    };
    elements: SynopticElement[];
    periods: { id: number; ulid: string; label: string; kind_label: string; sequence: number }[];
};
