<?php

// resources/help/articles/ai.limits.php
//
// «Porque é que a IA não aparece» — o artigo que responde à pergunta mais
// frequente sobre o módulo, e a razão pela qual existe: as quatro razões pelas
// quais um botão de IA pode não estar lá levam a quatro pessoas diferentes, e
// um professor que não saiba distinguir escreve para a errada.
//
// AS KEYWORDS INCLUEM «aparece» E «desapareceu» DE PROPÓSITO. «porque a IA não
// aparece» reduz-se, depois das stopwords, a «ia» + «aparece»; sem essa palavra
// nas keywords a pergunta mais provável do módulo não encontrava o seu próprio
// artigo.
//
// NENHUM NÚMERO CONCRETO É CITADO NO CORPO. Os limites são configuráveis por
// instalação e por plano, e um artigo que dissesse «40 por dia» ficaria falso
// na primeira vez que alguém mudasse a configuração — e ninguém iria lá
// corrigi-lo.

return [
    'title' => 'Limites de IA e porque a IA pode não aparecer',
    'summary' => 'As razões pelas quais uma funcionalidade de IA pode estar indisponível, o que significa cada mensagem, e como funcionam os limites de utilização.',
    'category' => 'Avaliação',
    'order' => 42,
    'content' => [
        'Se um botão de IA não aparece, ou se aparece uma mensagem em vez de uma resposta, há quatro razões possíveis. A mensagem no ecrã diz sempre qual é, e cada uma leva a uma pessoa diferente.',
        'PRIMEIRA: «não está incluída no plano desta organização». A funcionalidade existe, mas o plano contratado não a inclui. Quem resolve isto é quem trata do plano da sua organização, e esperar não altera o estado.',
        'SEGUNDA: «não está configurada nesta instalação» ou «não está ativada nesta instalação». O plano inclui a funcionalidade, mas o Lapispro ainda não tem um serviço de IA ligado, ou ele está desligado. Quem resolve isto é quem administra a instalação, e esperar também não altera o estado.',
        'TERCEIRA: «atingiu o limite». Já usou o que estava disponível na janela em causa. A mensagem diz qual — o seu limite diário, o limite mensal da organização, ou o plafond da organização — e quando renova. Esta é temporária e resolve-se sozinha.',
        'QUARTA: «ainda não há dados suficientes». A funcionalidade está disponível, mas não há material que chegue para uma leitura: um período com dois resultados, ou um aluno sem resultado, registo nem intervenção. Esta resolve-se à medida que vai registando o seu trabalho.',
        'COMO FUNCIONAM OS LIMITES. Existem três camadas, e um pedido tem de passar por todas as que se apliquem. Um limite por minuto, que existe para travar um clique repetido e se desfaz sozinho em segundos. Um limite por funcionalidade — quantos pedidos cada professor pode fazer por dia, e quantos a organização pode fazer por mês. E, nas organizações institucionais, um plafond que conta todos os pedidos de IA em conjunto, com um teto individual opcional dentro dele.',
        'Os números concretos não estão escritos neste artigo de propósito: dependem da instalação e do plano, e podem ser alterados sem atualizar a aplicação. A mensagem que vê no ecrã cita sempre o número que está realmente a ser aplicado.',
        'Um pedido recusado por limite não gasta limite. Só contam os pedidos que chegaram a ser feitos.',
        'Os limites por funcionalidade são separados. Ficar sem pedidos no assistente do Centro de Ajuda não o impede de pedir uma análise dos resultados — são contas diferentes, porque custam de forma diferente.',
        'SE FOR UMA ORGANIZAÇÃO INSTITUCIONAL, quem a administra pode ver, em Administração institucional › Inteligência Artificial, quais as funcionalidades incluídas, os limites em vigor, quanto foi consumido no mês e quantos pedidos foram recusados e porquê. Essa página mostra contagens — nunca o que alguém perguntou, nunca o que a IA respondeu, e nunca o consumo de cada professor em particular.',
        'A APLICAÇÃO FUNCIONA SEM IA. Todas as funcionalidades assistidas são opcionais e ficam por cima de páginas que existem por si: os resultados, a evolução do aluno, as estratégias, os relatórios e o Centro de Ajuda funcionam exatamente na mesma com a IA indisponível. Nenhum número, nenhum cálculo e nenhum documento depende dela.',
    ],
    'keywords' => ['limite', 'limites', 'quota', 'ia', 'inteligência artificial', 'aparece', 'desapareceu', 'indisponível', 'indisponibilidade', 'bloqueado', 'plafond'],
    'related' => ['ai.privacy', 'ai.assistant', 'ai.assessment'],
    'contexts' => [],
];
