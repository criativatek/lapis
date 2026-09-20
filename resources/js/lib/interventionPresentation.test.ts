import { describe, expect, it } from 'vitest';
import type { CatalogueType, SummarisableIntervention } from './interventionPresentation';
import { familyFor, groupByFamily, matchesSearch, presentType, summarise, toneClassesFor } from './interventionPresentation';

function type(overrides: Partial<CatalogueType> = {}): CatalogueType {
    return {
        value: 'pedagogical_differentiation',
        label: 'Diferenciação pedagógica',
        context: 'learning',
        context_label: 'Aprendizagem',
        requires_description: false,
        legal_mapping: null,
        ...overrides,
    };
}

function intervention(overrides: Partial<SummarisableIntervention> = {}): SummarisableIntervention {
    return {
        is_closed: false,
        review_on: null,
        needs_review: false,
        legal_framing: null,
        support_measures: [],
        ...overrides,
    };
}

describe('familyFor', () => {
    it('reads a support measure from the mapping the catalogue already sends', () => {
        expect(
            familyFor(
                type({
                    legal_mapping: {
                        mode: 'direct',
                        level: 'universal',
                        level_label: 'Medida universal',
                        measure: 'pedagogical_differentiation',
                        measure_label: 'Diferenciação pedagógica',
                        evaluation_adaptation: null,
                        evaluation_adaptation_label: null,
                    },
                }),
            ),
        ).toBe('support_measure');
    });

    it('reads an assessment adaptation from the evaluation_only mode', () => {
        expect(
            familyFor(
                type({
                    legal_mapping: {
                        mode: 'evaluation_only',
                        level: null,
                        level_label: null,
                        measure: null,
                        measure_label: null,
                        evaluation_adaptation: 'extra_time',
                        evaluation_adaptation_label: 'Tempo suplementar',
                    },
                }),
            ),
        ).toBe('evaluation_adaptation');
    });

    it('treats a type with no mapping at all as an ordinary pedagogical strategy', () => {
        expect(familyFor(type({ legal_mapping: null }))).toBe('pedagogical_strategy');
    });

    /**
     * A GARANTIA CENTRAL DESTA CAMADA: nada é adivinhado. «Apoios e
     * recursos» só pode aparecer quando alguém o disser explicitamente.
     */
    it('never invents the apoios/recursos family out of the current catalogue', () => {
        const families = new Set(
            ['direct', 'contextual', 'evaluation_only'].map((mode) =>
                familyFor(
                    type({
                        legal_mapping: {
                            mode: mode as 'direct',
                            level: null,
                            level_label: null,
                            measure: null,
                            measure_label: null,
                            evaluation_adaptation: null,
                            evaluation_adaptation_label: null,
                        },
                    }),
                ),
            ),
        );

        families.add(familyFor(type({ legal_mapping: null })));

        expect(families.has('resource_support')).toBe(false);
    });
});

describe('presentType', () => {
    it('shows no level at all when the catalogue gives none, rather than a placeholder', () => {
        const presented = presentType(type({ legal_mapping: null }));

        expect(presented.level).toBeNull();
        expect(presented.levelLabel).toBeNull();
    });

    it('carries the level label the catalogue wrote, never one written here', () => {
        const presented = presentType(
            type({
                legal_mapping: {
                    mode: 'direct',
                    level: 'selective',
                    level_label: 'Medida seletiva',
                    measure: 'tutorial_support',
                    measure_label: 'Apoio tutorial',
                    evaluation_adaptation: null,
                    evaluation_adaptation_label: null,
                },
            }),
        );

        expect(presented.levelLabel).toBe('Medida seletiva');
        expect(presented.isSuggestedLevel).toBe(false);
    });

    it('marks a contextual mapping as merely suggested', () => {
        const presented = presentType(
            type({
                legal_mapping: {
                    mode: 'contextual',
                    level: 'universal',
                    level_label: 'Medida universal',
                    measure: 'curricular_accommodation',
                    measure_label: 'Acomodação curricular',
                    evaluation_adaptation: null,
                    evaluation_adaptation_label: null,
                },
            }),
        );

        expect(presented.isSuggestedLevel).toBe(true);
    });

    it('keeps the pedagogical category as a second axis, distinct from the family', () => {
        const presented = presentType(type({ context_label: 'Avaliação', legal_mapping: null }));

        expect(presented.family).toBe('pedagogical_strategy');
        expect(presented.contextLabel).toBe('Avaliação');
    });
});

/**
 * O CONTRATO COM A FUNDAÇÃO DO CATÁLOGO (a outra frente).
 *
 * Estes testes fixam que, no dia em que o servidor enviar `presentation`,
 * nenhum componente precisa de mudar: a derivação cede o lugar aos metadados.
 */
describe('presentType with catalogue metadata', () => {
    it('prefers the family the catalogue declares over the derived one', () => {
        const presented = presentType(type({ legal_mapping: null, presentation: { family: 'resource_support' } }));

        expect(presented.family).toBe('resource_support');
        expect(presented.familyLabel).toBe('Apoios e recursos');
    });

    it('prefers the catalogue display label and legal level over the derived ones', () => {
        const presented = presentType(
            type({
                label: 'Reforço das aprendizagens',
                legal_mapping: null,
                presentation: {
                    display_label: 'Antecipação e reforço das aprendizagens',
                    legal_level: 'selective',
                    legal_level_label: 'Medida seletiva',
                },
            }),
        );

        expect(presented.label).toBe('Antecipação e reforço das aprendizagens');
        expect(presented.level).toBe('selective');
        expect(presented.levelLabel).toBe('Medida seletiva');
    });
});

/**
 * A JUNÇÃO DAS DUAS FRENTES.
 *
 * O servidor passou a classificar o catálogo (`CatalogueFamily`, lido pelo
 * enquadramento em vigor) e a enviar `presentation` com a forma que esta
 * camada tinha declarado. Estes testes fixam a regra que daí resulta: o
 * canónico vence SEMPRE, e a derivação local é só compatibilidade com um
 * payload antigo.
 */
describe('canonical metadata versus the legacy fallback', () => {
    /** Um item que a derivação classificaria de outra maneira. */
    const contextualSupportMeasure = {
        legal_mapping: {
            mode: 'contextual' as const,
            level: 'universal',
            level_label: 'Medida universal',
            measure: 'curricular_accommodation',
            measure_label: 'Acomodação curricular',
            evaluation_adaptation: null,
            evaluation_adaptation_label: null,
        },
    };

    it('takes the family the server read, even where deriving would disagree', () => {
        const presented = presentType(
            type({ ...contextualSupportMeasure, family: 'pedagogical_strategy', may_carry_measure_level: false, presentation: { family: 'pedagogical_strategy', legal_level: null, legal_level_label: null } }),
        );

        expect(presented.family).toBe('pedagogical_strategy');
        expect(presented.level).toBeNull();
    });

    /**
     * O CASO QUE JUSTIFICA A REGRA: sem enquadramento nenhum, nada é medida
     * legal — e a página não pode reintroduzir um nível a partir do mapping.
     */
    it('states no level under a framework that names none, instead of falling back', () => {
        const presented = presentType(
            type({
                ...contextualSupportMeasure,
                family: 'pedagogical_strategy',
                may_carry_measure_level: false,
                presentation: { family: 'pedagogical_strategy', legal_level: null, legal_level_label: null, legal_framework: null, article: null, status: null, display_label: null },
            }),
        );

        expect(presented.levelLabel).toBeNull();
    });

    it('reads the flat family field when that is all the payload carries', () => {
        expect(familyFor(type({ ...contextualSupportMeasure, family: 'evaluation_adaptation' }))).toBe('evaluation_adaptation');
    });

    it('shows the family label the server wrote, not the local grouping name', () => {
        const presented = presentType(type({ family: 'support_measure', family_label: 'Medida legal', legal_mapping: null }));

        expect(presented.familyLabel).toBe('Medida legal');
    });

    it('falls back to the local grouping name only when the server sent none', () => {
        expect(presentType(type({ legal_mapping: null })).familyLabel).toBe('Estratégias pedagógicas');
    });

    it('shows the diploma designation when it differs from the catalogue label', () => {
        const presented = presentType(
            type({
                label: 'Reforço das aprendizagens',
                family: 'support_measure',
                may_carry_measure_level: true,
                presentation: { family: 'support_measure', legal_level: 'selective', legal_level_label: 'Medida seletiva', display_label: 'Antecipação e reforço das aprendizagens', article: 'artigo 9.º, n.º 4, alínea b)', status: 'in_force' },
            }),
        );

        expect(presented.label).toBe('Antecipação e reforço das aprendizagens');
        expect(presented.level).toBe('selective');
    });

    /**
     * §12.3: uma adaptação ao processo de avaliação nunca é medida e nunca
     * tem nível. O servidor já o garante com `may_carry_measure_level`; a
     * página não pode desfazê-lo.
     */
    it('never gives an assessment adaptation a level', () => {
        const presented = presentType(
            type({
                family: 'evaluation_adaptation',
                may_carry_measure_level: false,
                legal_mapping: { mode: 'evaluation_only', level: null, level_label: null, measure: null, measure_label: null, evaluation_adaptation: 'extra_time', evaluation_adaptation_label: 'Tempo suplementar' },
                presentation: { family: 'evaluation_adaptation', legal_level: null, legal_level_label: null },
            }),
        );

        expect(presented.family).toBe('evaluation_adaptation');
        expect(presented.level).toBeNull();
        expect(presented.levelLabel).toBeNull();
    });

    it('refuses a level on a legacy payload for an item that may not carry one', () => {
        // Sem `presentation`, mas com a bandeira plana: continua sem nível.
        const presented = presentType(type({ ...contextualSupportMeasure, family: 'evaluation_adaptation', may_carry_measure_level: false }));

        expect(presented.level).toBeNull();
    });

    it('still derives everything for a payload that carries no metadata at all', () => {
        const presented = presentType(type(contextualSupportMeasure));

        expect(presented.family).toBe('support_measure');
        expect(presented.level).toBe('universal');
        expect(presented.isSuggestedLevel).toBe(true);
    });

    /** A quarta família deixa de ser teórica assim que o servidor a enviar. */
    it('shows the apoio/recurso family as soon as the server reads one', () => {
        const groups = groupByFamily([
            type({ value: 'cri', label: 'Apoio de CRI', family: 'resource_support', family_label: 'Apoio ou recurso', may_carry_measure_level: false, legal_mapping: null }),
        ]);

        expect(groups.map((group) => group.family)).toEqual(['resource_support']);
        expect(groups[0].label).toBe('Apoio ou recurso');
    });
});

describe('toneClassesFor', () => {
    it('gives each legal level a tone of its own, from the house palette', () => {
        const universal = toneClassesFor('support_measure', 'universal');
        const selective = toneClassesFor('support_measure', 'selective');
        const additional = toneClassesFor('support_measure', 'additional');

        expect(new Set([universal, selective, additional]).size).toBe(3);
    });

    it('falls back to a neutral tone for a level nobody has toned yet, instead of failing', () => {
        expect(toneClassesFor('support_measure', 'something_new_from_a_future_catalogue')).toBe(
            toneClassesFor('support_measure', null),
        );
    });
});

describe('groupByFamily', () => {
    const catalogue = [
        type({ value: 'a', label: 'Diferenciação pedagógica', context_label: 'Aprendizagem', legal_mapping: { mode: 'direct', level: 'universal', level_label: 'Medida universal', measure: 'a', measure_label: 'A', evaluation_adaptation: null, evaluation_adaptation_label: null } }),
        type({ value: 'b', label: 'Apoio tutorial', context_label: 'Aprendizagem', legal_mapping: { mode: 'direct', level: 'selective', level_label: 'Medida seletiva', measure: 'b', measure_label: 'B', evaluation_adaptation: null, evaluation_adaptation_label: null } }),
        type({ value: 'c', label: 'Promoção da autonomia', context_label: 'Métodos de estudo e autonomia', legal_mapping: null }),
        type({ value: 'd', label: 'Tempo suplementar', context_label: 'Avaliação', legal_mapping: { mode: 'evaluation_only', level: null, level_label: null, measure: null, measure_label: null, evaluation_adaptation: 'extra_time', evaluation_adaptation_label: 'Tempo suplementar' } }),
    ];

    it('omits a family the catalogue has nothing for, instead of an empty heading', () => {
        const families = groupByFamily(catalogue).map((group) => group.family);

        expect(families).not.toContain('resource_support');
        expect(families).toEqual(['support_measure', 'pedagogical_strategy', 'evaluation_adaptation']);
    });

    it('keeps the pedagogical categories as sub-groups inside each family', () => {
        const support = groupByFamily(catalogue).find((group) => group.family === 'support_measure');

        expect(support?.categories.map((category) => category.label)).toEqual(['Aprendizagem']);
        expect(support?.count).toBe(2);
    });

    it('loses nothing: every catalogue entry lands in exactly one group', () => {
        const values = groupByFamily(catalogue).flatMap((group) => group.categories.flatMap((category) => category.types.map((type) => type.value)));

        expect(values.sort()).toEqual(['a', 'b', 'c', 'd']);
    });
});

describe('matchesSearch', () => {
    const presented = presentType(
        type({
            label: 'Adaptação curricular não significativa',
            context_label: 'Aprendizagem',
            legal_mapping: { mode: 'direct', level: 'selective', level_label: 'Medida seletiva', measure: 'x', measure_label: 'X', evaluation_adaptation: null, evaluation_adaptation_label: null },
        }),
    );

    it('matches everything when nothing was typed', () => {
        expect(matchesSearch(presented, '')).toBe(true);
        expect(matchesSearch(presented, '   ')).toBe(true);
    });

    it('ignores accents and case, the way a teacher types in a hurry', () => {
        expect(matchesSearch(presented, 'adaptacao')).toBe(true);
        expect(matchesSearch(presented, 'ADAPTAÇÃO')).toBe(true);
    });

    it('requires every word, so two words narrow instead of widening', () => {
        expect(matchesSearch(presented, 'adaptacao curricular')).toBe(true);
        expect(matchesSearch(presented, 'adaptacao piano')).toBe(false);
    });

    it('also finds a measure by its level and by its pedagogical category', () => {
        expect(matchesSearch(presented, 'seletiva')).toBe(true);
        expect(matchesSearch(presented, 'aprendizagem')).toBe(true);
    });
});

describe('summarise', () => {
    const today = '2026-09-20';
    /** Tal como `supportMeasureLevels` a envia para o ecrã. */
    const LEVEL_ORDER = ['universal', 'selective', 'additional'];

    it('says nothing about levels when no intervention has one', () => {
        const summary = summarise([intervention(), intervention()], today);

        expect(summary.levels).toEqual([]);
        expect(summary.activeCount).toBe(2);
    });

    it('counts only the open ones as active', () => {
        const summary = summarise([intervention(), intervention({ is_closed: true })], today);

        expect(summary.activeCount).toBe(1);
    });

    /**
     * Conta o que está MOBILIZADO, não quantas linhas existem: duas medidas
     * seletivas na mesma intervenção continuam a ser uma intervenção.
     */
    it('counts an intervention once per distinct level it mobilises', () => {
        const summary = summarise(
            [
                intervention({
                    support_measures: [
                        { level: 'selective', level_label: 'Medida seletiva' },
                        { level: 'selective', level_label: 'Medida seletiva' },
                        { level: 'universal', level_label: 'Medida universal' },
                    ],
                }),
            ],
            today,
            LEVEL_ORDER,
        );

        expect(summary.levels).toEqual([
            expect.objectContaining({ level: 'universal', count: 1 }),
            expect.objectContaining({ level: 'selective', count: 1 }),
        ]);
    });

    /**
     * A ORDEM É DO DIPLOMA, NÃO DESTE FICHEIRO. Chega em
     * `supportMeasureLevels`, pela ordem por que o enquadramento em vigor
     * nomeia os seus níveis.
     */
    it('orders the levels the way the framework in force names them', () => {
        const rows = [
            intervention({ support_measures: [{ level: 'additional', level_label: 'Medida adicional' }] }),
            intervention({ support_measures: [{ level: 'universal', level_label: 'Medida universal' }] }),
            intervention({ support_measures: [{ level: 'selective', level_label: 'Medida seletiva' }] }),
        ];

        expect(summarise(rows, today, LEVEL_ORDER).levels.map((level) => level.level)).toEqual([
            'universal',
            'selective',
            'additional',
        ]);
    });

    it('follows a different framework order without a line changing here', () => {
        const rows = [
            intervention({ support_measures: [{ level: 'universal', level_label: 'Medida universal' }] }),
            intervention({ support_measures: [{ level: 'additional', level_label: 'Medida adicional' }] }),
        ];

        expect(summarise(rows, today, ['additional', 'universal']).levels.map((level) => level.level)).toEqual([
            'additional',
            'universal',
        ]);
    });

    it('keeps the order the data arrived in when no framework order is given', () => {
        const rows = [
            intervention({ support_measures: [{ level: 'selective', level_label: 'Medida seletiva' }] }),
            intervention({ support_measures: [{ level: 'universal', level_label: 'Medida universal' }] }),
        ];

        expect(summarise(rows, today).levels.map((level) => level.level)).toEqual(['selective', 'universal']);
    });

    it('also reads the level off the legacy single framing', () => {
        const summary = summarise(
            [intervention({ legal_framing: { level: 'universal', level_label: 'Medida universal' } })],
            today,
        );

        expect(summary.levels).toEqual([expect.objectContaining({ level: 'universal', count: 1 })]);
    });

    it('offers the nearest review date still ahead, and ignores the ones already past', () => {
        const summary = summarise(
            [
                intervention({ review_on: '2026-08-01' }),
                intervention({ review_on: '2026-11-15' }),
                intervention({ review_on: '2026-10-02' }),
            ],
            today,
        );

        expect(summary.nextReviewOn).toBe('2026-10-02');
    });

    it('has no next review when every date the teacher chose has passed', () => {
        const summary = summarise([intervention({ review_on: '2026-01-01' })], today);

        expect(summary.nextReviewOn).toBeNull();
    });

    it('counts the pending reviews the server already flagged, never deriving its own', () => {
        const summary = summarise([intervention({ needs_review: true }), intervention()], today);

        expect(summary.pendingReviewCount).toBe(1);
    });

    it('survives an empty page without inventing anything', () => {
        expect(summarise([], today)).toEqual({ activeCount: 0, levels: [], nextReviewOn: null, pendingReviewCount: 0 });
    });
});
