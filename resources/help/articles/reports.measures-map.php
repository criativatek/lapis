<?php

// resources/help/articles/reports.measures-map.php
//
// Mapa de Medidas Educativas — 0.173.0. Confirmado em
// App\Http\Controllers\Reports\ClassMeasuresMapController (routes
// 'reports.measures-map.*') e App\Services\Reporting\ClassDocuments\ClassMeasuresMap.
//
// O CRITÉRIO ESTÁ ESCRITO AQUI POR EXTENSO DE PROPÓSITO. «Implementada» e «a
// implementar» não são estados que o professor escolha: são a leitura que o
// mapa faz dos estados reais de Estratégias e Medidas (MeasureMapColumn). Um
// professor que vê uma medida importada da caracterização em «A implementar»
// precisa de saber que é o estado «Planeada» que a põe lá — e onde o muda.

return [
    'title' => 'Mapa de Medidas Educativas',
    'summary' => 'Um documento gerado na hora com os alunos que têm medidas registadas, separadas entre implementadas e a implementar, para imprimir ou guardar em PDF.',
    'category' => 'Relatórios e pautas',
    'order' => 20,
    'content' => [
        'O Mapa de Medidas Educativas abre-se em Relatórios, no bloco «Documentos da turma». Escolha a turma e o mapa é gerado de imediato, a partir do que está registado em Estratégias e Medidas.',
        'Cada aluno com pelo menos uma medida aparece uma única vez, pela ordem do número na turma, com duas colunas: «Implementadas» e «A implementar». Os alunos sem medidas não aparecem. No topo, um resumo conta os alunos da turma, os que têm medidas e as medidas de cada coluna — são apenas contagens, sem leitura nem interpretação.',
        'O CRITÉRIO VEM DO ESTADO DE CADA MEDIDA. Implementadas são as medidas em curso, concluídas ou suspensas — o estado aparece ao lado de cada uma. A implementar são as medidas planeadas. As medidas canceladas não entram no mapa.',
        'Uma medida registada fica «Planeada» até a marcar como «Em curso» em Estratégias e Medidas — incluindo as que foram associadas a partir da Caracterização pedagógica ou importadas do ficheiro da escola. Se uma medida que já está a ser aplicada aparece em «A implementar», é aí que se corrige; o mapa tem uma ligação direta para lá.',
        'As medidas dirigidas a toda a turma aparecem num bloco próprio, uma só vez, e não são repetidas em cada aluno. Uma medida de grupo aparece em cada aluno do grupo, assinalada «em grupo». Nas medidas de suporte do enquadramento legal, o mapa indica o nível — universal, seletiva ou adicional — tal como ficou registado.',
        'Só entram as medidas que estão marcadas como disponíveis para relatórios: é a escolha de cada professor sobre o que pode sair da sala de aula. Quando alguma ficou de fora por esse motivo, o mapa diz quantas.',
        'O mapa identifica cada aluno apenas pelo número e pelo nome. Não inclui datas de nascimento, números de processo, descrições, motivos, objetivos nem notas de acompanhamento.',
        'Pode mostrar só as implementadas ou só as medidas a implementar. «Imprimir» imprime o documento tal como o vê no ecrã; «PDF» descarrega o mesmo conteúdo em A4.',
        'O mapa não fica guardado: é gerado a partir dos registos no momento em que o abre e leva impressa a data de emissão. Não é um relatório finalizado — para um documento que fica congelado, crie um relatório em «Novo relatório».',
    ],
    'keywords' => ['mapa de medidas', 'medidas educativas', 'medidas implementadas', 'medidas a implementar', 'medidas de suporte', 'imprimir medidas', 'medidas em pdf', 'estratégias e medidas'],
    'related' => ['reports.view', 'classes.characterisation.measures'],
    'contexts' => ['reports.measures-map.index', 'reports.measures-map.show'],
];
