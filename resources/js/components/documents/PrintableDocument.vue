<script setup lang="ts">
import { onBeforeUnmount, onMounted } from 'vue';

/**
 * O gancho de impressão dos documentos «imprimir agora» de uma turma.
 *
 * UMA INFRAESTRUTURA DE IMPRESSÃO, não uma por documento. O que se imprime é a
 * pré-visualização que o professor tem à frente (a mesma ideia de `.report-print`
 * em reports/Show.vue): uma vista só para o papel seria uma segunda composição a
 * manter em dia com a do ecrã. Os documentos seguintes (grelhas em paisagem)
 * reutilizam este componente e só mudam a orientação.
 *
 * O `@page` É INJETADO NO `<head>` porque um SFC não consegue fazer o tamanho da
 * página seguir uma prop: `@page` não aceita seletores nem variáveis, e um
 * `<style>` do componente ficaria fixo em retrato. O elemento sai ao desmontar,
 * para uma página em paisagem não deixar o papel de outra virado de lado.
 */
const props = withDefaults(defineProps<{ orientation?: 'portrait' | 'landscape' }>(), {
    orientation: 'portrait',
});

let pageStyle: HTMLStyleElement | null = null;

onMounted(() => {
    if (typeof document === 'undefined') {
        return;
    }

    pageStyle = document.createElement('style');
    pageStyle.setAttribute('data-document-print-page', '');
    pageStyle.textContent = `@page { size: A4 ${props.orientation}; margin: 18mm 16mm; }`;
    document.head.appendChild(pageStyle);
});

onBeforeUnmount(() => {
    pageStyle?.remove();
    pageStyle = null;
});
</script>

<template>
    <div
        class="document-print rounded-lg border border-border bg-card p-6 sm:p-10"
        :class="orientation === 'landscape' ? 'w-full' : 'mx-auto w-full max-w-[46rem]'"
        :data-orientation="orientation"
    >
        <!-- `document-print` é um GANCHO DE IMPRESSÃO, não estilo: o seletor pelas
             classes utilitárias partir-se-ia na primeira vez que alguém mudasse o
             `max-w-[46rem]`. -->
        <slot />
    </div>
</template>

<style>
/* IMPRIMIR SÓ O DOCUMENTO, a partir deste mesmo ecrã, sem depender do markup
   do layout da aplicação — nenhuma regra abaixo nomeia uma classe do layout.

   TUDO PRESO A `body:has(.document-print)`. Um <style> de componente fica no
   documento depois de a página sair; um `body * { visibility: hidden }` solto
   apagaria a impressão de qualquer outra página visitada a seguir.

   NÃO É O TRUQUE DO `position: absolute; inset: 0`. Esse prende a altura da
   folha à do contentor do layout (o <main>, com altura própria e overflow), e
   tudo o que passasse da primeira página era cortado: um mapa de uma turma
   inteira saía com metade dos alunos. Aqui a folha fica no fluxo normal, o que
   não a contém sai do papel sem deixar espaço, e os contentores dela deixam de
   cortar e de fixar alturas — o browser pagina-a como a um documento comum. */
@media print {
    body:has(.document-print) *:not(:has(.document-print)):not(.document-print):not(.document-print *) {
        display: none !important;
    }
    body:has(.document-print) *:has(.document-print) {
        display: block !important;
        position: static !important;
        overflow: visible !important;
        height: auto !important;
        min-height: 0 !important;
        max-height: none !important;
        width: auto !important;
        max-width: none !important;
        margin: 0 !important;
        padding: 0 !important;
        border: 0 !important;
        background: transparent !important;
        box-shadow: none !important;
    }
    body:has(.document-print) .document-print {
        position: static;
        /* No papel não há cartão: o que fica é o documento. */
        border: 0 !important;
        border-radius: 0 !important;
        background: transparent !important;
        box-shadow: none !important;
        padding: 0 !important;
        max-width: none !important;
        margin: 0 !important;
    }
    /* Um título não fica sozinho no fim de uma página. */
    .document-print h1,
    .document-print h2,
    .document-print h3 {
        break-after: avoid;
        page-break-after: avoid;
    }
    /* Uma linha da tabela não se parte entre páginas; o cabeçalho repete-se. */
    .document-print tr {
        break-inside: avoid;
        page-break-inside: avoid;
    }
    .document-print thead {
        display: table-header-group;
    }
    /* Preto sobre branco: um cinzento de ecrã imprime-se ilegível. */
    .document-print,
    .document-print * {
        color: #000 !important;
    }
}
</style>
