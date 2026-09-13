<?php

// resources/help/articles/lessons.summary.php
//
// Confirmado em App\Http\Controllers\LessonController e
// resources/js/pages/lessons/Show.vue — routes 'lessons.index', 'lessons.show'.

return [
    'title' => 'Escrever o sumário de uma aula',
    'summary' => 'Abrir uma aula a partir da semana, guardar o sumário e voltar à mesma semana.',
    'category' => 'Aulas e sumários',
    'order' => 10,
    'content' => [
        'Em «Aulas», escolha a semana e abra a aula. Na página da aula escreve o sumário e, se quiser, notas, recursos e TPC.',
        '«Guardar» grava o sumário; «Marcar como lecionada» regista que a aula foi dada. São ações separadas, e nenhuma acontece sozinha.',
        '«Voltar às aulas da semana» existe no topo e no fundo da página e leva à semana dessa aula. Não guarda nem muda o estado da aula: se tiver alterações por guardar, o Lapispro avisa antes de sair.',
    ],
    'keywords' => ['sumário', 'aula', 'aulas', 'lecionada', 'voltar', 'semana', 'tpc'],
    'related' => ['classes.create'],
    'contexts' => ['lessons.show', 'lessons.index'],
];
