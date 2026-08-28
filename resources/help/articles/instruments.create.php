<?php

// resources/help/articles/instruments.create.php
//
// Confirmado em App\Http\Controllers\InstrumentController::create()/store()
// e resources/js/pages/instruments/InstrumentForm.vue — routes
// 'instruments.create'/'instruments.create-picker', módulo instruments.

return [
    'title' => 'Criar um elemento de avaliação',
    'summary' => 'Como criar um teste, ficha ou trabalho, definir as questões e a cotação, e prepará-lo para lançar resultados.',
    'category' => 'Avaliação',
    'order' => 20,
    'content' => [
        'Um elemento de avaliação (teste, ficha, trabalho, ou outro tipo à sua escolha) cria-se dentro de uma turma — pode partir diretamente da turma, ou usar o botão «+ Novo elemento de avaliação» em Elementos de Avaliação e escolher a turma primeiro.',
        'Indica um título, o tipo, a data de aplicação e o período letivo a que pertence.',
        'As questões organizam-se em grupos (por exemplo, «Grupo I», «Grupo II») — se não precisar de grupos, todas as questões ficam no grupo implícito, sem que isso apareça no ecrã.',
        'Cada questão tem uma cotação (em pontos) e é associada a um ou mais domínios do perfil de avaliação da turma — é essa associação que liga os pontos de cada questão ao cálculo por domínio.',
        'Pode guardar como rascunho e continuar mais tarde, ou preparar a grelha de correção diretamente — só uma grelha preparada fica pronta para lançar resultados.',
        'Pode também reutilizar as questões de um elemento de avaliação anterior seu, de uma turma da mesma disciplina, como ponto de partida.',
        'Depois de criado, a grelha de correção do elemento de avaliação é onde regista os resultados de cada aluno.',
    ],
    'keywords' => ['elemento de avaliação', 'teste', 'ficha', 'instrumento', 'grelha de correção', 'cotação', 'questões'],
    'related' => ['assessment.profiles', 'results.record'],
    'contexts' => ['instruments.create'],
];
