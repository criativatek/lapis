<?php

// resources/help/articles/assessment.analysis.php
//
// Análise da Avaliação (Pro) — 0.177.0. Confirmado em
// App\Http\Controllers\AssessmentAnalysisController (rota 'assessment-analysis.show',
// módulo advanced_analytics), App\Services\Assessment\Advanced\BuildAssessmentComparison,
// BuildClassStrengths, BuildClassTrends e TrajectoryReading.
//
// O QUE É, O QUE NÃO É E QUANDO SE CALA ESTÁ DITO POR EXTENSO DE PROPÓSITO. Uma
// leitura da avaliação ao longo do tempo é fácil de tomar por um veredicto: este artigo diz
// que descreve notas e momentos, que uma tendência (3 momentos) e um resultado abaixo do nível
// positivo persistente (2 momentos) são perguntas diferentes, e que nada é diagnóstico,
// previsão ou recomendação.

return [
    'title' => 'Comparar momentos, tendências e pontos fortes da turma (Pro)',
    'summary' => 'Comparar dois momentos, ver os pontos fortes e as dificuldades de um momento e acompanhar tendências e resultados abaixo do nível positivo persistentes — com os valores e as contagens que sustentam cada leitura.',
    'category' => 'Avaliação',
    'order' => 27,
    'content' => [
        'A «Análise da Avaliação» é uma página do plano Pro. Abre-se a partir da Estatística da turma, em «Comparar momentos →». Tem três secções: comparar dois momentos, pontos fortes e dificuldades de um momento, e tendências e resultados persistentes. Todas leem os mesmos resultados que já vê em Resultados e na Pauta; não calculam notas novas.',
        'COMPARAR DOIS MOMENTOS. Escolha o ponto de partida e o ponto de chegada: períodos (calculados com os dados de hoje) ou momentos que guardou na Pauta (fotografias do que então era verdade, que nunca mudam). Vê a evolução da turma, domínio a domínio, e quantos alunos progrediram, mantiveram-se ou regrediram. A evolução da turma é a dos mesmos alunos nos dois momentos — os que têm resultado em ambos —, para a média não mudar só porque um aluno falta num dos extremos. «Progrediu» é qualquer subida na casa decimal mostrada, a mesma regra de Resultados e da Estatística; a variação vem em pontos percentuais (p.p.), não em percentagem da percentagem.',
        'Também vê as mudanças de nível — do resultado e do nível atribuído por si —, na ordem da escala e atravessando, ou não, a linha entre positivo e negativo que a sua escola definiu.',
        'QUANDO NÃO SE COMPARA. Se os domínios, os pesos ou a escala mudaram entre os dois momentos, os números aparecem lado a lado e não há variação: a diferença seria de regra, não dos alunos. A página diz sempre porquê. Um aluno com resultado só num dos momentos aparece como «sem comparação» — um resultado em falta não é zero.',
        'PONTOS FORTES E DIFICULDADES. Escolha um momento — não precisa de um segundo. A página mostra, com a escala e os critérios desse momento, a média de cada domínio, quantos alunos têm valor e quantos estão abaixo do nível positivo («8 de 27 alunos»), e a cobertura. Destaca o domínio de média mais alta e o de média mais baixa; se dois domínios empatam, nenhum é destacado. Os «pontos fortes» e os «resultados a observar» são descrições dos números ao lado, cada uma com a sua evidência. Se o momento é uma fotografia anterior a guardar a escala, não se sabe onde ficava a linha entre positivo e negativo, e a página não conta alunos abaixo dela.',
        'TENDÊNCIAS E RESULTADOS PERSISTENTES. São duas perguntas diferentes. Uma tendência precisa de pelo menos três momentos comparáveis com valor: «progressão consistente» se todos os passos subiram, «regressão consistente» se todos desceram, «estável» se são todos iguais, «evolução variável» em qualquer outro caso. Um resultado abaixo do nível positivo persistente precisa de dois: abaixo do nível positivo no momento mais recente e no anterior. Um momento sem valor, ou com critérios ou escala diferentes, interrompe a sequência — a aplicação não adivinha o que aconteceu aí. Os momentos fora da linha aparecem com o motivo.',
        'ESTES RESULTADOS NÃO SÃO UM DIAGNÓSTICO. «Abaixo do nível positivo» descreve uma nota numa escala; não diz porquê e não é um diagnóstico de dificuldades de aprendizagem. A página não sugere causas, não faz previsões e não propõe medidas: a decisão pedagógica é sua.',
        'A página diz de onde vêm os dados (período «calculado com os dados de hoje» ou momento «guardado a …»), a cobertura de cada momento, e avisa quando há poucos alunos. As listas com nomes — alunos abaixo do nível positivo em mais de um domínio, trajetória de cada aluno — são só para os professores da turma.',
        'Para levar a análise para o papel, use «Documento (imprimir ou PDF) →»: é gerado na hora e não fica guardado.',
    ],
    'keywords' => ['análise da avaliação', 'comparar momentos', 'comparar períodos', 'pontos fortes', 'dificuldades da turma', 'tendência', 'tendências', 'resultado negativo persistente', 'abaixo do nível positivo', 'progrediu', 'regrediu', 'evolução da turma', 'pontos percentuais', 'p.p.', 'plano Pro', 'análise avançada'],
    'related' => ['assessment.analysis-document', 'assessment.year-reading', 'evaluation-sheets.grid-document', 'results.record'],
    'contexts' => ['assessment-analysis.show'],
];
