<?php

// resources/help/articles/subjects.manage.php
//
// Confirmado em App\Http\Controllers\SubjectController e
// App\Services\SubjectUsage — route 'subjects.index'.

return [
    'title' => 'Gerir disciplinas',
    'summary' => 'Criar e editar disciplinas, e porque uma disciplina em uso não pode ser eliminada.',
    'category' => 'Turmas e alunos',
    'order' => 5,
    'content' => [
        'As disciplinas são da organização e são geridas pelo responsável: cada uma tem um nome e um código único dentro da organização.',
        'Uma disciplina sem utilização pode ser eliminada no botão do caixote do lixo, com confirmação.',
        'Uma disciplina que já está a ser utilizada por turmas, perfis de avaliação, domínios, sequências de aulas ou estratégias da biblioteca não pode ser eliminada — o botão fica desativado e, se tentar, a mensagem diz onde está a ser utilizada. Nada é apagado para a libertar: turmas, avaliações, horários e relatórios ficam como estão.',
        'Se o nome ou o código estiverem errados, edite a disciplina em vez de a eliminar.',
    ],
    'keywords' => ['disciplina', 'disciplinas', 'eliminar disciplina', 'apagar disciplina', 'código da disciplina'],
    'related' => ['classes.create'],
    'contexts' => ['subjects.index'],
];
