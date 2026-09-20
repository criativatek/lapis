/**
 * CAMADA DE APRESENTAÇÃO DAS ESTRATÉGIAS E MEDIDAS.
 *
 * Existe por uma razão só: a página de Estratégias e Medidas precisa de
 * agrupar, colorir e rotular o catálogo, e NADA disso pode ser decidido
 * dentro de um componente Vue. O enquadramento legal é domínio — vive no
 * catálogo do servidor (`InterventionType::catalogue()`), não no template.
 *
 * O QUE ESTE MÓDULO FAZ: deriva, do que o servidor JÁ envia hoje, o mínimo
 * que a UI precisa para ter hierarquia — uma família, um nível, um tom e um
 * ícone. Deriva, nunca inventa: quando a informação não existe, devolve
 * `null` e o ecrã omite o elemento em vez de mostrar uma categoria falsa.
 *
 * O QUE ESTE MÓDULO NÃO FAZ: não classifica medidas, não decide o que é
 * universal ou seletivo, não traduz legislação e não guarda rótulos legais.
 * Cada rótulo mostrado vem do catálogo; o que está aqui em português são
 * apenas nomes de agrupamentos visuais, não termos jurídicos.
 *
 * ------------------------------------------------------------------
 * COMPATIBILIDADE FUTURA (a fundação do catálogo, noutra frente)
 * ------------------------------------------------------------------
 * Quando o catálogo passar a enviar metadados próprios, NÃO É PRECISO
 * reescrever um único componente: basta o servidor acrescentar o campo
 * `presentation` a cada entrada de `types`, com a forma de
 * `PresentationMetadata` abaixo, e `presentType()` passa a preferi-lo à
 * derivação. Os componentes consomem sempre `PresentedType` e não sabem de
 * onde veio cada campo.
 *
 * Contrato esperado, entrada a entrada de `types[]`:
 *
 *     presentation?: {
 *         family?: MeasureFamily       // a família a que pertence
 *         legal_level?: string          // universal | selective | additional
 *         legal_level_label?: string    // o rótulo do nível, tal como o catálogo o escreve
 *         legal_framework?: string      // o diploma, quando existir
 *         article?: string              // o artigo, quando existir
 *         status?: string               // vigente / revogada / …
 *         display_label?: string        // o rótulo a mostrar, se diferir de `label`
 *     }
 *
 * Todos opcionais, todos ignorados enquanto não chegarem. Nenhum destes
 * campos é inventado aqui, e nenhum componente os lê directamente.
 */

import type { QualitativeTone } from '@/lib/qualitativeTone';
import { qualitativeToneClasses } from '@/lib/qualitativeTone';

/** O modo do mapeamento legal, tal como o catálogo do servidor já o envia. */
export type LegalMappingMode = 'direct' | 'contextual' | 'evaluation_only';

export type LegalMapping = {
    mode: LegalMappingMode;
    level: string | null;
    level_label: string | null;
    measure: string | null;
    measure_label: string | null;
    evaluation_adaptation: string | null;
    evaluation_adaptation_label: string | null;
};

/** Metadados que o catálogo poderá vir a enviar. Hoje nunca chegam. */
export type PresentationMetadata = {
    family?: MeasureFamily;
    legal_level?: string | null;
    legal_level_label?: string | null;
    legal_framework?: string | null;
    article?: string | null;
    status?: string | null;
    display_label?: string | null;
};

export type CatalogueType = {
    value: string;
    label: string;
    context: string;
    context_label: string;
    requires_description: boolean;
    /**
     * A LEITURA DO ENQUADRAMENTO EM VIGOR, não uma propriedade do item.
     * O mesmo ato é medida legal em Portugal e ensino corrente noutro sítio;
     * sem enquadramento nenhum, nada é medida. Quem decide é o servidor.
     */
    family?: string;
    family_label?: string;
    /** Só uma medida legal pode ter nível. Uma adaptação à avaliação nunca tem. */
    may_carry_measure_level?: boolean;
    legal_mapping: LegalMapping | null;
    /** O contrato canónico. Ver o cabeçalho deste ficheiro. */
    presentation?: PresentationMetadata;
};

/**
 * AS QUATRO FAMÍLIAS — um eixo de NATUREZA, distinto do eixo pedagógico.
 *
 * `context_label` («Aprendizagem», «Avaliação», «Métodos de estudo e
 * autonomia») continua a ser o eixo pedagógico e continua a agrupar dentro
 * de cada família. São coisas diferentes e a página mostra as duas: uma
 * medida pode ser da família «suporte» e da categoria pedagógica
 * «Aprendizagem» ao mesmo tempo.
 *
 * `resource_support` está declarada mas NUNCA é devolvida por
 * `familyFor()`: nada no catálogo actual distingue um apoio/recurso de uma
 * estratégia, e adivinhar seria inventar uma classificação. Fica no tipo
 * para que os componentes já a saibam desenhar no dia em que o catálogo a
 * enviar em `presentation.family`.
 */
export type MeasureFamily = 'support_measure' | 'pedagogical_strategy' | 'evaluation_adaptation' | 'resource_support';

export const MEASURE_FAMILY_ORDER: MeasureFamily[] = [
    'support_measure',
    'pedagogical_strategy',
    'evaluation_adaptation',
    'resource_support',
];

const FAMILY_LABELS: Record<MeasureFamily, string> = {
    support_measure: 'Medidas de suporte',
    pedagogical_strategy: 'Estratégias pedagógicas',
    evaluation_adaptation: 'Adaptações à avaliação',
    resource_support: 'Apoios e recursos',
};

/**
 * Nome do ícone, não o componente. Quem desenha importa-o; assim este módulo
 * mantém-se sem dependências de Vue e continua a ser testável como função.
 */
export type FamilyIcon = 'HeartHandshake' | 'GraduationCap' | 'ClipboardCheck' | 'LifeBuoy';

const FAMILY_ICONS: Record<MeasureFamily, FamilyIcon> = {
    support_measure: 'HeartHandshake',
    pedagogical_strategy: 'GraduationCap',
    evaluation_adaptation: 'ClipboardCheck',
    resource_support: 'LifeBuoy',
};

/**
 * TOM POR NÍVEL LEGAL, e só em segundo lugar por família.
 *
 * Reutiliza a paleta da casa (`qualitativeToneClasses`) — nenhuma cor nova
 * entra na aplicação por causa deste ecrã. O tom é SEMPRE reforço: todos os
 * sítios que o usam mostram também o rótulo por extenso e um ícone, nunca
 * uma pastilha colorida sozinha.
 *
 * Mapeado pelo VALOR do nível, nunca pelo rótulo: os rótulos são pt-PT e
 * mudam, os valores são o contrato do enum.
 */
const LEVEL_TONES: Record<string, QualitativeTone> = {
    universal: 'blue',
    selective: 'amber',
    additional: 'violet',
};

const FAMILY_FALLBACK_TONES: Record<MeasureFamily, QualitativeTone> = {
    support_measure: 'neutral',
    pedagogical_strategy: 'neutral',
    evaluation_adaptation: 'green',
    resource_support: 'neutral',
};

/**
 * Se o servidor já classificou este item, quem manda é ele.
 *
 * `presentation.family` e `family` são o mesmo valor por dois caminhos — o
 * contrato desta camada e o campo plano que o catálogo também envia. Basta
 * um deles para não haver nada a derivar aqui.
 */
function canonicalFamily(type: CatalogueType): MeasureFamily | null {
    const declared = type.presentation?.family ?? type.family;

    return declared !== undefined && MEASURE_FAMILY_ORDER.includes(declared as MeasureFamily)
        ? (declared as MeasureFamily)
        : null;
}

/**
 * A família de um tipo do catálogo.
 *
 * O SERVIDOR É A FONTE. A família é a leitura que o enquadramento em vigor
 * faz do item, e essa leitura é domínio: vive em `CatalogueFamily` e chega
 * pronta no payload.
 *
 * A derivação abaixo é ESTRITAMENTE um fallback de compatibilidade, para
 * um payload antigo que ainda não traga a família. Não é uma segunda fonte
 * de verdade: nunca corre quando o servidor se pronunciou, nem sequer para
 * a corrigir.
 */
export function familyFor(type: CatalogueType): MeasureFamily {
    const canonical = canonicalFamily(type);

    if (canonical !== null) {
        return canonical;
    }

    if (type.legal_mapping === null) {
        return 'pedagogical_strategy';
    }

    if (type.legal_mapping.mode === 'evaluation_only') {
        return 'evaluation_adaptation';
    }

    return 'support_measure';
}

export function familyLabel(family: MeasureFamily): string {
    return FAMILY_LABELS[family];
}

export function familyIcon(family: MeasureFamily): FamilyIcon {
    return FAMILY_ICONS[family];
}

/** O tom de uma família quando não há nível legal que o determine. */
export function toneClassesFor(family: MeasureFamily, level: string | null): string {
    if (level !== null && LEVEL_TONES[level]) {
        return qualitativeToneClasses[LEVEL_TONES[level]];
    }

    return qualitativeToneClasses[FAMILY_FALLBACK_TONES[family]];
}

/**
 * Um tipo do catálogo pronto a desenhar.
 *
 * `levelLabel` é SEMPRE o que o catálogo escreveu — nunca uma tradução
 * feita aqui. Quando o catálogo não dá nível, fica `null` e o ecrã não
 * mostra pastilha de nível nenhuma (§4 do pedido: não inventar).
 */
export type PresentedType = {
    value: string;
    /** O rótulo a mostrar. Vem do catálogo, nunca está escrito no Vue. */
    label: string;
    family: MeasureFamily;
    familyLabel: string;
    familyIcon: FamilyIcon;
    /** Categoria pedagógica — o outro eixo. Continua a existir e a agrupar. */
    contextLabel: string;
    level: string | null;
    levelLabel: string | null;
    /** Quando o nível é apenas uma sugestão contextual que o professor ainda não confirmou. */
    isSuggestedLevel: boolean;
    toneClasses: string;
    requiresDescription: boolean;
};

export function presentType(type: CatalogueType): PresentedType {
    const family = familyFor(type);

    /**
     * NÍVEL: CANÓNICO OU NADA.
     *
     * Quando o servidor manda `presentation`, é ele que responde pelo nível
     * — INCLUINDO quando responde `null`. Um `null` canónico é uma resposta
     * («esta família não tem nível»), não uma ausência a preencher com o
     * `legal_mapping`. Cair para trás aqui devolveria a uma adaptação à
     * avaliação um nível que a lei não lhe dá.
     *
     * E mesmo no caminho legacy, um item que não pode ter nível não o tem.
     */
    const canonical = type.presentation;
    const mayCarryLevel = type.may_carry_measure_level ?? family === 'support_measure';

    const level = canonical
        ? (canonical.legal_level ?? null)
        : mayCarryLevel
          ? (type.legal_mapping?.level ?? null)
          : null;

    const levelLabel = canonical
        ? (canonical.legal_level_label ?? null)
        : mayCarryLevel
          ? (type.legal_mapping?.level_label ?? null)
          : null;

    return {
        value: type.value,
        label: type.presentation?.display_label ?? type.label,
        family,
        // O rótulo que o servidor escreveu para a família, quando o escreveu.
        // O nome local é só o cabeçalho do agrupamento visual, para um
        // payload que ainda não traga o dele.
        familyLabel: type.family_label ?? FAMILY_LABELS[family],
        familyIcon: FAMILY_ICONS[family],
        contextLabel: type.context_label,
        level,
        levelLabel,
        isSuggestedLevel: type.legal_mapping?.mode === 'contextual',
        toneClasses: toneClassesFor(family, level),
        requiresDescription: type.requires_description,
    };
}

export type PresentedFamilyGroup = {
    family: MeasureFamily;
    label: string;
    icon: FamilyIcon;
    /** Sub-agrupamento pelo eixo pedagógico, preservado tal como era. */
    categories: { label: string; types: PresentedType[] }[];
    count: number;
};

/**
 * O catálogo inteiro, agrupado por família e, dentro de cada uma, pela
 * categoria pedagógica que a página já tinha. Famílias vazias não aparecem —
 * é o que mantém «Apoios e recursos» fora do ecrã enquanto nada o alimentar.
 */
export function groupByFamily(types: CatalogueType[]): PresentedFamilyGroup[] {
    const groups = new Map<MeasureFamily, PresentedFamilyGroup>();

    for (const type of types) {
        const presented = presentType(type);

        let group = groups.get(presented.family);

        if (!group) {
            group = {
                family: presented.family,
                label: presented.familyLabel,
                icon: presented.familyIcon,
                categories: [],
                count: 0,
            };
            groups.set(presented.family, group);
        }

        let category = group.categories.find((candidate) => candidate.label === presented.contextLabel);

        if (!category) {
            category = { label: presented.contextLabel, types: [] };
            group.categories.push(category);
        }

        category.types.push(presented);
        group.count += 1;
    }

    return MEASURE_FAMILY_ORDER.map((family) => groups.get(family)).filter(
        (group): group is PresentedFamilyGroup => group !== undefined,
    );
}

/**
 * Filtra o catálogo por texto, sem pedido nenhum ao servidor: os tipos já
 * vieram todos no payload e a pesquisa é sobre esse array.
 *
 * Compara sem acentos e sem maiúsculas — quem escreve «adaptacao» à pressa
 * encontra «Adaptação». Procura no rótulo, na família e na categoria
 * pedagógica, que é onde um professor pensaria em procurar.
 */
export function matchesSearch(type: PresentedType, needle: string): boolean {
    const trimmed = needle.trim();

    if (trimmed === '') {
        return true;
    }

    const haystack = fold(`${type.label} ${type.familyLabel} ${type.contextLabel} ${type.levelLabel ?? ''}`);

    return fold(trimmed)
        .split(/\s+/)
        .every((word) => haystack.includes(word));
}

function fold(value: string): string {
    return value
        .toLocaleLowerCase('pt-PT')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '');
}

/** Uma intervenção já registada, vista pelo resumo. Só os campos que lê. */
export type SummarisableIntervention = {
    is_closed: boolean;
    review_on: string | null;
    needs_review: boolean;
    legal_framing: { level: string | null; level_label: string | null } | null;
    support_measures: { level: string; level_label: string }[];
};

export type InterventionSummary = {
    /** Quantas estão em curso ou planeadas. Fechadas não contam como «ativas». */
    activeCount: number;
    /**
     * Contagem por nível legal, e SÓ dos níveis que aparecem mesmo nos
     * dados. Um nível que nenhuma intervenção tem não é listado a zero.
     */
    levels: { level: string; label: string; count: number; toneClasses: string }[];
    /** A data de revisão mais próxima que ainda não passou. Null se não houver. */
    nextReviewOn: string | null;
    pendingReviewCount: number;
};

/**
 * O bloco de resumo do topo, calculado sobre as intervenções que a página
 * já recebeu — nenhum pedido novo, nenhum N+1.
 *
 * Conta uma intervenção UMA VEZ por nível distinto que ela mobiliza: uma
 * intervenção com duas medidas seletivas conta uma vez para «Seletiva», não
 * duas, porque o que se está a contar é o que está mobilizado e não quantas
 * linhas existem na tabela.
 *
 * Se nenhuma intervenção tiver nível, `levels` vem vazio e o resumo esconde
 * essa linha inteira em vez de mostrar três zeros.
 */
export function summarise(
    interventions: SummarisableIntervention[],
    today: string,
    /**
     * A ordem por que o enquadramento em vigor nomeia os seus níveis, tal
     * como chega em `supportMeasureLevels`. A ordenação é domínio: é o
     * diploma que diz que universal vem antes de seletiva, não este ficheiro.
     * Sem ela, mantém-se a ordem por que os níveis aparecem nos dados.
     */
    levelOrder: string[] = [],
): InterventionSummary {
    const counts = new Map<string, { label: string; count: number }>();
    let activeCount = 0;
    let pendingReviewCount = 0;
    let nextReviewOn: string | null = null;

    for (const intervention of interventions) {
        if (!intervention.is_closed) {
            activeCount += 1;
        }

        if (intervention.needs_review) {
            pendingReviewCount += 1;
        }

        if (intervention.review_on !== null && intervention.review_on >= today) {
            if (nextReviewOn === null || intervention.review_on < nextReviewOn) {
                nextReviewOn = intervention.review_on;
            }
        }

        const levels = new Map<string, string>();

        for (const measure of intervention.support_measures) {
            levels.set(measure.level, measure.level_label);
        }

        if (intervention.legal_framing?.level && intervention.legal_framing.level_label) {
            levels.set(intervention.legal_framing.level, intervention.legal_framing.level_label);
        }

        for (const [level, label] of levels) {
            const entry = counts.get(level);

            if (entry) {
                entry.count += 1;
            } else {
                counts.set(level, { label, count: 1 });
            }
        }
    }

    // A ordem é a do enquadramento, não uma lista escrita aqui. Um nível que
    // ele não nomeie vai para o fim, em vez de desaparecer do resumo.
    const levels = [...counts.entries()]
        .sort(([a], [b]) => {
            const rankA = levelOrder.indexOf(a);
            const rankB = levelOrder.indexOf(b);

            return (rankA === -1 ? levelOrder.length : rankA) - (rankB === -1 ? levelOrder.length : rankB);
        })
        .map(([level, entry]) => ({
            level,
            label: entry.label,
            count: entry.count,
            toneClasses: toneClassesFor('support_measure', level),
        }));

    return { activeCount, levels, nextReviewOn, pendingReviewCount };
}
