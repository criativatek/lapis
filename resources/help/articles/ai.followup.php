<?php

// resources/help/articles/ai.followup.php
//
// «Síntese de acompanhamento com IA» — a leitura da Evolução de um aluno.
//
// ESTE É O ÚNICO ARTIGO SOBRE UMA LEITURA QUE É SOBRE UMA PESSOA, e o texto
// diz isso mais de uma vez de propósito. Um professor que leia este artigo tem
// de sair a saber três coisas: que a IA não vê o texto que ele escreveu sobre
// o aluno, que a IA não pode dizer nada sobre o carácter do aluno, e que os
// factos estão em cima e a interpretação em baixo.
//
// Cada afirmação corresponde a uma propriedade do código:
// - «não vê o texto dos registos» — FollowupContext lê `kinds` e `total`, nunca
//   `rows`, onde vive `description`;
// - «não vê o objetivo das intervenções» — o mesmo contexto envia tipo, estado,
//   domínio e eficácia registada, nunca `objective` nem `motive`;
// - «tem sempre de dizer algo positivo» — FollowupSynthesisParser recusa uma
//   resposta sem a secção POSITIVOS;
// - «os factos são calculados» — BuildStudentFactualAlerts e
//   BuildStudentStrengths correm em todos os pedidos, com ou sem IA.

return [
    'title' => 'Síntese de acompanhamento de um aluno com IA',
    'summary' => 'Como pedir uma leitura de conjunto do percurso de um aluno, que dados são usados, e o que a IA nunca pode dizer sobre uma pessoa.',
    'category' => 'Acompanhamento',
    'order' => 30,
    'content' => [
        'Na página de Evolução de um aluno, depois dos registos e das intervenções, existe a secção «Síntese de acompanhamento com IA». Serve para juntar num texto curto o que a página já mostra em várias secções: como está o percurso, o que corre bem, o que pode merecer atenção, o que mudou, e o que valeria a pena verificar numa conversa.',
        'A síntese só acontece quando carrega no botão. Abrir a página nunca chama a IA.',
        'A resposta vem organizada em seis blocos: Síntese, Sinais positivos, Pontos de atenção, O que mudou, Próximo passo a considerar, e Limitações desta leitura. Os sinais positivos aparecem antes dos pontos de atenção, e isso é deliberado — um retrato de um aluno que comece pelos problemas ensina a lê-lo como um problema.',
        'FACTOS EM CIMA, INTERPRETAÇÃO EM BAIXO. As secções «Factos a assinalar» e «Pontos fortes», mais acima na página, são calculadas pelo Lapispro a partir de contagens: quantos TPC estão por realizar, quantos registos de dificuldade existem, qual é o domínio com melhor resultado. Não passam por nenhuma IA e aparecem na mesma se a IA estiver desligada. A síntese é a camada de cima: recebe esses factos e organiza-os em palavras. Se desconfiar da síntese, os factos continuam lá para conferir.',
        'O QUE A IA NÃO VÊ, E É MUITO. Não vê o texto que escreveu nos registos. Não vê o objetivo nem a descrição das intervenções. Não vê observações livres de nenhum tipo. É nesses campos que vive, na prática, aquilo que há de mais sensível sobre um aluno — saúde, acompanhamento clínico, necessidades específicas, contexto familiar, contexto social — e por isso nenhum deles é enviado.',
        'O que é enviado no lugar são categorias e contagens: quantos registos existem e de que tipo, quantas intervenções estão em curso ou concluídas, em que domínio, desde quando, e qual foi a eficácia que o professor registou. Isso chega para dizer «existem três registos de dificuldade neste período» e não chega para dizer porquê — o que é exatamente a intenção.',
        'Tem um custo, e é honesto dizê-lo: uma síntese que não pode ler as suas notas vai por vezes falhar aquilo que mais importa. Quando a evidência não chega, a IA está instruída para o dizer no bloco «Limitações desta leitura», em vez de adivinhar.',
        'Vai também a disciplina, o ano de escolaridade, o período, o resultado atual e o nível, a comparação com o período anterior, a comparação com a média da turma, os valores por domínio, e a autoavaliação registada pelo aluno. Não vai o nome, o número de aluno, o número de processo, nem qualquer identificador: o aluno aparece como «Aluno A».',
        'O QUE A IA NUNCA PODE DIZER. Não pode atribuir nem prever uma nota. Não pode dizer que o aluno deve passar, reprovar ou ser retido. Não pode diagnosticar — nem sugerir que possa existir um diagnóstico — nem recomendar encaminhamento para psicologia, saúde ou educação especial. Não pode caracterizar personalidade, motivação, esforço, maturidade ou capacidade. Não pode rotular. Não pode comentar a família, o contexto social ou a origem de ninguém.',
        'O que pode fazer é descrever o que a evidência mostra, assinalar o que mudou, e propor o que valeria a pena confirmar com o próprio aluno. A diferença entre «o aluno é desorganizado» e «os registos mostram três TPC por realizar» não é de estilo: a primeira é um juízo sobre uma criança feito por um sistema que nunca a viu.',
        'Sobre a autoavaliação, a IA pode assinalar que a perceção do aluno e a evidência disponível podem não coincidir, e sugerir isso como ponto de partida para uma conversa. Não pode concluir daí que o aluno se sobrestima ou se subestima.',
        'A SÍNTESE NÃO ESCREVE NO PERCURSO DO ALUNO. Não cria intervenções, não altera resultados, não regista observações e não fica guardada no percurso do aluno. Desaparece quando sair da página. Se quiser guardar alguma coisa que leu ali, escreve-a você, nos ecrãs que já usa.',
        'Fica registado que uma síntese foi pedida sobre este aluno, por quem e quando — não o texto da síntese nem os dados que a produziram. Uma escola a quem perguntem «foi pedida uma síntese de IA sobre o meu educando?» tem de poder responder.',
        'A IA apoia a análise e pode cometer erros. As decisões pedagógicas continuam a ser do professor.',
        'Se a funcionalidade estiver indisponível, a mensagem no ecrã diz porquê: ou o plano da sua organização não a inclui, ou esta instalação ainda não tem um serviço de IA configurado. A página de Evolução funciona na mesma em qualquer dos casos. Se o aluno ainda não tiver resultado, registo nem intervenção, a síntese também não é pedida — não há percurso para ler.',
    ],
    'keywords' => ['acompanhamento', 'síntese', 'evolução', 'aluno', 'ia', 'inteligência artificial', 'percurso', 'conversa', 'tendência'],
    'related' => ['ai.strategies', 'ai.privacy', 'ai.limits'],
    'contexts' => ['student-progress.student'],
];
