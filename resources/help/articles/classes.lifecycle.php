<?php

// resources/help/articles/classes.lifecycle.php
//
// Confirmado em App\Http\Controllers\ClassController::index()/archived()/
// archive()/restore()/destroy() e App\Services\Classes\SchoolClassHistory —
// routes 'classes.index', 'classes.archived', 'classes.show'.

return [
    'title' => 'Arquivar, restaurar ou eliminar',
    'summary' => 'A diferença entre arquivar e eliminar definitivamente, e quando uma turma em preparação pode ser eliminada logo.',
    'category' => 'Turmas e alunos',
    'order' => 15,
    'content' => [
        'A lista «Turmas» mostra só as turmas que não estão arquivadas — ativas e em preparação. As arquivadas têm lista própria: use o botão «Ver turmas arquivadas», e «Voltar às turmas» para regressar.',
        'Arquivar não apaga nada. A turma sai da lista principal, mas todos os dados ficam preservados e pode restaurá-la a qualquer momento na lista de arquivadas; restaurada, volta à lista «Turmas».',
        'Uma turma criada por engano, ainda «Em preparação», pode ser eliminada logo, sem arquivar: na página da turma aparece «Eliminar definitivamente». Só aparece enquanto a turma tiver apenas preparação — alunos sem registos, grupos T1/T2, horário e as aulas geradas desse horário, que são eliminados com ela.',
        'Os alunos não são apagados. Um aluno que está também noutra turma (por exemplo, numa turma de apoio) continua inteiro nessa turma, com o mesmo nome e fotografia.',
        'Se a turma já tiver história — elementos de avaliação, registos, estratégias e medidas, avaliações intercalares, autoavaliações, relatórios, alunos com registos pedagógicos, ou aulas lecionadas, preparadas ou com sumário — não pode ser eliminada: arquive-a. A mensagem diz o que a turma já tem.',
        'Uma turma ativa, ou arquivada, segue a regra de retenção: só pode ser eliminada definitivamente depois de arquivada, três anos após o fim do ano letivo, e apenas se não tiver história pedagógica.',
        'Eliminar definitivamente não pode ser anulado, e é sempre pedida confirmação. Só o professor responsável pela turma o pode fazer.',
    ],
    'keywords' => ['arquivar', 'arquivada', 'turmas arquivadas', 'restaurar', 'eliminar turma', 'apagar turma', 'turma criada por engano', 'em preparação', 'retenção'],
    'related' => ['classes.create', 'students.enroll'],
    'contexts' => ['classes.index', 'classes.archived', 'classes.show'],
];
