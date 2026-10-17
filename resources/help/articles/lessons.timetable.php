<?php

// resources/help/articles/lessons.timetable.php
//
// Confirmado em App\Http\Controllers\TeacherTimetableController, LessonScheduleController
// (update/destroy), TimetableActivityController, ActivitySessionController e
// TimetableImportController::confirm, e em resources/js/pages/timetable/Index.vue —
// routes 'timetable.index', 'lesson-slots.*', 'timetable-activities.*',
// 'activity-sessions.*'.

return [
    'title' => 'Corrigir o horário e registar as outras atividades',
    'summary' => 'Rever o horário depois de importar o PDF, mudar a turma ou as horas de uma aula, e acrescentar direção de turma, apoios, clubes e reuniões — com um sumário por sessão.',
    'category' => 'Aulas e sumários',
    'order' => 5,
    'content' => [
        'O «Horário do Professor» mostra a sua semana, de todas as turmas juntas. Depois de importar o PDF do horário, é aqui que vai parar: uma importação raramente sai perfeita, e este é o sítio para a rever e corrigir.',
        'Clique numa aula para a corrigir. Pode mudar a turma (a escolha mostra «Turma · Disciplina», porque a disciplina é da turma), o dia, as horas e o período em que vigora. Para acrescentar uma aula que falta, use «Adicionar aula».',
        'Se a aula já tem histórico — foi lecionada, tem sumário, plano ou faltas —, o Lapispro pede «Aplicar alteração a partir de». Até ao dia anterior, tudo fica como estava; a partir dessa data, vale o novo horário. O que já foi lecionado ou sumariado nunca se perde nem muda de turma. Se mudar a turma de uma aula que ainda não tem nada registado, a alteração faz-se no próprio horário, e as aulas vazias que já estavam preparadas na turma antiga são retiradas.',
        'Para eliminar uma aula, abra-a e escolha «Eliminar aula». Pode indicar «Deixa de vigorar a partir de»; sem data, o horário deixa de vigorar a partir de hoje. As aulas já lecionadas ou com registos ficam sempre.',
        '«Adicionar outra atividade» serve para o que faz parte do seu horário mas não é uma aula: direção de turma, apoio a alunos, clubes, reuniões, coordenação ou cargos. Dê-lhe uma designação (por exemplo, «DT 6.º D»), escolha o tipo, o dia e as horas, e, se quiser, a turma a que se refere. Aparece na mesma semana, com um aspeto tracejado, para não a confundir com uma aula.',
        'Cada sessão de uma outra atividade tem o seu sumário. Clique na atividade, na semana em que quer registar: abre-se o registo dessa data, onde pode escrever o sumário, guardá-lo como preparado, marcá-lo como realizado ou dizer que a sessão não se realizou (o texto fica). Só passa a existir uma sessão quando guarda; ver o horário nunca cria nada. O estado aparece no bloco: Preparada, Realizada ou Não realizada. Para mudar o dia ou as horas, use «Editar horário» dentro do registo. O mesmo registo abre-se em «Aulas e Sumários», ao lado das aulas da semana, e o «Histórico das outras atividades» lista tudo o que registou, com filtro por atividade e período e exportação para Excel.',
        'Se quiser contar as sessões («Sessão 1», «Sessão 2»…), ative «Numerar as sessões» na atividade e, se precisar, indique o número inicial. A numeração é só dessa atividade e de cada ano letivo, conta as sessões realizadas e é independente da numeração das lições. Sem ela, a sessão é identificada só pela data.',
        'Se editar o horário de uma atividade que já tem registos, as sessões com registos ficam na data e hora em que estavam, para não se perder o que lá está, e o Lapispro avisa-o de quais foram. Se a eliminar e já tiver registos, a atividade não é apagada: deixa de vigorar a partir da data que escolher e o histórico fica guardado.',
        'As outras atividades não geram aulas nem faltas e não entram na numeração das aulas. Não confunda com o desfecho «Turma em outras atividades letivas», que se escolhe numa aula para dizer que a turma não esteve na sala: aquilo é o resultado de uma aula do horário; as outras atividades do horário são as suas, e só suas — mais nenhum colega as vê, nem os que lecionam a mesma turma. Não podem sobrepor-se umas às outras no mesmo dia e período, mas podem coincidir com uma aula. Entram no backup dos seus dados.',
        'Os grupos de uma turma (T1/T2) continuam a gerir-se na página da turma, que também tem o seu próprio editor do horário.',
    ],
    'keywords' => ['horário', 'horario', 'corrigir', 'editar', 'importar', 'pdf', 'turma errada', 'horas', 'outras atividades', 'outra atividade', 'atividade não letiva', 'clube', 'sumário da sessão', 'histórico', 'direção de turma', 'DT', 'apoio', 'reunião', 'coordenação', 'cargo', 'aplicar alteração', 'eliminar aula'],
    'related' => ['lessons.summary', 'classes.create'],
    'contexts' => [],
];
