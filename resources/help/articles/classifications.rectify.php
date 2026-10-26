<?php

// resources/help/articles/classifications.rectify.php
//
// Confirmado em App\Services\Assessment\RectifyClassification, no diálogo
// «Retificar» de resources/js/pages/classifications/Show.vue e de
// resources/js/pages/results/Show.vue — rotas 'classifications.show' e 'results.show'.

return [
    'title' => 'Retificar uma classificação publicada',
    'summary' => 'Como corrigir uma classificação que já foi publicada, e o que fica registado.',
    'category' => 'Avaliação',
    'order' => 35,
    'content' => [
        'Retificar é corrigir uma classificação que já foi publicada. Uma classificação publicada não se edita: ao retificar, o Lapispro cria uma classificação nova, já publicada, e a anterior fica guardada no histórico, marcada como substituída. Nunca se perde o que estava antes.',
        'Para retificar, clique em «Retificar» na linha do aluno, em Classificações ou em Resultados. O botão só aparece nas classificações publicadas e só a quem pode fazer a retificação.',
        'Escolha o novo valor e indique o motivo. O motivo é obrigatório e fica no histórico. Se escolher «Outro», tem de acrescentar uma nota a explicá-lo. O novo valor tem de ser diferente do atual.',
        'Pode ver o histórico da classificação no próprio diálogo: o valor de cada momento, quem retificou, quando e porquê. Se duas pessoas tentarem retificar a mesma classificação ao mesmo tempo, só a primeira é aplicada; a outra é avisada para recarregar a página.',
        'Depois de o ano letivo ser encerrado, é pedida uma confirmação adicional antes de retificar.',
        'A retificação muda apenas a classificação atribuída. Não altera as pontuações, os elementos de avaliação, a escala nem os critérios, e a proposta original mantém-se como estava. Também não altera a classificação acumulada, que continua a ser calculada a partir dos resultados.',
        'Atenção: o Lapispro só corrige o que está no Lapispro. Se já comunicou a classificação anterior noutro sítio, por exemplo no Inovar, numa pauta impressa ou num PDF, tem de atualizar esses registos à mão.',
    ],
    'keywords' => ['retificar', 'retificação', 'corrigir classificação', 'classificação publicada', 'alterar classificação', 'erro de lançamento', 'histórico'],
    'related' => ['results.record', 'evaluation-sheets.grid-document'],
    'contexts' => ['classifications.show', 'results.show'],
];
