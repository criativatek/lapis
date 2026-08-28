<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({ inheritAttrs: false });

defineProps<{ className?: HTMLAttributes['class'] }>();
</script>

<template>
    <!--
        O logótipo por extenso.

        UM FICHEIRO, NÃO DOIS. As duas versões da identidade — a azul e a
        branca — diferem só na cor do texto, por isso essa parte é
        `currentColor` e a troca de tema faz-se com uma classe. Duas imagens
        trocadas por CSS piscariam ao mudar de tema e obrigariam a carregar as
        duas.
    -->
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 271.34 44.27"
        role="img"
        aria-label="Lapispro"
        :class="className"
        v-bind="$attrs"
    >
        <g>
            <path
                fill="currentColor"
                d="M58.78,4.82h-10l-15.7,35h10.5l2.3-5.75h15.65l2.3,5.75h10.6L58.78,4.82ZM49.23,25.77l4.5-11.15,4.45,11.15h-8.95Z"
            />
            <path
                fill="currentColor"
                d="M16.28,4.82v26.6h16.75v8.4H6.38V4.82h9.9Z"
            />
            <path
                fill="currentColor"
                d="M87.13,30.37v9.45h-9.9V4.82h17c9.4,0,14.05,5.9,14.05,12.75s-4.7,12.7-14.05,12.8h-7.1ZM87.13,22.02h7.1c2.75,0,4.15-2,4.15-4.3s-1.45-4.4-4.15-4.4h-7.1v8.7Z"
            />
            <path
                fill="currentColor"
                d="M151.18,14.52c-2.4-1.8-5.15-2.4-8.2-2.4-2.4,0-4.75.75-4.75,2.6,0,2.4,2.35,2.75,5.8,3.1,6.95.75,14.2,3.55,14.2,11.6s-7.5,11.35-15.35,11.4c-6.3.05-10.8-1.05-15.65-6.15l5.45-6.25c3.45,3.35,7.3,3.95,10.25,3.95,3.25,0,5.5-1.15,5.5-3.1,0-1.75-2-2.85-5.85-3.2-7.65-.7-14.25-2.65-14.25-11.35,0-7.4,7.25-10.6,14.65-10.6,5.25,0,9.35,1.2,13.1,4.15l-4.9,6.25Z"
            />
            <path
                fill="currentColor"
                d="M167.73,28.17v11.65h-3.5V4.82h15.65c15.65,0,15.65,23.3,0,23.35h-12.15ZM167.73,24.87h12.15c10.95,0,10.95-16.75,0-16.75h-12.15v16.75Z"
            />
            <path
                fill="currentColor"
                d="M226.88,39.82h-4.55l-11.6-12.9h-9.05v12.9h-3.5V4.82h15.7c7.8,0,11.55,5.2,11.6,11.1.05,5.45-3.15,10.4-10.55,10.75l11.95,13.15ZM201.68,8.12v15.5h12c5.6,0,8.3-3.3,8.3-7.7s-2.7-7.8-8.1-7.8h-12.2Z"
            />
            <path
                fill="currentColor"
                d="M266.78,22.42c-.05,9.15-5.85,18.05-17.7,18.05s-17.75-8.9-17.75-18.1c0-9.85,6.4-18.15,17.75-18.15,11.85.05,17.75,8.85,17.7,18.2ZM249.08,37.17c9.55,0,14.15-7.25,14.2-14.75,0-7.85-4.65-14.85-14.2-14.9-9.4,0-14.35,6.65-14.25,14.9.1,7.65,4.7,14.75,14.25,14.75Z"
            />
        </g>
        <polyline
            fill="currentColor"
            points="122.53 39.82 112.68 39.82 112.68 11.63 122.53 11.63"
        />
        <g>
            <polygon
                fill="#f9b04e"
                points="115.93 6.23 112.68 11.63 122.53 11.63 119.64 6.82 115.93 6.23"
            />
            <polygon
                fill="currentColor"
                points="118.99 5.74 118.99 5.74 117.61 3.44 117.61 3.44 117.61 3.44 115.93 6.23 119.64 6.82 118.99 5.74"
            />
        </g>
    </svg>
</template>
