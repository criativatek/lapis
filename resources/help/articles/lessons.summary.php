<?php

// resources/help/articles/lessons.summary.php
//
// Confirmado em App\Http\Controllers\LessonController e
// resources/js/pages/lessons/Show.vue e AppServicesessonsessonnumbering — routes 'lessons.index', 'lessons.show'.

return [
    'title' => 'Escrever o sumário de uma aula',
    'summary' => 'Abrir uma aula a partir da semana, guardar o sumário e voltar à mesma semana.',
    'category' => 'Aulas e sumários',
    'order' => 10,
    'content' => [
        'Em «Aulas», escolha a semana e abra a aula. Na página da aula escreve o sumário e, se quiser, notas, recursos e TPC.',
        '«Guardar» grava o sumário; «Marcar como lecionada» regista que a aula foi dada e, com ela, a assiduidade (ver «Registar faltas numa aula»). São ações separadas, e nenhuma acontece sozinha.',
        '«Voltar às aulas da semana» existe no topo e no fundo da página e leva à semana dessa aula. Não guarda nem muda o estado da aula: se tiver alterações por guardar, o Lapispro avisa antes de sair.',
        'Cada aula tem um número de lição («Lição 12») que segue a ordem das aulas da turma. Em turmas desdobradas, T1 e T2 partilham o mesmo número de lição quando correspondem à mesma lição da turma (a primeira aula de T1 e a primeira de T2 da mesma semana, e assim por diante); a aula seguinte da turma inteira continua no número seguinte. Uma turma de apoio tem a sua própria numeração.',
    ],
    'keywords' => ['sumário', 'aula', 'aulas', 'lecionada', 'voltar', 'semana', 'tpc', 'lição', 'número', 'numeração', 't1', 't2', 'desdobramento'],
    'related' => ['lessons.attendance', 'classes.create'],
    'contexts' => ['lessons.show', 'lessons.index'],
];
