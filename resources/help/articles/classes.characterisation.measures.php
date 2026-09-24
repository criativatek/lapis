<?php

// resources/help/articles/classes.characterisation.measures.php
//
// Medidas associadas na Caracterização pedagógica — Janela P.
//
// ESCRITO DEPOIS DA JANELA P: até aqui, o cartão de cada aluno mostrava um
// bloco «Medidas de origem» que só listava o que uma importação tinha lido do
// ficheiro da escola. Uma medida registada à mão em Estratégias e Medidas não
// aparecia ali — a mesma informação vivia, sem se saber, em dois lugares que
// podiam divergir. Agora há um único conjunto de medidas associadas, lido das
// mesmas Interventions estruturadas que Estratégias e Medidas usa, com as
// duas proveniências possíveis (importação ou registo manual) sempre visíveis
// lado a lado.

return [
    'title' => 'Medidas associadas na Caracterização pedagógica',
    'summary' => 'O que é uma medida associada, como se distingue de um apoio ou de uma observação, e porque aparece o mesmo registo em Estratégias e Medidas.',
    'category' => 'Caracterização',
    'order' => 12,
    'content' => [
        'No cartão de cada aluno, dentro de Caracterização pedagógica, há uma secção «Medidas associadas». Não é um campo de texto livre: cada linha é uma medida de apoio concreta do enquadramento legal em vigor, com o seu nível — universal, seletiva ou adicional — e o seu código.',
        'UMA MEDIDA ASSOCIADA AQUI É A MESMA COISA QUE UMA MEDIDA EM ESTRATÉGIAS E MEDIDAS. Não há duas listas. Adicionar uma medida a partir deste cartão cria o mesmo registo estruturado (uma Intervenção com uma medida de apoio) que o formulário de Estratégias e Medidas cria — por isso aparece nos dois sítios, e editar o estado ou acompanhar a eficácia dessa medida faz-se em Estratégias e Medidas, como sempre fez.',
        'Para associar uma medida, abra o cartão do aluno e escolha «Adicionar medida». A lista de medidas disponíveis vem do enquadramento legal aplicável hoje à sua organização — o mesmo catálogo que já vê em Estratégias e Medidas. O nível de cada medida é sempre determinado pela lei, nunca escolhido à mão.',
        'Uma medida só pode estar associada uma vez a cada aluno enquanto estiver ativa. Se tentar adicionar uma que já lá está, a aplicação avisa e não duplica.',
        'A PROVENIÊNCIA FICA SEMPRE VISÍVEL. Por baixo de cada medida associada lê-se se foi registada manualmente ou se veio de uma importação da caracterização — e, neste segundo caso, o texto exato que o ficheiro da escola indicava para essa medida, para que se possa confirmar a leitura a qualquer momento.',
        'MEDIDA, APOIO E OBSERVAÇÃO SÃO TRÊS COISAS DIFERENTES. Uma medida associada é uma medida de apoio do enquadramento legal — com nível e código. Um apoio ou recurso como o CRI (Centro de Recursos para a Inclusão) não é uma medida: é onde o aluno é apoiado, não uma classificação legal, e por isso nunca aparece nesta lista nem é convertido numa. Da mesma forma, um percurso curricular como o PLNM (Português Língua Não Materna) não é uma medida de apoio. E um texto solto sobre o aluno — nos campos «Pontos fortes», «Interesses», «Necessidades», «Barreiras» ou «Participação» — é observação pedagógica, não uma medida: fica onde sempre esteve, nesses campos de texto, e não entra na lista de medidas associadas.',
        'Quando uma turma tem uma caracterização importada de um ficheiro da escola, as siglas que o ficheiro usava e que a aplicação não conseguiu ligar a uma medida concreta continuam a aparecer como notas por resolver, separadas da lista de medidas — nunca inventadas como se fossem uma medida.',
    ],
    'keywords' => ['caracterização', 'caracterização pedagógica', 'medida', 'medidas', 'medida associada', 'medidas associadas', 'apoio', 'CRI', 'PLNM', 'nível', 'proveniência', 'importação'],
    'related' => ['ai.strategies'],
    'contexts' => ['classes.characterisation.show'],
];
