<?php

// resources/help/articles/getting-started.php
//
// «Começar a utilizar o Lapispro» — a orientação geral, com a ordem real
// confirmada em DashboardController::readiness()/firstSteps(): ano letivo →
// disciplinas → perfil de avaliação → turma → perfil associado, e depois
// aluno → instrumento → resultado → relatório. É a mesma sequência que o
// cartão «Primeiros passos» do dashboard já segue (A1) — este artigo explica
// por palavras o que aquele cartão mostra por passos.

return [
    'title' => 'Começar a utilizar o Lapispro',
    'summary' => 'A ordem pela qual configurar o Lapispro pela primeira vez, do ano letivo ao primeiro relatório.',
    'category' => 'Começar',
    'order' => 10,
    'content' => [
        'O Lapispro organiza-se à volta de um ano letivo. É por aí que se começa, e é dentro dele que tudo o resto — disciplinas, perfis de avaliação, turmas, resultados — passa a fazer sentido.',
        '1. Crie e ative o ano letivo. Sem um ano letivo ativo, o dashboard não sabe a que período pertence o resto do que fizer.',
        '2. Configure as disciplinas que leciona. Cada turma pertence a uma disciplina, por isso as disciplinas vêm antes das turmas.',
        '3. Crie ou reutilize um perfil de avaliação para este ano. É aqui que define os domínios que avalia e a ponderação de cada um — a base sobre a qual as classificações são calculadas.',
        '4. Crie a sua primeira turma, associada a uma disciplina e a este ano letivo.',
        '5. Associe um perfil de avaliação à turma. Sem perfil associado, a turma existe mas o Lapispro ainda não sabe como calcular classificações para ela.',
        '6. Inscreva os alunos na turma — um a um, ou todos de uma vez através de uma importação de pauta, se a tiver disponível em formato Excel.',
        '7. Crie o primeiro elemento de avaliação (um teste, uma ficha, um trabalho) e prepare a grelha de correção.',
        '8. Registe os resultados dos alunos nesse elemento de avaliação.',
        '9. Consulte o relatório ou a pauta da turma — é onde os resultados registados se juntam às classificações e, mais tarde, a um documento pronto a exportar.',
        'Não precisa de terminar todos os passos de uma só vez. O dashboard mostra sempre qual é o próximo passo em falta, e o cartão «Primeiros passos» acompanha o mesmo progresso até o dispensar.',
    ],
    'keywords' => ['começar', 'primeiros passos', 'introdução', 'onboarding', 'ano letivo', 'configuração inicial'],
    'related' => ['classes.create', 'assessment.profiles', 'instruments.create', 'results.record'],
    'contexts' => [],
];
