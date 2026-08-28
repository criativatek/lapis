<?php

// resources/help/articles/students.import.php
//
// Confirmado em App\Http\Controllers\RosterImportController (store, attachPhotos,
// confirm) — routes 'classes.roster-imports.*', a partir da página da turma.
// O ficheiro de alunos é .xls/.xlsx; as fotos, quando existem, vêm de um
// ficheiro Word (modelo EB019) associado por nome numa segunda fase.

return [
    'title' => 'Importar uma pauta de alunos',
    'summary' => 'Inscrever uma turma inteira a partir de um ficheiro Excel, com pré-visualização antes de confirmar.',
    'category' => 'Turmas e alunos',
    'order' => 30,
    'content' => [
        'Em vez de inscrever os alunos um a um, pode importar uma pauta em Excel (.xls ou .xlsx) a partir da página da turma.',
        'A importação nunca inscreve ninguém de imediato: primeiro mostra uma pré-visualização com uma linha por aluno, onde pode corrigir nomes, desmarcar quem não quer incluir, e ver avisos — por exemplo, um nome duplicado no ficheiro, ou um aluno que já está nesta turma e cujos dados em falta vão ser preenchidos, nunca substituídos.',
        'Se a situação de um aluno no ficheiro (por exemplo, «mudou de turma») não for reconhecida, o estado da inscrição fica como está — a importação nunca adivinha um estado a partir de um código desconhecido.',
        'Pode ainda associar fotografias, a partir de um ficheiro Word exportado do Intuitivo (modelo EB019), antes de confirmar. As fotos são associadas às linhas automaticamente por nome; pode corrigir a atribuição manualmente para cada aluno.',
        'Só depois de rever a pré-visualização — e de associar as fotos, se for esse o caso — é que confirma a importação, e é só nesse momento que os alunos ficam inscritos.',
        'Um aluno já inscrito nesta turma nunca é duplicado por uma reimportação: os seus dados em falta são preenchidos, e o que já existir mantém-se.',
    ],
    'keywords' => ['importar', 'importação', 'pauta', 'excel', 'alunos em massa', 'fotos', 'intuitivo'],
    'related' => ['students.enroll', 'classes.create'],
    'contexts' => ['classes.roster-imports.store'],
];
