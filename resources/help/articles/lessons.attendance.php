<?php

// resources/help/articles/lessons.attendance.php
//
// Confirmado em App\Actions\Lessons\MarkLessonAsTaught, RecordLessonAttendance,
// CorrectLessonAttendance, App\Services\Lessons\LessonAttendanceRoster,
// App\Actions\Lessons\MarkLessonsAsTaughtInBatch e
// resources/js/components/lessons/LessonAttendanceList.vue.

return [
    'title' => 'Registar faltas numa aula',
    'summary' => 'Assinalar só quem faltou; ao marcar a aula como lecionada, os restantes ficam presentes.',
    'category' => 'Aulas e sumários',
    'order' => 20,
    'content' => [
        'Em «Aulas», abra a aula. Logo a seguir ao sumário está a área «Assiduidade», com a fotografia, o número e o nome de cada aluno da aula.',
        'Só precisa de assinalar quem faltou. Não é preciso marcar ninguém como presente.',
        'Enquanto a aula não está lecionada, as faltas assinaladas são um rascunho: «Guardar» guarda-as juntamente com o sumário, e pode desmarcá-las à vontade. Nesta fase, um aluno sem falta assinalada ainda não conta como presente.',
        'Ao carregar em «Marcar como lecionada», a assiduidade fica registada: quem tem falta assinalada fica com Falta, e todos os outros alunos da aula ficam com Presente. Não há um botão extra para confirmar.',
        'Depois de registada, pode corrigir a qualquer momento, aluno a aluno — de Presente para Falta ou de Falta para Presente — sem voltar a mudar o estado da aula. Cada correção fica registada.',
        'Numa aula da turma inteira aparecem os alunos inscritos na turma no dia da aula. Um aluno que entrou depois dessa data não aparece nas aulas anteriores.',
        'Numa aula de um grupo (por exemplo T1 ou T2) aparecem apenas os alunos que pertenciam a esse grupo no dia da aula. Os alunos do outro grupo não aparecem.',
        'Numa turma de apoio funciona da mesma forma: aparecem os alunos inscritos nessa turma de apoio no dia da aula.',
        '«Marcar lecionadas» em lote regista a assiduidade apenas das aulas onde já assinalou faltas. As outras ficam lecionadas com «Assiduidade por registar» — abra cada uma e use «Registar assiduidade». O Lapispro não presume que todos estiveram presentes.',
        'Aulas lecionadas antes de existir esta funcionalidade aparecem como «Assiduidade não registada». Não são convertidas em presenças.',
        'A assiduidade de cada aluno aparece em «Evolução», na página do aluno, na secção «Assiduidade», com a data, a disciplina, a turma e a aula. Aparece também nos relatórios da turma e do aluno, na secção «Assiduidade», para o período do relatório. Em todo o lado distingue-se Presente, Falta e Assiduidade não registada.',
        'Só conta assiduidade consolidada — a que ficou registada ao marcar a aula como lecionada. Um rascunho de faltas numa aula ainda não fechada nunca conta nos totais.',
        'Numa aula marcada como «Não houve aula — Professor ausente», a assiduidade não se aplica: um rascunho de faltas que já existisse é descartado, e deixa de se poder registar assiduidade nessa aula. Numa aula marcada como «Turma em outras atividades letivas» a assiduidade também não se aplica (ver «Escrever o sumário de uma aula»).',
        'Nesta versão regista-se só Presente ou Falta: não há faltas justificadas, atrasos nem comunicação a encarregados de educação. A assiduidade não é enviada a nenhum serviço de inteligência artificial.',
    ],
    'keywords' => ['faltas', 'falta', 'assiduidade', 'presenças', 'presente', 'ausência', 'faltou', 'lecionada', 'aula', 't1', 't2', 'grupo', 'apoio', 'não houve aula', 'professor ausente'],
    'related' => ['lessons.summary', 'classes.create'],
    'contexts' => ['lessons.show', 'lessons.index', 'student-progress.student'],
];
