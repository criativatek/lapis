<?php

// resources/help/articles/assessment.year-reading.php
//
// «Ao longo do ano» na Evolução do Aluno (Pro) — 0.177.0. Confirmado em
// App\Http\Controllers\StudentProgressController (prop yearReading, só com
// advanced_analytics) e App\Services\Assessment\Advanced\BuildStudentYear.
//
// OUTRA LEITURA, DITA: a secção usa o resultado de cada período por si, e não a
// «leitura contínua» da página. E o que descreve são notas — nunca um diagnóstico.

return [
    'title' => 'Ver um aluno «Ao longo do ano» (Pro)',
    'summary' => 'Como comparar um aluno em dois momentos à sua escolha, ver cada domínio ao longo do ano e perceber o que são uma tendência e um resultado abaixo do nível positivo persistente.',
    'category' => 'Avaliação',
    'order' => 29,
    'content' => [
        'Na página «Evolução do Aluno», o plano Pro acrescenta a secção «Ao longo do ano», depois de «Em que domínios». Mostra o aluno em dois momentos à sua escolha — períodos ou momentos que guardou na Pauta — e cada domínio em todos os momentos. Só aparecem os momentos em que este aluno tem resultado; por omissão, os dois últimos.',
        'QUE RESULTADO É ESTE. A secção usa o resultado do próprio período, o de Resultados. A «leitura contínua» da página junta os períodos; por isso os números podem ser diferentes e nenhum está errado. O que a página já diz — «Como evoluiu» e «Em que domínios» — não se repete: aqui escolhe quaisquer dois momentos, vê a mudança de nível e vê os limites de comparabilidade.',
        'VÊ O resultado global e o nível (e o nível que atribuiu), com a variação em pontos percentuais; cada domínio, com os dois valores, a variação e a mudança de nível; e um gráfico com o resultado e os domínios em cada momento (círculo: fim de período; quadrado: momento guardado). Um momento sem valor é uma lacuna no gráfico, nunca um zero. Os momentos com critérios diferentes dos do último ficam fora do gráfico, e a página diz quais.',
        'QUANDO NÃO SE COMPARA. Se os domínios, os pesos ou a escala mudaram entre os dois momentos, os números aparecem lado a lado e não há variação; um valor em falta num dos extremos é «sem comparação». A secção diz sempre de onde vêm os dados («calculado com os dados de hoje» ou «guardado a …») e avisa quando um resultado assenta em avaliação parcial.',
        'TENDÊNCIAS E RESULTADOS ABAIXO DO NÍVEL POSITIVO. No fim da secção há duas leituras diferentes. Uma tendência precisa de três momentos comparáveis com valor: progressão consistente, regressão consistente, estável ou evolução variável, a partir dos passos entre momentos seguidos, que a página mostra. Estar abaixo do nível positivo em momentos seguidos, incluindo o mais recente, precisa de dois, e a página mostra os valores e os momentos. Um momento sem valor ou com critérios ou escala diferentes interrompe a sequência.',
        'ISTO NÃO É UM DIAGNÓSTICO. Uma descida ou um resultado abaixo do nível positivo são dois números numa escala: não explicam causas, não fazem previsões e não propõem medidas. A leitura do aluno é só para os professores da turma e não leva nenhum colega nem contagens deles.',
    ],
    'keywords' => ['ao longo do ano', 'evolução do aluno', 'aluno em dois momentos', 'tendência do aluno', 'resultado negativo persistente', 'abaixo do nível positivo', 'plano Pro', 'análise avançada'],
    'related' => ['assessment.analysis', 'records.followup', 'results.record'],
    'contexts' => ['student-progress.student'],
];
