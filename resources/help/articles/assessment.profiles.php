<?php

// resources/help/articles/assessment.profiles.php
//
// Confirmado em App\Http\Controllers\AssessmentProfileController (create,
// store, edit, update, activate) e resources/js/pages/assessment-profiles/
// ProfileForm.vue — routes 'assessment-profiles.*', módulo assessment_profiles.

return [
    'title' => 'Configurar perfis, domínios e pesos',
    'summary' => 'Como criar um perfil de avaliação, definir os domínios e as suas ponderações, e ativá-lo.',
    'category' => 'Avaliação',
    'order' => 10,
    'content' => [
        'Um perfil de avaliação define COMO uma turma é avaliada: em que domínios (por exemplo, «Conhecimentos», «Comunicação»), com que peso cada um tem na classificação final, e segundo que escala.',
        'Ao criar um perfil, indica um nome, o ano letivo e a disciplina a que se destina, e opcionalmente o ano de escolaridade.',
        'Os domínios são a parte central: cada um tem um nome e um peso percentual, e a soma de todos os pesos tem de ser exatamente 100%. O Lapispro não deixa guardar um perfil cuja soma não feche.',
        'A escala determina como os resultados são traduzidos em classificação — pode escolher uma escala já existente ou criar uma nova, sem sair do formulário.',
        'Guardar um perfil novo cria-o como RASCUNHO. Um rascunho pode ser editado livremente e ainda não pode ser associado a nenhuma turma.',
        'Ativar o perfil torna-o definitivo: a versão fica congelada (imutável) a partir desse momento, e só então pode ser associada a uma turma. Se voltar a editar um perfil já ativo, o Lapispro abre um novo rascunho — a versão ativa e os resultados já calculados com ela mantêm-se intactos até ativar a nova versão.',
        'Disponível conforme o seu plano: reutilizar um perfil de um ano letivo anterior noutro ano é uma forma de poupar trabalho quando os domínios não mudam de um ano para o outro.',
    ],
    'keywords' => ['perfil de avaliação', 'domínios', 'pesos', 'ponderação', 'escala', 'critérios', 'ativar perfil'],
    'related' => ['classes.create', 'instruments.create', 'results.record'],
    'contexts' => ['assessment-profiles.create', 'assessment-profiles.edit'],
    'plan_note' => 'A reutilização de um perfil de um ano letivo anterior está disponível conforme o seu plano.',
];
