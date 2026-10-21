<?php

// resources/help/articles/instruments.grid-document.php
//
// Grelha imprimível de um elemento de avaliação — 0.174.0. Confirmado em
// App\Http\Controllers\InstrumentGridDocumentController (routes
// 'instruments.grid-document' e 'instruments.grid-document.pdf', módulo
// instruments) e App\Services\Reporting\ClassDocuments\InstrumentGrid.
//
// O QUE É E O QUE NÃO É ESTÁ DITO POR EXTENSO DE PROPÓSITO. A «Pontuação bruta» não
// é o «Resultado», e um gráfico factual não é uma interpretação: um professor que
// vê dois números diferentes na mesma linha precisa de saber porquê, e quem espera
// «análise» deste documento precisa de saber que ela é outra coisa, do plano Pro.

return [
    'title' => 'Ver, imprimir e guardar em PDF a grelha de um elemento de avaliação',
    'summary' => 'Como abrir a grelha de um teste ou ficha como documento — com os resultados por aluno e, se quiser, gráficos — para imprimir ou guardar em PDF.',
    'category' => 'Avaliação',
    'order' => 25,
    'content' => [
        'Para abrir a grelha de um elemento de avaliação como documento, vá à grelha de correção do teste, ficha ou trabalho e escolha «Imprimir / PDF» (também está no separador Resultados). Não confunda com «Descarregar grelha», que é a folha de cálculo que preenche fora da aplicação.',
        'O documento mostra um aluno por linha e uma coluna por questão, com a cotação de cada uma. No fim da linha vêm a pontuação bruta, o resultado, o nível da escala e, se o elemento tocar dois ou mais domínios, o resultado de cada domínio. Só aparecem os alunos a quem o elemento se aplica: se algum não foi abrangido (entrou depois, por exemplo), o documento diz quantos ficaram de fora.',
        'A PONTUAÇÃO BRUTA E O RESULTADO SÃO COISAS DIFERENTES. A pontuação bruta é a soma dos pontos das questões avaliadas sobre a cotação total. O resultado é o que o cálculo da aplicação dá, com os pesos dos domínios do perfil da turma — por isso, com dois ou mais domínios, ou com questões sem domínio, pode não coincidir com pontos sobre cotação. É o mesmo resultado que vê na grelha de correção e em Resultados, e não é a classificação do período.',
        'Nas células, «—» quer dizer que ainda não foi avaliado — não é zero. As restantes abreviaturas (Aus, AusJ, Disp, N/A, Anul, Rev) são explicadas numa legenda no próprio documento. Um asterisco numa linha indica avaliação parcial: ainda há questões por avaliar.',
        'Enquanto a correção não estiver concluída, o documento sai marcado como PROVISÓRIO, em todas as páginas do PDF, e não tem gráficos. Quando a correção for concluída, passa a «Correção concluída». Se reabrir a correção, volta a provisório. Um elemento de avaliação diagnóstica vem identificado como tal e não conta para as médias.',
        'Antes de imprimir, pode escolher o que mostrar: a pontuação bruta, o resultado e o nível, os resultados por domínio, a legenda das questões e os gráficos. Pode também escolher a orientação do papel. Em «Automática», o documento fica na vertical se couber e passa para a horizontal quando é largo; se tiver muitas questões, as colunas dividem-se por partes («Parte 1 de 3»…), cada uma com o número e o nome dos alunos, e nenhuma questão fica de fora.',
        'Os gráficos são factuais e só ficam disponíveis com a correção concluída — a opção está desligada até lá e, quando a liga, aparecem três: a distribuição dos resultados por nível da escala (ou por intervalos, se a escala não tiver níveis), a média da turma global e por domínio, e a média por questão. Em cada um vê o número de alunos em que se baseia. Quando há poucos alunos, o documento avisa para ler com cautela. As ausências e as questões por avaliar não contam como zero: ficam à parte.',
        'Os gráficos descrevem o que está registado; não o interpretam. A interpretação dos resultados, a comparação automática com outros instrumentos ou períodos e as tendências são capacidades do plano Pro e não fazem parte deste documento.',
        '«Imprimir» imprime o documento tal como o vê no ecrã. Em browsers recentes baseados em Chromium, cada folha impressa leva o selo de provisório (quando é o caso) e «Página N / T»; noutros browsers esses dois elementos podem não aparecer em todas as folhas. «PDF» descarrega o mesmo conteúdo em A4, com o cabeçalho da tabela repetido em cada página e a numeração «Página N / T» — é a forma mais segura de obter sempre o mesmo resultado, em particular no telemóvel.',
        'O documento identifica cada aluno só pelo número e pelo nome. Não inclui fotografias, datas de nascimento, números de processo, nem as notas e observações que escreveu. Só os professores da turma o podem abrir.',
        'Não fica guardado: é gerado a partir dos registos no momento em que o abre e leva impressa a data de emissão. Não é um relatório finalizado — se corrigir uma nota e voltar a abrir, obtém o documento atualizado. Para um documento que fica congelado, crie um relatório em «Novo relatório».',
    ],
    'keywords' => ['grelha imprimível', 'imprimir grelha', 'grelha em pdf', 'pdf do teste', 'resultados do teste', 'pontuação bruta', 'gráficos do teste', 'distribuição dos resultados', 'média por questão', 'elemento de avaliação', 'provisório'],
    'related' => ['instruments.create', 'results.record', 'reports.measures-map', 'ai.assessment'],
    'contexts' => ['instruments.grid-document'],
];
