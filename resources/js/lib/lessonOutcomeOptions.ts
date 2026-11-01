import type { ComputedRef, InjectionKey } from 'vue';

/**
 * O que o diálogo «O que aconteceu nesta aula?» oferece para «Aula não
 * realizada». Vem do servidor (`NotHeldOptions::forPage`); `null` com a
 * funcionalidade desligada — e então a opção nem existe no ecrã.
 */
export type NotHeldOptions = {
    causes: { value: string; label: string }[];
    /** As especificações facultativas da greve oferecidas hoje («Aderi» só com a chave ligada). */
    specifications: { value: string; label: string }[];
};

/** A página diz aos menus rápidos se «Aula não realizada» existe — sem passar a prop por cada cartão. */
export const notHeldEnabledKey: InjectionKey<ComputedRef<boolean>> = Symbol('lessonNotHeldEnabled');

/**
 * CORRIGIR o resultado já registado (`outcome_rectification` da página da aula). Só chega a quem pode
 * corrigir — o titular do registo —, porque o valor atual e o histórico podem ter dado reservado.
 */
export type OutcomeRectification = {
    current: {
        outcome: string | null;
        reason: string | null;
        note: string | null;
        cause: string | null;
        situation: string | null;
        cause_note: string | null;
    };
    /** Os resultados para onde esta aula se pode corrigir (inclui o atual, para corrigir só os detalhes). */
    targets: string[];
    /** Porque é que o resultado não se troca (os detalhes sim); `null` se pode. */
    outcome_locked_reason: string | null;
    history: {
        ulid: string;
        at: string;
        by: string | null;
        kind: string;
        motive: string;
        changes: { field: string; label: string; from: string; to: string }[];
    }[];
};
