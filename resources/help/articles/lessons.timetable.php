<?php

// resources/help/articles/lessons.timetable.php
//
// Confirmado em App\Http\Controllers\TeacherTimetableController, LessonScheduleController
// (update/destroy), TimetableActivityController e TimetableImportController::confirm,
// e em resources/js/pages/timetable/Index.vue — routes 'timetable.index',
// 'lesson-slots.*', 'timetable-activities.*'.

return [
    'title' => 'Corrigir o horário e acrescentar atividades não letivas',
    'summary' => 'Rever o horário depois de importar o PDF, mudar a turma ou as horas de uma aula, e acrescentar direção de turma, apoios e reuniões.',
    'category' => 'Aulas e sumários',
    'order' => 5,
    'content' => [
        'O «Horário do Professor» mostra a sua semana, de todas as turmas juntas. Depois de importar o PDF do horário, é aqui que vai parar: uma importação raramente sai perfeita, e este é o sítio para a rever e corrigir.',
        'Clique numa aula para a corrigir. Pode mudar a turma (a escolha mostra «Turma · Disciplina», porque a disciplina é da turma), o dia, as horas e o período em que vigora. Para acrescentar uma aula que falta, use «Adicionar aula».',
        'Se a aula já tem histórico — foi lecionada, tem sumário, plano ou faltas —, o Lapispro pede «Aplicar alteração a partir de». Até ao dia anterior, tudo fica como estava; a partir dessa data, vale o novo horário. O que já foi lecionado ou sumariado nunca se perde nem muda de turma. Se mudar a turma de uma aula que ainda não tem nada registado, a alteração faz-se no próprio horário, e as aulas vazias que já estavam preparadas na turma antiga são retiradas.',
        'Para eliminar uma aula, abra-a e escolha «Eliminar aula». Pode indicar «Deixa de vigorar a partir de»; sem data, o horário deixa de vigorar a partir de hoje. As aulas já lecionadas ou com registos ficam sempre.',
        '«Adicionar atividade não letiva» serve para o que faz parte do seu horário mas não é uma aula: direção de turma, apoio a alunos, reuniões, coordenação ou cargos. Dê-lhe uma designação (por exemplo, «DT 6.º D»), escolha o tipo, o dia e as horas, e, se quiser, a turma a que se refere. Aparece na mesma semana, com um aspeto tracejado, para não a confundir com uma aula.',
        'As atividades não letivas não geram aulas, sumários nem faltas e não entram na numeração das aulas. São suas: mais nenhum colega as vê. Não podem sobrepor-se umas às outras no mesmo dia e período, mas podem coincidir com uma aula. Entram no backup dos seus dados.',
        'Os grupos de uma turma (T1/T2) continuam a gerir-se na página da turma, que também tem o seu próprio editor do horário.',
    ],
    'keywords' => ['horário', 'horario', 'corrigir', 'editar', 'importar', 'pdf', 'turma errada', 'horas', 'atividade não letiva', 'direção de turma', 'DT', 'apoio', 'reunião', 'coordenação', 'cargo', 'aplicar alteração', 'eliminar aula'],
    'related' => ['lessons.summary', 'classes.create'],
    'contexts' => [],
];
