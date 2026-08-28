<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({ inheritAttrs: false });

defineProps<{ className?: HTMLAttributes['class'] }>();
</script>

<template>
    <!--
        A marca, do ficheiro original da identidade.

        Substituiu um símbolo desenhado à mão em código que nunca foi a marca
        de ninguém — um lápis e um ponteiro de relógio inventados aqui dentro.

        O AZUL É `currentColor` DE PROPÓSITO: assim a marca inverte com o texto
        à sua volta, azul sobre claro e branca sobre escuro, sem serem dois
        ficheiros a manter nem o salto que se vê quando uma imagem troca depois
        de a página já estar pintada. O âmbar nunca muda — é ele que identifica.
    -->
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 66.66 67.17"
        role="img"
        aria-label="Lapispro"
        :class="className"
        v-bind="$attrs"
    >
        <polyline
            fill="currentColor"
            points="62.79 67.2 3.86 67.2 3.86 50.76 62.79 50.76"
        />
        <g>
            <polygon
                fill="#f9b04e"
                points="23.3 18.44 3.86 50.76 62.79 50.76 45.48 21.98 23.3 18.44"
            />
            <polygon
                fill="currentColor"
                points="41.6 15.52 41.6 15.52 33.33 1.77 33.33 1.77 33.33 1.77 23.3 18.44 45.48 21.98 41.6 15.52"
            />
        </g>
    </svg>
</template>
