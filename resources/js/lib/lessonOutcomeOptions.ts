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
