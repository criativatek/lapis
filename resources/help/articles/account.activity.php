<?php

// resources/help/articles/account.activity.php
//
// Confirmado em App\Http\Controllers\ActivityController e routes/web.php
// (0.145.1): 'activity.index' («Minha atividade») não tem gate de módulo e
// filtra por causer_id; 'activity.organization' («Auditoria da organização»)
// exige `module:audit_log`, que no EntitlementsSeeder só o Institucional tem,
// e dentro dela o responsável vê tudo e um membro só o seu
// (AuditEvent::scopeVisibleTo). O limite das 100 ações vem do controlador.
//
// A frase sobre o que NÃO aparece é deliberada: lançar notas e criar turmas,
// alunos, instrumentos ou registos não geram evento de auditoria hoje. Prometer
// «tudo o que fez» seria inventar.

return [
    'title' => 'Minha atividade e auditoria da organização',
    'summary' => 'Confirmar as ações que registou e, no plano Institucional, consultar a atividade de toda a organização.',
    'category' => 'Conta e dados',
    'order' => 20,
    'content' => [
        '«Minha atividade» mostra as ações que registou na organização em que está a trabalhar, das mais recentes para as mais antigas — por exemplo, classificações confirmadas ou publicadas, relatórios finalizados ou exportados, aulas marcadas como lecionadas, assiduidade registada ou pedidos feitos à IA. Abre-se a partir do Painel do Professor e está disponível em qualquer plano.',
        'Só vê as suas próprias ações. Nunca aparecem ações de colegas, nem ações feitas noutra organização a que também pertença: para as ver, mude de organização.',
        'Não aparece tudo o que faz na aplicação. Ficam registadas as ações com peso próprio — decisões de avaliação, documentos, aulas, importações e exportações, convites e alterações à organização. O lançamento de notas numa grelha, ou a criação de turmas, alunos e elementos de avaliação, não aparecem nesta lista.',
        'O registo é imutável: não é possível editar nem apagar uma entrada. São mostradas as 100 ações mais recentes.',
        'No plano Institucional existe também «Auditoria da organização», em Instituição e no Painel. O responsável da organização vê aí a atividade de todos os membros, com o nome de quem fez cada ação; um membro vê apenas a sua. Nos outros planos esta vista não está incluída e o link não aparece.',
    ],
    'keywords' => ['atividade', 'minha atividade', 'registo de atividade', 'histórico', 'auditoria', 'quem fez', 'ações'],
    'related' => ['data.export'],
    'contexts' => ['activity.index', 'activity.organization'],
];
