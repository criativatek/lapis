<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Centro de Ajuda — search vocabulary
    |--------------------------------------------------------------------------
    |
    | The Centro de Ajuda's search is a full scan over nine authored articles
    | (App\Support\Help\HelpCenter). It has always matched the query as ONE
    | literal string, which works for «avaliação» and fails for every way a
    | teacher actually asks a question: «por onde começo», «o que faço
    | primeiro», «quais são as primeiras coisas a fazer na aplicação?» all
    | returned nothing while the «Começar a utilizar o Lapispro» article sat
    | there answering exactly that.
    |
    | This file is the vocabulary that closes that gap, and it is deliberately
    | DATA rather than rules in code: a colleague who notices a question that
    | finds nothing adds a word here, not a branch in a scorer. There is no
    | model, no embedding and no external service — the behaviour is a pure
    | function of this file plus the articles, so the same query always
    | returns the same articles in the same order.
    |
    | App\Support\Help\HelpSearchVocabulary is the typed reader over it, the
    | same "static file as source + typed reader on top" convention
    | config/retention.php and config/trial.php already use.
    |
    | Everything below may be written naturally, with accents and uppercase.
    | The reader folds it (lowercase, accent-stripped, punctuation removed)
    | before anything is compared, so «Ponderação» and «ponderacao» are the
    | same entry and there is no need to pre-normalize by hand.
    |
    */

    /*
    | Words carrying no topical meaning in a help query — the scaffolding of a
    | question rather than its subject. Dropped before matching, so «como
    | começar» and «começar» ask the same thing.
    |
    | KEEP THIS LIST BORING. Every word here is a word the search can no
    | longer find, so it must contain nothing a teacher could plausibly be
    | looking for. «ano», «nota», «conta» and «peso» are question-shaped words
    | in ordinary Portuguese and subjects in this product — they are not here,
    | and must not be added.
    */
    'stopwords' => [
        // Articles and contractions.
        'a', 'à', 'ao', 'aos', 'as', 'às', 'o', 'os', 'um', 'uma', 'uns', 'umas',
        'de', 'do', 'da', 'dos', 'das', 'em', 'no', 'na', 'nos', 'nas', 'num', 'numa',
        'por', 'pelo', 'pela', 'pelos', 'pelas', 'para', 'com', 'sem', 'sobre',
        'entre', 'até', 'desde', 'e', 'ou', 'mas', 'que', 'se',

        // Interrogatives — the frame of the question, never its subject.
        'como', 'qual', 'quais', 'quando', 'onde', 'quem', 'porque', 'porquê', 'quê',

        // Copulas and auxiliaries.
        'é', 'ser', 'sou', 'são', 'está', 'estão', 'estar', 'ter', 'tem', 'tenho', 'há',

        // Pronouns and demonstratives.
        'eu', 'me', 'mim', 'meu', 'minha', 'meus', 'minhas', 'seu', 'sua', 'nós',
        'isto', 'isso', 'este', 'esta', 'esse', 'essa', 'aquele', 'aquela',

        // Filler common to «o que faço primeiro» / «primeiras coisas a fazer».
        'não', 'sim', 'já', 'aqui', 'ali', 'aí', 'coisa', 'coisas',
        'fazer', 'faço', 'faz', 'fazem', 'posso', 'quero', 'preciso', 'devo', 'deve',
        'consigo', 'gostava', 'gostaria',
        'mais', 'menos', 'muito', 'todo', 'toda', 'todos', 'todas', 'algum', 'alguma',
    ],

    /*
    | Groups of interchangeable words. Membership is SYMMETRIC: any term in a
    | group matches every other term in the same group, so a group is read as
    | "these all mean the same thing here" and never as a direction.
    |
    | A group is not a thesaurus of Portuguese — it is a claim about THIS
    | product's vocabulary. Add a word when a real query missed an article
    | that answers it; do not add words defensively.
    */
    'synonyms' => [

        // «Por onde começo?» — every way the first question gets asked. This
        // is the group the whole change exists for.
        ['começar', 'começo', 'começa', 'comece', 'início', 'iniciar', 'inicial',
            'primeiro', 'primeiros', 'primeira', 'primeiras', 'passo', 'passos',
            'arranque', 'onboarding'],

        // "How does this thing work" — generic, and deliberately pointed at
        // the same place «começar» is.
        ['ajuda', 'ajudar', 'dúvida', 'dúvidas', 'guia', 'tutorial',
            'funciona', 'funcionamento', 'usar', 'utilizar', 'uso', 'utilização'],

        // The school year. «escolar» also prefixes «escolaridade», which is
        // why «ano escolar» reaches both the year and the year-of-schooling.
        ['letivo', 'letivos', 'escolar', 'escolares'],

        ['aluno', 'alunos', 'estudante', 'estudantes', 'discente', 'discentes'],

        ['turma', 'turmas', 'classe', 'classes'],

        ['criar', 'criação', 'crio', 'adicionar', 'acrescentar', 'inserir',
            'novo', 'nova', 'novos', 'novas'],

        ['inscrever', 'inscrição', 'inscrições', 'inscrito', 'matricular', 'matrícula'],

        ['avaliar', 'avaliação', 'avaliações', 'avalia', 'avalio'],

        ['nota', 'notas', 'classificação', 'classificações',
            'resultado', 'resultados', 'cotação', 'lançar'],

        ['critério', 'critérios', 'domínio', 'domínios',
            'peso', 'pesos', 'ponderação', 'ponderações'],

        ['configurar', 'configuração', 'definir', 'definição', 'parametrizar'],

        ['relatório', 'relatórios', 'pauta', 'pautas', 'documento', 'documentos'],

        ['instrumento', 'instrumentos', 'elemento', 'elementos',
            'teste', 'testes', 'ficha', 'fichas', 'trabalho', 'trabalhos'],

    ],

];
