<?php

// resources/help/articles/reports.view.php
//
// Confirmado em App\Http\Controllers\Reports\ReportController (routes
// 'reports.*') e App\Http\Controllers\EvaluationSheetController (routes
// 'evaluation-sheets.*'). São dois artefactos distintos, deliberadamente não
// confundidos — e desde que a pauta antiga foi absorvida pela Pauta de
// Avaliação vivem em menus diferentes: Relatórios e Avaliação.

return [
    'title' => 'Consultar relatórios e a Pauta de Avaliação',
    'summary' => 'A diferença entre a pauta (as classificações decididas) e o relatório (o documento com secções, exportável).',
    'category' => 'Relatórios e pautas',
    'order' => 10,
    'content' => [
        'O Lapispro distingue duas coisas que, à primeira vista, parecem a mesma: a pauta e o relatório.',
        'A pauta é uma tabela — a Pauta de Avaliação, no menu Avaliação, mostra a situação de uma turma num período, aluno a aluno: o quantitativo e a apreciação por domínio, o global, e o nível atribuído. Distingue sempre o que o Lapispro propõe do que o professor decidiu. Pode ser impressa tal como está no ecrã, ou exportada em CSV.',
        'O relatório é um documento — tem secções, um autor, pode ser editado, regenerado a partir dos dados mais recentes, e só depois de finalizado é exportado em PDF ou Word. Enquanto não é finalizado, continua a poder ser ajustado.',
        'Em resumo: abra a Pauta de Avaliação para ver classificações; consulte ou crie um relatório quando precisar de um documento — para uma reunião, para os encarregados de educação, ou para arquivo.',
        'Ambos dependem de resultados já registados e de classificações já decididas nas turmas a que se referem. O relatório vive no menu Relatórios; a pauta vive no menu Avaliação.',
    ],
    'keywords' => ['relatório', 'pauta', 'pauta de avaliação', 'documento', 'classificações', 'exportar relatório', 'csv', 'pdf', 'word'],
    'related' => ['results.record', 'data.export'],
    'contexts' => ['reports.index', 'evaluation-sheets.index', 'evaluation-sheets.show'],
];
