<?php

// resources/help/articles/ai.strategies.php
//
// «Sugestões pedagógicas (IA)» em Estratégias e Medidas.
//
// A FUNCIONALIDADE JÁ EXISTIA; O ARTIGO NÃO. Foi escrito na fatia AI-complete,
// quando a funcionalidade passou a atravessar o AiGateway como todas as
// outras. O texto descreve o comportamento atual, não a história.
//
// A afirmação central — «a IA nunca cria uma estratégia» — corresponde a uma
// propriedade estrutural: InterventionStrategySuggester não tem relação
// nenhuma com o modelo Intervention, e AiNonWriteTest verifica que um pedido
// de sugestão deixa a base de dados byte a byte igual.

return [
    'title' => 'Sugestões de estratégias e medidas com IA',
    'summary' => 'Como pedir propostas de estratégias para um domínio, o que a IA recebe, e porque é que a criação da estratégia é sempre sua.',
    'category' => 'Acompanhamento',
    'order' => 31,
    'content' => [
        'Na página de Evolução de um aluno existe a secção «Sugestões pedagógicas (IA)». Escolhe a finalidade — recuperação, consolidação ou melhoria — e o domínio, e a IA propõe estratégias que pode considerar para esse aluno naquele domínio.',
        'Pode acrescentar um objetivo em palavras suas. É opcional, e serve para orientar a proposta: «quero trabalhar a leitura em voz alta» dá sugestões diferentes de «quero reduzir os erros ortográficos».',
        'Cada sugestão vem com um nome, um objetivo, uma proposta de aplicação e, quando faz sentido, uma frequência, uma duração, um indicador a observar e um momento sugerido para rever. É a forma de uma estratégia — de propósito, para que seja fácil de adaptar.',
        'A IA NUNCA CRIA A ESTRATÉGIA. Não regista, não associa ao aluno, não ativa medidas, não altera estados e não marca como concluído. As sugestões ficam no ecrã e desaparecem quando sair da página.',
        'Se quiser usar uma, cria-a você: «Adicionar estratégia» abre o formulário habitual, onde pode copiar, adaptar ou reescrever o que leu, e é o seu botão de guardar que a regista. Essa segunda ação é deliberadamente sua — uma medida pedagógica associada a um aluno é uma decisão, e uma decisão tem um autor.',
        'A IA também não avalia a eficácia de uma medida. Uma estratégia é eficaz quando o professor, olhando para a evidência que se seguiu, decide que foi — e regista isso na revisão da intervenção, como sempre.',
        'QUE DADOS SÃO ENVIADOS. Vai o nome do domínio, a finalidade que escolheu, uma frase factual curta construída pelo Lapispro a partir do resultado atual nesse domínio, os nomes e objetivos das estratégias que já registou nesse mesmo domínio, e o objetivo que tiver escrito.',
        'Não vai o nome do aluno, o número, o número de processo, nem qualquer identificador. Também não vai o texto dos seus registos nem a descrição das intervenções. Antes de sair, a aplicação retira automaticamente endereços de correio eletrónico, números de telefone, códigos postais e identificadores internos que tenham ficado no texto — e recusa enviar de todo um objetivo ou uma estratégia anterior que contenha o nome ou o número deste aluno.',
        'O que escrever no objetivo é tratado como conteúdo, nunca como instrução. Uma frase escrita ali que pareça dar uma ordem à IA — «ignora as regras acima» — chega ao motor delimitada e identificada como texto do professor, e as instruções que o motor segue continuam a ser as da aplicação.',
        'Não escreva o nome do aluno no objetivo. Não é preciso, e a sugestão sai igualmente boa escrita como «este aluno».',
        'Por baixo da caixa do objetivo está um lembrete a pedir isso mesmo, e antes de enviar a aplicação procura no que escreveu padrões reconhecíveis — e também o nome deste aluno, que a página já tem à frente. Se encontrar algum, mostra um aviso, deixa o texto como está, e pode corrigi-lo ou gerar mesmo assim.',
        'A IA apoia a análise e pode cometer erros. As decisões pedagógicas continuam a ser do professor.',
        'Se a funcionalidade estiver indisponível, a mensagem no ecrã diz porquê: ou o plano da sua organização não a inclui, ou esta instalação ainda não tem um serviço de IA configurado. Registar estratégias e medidas à mão funciona na mesma em qualquer dos casos.',
    ],
    'keywords' => ['estratégia', 'estratégias', 'medida', 'medidas', 'intervenção', 'intervenções', 'sugestão', 'sugestões', 'ia', 'inteligência artificial', 'recuperação', 'consolidação'],
    'related' => ['ai.followup', 'ai.privacy', 'ai.limits'],
    'contexts' => ['student-progress.student', 'interventions.show'],
];
