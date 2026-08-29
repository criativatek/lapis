<?php

// resources/help/articles/ai.assessment.php
//
// «Analisar a avaliação com IA» — a leitura dos Resultados de um período.
//
// O ARTIGO IRMÃO É ai.pedagogical-analysis, E A DIFERENÇA É O ECRÃ. Aquele
// descreve a leitura da Estatística da turma (distribuição, evolução, taxa de
// sucesso); este descreve a leitura da grelha de Resultados (por domínio, por
// aluno, com os avisos de cobertura). São dois modelos de leitura diferentes,
// em duas páginas diferentes, com duas capacidades comerciais diferentes — e o
// artigo diz isso explicitamente, porque um professor que leia só um deles
// ficaria à espera do outro.
//
// Cada afirmação corresponde a uma propriedade do código:
// - «não calcula» — ResultsAnalysisContext transporta só números já decididos
//   por ClassResultsCalculator, e o prompt proíbe recalcular;
// - «não altera resultados» — nenhum endpoint aceita uma análise de volta, e
//   ResultsAnalysis não tem id nem chave que o permitisse;
// - «pseudonimizado» — ResultsAnalysisContext constrói as linhas a partir de
//   uma lista de campos permitidos e nunca lê nome, número ou identificador;
// - «cobertura parcial» — o prompt proíbe descrever como forte ou frágil um
//   domínio cuja cobertura seja parcial.
//
// A palavra «anónimo» não aparece de propósito: os dados são pseudonimizados,
// e prometer anonimato seria prometer o que não se faz.

return [
    'title' => 'Analisar a avaliação de um período com IA',
    'summary' => 'Como pedir uma leitura em palavras dos resultados de um período, o que a IA pode dizer sobre eles, e o que nunca faz.',
    'category' => 'Avaliação',
    'order' => 41,
    'content' => [
        'Na página de Resultados de uma turma, por baixo da grelha, existe a secção «Analisar a avaliação com IA». Serve para obter uma leitura em palavras dos resultados que a página já mostra: o que correu bem, onde a evidência é sólida, onde é escassa, e o que pode considerar a seguir.',
        'A análise só acontece quando carrega no botão. Abrir a página nunca chama a IA.',
        'A resposta vem organizada em seis blocos: uma Síntese, os Padrões observados nos resultados, os domínios em que a evidência é sólida, os Pontos de atenção, Sugestões pedagógicas, e as Limitações desta leitura. É uma leitura, não um documento: não fica guardada e desaparece quando sair da página.',
        'ISTO É DIFERENTE DE «ANALISAR COM IA» NA ESTATÍSTICA DA TURMA. As duas leituras existem e respondem a perguntas diferentes. A da Estatística olha para a turma como um todo — a distribuição pelos níveis, a evolução entre períodos, a taxa de sucesso. Esta olha para a grelha de Resultados: os valores por domínio, a dispersão entre alunos, a autoavaliação ao lado da evidência, e sobretudo a cobertura. Se quer perceber «como está a turma», use a da Estatística; se quer perceber «como correu esta avaliação», use esta.',
        'COBERTURA PARCIAL É A COISA MAIS IMPORTANTE QUE ESTA LEITURA SABE. Um resultado marcado com cobertura parcial assenta apenas em parte dos elementos previstos — é um resultado provisório, não um resultado mau. A IA está instruída para nunca descrever um domínio nessas condições como forte nem como frágil, apenas como ainda sem evidência suficiente, e para o dizer no bloco «Limitações desta leitura». Ausência de resultado não é zero e não é insucesso.',
        'Se o período ainda tiver menos de três alunos com resultado, a análise não é pedida de todo: a aplicação avisa que faltam elementos, em vez de pedir à IA que invente uma leitura sobre dois números.',
        'A IA INTERPRETA, NÃO CALCULA. Todos os valores — médias ponderadas, resultados por domínio, propostas, evoluções — são calculados pelo Lapispro a partir dos resultados que registou. A IA recebe esses números já feitos e limita-se a descrevê-los por palavras. O Lapispro é a fonte da verdade para notas, resultados, médias, pesos, classificações e regras de avaliação — se um número na análise não corresponder ao que a página mostra, o número certo é o da página.',
        'A IA NÃO ALTERA RESULTADOS. Não atribui nem muda notas, não regista resultados, não aprova nem reprova ninguém, não mexe em pesos, domínios ou critérios, e não comenta a classificação que decidiu. A aplicação não tem nenhuma forma de aplicar automaticamente o que a análise sugere: tudo o que decidir fazer a seguir, faz nos ecrãs habituais, com a sua confirmação.',
        'QUE DADOS SÃO ENVIADOS. Vai a disciplina, o ano de escolaridade, o período em análise, a escala, os nomes dos domínios avaliados, quantos alunos têm resultado e quantos resultados têm cobertura parcial, e uma linha por aluno com o resultado, o nível, a evolução, a autoavaliação registada, a classificação que já decidiu e os valores por domínio.',
        'Essas linhas são pseudonimizadas: aparecem como «Aluno A», «Aluno B», e não incluem nome, número de aluno, número de processo, e-mail nem qualquer outro identificador. A ordem também não é a da pauta — as linhas são ordenadas pelo resultado, precisamente para que a posição não denuncie quem é quem.',
        'Os valores são enviados com a mesma precisão com que aparecem no ecrã. Se a página mostra 72,4%, é 72,4% que segue — nunca um valor com mais casas decimais do que alguma vez viu.',
        'COMO LER A RESPOSTA. A análise está escrita em linguagem de possibilidade, não de certeza: «os resultados podem justificar verificar…», «este padrão pode sugerir…». É deliberado. Uma observação sobre números é uma observação sobre números, e não um diagnóstico sobre um aluno.',
        'Sobre a autoavaliação, a IA pode assinalar que a perceção registada por um aluno e a evidência disponível podem não coincidir. Não pode concluir daí seja o que for sobre o aluno — nem que se sobrestima, nem que se subestima. Serve como ponto de partida para uma conversa, não como conclusão.',
        'A IA apoia a análise e pode cometer erros. As decisões pedagógicas continuam a ser do professor.',
        'Se a funcionalidade estiver indisponível, a mensagem no ecrã diz porquê: ou o plano da sua organização não a inclui, ou esta instalação ainda não tem um serviço de IA configurado. A grelha de Resultados funciona na mesma em qualquer dos casos.',
    ],
    'keywords' => ['analisar', 'análise', 'avaliação', 'avaliar', 'ia', 'inteligência artificial', 'resultados', 'domínios', 'cobertura', 'padrões', 'interpretar'],
    'related' => ['results.record', 'ai.pedagogical-analysis', 'ai.privacy', 'ai.limits'],
    'contexts' => ['results.show'],
];
