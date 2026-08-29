<?php

// resources/help/articles/ai.privacy.php
//
// O artigo transversal: o que sai da aplicação quando usa IA, o que não sai,
// e quem decide.
//
// EXISTE PORQUE A PERGUNTA É TRANSVERSAL. Cada funcionalidade tem o seu artigo
// e cada um repete a parte que lhe diz respeito; esta é a pergunta que um
// encarregado de educação, um diretor ou um professor cauteloso faz uma vez
// sobre o produto inteiro — «os dados dos meus alunos vão para uma IA?» — e
// merece um sítio onde a resposta esteja completa.
//
// «PSEUDONIMIZADO», NUNCA «ANÓNIMO». A distinção é jurídica e é real: o
// professor, com a pauta à frente, reconhece cada linha. Prometer anonimato
// seria prometer o que não se faz.
//
// Cada afirmação corresponde a uma propriedade do código: a ordem
// allowlist → pseudonimizar → serializar → sanitizar é AiContext; a segunda
// barreira é AiPayloadSanitizer; a impossibilidade de guardar conteúdo é a
// ausência de colunas em ai_usage_events.

return [
    'title' => 'Privacidade nas funcionalidades de IA',
    'summary' => 'Que dados saem da aplicação quando usa IA, que dados nunca saem, o que fica registado, e porque a decisão continua a ser do professor.',
    'category' => 'Começar',
    'order' => 21,
    'content' => [
        'Todas as funcionalidades de IA do Lapispro passam pelo mesmo caminho, e esse caminho tem sempre as mesmas etapas. Não há uma funcionalidade que fale com o motor de IA por atalho.',
        'PRIMEIRO, UMA LISTA DE CAMPOS PERMITIDOS. Cada funcionalidade declara, campo a campo, o que envia. Não existe nenhum sítio no código onde um registo da base de dados seja entregue inteiro: quem quiser enviar alguma coisa tem de a nomear. Isso é intencionalmente trabalhoso — é o momento em que alguém repara que o décimo segundo campo é um contacto do encarregado de educação.',
        'SEGUNDO, OS NOMES SÃO SUBSTITUÍDOS. Cada valor é pseudonimizado no momento em que entra, ainda antes de qualquer texto ser montado. Os alunos aparecem como «Aluno A», «Aluno B». Isso acontece campo a campo, e não sobre o texto final — é o que garante que um nome escrito noutro sítio, por exemplo num domínio a que alguém tenha chamado «Apoio ao João», também é substituído.',
        'TERCEIRO, UMA SEGUNDA LIMPEZA. Já com o texto montado, a aplicação retira endereços de correio eletrónico, números de telefone, códigos postais, endereços de internet, números escritos na forma «n.º 12», identificadores internos e séries longas de algarismos. No lugar de cada um fica uma marca a dizer que foi retirado.',
        'A segunda limpeza é uma rede de segurança, não a defesa principal. Reconhece formatos, não pessoas: um nome por extenso não tem formato que o distinga de qualquer outra palavra. A defesa principal é a primeira etapa — construir o contexto só com o que é permitido.',
        'PSEUDONIMIZADO NÃO É ANÓNIMO, e a distinção importa. O professor, que tem a pauta à frente, reconhece quem é cada linha. Quem recebe os dados do lado do serviço de IA não reconhece. Os pseudónimos também não são estáveis entre pedidos: «Aluno A» hoje pode não ser o mesmo aluno amanhã, precisamente para que não se acumule um perfil do lado de lá.',
        'O QUE NUNCA SAI, EM NENHUMA FUNCIONALIDADE. Nomes de alunos. Números de aluno e números de processo. Endereços de correio eletrónico e telefones. Moradas. Dados de encarregados de educação. Identificadores internos da aplicação. E o texto livre que o professor escreve sobre um aluno — a descrição de um registo, o objetivo de uma intervenção, uma observação — que é onde, na prática, vivem os dados mais sensíveis: saúde, acompanhamento clínico, necessidades específicas, contexto familiar e social.',
        'Em vez desse texto, o que segue são categorias, estados e contagens: «três registos de dificuldade», «uma intervenção em curso no domínio da Leitura». Chega para uma leitura útil e não chega para expor ninguém.',
        'O QUE FICA REGISTADO. A aplicação guarda que houve um pedido de IA: que funcionalidade, que tipo de pedido, que motor, quantos tokens custou, quanto tempo demorou e como terminou. Não guarda a pergunta, não guarda a resposta, não guarda nomes e não guarda conteúdo pedagógico — e não é uma questão de escolha: não existem colunas onde isso pudesse ser guardado.',
        'Nas funcionalidades sobre um aluno concreto fica também um registo de auditoria a dizer que uma leitura foi pedida sobre aquele aluno, por quem e quando. Uma escola a quem perguntem «foi pedida uma análise de IA sobre o meu educando?» tem de poder responder — mas o que a análise dizia não fica guardado em lado nenhum.',
        'DUAS SITUAÇÕES, DUAS GARANTIAS DIFERENTES. Tudo o que está descrito acima vale para o contexto que o Lapispro monta sozinho a partir dos seus dados — aí a aplicação controla campo a campo o que sai, e nenhum identificador direto entra. É diferente do texto que escreve à mão numa caixa destinada à IA: a pergunta ao assistente, o objetivo de uma sugestão de estratégia, o texto de uma secção de relatório. Aí o Lapispro não controla o que lá escreve.',
        'Por isso, nesses campos, aparece sempre um lembrete a pedir que não escreva nomes, contactos nem outros dados pessoais dos alunos. E, antes de enviar, a aplicação procura no que escreveu padrões reconhecíveis — endereços de correio eletrónico, contactos telefónicos, códigos postais, números na forma «n.º 12», identificadores internos e séries longas de algarismos.',
        'Se encontrar algum, o pedido para. Aparece um aviso a dizer o que parece ter sido encontrado, o texto fica exatamente como o escreveu, e pode voltar atrás para o corrigir ou continuar mesmo assim. Continuar é uma escolha sua, feita a cada vez. Esta verificação corre no seu navegador e não recorre a nenhum serviço externo.',
        'ESTE AVISO REDUZ O RISCO E NÃO O ELIMINA, e vale a pena perceber porquê. Reconhece formatos, não pessoas: um endereço de e-mail tem uma forma que o denuncia, um nome próprio escrito por extenso não tem. Uma frase como «o que fazer com o João Silva?» passa sem aviso. Nas páginas em que a aplicação já tem o nome do aluno à frente — a Evolução desse aluno — esse nome concreto também é procurado; na caixa do assistente não é procurado nome nenhum, porque ir buscar a lista de alunos para verificar uma pergunta seria pior do que o problema que resolve.',
        'A DECISÃO É SEMPRE DO PROFESSOR. Nenhuma funcionalidade de IA do Lapispro altera uma nota, decide uma classificação, aprova ou reprova alguém, muda um peso ou um critério, cria ou aplica uma estratégia, altera uma medida, conclui um relatório ou grava uma decisão pedagógica. A IA produz texto para uma pessoa ler. Tudo o que se faz a seguir faz-se nos ecrãs habituais, com a confirmação de quem o faz.',
        'Isso não é uma promessa de comportamento: é uma propriedade da aplicação. Não existe nenhum caminho pelo qual uma resposta de IA volte a entrar nos dados — nenhum ecrã aceita uma análise de volta, e os objetos que transportam essas respostas não têm sequer um identificador que o permitisse.',
        'Não é usada pesquisa na internet, não são usados dados de outras aplicações, não há memória entre pedidos, e nenhuma resposta de IA é usada para treinar seja o que for do lado do Lapispro.',
        'A IA apoia e pode cometer erros. As decisões pedagógicas continuam a ser do professor.',
    ],
    'keywords' => ['privacidade', 'rgpd', 'dados', 'pseudonimização', 'pseudonimizado', 'anónimo', 'ia', 'inteligência artificial', 'segurança', 'decisão'],
    'related' => ['ai.limits', 'ai.assistant', 'ai.assessment', 'ai.followup'],
    'contexts' => [],
];
