<?php

// resources/help/articles/evaluation-sheets.grid-document.php
//
// Grelha factual de avaliação da turma — 0.175.0. Confirmado em
// App\Http\Controllers\EvaluationSheetGridDocumentController (rotas
// 'evaluation-sheets.grid-document' e 'evaluation-sheets.snapshot.grid-document',
// com os respetivos '.pdf', módulo results), App\Services\Reporting\ClassDocuments\
// EvaluationSheetGrid e EvaluationSheetGridCharts.
//
// O QUE É E O QUE NÃO É ESTÁ DITO POR EXTENSO DE PROPÓSITO. A percentagem, a
// proposta e a decisão do professor são três coisas, e um documento impresso não as
// pode misturar; um momento guardado é o do dia em que foi guardado; e um gráfico
// factual não é uma interpretação — quem espera «análise» ou comparação com outro
// período precisa de saber que ela é outra coisa, do plano Pro.

return [
    'title' => 'Ver, imprimir e guardar em PDF a grelha de avaliação da turma',
    'summary' => 'Como abrir a Pauta de Avaliação como documento — a de hoje ou um momento guardado — com os resultados, as propostas e os níveis atribuídos por aluno e, se quiser, gráficos, para imprimir ou guardar em PDF.',
    'category' => 'Avaliação',
    'order' => 26,
    'content' => [
        'Para abrir a grelha de avaliação de uma turma como documento, vá à Pauta de Avaliação e escolha «Grelha para imprimir». Abre a Pauta do período e do momento (intercalar ou final) que estava a ver. Para um momento que guardou, use «Grelha para imprimir» no Histórico da Pauta, junto de cada momento, ou na própria pauta guardada. Não confunda com «Imprimir», que imprime o ecrã da Pauta tal como o deixou, nem com «Exportar CSV» e «Exportar Excel», que são ficheiros de dados.',
        'O documento mostra um aluno por linha, os mesmos que a Pauta. Por cada domínio vê o Resultado e a Apreciação. Depois vem o Global (o resultado e o nível onde cai) e a Classificação, com a Proposta do Lapispro, o Nível atribuído e o Estado. Os avisos de cobertura dizem em que valores faltam elementos, e um asterisco assinala um valor que assenta em elementos parciais ou em falta.',
        'O RESULTADO, A PROPOSTA E A DECISÃO SÃO TRÊS COISAS DIFERENTES. O Resultado é a percentagem que o cálculo da aplicação dá a partir das pontuações registadas. A Proposta do Lapispro é o nível que a aplicação propõe. O Nível atribuído é a sua decisão, e o Estado diz em que ponto está: Proposta, Confirmada ou Publicada. Quando ainda não decidiu, o Nível atribuído fica em branco e diz «não decidido» (ou «sem classificação», se nem proposta há): a proposta nunca passa por decisão. Nos domínios, uma apreciação decidida por si leva a marca «prof.» e a proposta que substituiu aparece por baixo.',
        'Onde falta valor aparece «—». Não é zero: um aluno sem elementos num domínio não tem 0 %, não tem nada ainda.',
        'Enquanto algum aluno não tiver nível atribuído por si, o documento sai marcado como PROVISÓRIO, em todas as páginas do PDF, e diz quantos alunos faltam. Quando todos têm nível atribuído, passa a «Classificações tomadas» e o selo desaparece.',
        'O documento diz sempre de onde vêm os valores. A grelha da Pauta de hoje é o «Estado à data de emissão»: lê os registos no momento em que a abre. Os dois momentos de um período — intercalar e final — leem a mesma pauta, por isso um intercalar de hoje mostra os mesmos números que o final; o que muda é o título. Um momento guardado é diferente: é uma fotografia, e a grelha mostra-a exatamente como estava quando a guardou — com a data e o autor — e nunca é alterada por correções, decisões ou propostas posteriores. Se quiser o estado de hoje, abra a Pauta; se quiser o de então, abra o momento guardado.',
        'Antes de imprimir, pode escolher a orientação do papel e se inclui a autoavaliação dos alunos. A orientação é horizontal por omissão, porque uma Pauta é larga; se tiver muitos domínios, as colunas dividem-se por partes («Parte 1 de 3»…), cada uma com o número e o nome dos alunos, e nenhum domínio fica de fora. A autoavaliação vem desligada, porque é informação de apoio e não faz parte da Pauta; só pode ligá-la se a pauta tiver autoavaliação.',
        'Pode também incluir três gráficos factuais, desligados por omissão: a distribuição dos níveis propostos pela aplicação, a distribuição dos níveis atribuídos por si — separada das propostas e só quando já atribuiu algum — e a média da turma, global e por domínio. Cada um diz em quantos alunos se baseia; abaixo de cinco alunos o documento avisa para ler com cautela. Quem ainda não tem proposta, nível ou resultado é contado à parte e nunca entra como zero, e um nível que a escala não tem ou um resultado acima de 100 % (por bónus, por exemplo) é assinalado numa nota.',
        'Os gráficos descrevem o que está registado; não o interpretam. A interpretação dos resultados, a comparação automática com outros períodos ou momentos e as tendências são capacidades do plano Pro e não fazem parte deste documento. Nas pautas guardadas antes da 0.175.0 os gráficos por nível não estão disponíveis (esses momentos não guardaram a escala) e o documento di-lo; a média da turma aparece na mesma.',
        '«Imprimir» imprime o documento tal como o vê no ecrã. Em browsers recentes baseados em Chromium, cada folha impressa leva o selo de provisório (quando é o caso) e «Página N / T»; noutros browsers esses dois elementos podem não aparecer em todas as folhas. «PDF» descarrega o mesmo conteúdo em A4, com o cabeçalho da tabela repetido em cada página e a numeração «Página N / T» — é a forma mais segura de obter sempre o mesmo resultado, em particular no telemóvel.',
        'O documento identifica cada aluno só pelo número e pelo nome. Não inclui fotografias, datas de nascimento, números de processo, nem o motivo escrito de uma decisão ou as notas e observações que escreveu. Só os professores da turma o podem abrir, e o PDF não fica guardado no servidor. Cada PDF descarregado fica registado no histórico de auditoria só com contagens e opções, nunca com nomes.',
        'A grelha da Pauta de hoje não fica guardada: é gerada no momento em que a abre e leva impressa a data de emissão. Não é um relatório finalizado. Para um documento que fica congelado, guarde a Pauta num momento — e abra depois a grelha desse momento.',
    ],
    'keywords' => ['grelha da turma', 'pauta em pdf', 'imprimir pauta', 'grelha de avaliação da turma', 'grelha para imprimir', 'momento guardado', 'momento intercalar', 'nível atribuído', 'proposta do lapispro', 'gráficos da pauta', 'distribuição dos níveis', 'média da turma', 'provisório'],
    'related' => ['instruments.grid-document', 'results.record', 'reports.measures-map', 'classes.create'],
    'contexts' => ['evaluation-sheets.grid-document', 'evaluation-sheets.snapshot.grid-document'],
];
