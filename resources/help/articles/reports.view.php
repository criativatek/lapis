<?php

// resources/help/articles/reports.view.php
//
// Confirmado em App\Http\Controllers\Reports\ReportController (routes
// 'reports.*') e App\Http\Controllers\ReportsController (routes 'pautas.*',
// ver o próprio docblock desta última: «A TABLE, NOT A DOCUMENT»). São dois
// artefactos distintos, deliberadamente não confundidos.

return [
    'title' => 'Consultar relatórios e pautas',
    'summary' => 'A diferença entre a pauta (as classificações decididas) e o relatório (o documento com secções, exportável).',
    'category' => 'Relatórios e pautas',
    'order' => 10,
    'content' => [
        'O Lapispro distingue duas coisas que, à primeira vista, parecem a mesma: a pauta e o relatório.',
        'A pauta é uma tabela — mostra as classificações já decididas (confirmadas ou publicadas) de uma turma, num período, aluno a aluno. Não mostra propostas por confirmar. É a fotografia do que o professor já decidiu, e pode ser exportada.',
        'O relatório é um documento — tem secções, um autor, pode ser editado, regenerado a partir dos dados mais recentes, e só depois de finalizado é exportado em PDF ou Word. Enquanto não é finalizado, continua a poder ser ajustado.',
        'Em resumo: consulte a pauta para ver classificações; consulte ou crie um relatório quando precisar de um documento — para uma reunião, para os encarregados de educação, ou para arquivo.',
        'Ambos partem do mesmo sítio, o hub de Relatórios, e ambos dependem de resultados já registados e de classificações já decididas nas turmas a que se referem.',
    ],
    'keywords' => ['relatório', 'pauta', 'documento', 'classificações', 'exportar relatório', 'pdf', 'word'],
    'related' => ['results.record', 'data.export'],
    'contexts' => ['reports.index', 'pautas.index'],
];
