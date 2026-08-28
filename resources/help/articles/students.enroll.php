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
        'Um aluno inscrito só entra nas classificações e resultados calculados a partir da data em que foi inscrito — resultados de elementos de avaliação anteriores a essa data não contam para ele.',
    ],
    'keywords' => ['aluno', 'inscrever', 'inscrição', 'novo aluno', 'adicionar aluno'],
    'related' => ['classes.create', 'students.import'],
    'contexts' => ['classes.show'],
];
