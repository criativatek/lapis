<?php

// resources/help/articles/ai.assistant.php
//
// «O Assistente Lapispro» — o que é, como se usa e, sobretudo, o que NÃO faz.
// Os limites descritos aqui não são promessas de marketing: cada um
// corresponde a uma propriedade real do código, verificada em testes.
// «Só responde a partir dos artigos» é HelpAssistant::answer(), que faz o
// grounding em HelpCenter::search() e devolve «não há informação suficiente»
// quando a pesquisa não encontra artigos; «não recebe dados de alunos» é a
// assinatura do próprio serviço, que não aceita turma, aluno nem resultado.
//
// O TÍTULO NÃO DIZ «CENTRO DE AJUDA», E ISSO É DELIBERADO. «ajuda» pertence
// ao grupo de sinónimos que config/help-search.php aponta a «começar», e um
// título com essa palavra dava a este artigo o primeiro lugar na pesquisa por
// «ajuda» — lugar que é de «Começar a utilizar o Lapispro», como
// HelpCenterTest fixa desde a 0.82.0. Pelo mesmo motivo «ajuda» e «dúvidas»
// não estão nas keywords. Este artigo encontra-se por «assistente» e por
// «ia», que são as suas próprias palavras.

return [
    'title' => 'O Assistente Lapispro',
    'summary' => 'Como fazer perguntas por palavras suas sobre o Lapispro, e que limites o assistente tem.',
    'category' => 'Começar',
    'order' => 20,
    'content' => [
        'O Centro de Ajuda tem um assistente a que pode fazer perguntas por palavras suas, em vez de adivinhar o termo exato pelo qual um artigo foi escrito. «Como começo a usar o Lapispro?», «como crio uma turma?» ou «onde configuro a avaliação?» são perguntas que ele entende.',
        'O assistente responde exclusivamente a partir dos artigos deste Centro de Ajuda. Não consulta a internet, não consulta outras aplicações e não usa conhecimento geral sobre software escolar. Se a resposta não estiver nos artigos, ele diz que a documentação não cobre essa pergunta — e essa é a resposta correta, não uma falha.',
        'Por baixo, o assistente usa exatamente a mesma pesquisa que a caixa de pesquisa desta página. Primeiro procura os artigos mais relevantes para a sua pergunta, depois lê-os e escreve a resposta a partir deles. Por isso, no fim de cada resposta, indica sempre em que artigos se baseou — pode abri-los e confirmar.',
        'Isto tem uma consequência prática útil: uma pergunta que a pesquisa não consegue responder também não será respondida pelo assistente. Nesse caso vale a pena reformular a pergunta com outras palavras, ou percorrer as categorias de artigos.',
        'O QUE O ASSISTENTE NÃO FAZ. Não vê os seus alunos, as suas turmas, os seus resultados nem quaisquer dados pedagógicos. Não recebe nenhuma dessas informações: quando faz uma pergunta, o que sai da aplicação é a sua pergunta e os artigos de documentação, e mais nenhum dado. Também não inventa funcionalidades — se um botão ou um menu não estiver descrito nos artigos, o assistente não o descreve.',
        'Não escreva dados pessoais na pergunta. O assistente não precisa deles para responder, e uma pergunta como «o João do 8.ºB não aparece na pauta» responde-se igualmente bem escrita como «um aluno não aparece na pauta».',
        'Antes de a pergunta sair da aplicação, o Lapispro retira automaticamente endereços de correio eletrónico, números de telefone, códigos postais, endereços de internet, números de processo escritos na forma «n.º 12» e identificadores internos. No lugar de cada um fica uma marca a dizer que foi retirado.',
        'Essa limpeza tem um limite que vale a pena conhecer: reconhece formatos, não pessoas. Um nome escrito por extenso — «a Mariana Ferreira» — não tem um formato que a distinga de qualquer outra palavra, e por isso não é retirado. É por essa razão que a recomendação continua a ser não escrever nomes na pergunta, e não porque a limpeza não exista.',
        'A pergunta que escreve não fica guardada na aplicação. O registo interno de utilização guarda que houve um pedido, que artigos foram consultados e quanto custou — nunca o texto da pergunta nem o texto da resposta.',
        'A resposta é temporária. Fica no ecrã enquanto estiver nessa página e desaparece quando sair ou atualizar. O assistente não tem memória de perguntas anteriores: cada pergunta começa do zero.',
        'Se o assistente estiver indisponível, o Centro de Ajuda continua a funcionar exatamente como antes — os artigos e a pesquisa não dependem dele. A indisponibilidade pode ter duas razões: o plano da sua organização não inclui o assistente, ou esta instalação ainda não tem um serviço de IA configurado. A mensagem no ecrã diz qual das duas é.',
        'A IA apoia, não decide. Uma resposta do assistente é uma explicação sobre como a aplicação funciona, nunca uma instrução pedagógica e nunca uma decisão sobre um aluno.',
    ],
    'keywords' => ['assistente', 'ia', 'inteligência artificial', 'perguntar', 'pergunta', 'chat'],
    'related' => ['getting-started', 'ai.pedagogical-analysis'],
    'contexts' => [],
];
