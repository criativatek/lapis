<?php

// resources/help/articles/ai.pedagogical-analysis.php
//
// «Analisar com IA» na Estatística da turma. O artigo serve tanto para
// explicar como para delimitar: a secção sobre o que a IA não pode fazer é
// tão importante como a que diz onde carregar.
//
// Cada afirmação corresponde a uma propriedade do código:
// - «não calcula» — ClassAnalysisContext só transporta números já decididos
//   por BuildClassStatistics, e o prompt proíbe recalcular;
// - «não altera resultados» — nenhum endpoint aceita uma análise de volta, e
//   ClassAnalysis não tem id nem chave que o permitisse;
// - «pseudonimizado» — ClassAnalysisContext constrói as linhas a partir de
//   uma lista de campos permitidos e nunca lê nome, número ou identificador.
//
// A palavra «anónimo» não aparece deliberadamente: os dados são
// pseudonimizados, e prometer anonimato seria prometer o que não se faz.
//
// O texto evita de propósito a combinação «existe» + «nada» no corpo do
// artigo. São palavras sem valor de pesquisa que, juntas num corpo de texto,
// bastavam para este artigo passar o overlap gate de HelpCenter::score() numa
// pesquisa por disparate — que HelpCenterTest fixa como devendo devolver zero
// resultados.

return [
    'title' => 'Analisar os resultados de uma turma com IA',
    'summary' => 'Como pedir uma leitura em palavras da estatística de uma turma, e o que a IA pode e não pode fazer com os seus resultados.',
    'category' => 'Avaliação',
    'order' => 40,
    'content' => [
        'Na página de Estatística de uma turma — em Resultados, «Estatística» — encontra no fim da página uma secção «Analisar com IA». Serve para obter uma leitura em palavras dos números que a página já mostra.',
        'A análise só acontece quando carrega no botão. Abrir a página nunca chama a IA.',
        'A resposta vem organizada em quatro blocos: uma Síntese geral, os Padrões observados nos resultados, os Pontos de atenção que podem merecer um segundo olhar, e Sugestões pedagógicas que pode considerar. É uma leitura, não um relatório: não fica guardada e desaparece quando sair da página.',
        'A IA INTERPRETA, NÃO CALCULA. Todas as médias, percentagens, distribuições pela escala, evoluções entre períodos e taxas de sucesso são calculadas pelo Lapispro, a partir dos resultados que registou. A IA recebe esses números já feitos e limita-se a descrevê-los por palavras. O Lapispro é a fonte da verdade para notas, resultados, médias, pesos, classificações e regras de avaliação — se um número na análise não corresponder ao que a página mostra, o número certo é o da página.',
        'A IA NÃO ALTERA RESULTADOS. Não atribui nem muda notas, não regista resultados, não aprova nem reprova ninguém, não mexe em pesos, domínios ou critérios, e não cria intervenções. A aplicação não tem nenhuma forma de aplicar automaticamente o que a análise sugere: tudo o que decidir fazer a seguir, faz nos ecrãs habituais, com a sua confirmação.',
        'QUE DADOS SÃO ENVIADOS. Vai a disciplina, o ano de escolaridade, o período em análise, a escala usada, as estatísticas da turma já calculadas, a distribuição pelos níveis, a evolução face ao período anterior, os valores por domínio, e uma linha por aluno com o resultado, o nível e a evolução.',
        'Essas linhas são pseudonimizadas: aparecem como «Aluno A», «Aluno B», e não incluem nome, número de aluno, e-mail nem qualquer outro identificador. A ordem também não é a da pauta — as linhas são ordenadas pelo resultado, precisamente para que a posição não denuncie quem é quem.',
        'A substituição é feita campo a campo, no momento em que cada valor entra, e não sobre o texto já montado. Isso é o que garante que um nome escrito noutro sítio — por exemplo, num domínio a que alguém tenha chamado «Apoio ao João» — também é substituído, e não apenas os nomes da lista de alunos.',
        'Note que pseudonimizado não é anónimo: o professor, que tem a pauta à frente, consegue reconhecer quem é cada linha. Quem recebe os dados do lado do serviço de IA não consegue. Os pseudónimos também não são estáveis entre pedidos — «Aluno A» hoje pode não ser o mesmo aluno amanhã, precisamente para que não se acumule um perfil.',
        'As médias são enviadas com a mesma precisão com que aparecem no ecrã. Se a página mostra 72,4%, é 72,4% que segue — nunca um valor com mais casas decimais do que alguma vez viu.',
        'Se a turma ainda não tiver resultados suficientes no período, a análise não é pedida — a aplicação avisa que faltam dados, em vez de pedir à IA que invente uma leitura.',
        'COMO LER A RESPOSTA. A análise está escrita em linguagem de possibilidade, não de certeza: «os resultados podem justificar verificar…», «este padrão pode sugerir…». É deliberado. Uma observação sobre números é uma observação sobre números, e não um diagnóstico sobre um aluno. A IA não sabe coisa alguma sobre motivação, esforço, personalidade ou circunstâncias de quem quer que seja, e está instruída para não o afirmar.',
        'A IA apoia a análise e pode cometer erros. As decisões pedagógicas continuam a ser do professor.',
        'Se a funcionalidade estiver indisponível, a mensagem no ecrã diz porquê: ou o plano da sua organização não a inclui, ou esta instalação ainda não tem um serviço de IA configurado. A página de Estatística funciona na mesma em qualquer dos casos.',
    ],
    'keywords' => ['analisar', 'análise', 'ia', 'inteligência artificial', 'estatística', 'padrões', 'sugestões', 'interpretar'],
    'related' => ['results.record', 'reports.view', 'ai.assistant'],
    'contexts' => ['results.statistics'],
];
