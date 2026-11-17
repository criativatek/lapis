<?php

// resources/help/articles/reports.meeting-without-internet.php
//
// Reunião de avaliação sem Internet — 0.197.0. O Lapispro é uma aplicação web:
// sem ligação não abre, e este artigo não promete o contrário. O que faz é
// dizer ao professor o que levar consigo ANTES da reunião. Cada documento aqui
// nomeado foi confirmado na rota e no rótulo do botão:
//
//   - «Preparar reunião» ('evaluation-sheets.meeting-package'), na Pauta de
//     Avaliação, junto de «Grelha para imprimir» — resources/js/pages/
//     evaluation-sheets/Show.vue e MeetingPackage (App\Services\Reporting\
//     ClassDocuments). Um ZIP de folhas de Excel: a Pauta de cada período do
//     ano até ao pedido (a mesma de «Exportar Excel», 'evaluation-sheets.xlsx'),
//     o Quadro Síntese ('results.summary.xlsx') e a grelha com resultados de
//     cada elemento de avaliação elegível desses períodos
//     ('instruments.grid.results'), mais um LEIA-ME. A avaliação é contínua,
//     por isso o pacote do período N leva também tudo o que está para trás. É a PRIMEIRA coisa que o artigo diz, porque é a que poupa passos.
//   - Em papel, um a um: «Grelha para imprimir» → «PDF»
//     ('evaluation-sheets.grid-document.pdf') e, no elemento, «Imprimir / PDF»
//     ('instruments.grid-document'); Quadro Síntese: «Imprimir / PDF»
//     — resources/js/pages/results/Summary.vue.
//   - Outros documentos, que NÃO vêm no pacote: Mapa de Medidas Educativas,
//     «PDF» ('reports.measures-map.pdf'); Análise da Avaliação,
//     'assessment-analysis.document.pdf', módulo advanced_analytics (Pro).
//
// `contexts` é só a Pauta ('evaluation-sheets.show'), que é onde está o botão;
// o resto do artigo continua a ser preparação, e encontra-se também pela
// pesquisa e pelo «ver também» dos artigos dos documentos.
//
// UMA CÓPIA FORA DA APLICAÇÃO É RESPONSABILIDADE DE QUEM A LEVA, e o artigo
// di-lo: o conselho de exportar não pode sair sem o de guardar e apagar.

return [
    'title' => 'Reunião de avaliação sem Internet: o que levar preparado',
    'summary' => 'O Lapispro precisa de ligação à Internet. O que fazer se a rede da escola falhar numa reunião e como levar, num só ficheiro, os dados que contam para as classificações.',
    'category' => 'Relatórios e pautas',
    'order' => 30,
    'content' => [
        'O Lapispro funciona no browser e precisa de ligação à Internet: sem ela, a aplicação não abre. Numa reunião de avaliação isso resolve-se com dois gestos simples, e o segundo faz-se antes da reunião.',
        'SE A REDE DA ESCOLA FALHAR, USE OS DADOS MÓVEIS. Quase sempre o que falha é a rede da escola, não o Lapispro. Ligue o computador ao ponto de acesso (hotspot) do telemóvel e continue a trabalhar normalmente. Convém experimentar uma vez antes, para saber onde se liga no seu telemóvel.',
        'ANTES DA REUNIÃO, PREPARE O PACOTE. Na Pauta de Avaliação da turma, ao lado de «Grelha para imprimir», escolha «Preparar reunião». O Lapispro descarrega um único ficheiro ZIP, para o período e o momento (intercalar ou final) que está a ver, com folhas de Excel: a Pauta deste período e as dos períodos anteriores do ano letivo, o Quadro Síntese da turma e uma grelha com os resultados de cada elemento de avaliação desses períodos — pontuações por questão e por aluno, e o resultado. A avaliação é contínua, por isso o pacote traz também o que está para trás: se pedir o 2.º período, leva as Pautas e os elementos do 1.º e do 2.º. Traz ainda um ficheiro LEIA-ME.txt que diz o que lá está e de quando é. É uma turma de cada vez: se vai a uma reunião de várias turmas, prepare um pacote por turma. Faça-o no próprio dia ou na véspera: a cópia mostra os dados do momento em que o fez e não se atualiza sozinha.',
        'O QUE ENTRA E O QUE FICA DE FORA. Entram os elementos de avaliação elegíveis do ano letivo até ao período pedido, por ordem cronológica e agrupados por período no LEIA-ME. Se o perfil de avaliação deixar um período de fora do acumulado, os elementos desse período vêm na mesma, e o LEIA-ME assinala que não entram no acumulado. Um elemento cuja correção ainda não está concluída entra, mas vem assinalado como provisório no LEIA-ME. Ficam de fora os anulados, os de diagnóstico, os que não contam para a classificação, os que ainda estão em preparação e os de períodos posteriores ao pedido, e o LEIA-ME diz quantos são e porquê. Se a organização não tiver os elementos de avaliação no seu plano, o pacote sai na mesma, sem as grelhas dos elementos, e o LEIA-ME di-lo. Nada disto dá erro.',
        'SE PREFERIR PAPEL, TIRE OS PDF UM DE CADA VEZ. A Pauta, na «Grelha para imprimir», com o botão «PDF»; o Quadro Síntese, com «Imprimir / PDF» (para ficar com um ficheiro, escolha «Guardar como PDF» no diálogo de impressão do browser); e a grelha de um elemento, abrindo a grelha de correção desse elemento e escolhendo «Imprimir / PDF». O pacote tem os dados, não a folha impressa.',
        'OUTROS DOCUMENTOS. O Mapa de Medidas Educativas e, no plano Pro, a Análise da Avaliação não vêm no pacote. O Mapa está em Relatórios, no bloco «Documentos da turma», com o botão «PDF»; a Análise guarda-se em PDF a partir de «Documento (imprimir ou PDF) →».',
        'SEM LIGAÇÃO, CONSULTA-SE; NÃO SE LANÇA. Os ficheiros que guardou servem para ler. Se na reunião for decidida uma alteração e não tiver ligação, anote-a e registe-a no Lapispro quando voltar a ter rede. Alterar o Excel ou o PDF não altera nada na aplicação.',
        'ESTAS CÓPIAS SÃO DADOS DE ALUNOS IDENTIFICÁVEIS. Guarde-as só no seu computador, não as envie por canais que não usaria para a pauta, e apague-as quando a reunião terminar. Num computador partilhado da escola, não as deixe na pasta de transferências.',
    ],
    'keywords' => ['sem internet', 'sem ligação', 'sem rede', 'offline', 'reunião de avaliação', 'conselho de turma', 'rede da escola', 'dados móveis', 'hotspot', 'ponto de acesso', 'guardar pauta', 'levar a pauta', 'cópia para a reunião', 'pacote de reunião', 'preparar reunião', 'zip'],
    'related' => ['evaluation-sheets.grid-document', 'instruments.grid-document', 'reports.measures-map', 'assessment.analysis-document', 'reports.view'],
    'contexts' => ['evaluation-sheets.show'],
];
