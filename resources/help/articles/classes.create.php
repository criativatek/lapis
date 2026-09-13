<?php

// resources/help/articles/classes.create.php
//
// Confirmado em App\Http\Controllers\ClassController::create()/store() —
// route 'classes.create', form em resources/js/pages/classes/Create.vue.

return [
    'title' => 'Criar uma turma',
    'summary' => 'O que precisa antes de criar uma turma, e como associar-lhe um perfil de avaliação.',
    'category' => 'Turmas e alunos',
    'order' => 10,
    'content' => [
        'Uma turma pertence sempre a um ano letivo e a uma disciplina — configure-os primeiro, se ainda não o fez.',
        'Ao criar a turma indica um nome (por exemplo, «7.º A»), o ano letivo, a disciplina e, opcionalmente, o ano de escolaridade.',
        'Pode associar logo um perfil de avaliação ATIVO — só um perfil já ativado pode ser associado, nunca um rascunho. Se ainda não tiver um perfil pronto, pode criar a turma sem ele e associá-lo mais tarde, a partir da própria página da turma.',
        'Sem um perfil associado, a turma existe e pode inscrever alunos, mas o Lapispro ainda não sabe como calcular classificações para ela — associe o perfil assim que o tiver pronto.',
        'Se a turma for de apoio, marque «Turma de apoio». Uma turma de apoio pode reunir alunos de várias turmas: em vez de os criar de novo, adiciona os alunos que já estão nas suas turmas, com o mesmo nome e fotografia, e eles continuam também na turma de origem. A opção pode ser alterada depois em «Editar turma»; desligá-la não remove nenhum aluno.',
        'Uma turma de apoio não é o mesmo que os grupos T1/T2. Os grupos dividem os alunos de UMA turma para o horário e continuam a ser a mesma turma; a turma de apoio é outra turma, com alunos, aulas, horário e sumários próprios.',
        'Depois de criada, a turma abre na sua própria página, onde inscreve alunos, um a um ou por importação de pauta, e a partir de onde acede aos elementos de avaliação, resultados e relatórios dessa turma.',
    ],
    'keywords' => ['turma', 'criar turma', 'nova turma', 'ano de escolaridade', 'turma de apoio', 'apoio'],
    'related' => ['assessment.profiles', 'students.enroll', 'students.import'],
    'contexts' => ['classes.create'],
];
