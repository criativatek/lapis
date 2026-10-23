<?php

// resources/help/articles/assessment.analysis-document.php
//
// O documento da Análise da Avaliação (Pro) — 0.177.0. Confirmado em
// App\Http\Controllers\AssessmentAnalysisDocumentController (rotas
// 'assessment-analysis.document' e 'assessment-analysis.document.pdf', módulo
// advanced_analytics), App\Services\Assessment\Advanced\Document\BuildAnalysisDocument
// e AnalysisDocumentOptions.
//
// O QUE SE IMPRIME É O QUE SE VÊ, e os nomes só saem quando se pedem: as duas
// coisas estão ditas aqui por extenso, porque um documento em papel sobre alunos
// identificáveis circula sem a página que o explica.

return [
    'title' => 'Imprimir ou guardar em PDF a Análise da Avaliação (Pro)',
    'summary' => 'Como abrir a análise como documento — com avisos, valores, contagens e critérios —, escolher as secções, decidir se leva os nomes dos alunos, imprimir e guardar em PDF.',
    'category' => 'Avaliação',
    'order' => 28,
    'content' => [
        'Na página «Análise da Avaliação», escolha «Documento (imprimir ou PDF) →». Abre a análise que tem no ecrã — os mesmos momentos — como documento: o timbre da escola, a identificação (turma, disciplina, ano letivo, escala e os momentos analisados) e as secções que escolher.',
        'O DOCUMENTO DIZ O QUE O ECRÃ DIZ. É composto a partir dos mesmos números da página, e a pré-visualização, a impressão do browser e o PDF mostram exatamente o mesmo texto: nenhum valor é recalculado nem reescrito para o papel. Cada conclusão leva os valores, as contagens com o total em que assentam («8 de 27 alunos») e os critérios.',
        'OS AVISOS ESTÃO NO TOPO. Antes das secções, o documento reúne o que limita as conclusões: cobertura insuficiente ou parcial, uma comparação que não se pode fazer (critérios ou escala diferentes) e porquê, momentos que ficaram fora da linha de tendências, poucos alunos, ou momentos sem linha entre positivo e negativo. Onde falta valor aparece «—»: não é zero.',
        'AS SECÇÕES. Pode escolher entre a comparação entre momentos, os pontos fortes e dificuldades de um momento e as tendências e resultados persistentes; o documento tem sempre pelo menos uma.',
        'OS NOMES SÓ SAEM QUANDO OS PEDE. Por omissão, o documento só traz contagens, domínios e tendências, sem nomear nenhum aluno. Se ligar «Incluir a lista nominal de alunos», acrescenta as listas com nomes: o aluno de cada comparação, os alunos abaixo do nível positivo em mais de um domínio e a trajetória de cada aluno. É uma cópia sua de resultados de alunos identificáveis; guarde-a e partilhe-a como faria com a pauta.',
        'NÃO É UM DIAGNÓSTICO. O documento descreve notas e momentos. Não indica causas, não faz previsões e não propõe medidas; diz-o no fim.',
        '«Imprimir» imprime a pré-visualização tal como a vê. O PDF é gerado no servidor, descarrega-se com o nome da turma e da data — nunca com o nome de um aluno — e não fica guardado em lado nenhum. Cada PDF exportado fica registado no histórico de auditoria só com a turma, as opções e contagens, nunca com nomes nem notas.',
        'O documento é do plano Pro e é gerado no momento em que o abre, a partir dos dados de então, com a data de emissão. Não é um relatório finalizado: se mudar uma nota e o abrir de novo, sai com o valor novo.',
    ],
    'keywords' => ['imprimir análise', 'análise da avaliação em pdf', 'documento da análise', 'pdf da análise', 'lista nominal', 'nomes dos alunos', 'avisos de cobertura', 'plano Pro', 'análise avançada'],
    'related' => ['assessment.analysis', 'evaluation-sheets.grid-document', 'reports.measures-map'],
    'contexts' => ['assessment-analysis.document'],
];
