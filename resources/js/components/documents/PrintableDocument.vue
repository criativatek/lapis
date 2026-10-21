<script setup lang="ts">
import { onBeforeUnmount, onMounted, watch } from 'vue';

/**
 * O gancho de impressão dos documentos «imprimir agora» (mapa de medidas,
 * relatório, pauta).
 *
 * UMA INFRAESTRUTURA DE IMPRESSÃO, não uma por documento. O que se imprime é a
 * pré-visualização que o professor tem à frente: uma vista só para o papel seria
 * uma segunda composição a manter em dia com a do ecrã.
 *
 * SEM ASPETO NO ECRÃ. O componente só marca a folha e trata do papel; cartão,
 * padding e largura vêm da `class` que cada página passa (o Vue junta-a à do
 * root). Assim o relatório, o mapa e a pauta mantêm cada um o seu aspeto e
 * partilham o mesmo comportamento de impressão.
 *
 * O `@page` É INJETADO NO `<head>` porque um SFC não consegue fazer o tamanho da
 * página seguir uma prop: `@page` não aceita seletores nem variáveis. Um `watch`
 * reescreve-o quando a orientação ou a margem mudam sem o componente remontar
 * (`router.get(..., { preserveState: true })` reaproveita a instância); só ler
 * a prop no `onMounted` deixava o papel da página anterior. O elemento sai ao
 * desmontar, para uma página em paisagem não deixar o papel de outra virado de
 * lado.
 *
 * O QUE TEM DE SAIR EM CADA FOLHA VAI NAS MARGENS DA PÁGINA. Um selo («PROVISÓRIO»)
 * ou a numeração («Página N / T») escritos no corpo do documento só saem na folha
 * onde calham, e um `position: fixed` repete-se mas falha a última. As caixas de
 * margem do próprio `@page` (`@top-right`, `@bottom-right`) saem em TODAS, a
 * última incluída. Só há Chromium recente que as desenha; nos outros browsers o
 * documento imprime-se na mesma, sem elas — por isso o que importa continua
 * também escrito no corpo, e o PDF do servidor é o caminho que garante as duas
 * coisas em todas as páginas. Ambas as props são opcionais e, por omissão, o
 * `@page` é o de sempre.
 *
 * A VISIBILIDADE É REPOSTA. Páginas antigas usavam `body * { visibility: hidden }`
 * e o `<style>` sem âmbito ficava carregado na SPA depois de a página sair: ao
 * navegar do relatório para o mapa, o mapa imprimia em branco. A regra abaixo
 * volta a mostrar a folha, com especificidade acima de `body *`.
 */
const props = withDefaults(
    defineProps<{
        orientation?: 'portrait' | 'landscape';
        /** Margem de 10 mm (grelhas largas) em vez de 18 mm 16 mm. */
        compact?: boolean;
        /** Selo no canto superior direito de CADA folha (caixa de margem do `@page`). */
        stamp?: string | null;
        /** «Página N / T» no canto inferior direito de cada folha. */
        pageNumbers?: boolean;
    }>(),
    {
        orientation: 'portrait',
        compact: false,
        stamp: null,
        pageNumbers: false,
    },
);

let pageStyle: HTMLStyleElement | null = null;

/** Um texto como string de CSS: o selo vem do servidor e não pode partir a regra. */
function cssString(text: string): string {
    const escaped = text.replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/\s+/g, ' ');

    return `"${escaped}"`;
}

function pageRule(): string {
    const margin = props.compact ? '10mm' : '18mm 16mm';
    const boxes: string[] = [];

    if (props.stamp) {
        boxes.push(
            `@top-right { content: ${cssString(props.stamp)}; font: bold 9pt sans-serif; letter-spacing: 0.4pt; color: #000; text-align: right; vertical-align: middle; }`,
        );
    }

    if (props.pageNumbers) {
        boxes.push('@bottom-right { content: "Página " counter(page) " / " counter(pages); font: 8pt sans-serif; color: #444; }');
    }

    return `@page { size: A4 ${props.orientation}; margin: ${margin};${boxes.map((box) => ` ${box}`).join('')} }`;
}

onMounted(() => {
    if (typeof document === 'undefined') {
        return;
    }

    pageStyle = document.createElement('style');
    pageStyle.setAttribute('data-document-print-page', '');
    pageStyle.textContent = pageRule();
    document.head.appendChild(pageStyle);
});

watch(
    () => [props.orientation, props.compact, props.stamp, props.pageNumbers],
    () => {
        if (pageStyle) {
            pageStyle.textContent = pageRule();
        }
    },
);

onBeforeUnmount(() => {
    pageStyle?.remove();
    pageStyle = null;
});
</script>

<template>
    <div class="document-print" :data-orientation="orientation" :data-compact="compact">
        <!-- `document-print` é um GANCHO DE IMPRESSÃO, não estilo: o aspeto no
             ecrã vem da `class` do pai, e um seletor pelas classes utilitárias
             partir-se-ia na primeira vez que alguém as mudasse. -->
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
    /* Cenário «relatório → mapa em branco»: um `body * { visibility: hidden }`
       de outra página, ainda carregado na SPA, apagava esta folha. Repõe-se aqui
       com especificidade acima de `body *`, sem `!important`. */
    body:has(.document-print) .document-print,
    body:has(.document-print) .document-print * {
        visibility: visible;
    }
    body:has(.document-print) .document-print {
        position: static;
        overflow: visible !important;
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
