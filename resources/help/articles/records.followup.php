<?php

// resources/help/articles/records.followup.php
//
// Confirmado em App\Http\Controllers\RecordConsultationController,
// App\Services\Evidence\Followup\RecordFollowupCounter, RecordFollowupAlerts e
// InovarFollowupMessage, App\Services\Lessons\ConsolidatedAbsences, e em
// resources/js/pages/records/Index.vue, components/records/FollowupCaseDialog.vue e
// AbsenceCaseDialog.vue — routes 'records.index', 'records.show'.

return [
    'title' => 'Consultar registos e acompanhar TPC, atrasos e faltas',
    'summary' => 'Pesquisar os registos, ver quem acumulou TPC não realizados, atrasos, faltas de material ou faltas de presença no período e preparar a mensagem para o Inovar.',
    'category' => 'Acompanhamento',
    'order' => 20,
    'content' => [
        'Em «Registos» começa pelos filtros — ano letivo, turma, disciplina (quando ensina mais do que uma), aluno (depois de escolher a turma), tipo de registo e período de avaliação, ou um intervalo de datas — e por um resumo. A lista de registos só aparece depois de «Pesquisar», 25 de cada vez. «Limpar filtros» volta ao período em curso e a todas as turmas.',
        'O período em curso fica escolhido quando hoje cai num dos períodos configurados no ano letivo. Se hoje não cai em nenhum (por exemplo, nas férias), nada é escolhido por si: escolha um período ou um intervalo de datas.',
        'Os acessos «TPC não realizados», «Atrasos», «Faltas de material» e «Faltas de presença» dizem quantos alunos e quantas ocorrências há no âmbito escolhido. Ao abrir um deles vê só os alunos com ocorrências desse tipo, do que tem mais para o que tem menos; tocar num aluno mostra as datas, cada uma com «Abrir registo» ou «Abrir aula».',
        '«Alunos» conta cada aluno uma vez, pela ficha do aluno na aplicação — nunca pelo nome. Um aluno de uma turma de apoio que também está noutra turma é um aluno com dois casos, cada um com a sua linha e o seu alerta; dois alunos com o mesmo nome em turmas diferentes contam como dois.',
        'As «Faltas de presença» vêm da assiduidade das aulas, não de registos: conta uma falta por cada aula em que o aluno ficou com falta, depois de a assiduidade da aula ser lançada. A assiduidade por lançar não é falta, um atraso não é falta, e uma correção na aula muda logo a contagem. A data é a da aula. O detalhe mostra as faltas e, à parte, em quantos dias — duas aulas no mesmo dia são duas faltas num dia. A aplicação não distingue faltas justificadas de injustificadas. Para as faltas de presença não há alerta nem mensagem para o Inovar.',
        'Conta-se cada tipo à parte, por aluno, turma (disciplina) e período: um atraso, uma falta de material e um TPC não somam três. Num TPC só conta «Não realizado» — «Parcialmente realizado», «Realizado» e o TPC que não foi verificado não contam. Dois registos no mesmo dia contam como dois, e a data que conta é a do registo, não a do dia em que o escreveu.',
        'Quando um aluno chega a três ocorrências do mesmo tipo, na mesma turma e no mesmo período, fica um alerta em «Alertas por tratar», nesta página e no Início, e o aviso aparece também ao gravar. Há um só alerta por aluno, tipo, turma e período — não se repete aos seis nem aos nove. Os alunos que já tinham três ou mais antes de haver alertas aparecem nas listas como «Por tratar», sem alerta.',
        'Para receber alertas de acumulação, configure os períodos de avaliação e confirme as respetivas datas. Um registo numa data fora de todos os períodos não conta para nenhum período nem gera alerta, mas aparece na consulta por datas.',
        'No detalhe do aluno está a mensagem para o Inovar, já preenchida com o número, o primeiro e o último nome, a disciplina, o período, a quantidade por extenso e as datas. Pode editá-la antes de copiar; editar não altera os registos. «Copiar mensagem para o Inovar» copia o texto — se o browser não deixar, o texto fica selecionado para copiar à mão. Copiar não marca nada.',
        'Depois de registar no Inovar, use «Marcar como registado no Inovar» e confirme. Fica guardado quem marcou, quando e que ocorrências abrangia; pode desfazer. A aplicação não verifica o Inovar. Se um registo for corrigido ou eliminado depois, a contagem muda e o detalhe diz o que mudou desde a marcação.',
        'Os alertas e as marcações vão na exportação de dados e no backup. Ao restaurar um backup não se criam alertas novos nem se enviam avisos. Uma marcação reposta diz «reposta a partir de um backup»; se algum dos registos que abrangia já não existir, o detalhe diz quantas ocorrências não foram recuperadas.',
    ],
    'keywords' => ['registos', 'consulta', 'pesquisar', 'tpc', 'trabalho de casa', 'atraso', 'atrasos', 'falta de material', 'faltas de material', 'alerta', 'acumulação', 'inovar', 'ocorrência', 'nível 2', 'grau 2', 'mensagem', 'copiar', 'faltas de presença', 'falta', 'faltas', 'assiduidade', 'backup', 'restaurar'],
    'related' => ['lessons.attendance'],
    'contexts' => ['records.index', 'records.show'],
];
