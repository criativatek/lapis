<?php

// resources/help/articles/lessons.search.php
//
// Confirmado em App\Http\Controllers\LessonWeekController::search,
// App\Services\Lessons\LessonSummarySearch e SummarySearchText, e em
// resources/js/pages/lessons/Index.vue — routes 'lessons.index', 'lessons.search'.

return [
    'title' => 'Pesquisar nos sumários',
    'summary' => 'Encontrar em que aulas do ano letivo escreveu uma palavra ou uma expressão no sumário.',
    'category' => 'Aulas e sumários',
    'order' => 15,
    'content' => [
        'Em «Aulas e Sumários», escreva no campo «Pesquisar nos sumários…». A pesquisa procura em todo o ano letivo selecionado, e não só na semana que está à vista, e os resultados aparecem no lugar do calendário. Enter pesquisa logo; ao escrever, a pesquisa faz-se quando para de escrever.',
        'Uma expressão com várias palavras é procurada exatamente por essa ordem — também quando o sumário mudou de linha entre elas. Maiúsculas e acentos não contam: «fracoes» encontra «Frações». Uma palavra incompleta também encontra: «fraç» encontra «frações».',
        'Só se pesquisa o texto do sumário. As notas, os recursos e o TPC da aula ficam de fora.',
        'Por baixo, escolha as turmas (por omissão, as que o calendário estava a mostrar; «Todas» para todas) e o estado: «Lecionadas» (por omissão na primeira utilização), «Preparadas» ou «Ambas». A escolha fica lembrada neste browser. Em «Mais filtros» pode limitar o período e incluir as aulas com professor ausente ou com a turma noutras atividades.',
        'Por cima dos resultados está sempre onde se procurou (por exemplo, «Ano letivo 2026/2027 · Todas as turmas · Aulas lecionadas») e quanto se encontrou: o número de aulas e, à parte, o número de vezes que a expressão aparece. As aulas mais recentes vêm primeiro, com o sumário completo e a expressão destacada.',
        'Uma aula preparada mostra o que estava previsto: encontrar lá a expressão não confirma que o conteúdo foi dado. O estado de cada aula está sempre no cartão. E não encontrar nada quer dizer que não há sumários com aqueles critérios — não que o conteúdo não foi lecionado.',
        '«Abrir aula» abre a aula; «Voltar aos resultados da pesquisa», na página da aula, regressa à mesma pesquisa, com os mesmos filtros e na mesma página. «Voltar ao calendário» (ou limpar o campo) volta à semana, à vista e aos filtros que tinha antes de pesquisar.',
    ],
    'keywords' => ['pesquisar', 'pesquisa', 'procurar', 'encontrar', 'sumário', 'sumários', 'expressão', 'palavra', 'conteúdo', 'matéria', 'lecionado', 'preparado', 'ano letivo'],
    'related' => ['lessons.summary', 'lessons.attendance'],
    'contexts' => ['lessons.index'],
];
