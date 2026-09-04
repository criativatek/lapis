<script setup lang="ts">
/**
 * A moldura de tabela que 25 páginas repetiam à mão.
 *
 * `headClass`: a única variação legítima encontrada no levantamento foi um
 * thead mais miúdo (`text-xs`) nas páginas densas de backoffice — entra como
 * acrescento, nunca como substituição do par AA.
 *
 * Duas responsabilidades, e só duas: o contentor (canto generoso, borda, e o
 * `overflow-x-auto` que impede uma tabela larga de arrastar a página inteira
 * — a regra do mobile) e o `<thead>` com o par `bg-muted`/`muted-foreground`,
 * que desde a 0.119.0 cumpre AA. As linhas continuam nas páginas, via slots:
 * a adopção é trocar a moldura, não reescrever a tabela.
 */
withDefaults(defineProps<{ headClass?: string }>(), { headClass: '' });
</script>

<template>
    <div class="overflow-x-auto rounded-xl border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted/50 text-left text-muted-foreground" :class="headClass">
                <slot name="head" />
            </thead>
            <tbody class="divide-y divide-border">
                <slot name="body" />
            </tbody>
        </table>
    </div>
</template>
