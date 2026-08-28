<?php

// resources/help/articles/results.record.php
//
// Confirmado em App\Http\Controllers\InstrumentController::show()/saveScores()/
// completeCorrection() — route 'instruments.show', resources/js/pages/instruments/Grid.vue.

return [
    'title' => 'Registar resultados',
    'summary' => 'Como preencher a grelha de correção de um elemento de avaliação e concluir a correção.',
    'category' => 'Avaliação',
    'order' => 30,
    'content' => [
        'A grelha de correção de um elemento de avaliação já preparado mostra os alunos da turma em linhas e as questões em colunas.',
        'Para cada aluno e cada questão, indica a pontuação obtida — ou um estado como «não realizou» ou «não aplicável», quando for o caso. Um aluno que se inscreveu depois da data do elemento de avaliação aparece assinalado, e as questões ficam automaticamente não aplicáveis para ele.',
        'A grelha guarda apenas as células que alterar — não precisa de reenviar tudo de cada vez, e pode ir e voltar a esta página as vezes que precisar antes de terminar.',
        'Se duas pessoas (ou dois separadores) editarem a mesma grelha ao mesmo tempo, o Lapispro deteta o conflito por célula e avisa quando um valor foi entretanto alterado, em vez de sobrepor silenciosamente uma alteração à outra.',
        'Quando todos os alunos aplicáveis tiverem um resultado registado, pode concluir a correção — a partir desse momento a grelha fica só de leitura. Pode reabrir a correção mais tarde, se precisar de corrigir algo.',
        'Registar resultados não decide a classificação por si só — os resultados alimentam o cálculo, mas a classificação final de cada aluno é sempre confirmada pelo professor, na página de resultados da turma.',
    ],
    'keywords' => ['resultados', 'grelha de correção', 'notas', 'cotação', 'lançar notas', 'corrigir'],
    'related' => ['instruments.create', 'reports.view'],
    'contexts' => ['instruments.show'],
];
