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
// A lista de áreas corresponde aos eventos registados na 0.145.1 (turmas,
// inscrições, grupos, aulas e sumários, assiduidade, elementos de avaliação,
// notas, classificações, avaliações intercalares, medidas, registos,
// autoavaliações, relatórios). Abrir páginas, pesquisar e filtrar não ficam
// registados — por isso o texto não promete «tudo o que fez».

return [
    'title' => 'Minha atividade e auditoria da organização',
    'summary' => 'Confirmar as principais ações que realizou e, no plano Institucional, consultar a atividade de toda a organização.',
    'category' => 'Conta e dados',
    'order' => 20,
    'content' => [
        '«Minha atividade» mostra as principais ações pedagógicas e de configuração realizadas por si na organização em que está a trabalhar, das mais recentes para as mais antigas. Abre-se a partir do Painel do Professor e está disponível em qualquer plano.',
        'Serve para confirmar rapidamente que uma ação ficou feita: turmas criadas, alteradas, arquivadas ou eliminadas; alunos inscritos ou removidos; aulas, sumários e assiduidade; elementos de avaliação e notas lançadas; classificações; estratégias e medidas; registos; autoavaliações; relatórios e exportações.',
        'Só vê as suas próprias ações. Nunca aparecem ações de colegas, nem ações feitas noutra organização a que também pertença: para as ver, mude de organização.',
        'Não fica registado cada clique: abrir páginas, pesquisar, filtrar ou consultar não aparecem. As entradas não repetem nomes de alunos nem o conteúdo de notas e observações — indicam a ação e a turma.',
        'O registo é imutável: não é possível editar nem apagar uma entrada. São mostradas as 100 ações mais recentes.',
        'No plano Institucional existe também «Auditoria da organização», em Instituição e no Painel. O responsável da organização vê aí a atividade de todos os membros, com o nome de quem fez cada ação; um membro vê apenas a sua. Nos outros planos esta vista não está incluída e o link não aparece.',
    ],
    'keywords' => ['atividade', 'minha atividade', 'registo de atividade', 'histórico', 'auditoria', 'quem fez', 'ações', 'confirmar'],
    'related' => ['data.export'],
    'contexts' => ['activity.index', 'activity.organization'],
];
