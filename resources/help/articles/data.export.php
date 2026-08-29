<?php

// resources/help/articles/data.export.php
//
// Confirmado em App\Http\Controllers\DataExportController — route
// 'data-exports.index'/'data-exports.store'/'data-exports.download', e no
// próprio docblock do controlador: «disponível em cada plano — é
// portabilidade, não uma funcionalidade paga».
//
// A frase sobre repor dados foi corrigida no realinhamento Base/Pro: a rota
// 'data-imports.*' passou a exigir `module:data_backup_restore` (routes/web.php),
// conforme a Matriz Mestre §7, que marca «Restauro self-service» como Pro e
// Institucional. O artigo deixaria de outro modo a promessa de reposição sem
// qualquer ressalva a quem não a pode executar. A exportação — as três rotas
// acima — continua sem gate nenhum e o texto continua a dizê-lo.

return [
    'title' => 'Exportar os seus dados',
    'summary' => 'Como gerar e transferir uma cópia dos seus dados, disponível em qualquer plano.',
    'category' => 'Conta e dados',
    'order' => 10,
    'content' => [
        'Pode gerar, a qualquer momento, uma exportação com os dados da sua organização — turmas, alunos, avaliações, resultados e o resto do que registou.',
        'A exportação é gerada como um ficheiro compactado (.zip) e fica disponível para transferir durante 24 horas; depois disso é eliminada automaticamente.',
        'Esta funcionalidade está disponível em qualquer plano — é uma forma de garantir que os seus dados são sempre seus e sempre portáveis, não uma funcionalidade paga.',
        'A exportação reflete apenas os dados a que já tem acesso através da sua conta — não acrescenta nem revela nada que não pudesse já consultar na aplicação.',
        'Repor esses dados mais tarde — na mesma organização ou numa nova — faz-se através da importação de dados, a partir de uma exportação gerada anteriormente. A reposição de uma cópia completa está disponível conforme o seu plano; a exportação em si não depende do plano.',
    ],
    'keywords' => ['exportar', 'exportação', 'dados', 'backup', 'cópia de segurança', 'portabilidade'],
    'related' => ['reports.view'],
    'contexts' => ['data-exports.index'],
];
