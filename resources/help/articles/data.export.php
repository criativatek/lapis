<?php

// resources/help/articles/data.export.php
//
// Confirmado em App\Http\Controllers\DataExportController — route
// 'data-exports.index'/'data-exports.store'/'data-exports.download', e no
// próprio docblock do controlador: «disponível em cada plano — é
// portabilidade, não uma funcionalidade paga».

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
        'Se pretender repor esses dados mais tarde — na mesma organização ou numa nova — pode fazê-lo através da importação de dados, a partir de uma exportação gerada anteriormente.',
    ],
    'keywords' => ['exportar', 'exportação', 'dados', 'backup', 'cópia de segurança', 'portabilidade'],
    'related' => ['reports.view'],
    'contexts' => ['data-exports.index'],
];
