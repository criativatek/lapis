<?php

// resources/help/articles/students.enroll.php
//
// Confirmado em App\Http\Controllers\EnrollmentController::store() — route
// 'classes.students.store', a partir da própria página da turma
// (resources/js/pages/classes/Show.vue).

return [
    'title' => 'Inscrever um aluno numa turma',
    'summary' => 'Como adicionar um aluno individualmente, a partir da página da turma.',
    'category' => 'Turmas e alunos',
    'order' => 20,
    'content' => [
        'A partir da página de uma turma pode inscrever alunos um a um — o nome é o único campo obrigatório.',
        'Pode indicar também o número de turma, a data de inscrição e o número de processo da escola, se os tiver à mão; qualquer um destes pode ser preenchido mais tarde.',
        'Se tiver uma lista inteira de alunos para inscrever de uma vez, em vez de os adicionar um a um veja o artigo sobre importar uma pauta.',
        'Numa turma de apoio aparece também «Adicionar aluno existente». Escreva parte do nome ou o n.º de processo: a pesquisa mostra só alunos das turmas que leciona neste ano letivo, cada um com a sua turma e número, para distinguir alunos com o mesmo nome. Ao escolher, o aluno entra na turma de apoio sem ser criado de novo — é o mesmo aluno, com o mesmo nome e fotografia, e continua na turma de origem.',
        'Se o aluno ainda não está no Lapispro, adicione-o como aluno novo pelo formulário habitual. Um aluno que já pertence à turma não pode ser adicionado duas vezes. Remover um aluno da turma de apoio não o apaga nem o tira da turma de origem.',
        'Um aluno inscrito só entra nas classificações e resultados calculados a partir da data em que foi inscrito — resultados de elementos de avaliação anteriores a essa data não contam para ele.',
    ],
    'keywords' => ['aluno', 'inscrever', 'inscrição', 'novo aluno', 'adicionar aluno', 'turma de apoio', 'apoio'],
    'related' => ['classes.create', 'students.import'],
    'contexts' => ['classes.show'],
];
