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
        'Cada aula tem um número de lição («Lição 12») que segue a ordem das aulas da turma. Em turmas desdobradas, T1 e T2 partilham o mesmo número de lição quando correspondem à mesma lição da turma: no horário da turma, ao configurar o tempo de um grupo, indique em «Mesma lição que…» o tempo do outro grupo. Se um grupo perder uma aula (feriado, cancelamento), a aula seguinte desse grupo continua a ser a lição que ficou por dar. A aula seguinte da turma inteira continua no número seguinte. Uma turma de apoio tem a sua própria numeração.',
        'Nem toda a ocorrência do horário chega a ser uma aula dada. Quando não houve aula, carregue em «Não houve aula» e escolha o motivo: «Professor ausente» ou «Turma em outras atividades letivas».',
        'Em «Professor ausente», o motivo é só uma categoria — Formação, Serviço oficial ou Outro, sem texto livre. A aula não é numerada, não conta como lecionada, e a assiduidade não se aplica: um rascunho de faltas dessa aula é descartado e deixa de se poder registar assiduidade nela.',
        'Em «Turma em outras atividades letivas» pode escrever uma descrição curta, opcional, até 160 carateres. A aula É numerada e conta como lecionada para efeitos de serviço docente, mas não como desenvolvimento efetivo da disciplina; a assiduidade também não se aplica.',
        'Em qualquer um dos dois casos, o sumário previsto, os recursos, o TPC e as notas dessa aula passam para a próxima aula da mesma turma ou grupo que ainda não tenha planeamento — cada aula planeada seguinte desloca-se uma posição até à primeira aula sem planeamento. Se ainda não existir essa aula seguinte, o Lapispro cria a próxima ocorrência do horário; se não houver nenhuma até ao fim do ano letivo, o planeamento fica como «Planeamento pendente», indicado na página da aula.',
        'Em turmas desdobradas, os grupos podem ficar temporariamente desalinhados: se T1 teve a aula e T2 teve professor ausente, T2 mantém a mesma lição curricular (o mesmo número de lição) na sua próxima ocorrência. Não há alinhamento por data entre os grupos.',
        'Os resultados registam-se por ordem: se já existir um resultado numa aula posterior da mesma turma ou grupo, o Lapispro recusa registar esta. Uma aula com assiduidade já consolidada não pode ser marcada como professor ausente. Uma aula fechada — com qualquer resultado — não pode ser removida, o sumário não pode ser apagado, e fica fora do «Marcar lecionadas» em lote.',
        'O motivo escolhido em «Professor ausente» fica registado no histórico de alterações; nunca é mostrado nos relatórios.',
    ],
    'keywords' => ['sumário', 'aula', 'aulas', 'lecionada', 'voltar', 'semana', 'tpc', 'lição', 'número', 'numeração', 't1', 't2', 'desdobramento', 'não houve aula', 'professor ausente', 'outras atividades letivas', 'planeamento pendente'],
    'related' => ['lessons.attendance', 'classes.create', 'reports.view'],
    'contexts' => ['lessons.show', 'lessons.index'],
];
