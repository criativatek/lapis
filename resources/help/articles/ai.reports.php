<?php

// resources/help/articles/ai.reports.php
//
// «Aperfeiçoar redação» nos Relatórios.
//
// A FUNCIONALIDADE É A MAIS ANTIGA DO MÓDULO E ERA A ÚNICA SEM ARTIGO. O texto
// descreve a direção da seta, que é o desenho todo: dados → narrativa
// determinística → o professor edita → a IA aperfeiçoa UMA secção → o professor
// decide. Nunca dados → IA → relatório.
//
// «A IA não vê os resultados» é literal e verificável: ReportWritingAssistant
// lê o `body` de uma secção e mais nada — nenhuma estatística, nenhum
// resultado, nenhuma classificação entra nesta classe.
//
// «Os números saem como marcas» é ProtectedFacts, e a recusa de uma resposta
// que os altere é RewriteGuard.

return [
    'title' => 'Aperfeiçoar a redação de um relatório com IA',
    'summary' => 'Como pedir que uma secção seja dita melhor, porque é que a IA não pode inventar factos, e porque é que o relatório só muda quando aceitar.',
    'category' => 'Relatórios',
    'order' => 30,
    'content' => [
        'Ao editar uma secção de um relatório em rascunho, encontra a ação «Aperfeiçoar redação». Serve para pedir que o texto que já lá está seja dito melhor — mais claro, mais conciso, com outro tom — sem acrescentar factos novos.',
        'A DIREÇÃO IMPORTA. O Lapispro escreve primeiro uma narrativa a partir dos dados que já registou; o professor edita; e só depois, se quiser, a IA aperfeiçoa uma secção de cada vez. Nunca o contrário. A IA não é a origem de nenhum facto no relatório, porque nunca vê nenhum.',
        'O QUE A IA RECEBE É UMA SECÇÃO DE TEXTO, E MAIS NADA. Não recebe as outras secções, nem a turma, nem o período, nem a lista de alunos, nem as estatísticas, nem os resultados, nem as classificações, nem as autoavaliações, nem os registos, nem as intervenções, nem a identidade da escola, nem o seu nome.',
        'Antes de sair, todos os números do parágrafo são substituídos por marcas — uma percentagem, uma data, um nível, uma contagem — e todos os nomes que a aplicação conhece são substituídos por pseudónimos. O que segue para o motor é uma frase sem um único algarismo.',
        'Quando a resposta volta, o Lapispro compara-a com o que enviou. Se faltar uma marca, se aparecer um número que não estava lá, ou se um facto tiver mudado, a sugestão é recusada e não lhe é sequer mostrada. Só depois de passar nessa verificação é que os números e os nomes voltam ao lugar.',
        'É por isso que a IA não pode inventar uma dificuldade, uma medida, uma caracterização de comportamento ou um artigo de legislação: não tem números para os sustentar, e uma resposta que os invente é apanhada na comparação.',
        'O RELATÓRIO SÓ MUDA QUANDO ACEITAR. A sugestão aparece ao lado do texto atual. Pode ignorá-la, copiar uma parte, ou aceitá-la — e aceitar é guardar a secção, com o seu botão, no editor que já usa. Enquanto não o fizer, o relatório está exatamente como estava.',
        'Aceitar altera só o texto que escreveu. O texto automático que o Lapispro tinha gerado fica intacto por baixo, e «restaurar texto automático» continua a trazê-lo de volta.',
        'Um relatório finalizado nunca é aperfeiçoado. Um documento emitido é o que diz, e fica fechado.',
        'Uma secção muito longa é recusada em vez de cortada a meio: meio parágrafo reescrito é pior do que nenhum.',
        'O QUE ESCREVEU É TRATADO COMO TEXTO, NUNCA COMO ORDEM. Se um parágrafo do relatório contiver uma frase que pareça dar instruções à IA, ela chega ao motor na posição de conteúdo, e as instruções que o motor segue continuam a ser as da aplicação.',
        'Antes de a secção sair, a aplicação procura no texto padrões que não deviam lá estar — um contacto, uma morada, um código postal, um identificador interno. O nome do aluno não é assinalado: num relatório sobre ele o nome é suposto lá estar, e é substituído automaticamente na saída. Se encontrar algum dos outros, mostra um aviso e deixa-o decidir se corrige ou continua.',
        'Fica registado que um aperfeiçoamento foi pedido, em que secção, em que modo, se foi aceite pela verificação e quanto custou — nunca o texto enviado nem o texto recebido.',
        'A IA apoia a redação e pode cometer erros. As decisões pedagógicas, e o que fica escrito no relatório, continuam a ser do professor.',
        'Se a funcionalidade estiver indisponível, a mensagem no ecrã diz porquê: ou o plano da sua organização não a inclui, ou esta instalação ainda não tem um serviço de IA configurado. Escrever e finalizar relatórios funciona na mesma em qualquer dos casos.',
    ],
    'keywords' => ['relatório', 'relatórios', 'redação', 'aperfeiçoar', 'escrever', 'texto', 'secção', 'ia', 'inteligência artificial', 'reformular'],
    'related' => ['reports.view', 'ai.privacy', 'ai.limits'],
    'contexts' => ['reports.show'],
];
