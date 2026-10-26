<?php

// resources/help/articles/classifications.rectifications-page.php
//
// Confirmado em App\Http\Controllers\ClassificationRectificationController e em
// resources/js/pages/classifications/rectifications/{Index,Show}.vue — rotas
// 'classifications.rectifications.index' e 'classifications.rectifications.show'.

return [
    'title' => 'Retificações: a página do responsável da instituição',
    'summary' => 'Como o responsável da instituição retifica classificações publicadas de turmas que não leciona.',
    'category' => 'Avaliação',
    'order' => 36,
    'content' => [
        'A página «Retificações», no menu «Instituição», é para o responsável da instituição. Permite retificar classificações já publicadas, mesmo de turmas que não leciona. Só aparece a quem é responsável de uma instituição.',
        'A lista mostra as turmas que têm classificações publicadas num período encerrado, com os professores da turma e, para cada período, quantas classificações estão publicadas e quantas já foram retificadas. Os períodos que ainda estão abertos não aparecem: enquanto estiverem abertos, as classificações são do professor.',
        'Ao abrir um período, vê o número, o nome do aluno, a classificação publicada e a data da publicação. Não vê médias, propostas, autoavaliações, pontuações nem observações. Para retificar, clique em «Retificar» na linha do aluno, escolha o novo valor, indique o motivo e confirme. O marcador ao lado da classificação mostra o histórico, quando já houve retificações.',
        'Cada vez que abre a tabela de uma turma que não leciona, a consulta fica registada na auditoria da organização. As retificações ficam também no histórico da classificação.',
        'Se uma turma não tem nenhum professor ativo e o período ainda está aberto, não é possível retificar: atribua primeiro um professor à turma, em «Turmas a Reatribuir».',
    ],
    'keywords' => ['retificações', 'responsável', 'instituição', 'turmas de outros professores', 'período encerrado', 'retificar classificação', 'consulta registada'],
    'related' => ['classifications.rectify'],
    'contexts' => ['classifications.rectifications.index', 'classifications.rectifications.show'],
];
