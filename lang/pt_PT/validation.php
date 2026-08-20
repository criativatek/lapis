<?php

/*
|--------------------------------------------------------------------------
| Mensagens de validação — português europeu
|--------------------------------------------------------------------------
|
| A aplicação corre com APP_LOCALE=pt_PT e APP_FALLBACK_LOCALE=pt_PT, pelo que
| sem este ficheiro o Laravel devolve a própria chave ("validation.required")
| ao professor. §23.4 exige mensagens de erro claras e específicas.
|
| Tratamento por "você" implícito (sem pronome), como é hábito em interfaces
| portuguesas de trabalho.
|
*/

return [

    'accepted' => 'O campo :attribute tem de ser aceite.',
    'accepted_if' => 'O campo :attribute tem de ser aceite quando :other é :value.',
    'active_url' => 'O campo :attribute não é um URL válido.',
    'after' => 'O campo :attribute tem de ser uma data posterior a :date.',
    'after_or_equal' => 'O campo :attribute tem de ser uma data igual ou posterior a :date.',
    'alpha' => 'O campo :attribute só pode conter letras.',
    'alpha_dash' => 'O campo :attribute só pode conter letras, números, hífenes e underscores.',
    'alpha_num' => 'O campo :attribute só pode conter letras e números.',
    'any_of' => 'O campo :attribute é inválido.',
    'array' => 'O campo :attribute tem de ser uma lista.',
    'ascii' => 'O campo :attribute só pode conter caracteres alfanuméricos e símbolos de um byte.',
    'before' => 'O campo :attribute tem de ser uma data anterior a :date.',
    'before_or_equal' => 'O campo :attribute tem de ser uma data igual ou anterior a :date.',
    'between' => [
        'array' => 'O campo :attribute tem de ter entre :min e :max elementos.',
        'file' => 'O ficheiro :attribute tem de ter entre :min e :max kilobytes.',
        'numeric' => 'O campo :attribute tem de estar entre :min e :max.',
        'string' => 'O campo :attribute tem de ter entre :min e :max caracteres.',
    ],
    'boolean' => 'O campo :attribute tem de ser verdadeiro ou falso.',
    'can' => 'O campo :attribute contém um valor não autorizado.',
    'confirmed' => 'A confirmação de :attribute não coincide.',
    'contains' => 'Falta um valor obrigatório no campo :attribute.',
    'current_password' => 'A palavra-passe está incorreta.',
    'date' => 'O campo :attribute não é uma data válida.',
    'date_equals' => 'O campo :attribute tem de ser uma data igual a :date.',
    'date_format' => 'O campo :attribute não corresponde ao formato :format.',
    'decimal' => 'O campo :attribute tem de ter :decimal casas decimais.',
    'declined' => 'O campo :attribute tem de ser recusado.',
    'declined_if' => 'O campo :attribute tem de ser recusado quando :other é :value.',
    'different' => 'Os campos :attribute e :other têm de ser diferentes.',
    'digits' => 'O campo :attribute tem de ter :digits dígitos.',
    'digits_between' => 'O campo :attribute tem de ter entre :min e :max dígitos.',
    'dimensions' => 'A imagem :attribute tem dimensões inválidas.',
    'distinct' => 'O campo :attribute tem um valor duplicado.',
    'doesnt_contain' => 'O campo :attribute não pode conter nenhum dos seguintes valores: :values.',
    'doesnt_end_with' => 'O campo :attribute não pode terminar com nenhum dos seguintes valores: :values.',
    'doesnt_start_with' => 'O campo :attribute não pode começar por nenhum dos seguintes valores: :values.',
    'email' => 'O campo :attribute tem de ser um endereço de e-mail válido.',
    'encoding' => 'O campo :attribute tem de estar codificado em :encoding.',
    'ends_with' => 'O campo :attribute tem de terminar com um dos seguintes valores: :values.',
    'enum' => 'O valor selecionado em :attribute é inválido.',
    'exists' => 'O valor selecionado em :attribute é inválido.',
    'extensions' => 'O ficheiro :attribute tem de ter uma das seguintes extensões: :values.',
    'file' => 'O campo :attribute tem de ser um ficheiro.',
    'filled' => 'O campo :attribute tem de ser preenchido.',
    'gt' => [
        'array' => 'O campo :attribute tem de ter mais do que :value elementos.',
        'file' => 'O ficheiro :attribute tem de ser maior do que :value kilobytes.',
        'numeric' => 'O campo :attribute tem de ser maior do que :value.',
        'string' => 'O campo :attribute tem de ter mais do que :value caracteres.',
    ],
    'gte' => [
        'array' => 'O campo :attribute tem de ter :value ou mais elementos.',
        'file' => 'O ficheiro :attribute tem de ser igual ou maior do que :value kilobytes.',
        'numeric' => 'O campo :attribute tem de ser igual ou maior do que :value.',
        'string' => 'O campo :attribute tem de ter :value ou mais caracteres.',
    ],
    'hex_color' => 'O campo :attribute tem de ser uma cor hexadecimal válida.',
    'image' => 'O campo :attribute tem de ser uma imagem.',
    'in' => 'O valor selecionado em :attribute é inválido.',
    'in_array' => 'O campo :attribute tem de existir em :other.',
    'in_array_keys' => 'O campo :attribute tem de conter pelo menos uma das seguintes chaves: :values.',
    'integer' => 'O campo :attribute tem de ser um número inteiro.',
    'ip' => 'O campo :attribute tem de ser um endereço IP válido.',
    'ipv4' => 'O campo :attribute tem de ser um endereço IPv4 válido.',
    'ipv6' => 'O campo :attribute tem de ser um endereço IPv6 válido.',
    'json' => 'O campo :attribute tem de ser um texto JSON válido.',
    'list' => 'O campo :attribute tem de ser uma lista.',
    'lowercase' => 'O campo :attribute tem de estar em minúsculas.',
    'lt' => [
        'array' => 'O campo :attribute tem de ter menos do que :value elementos.',
        'file' => 'O ficheiro :attribute tem de ser menor do que :value kilobytes.',
        'numeric' => 'O campo :attribute tem de ser menor do que :value.',
        'string' => 'O campo :attribute tem de ter menos do que :value caracteres.',
    ],
    'lte' => [
        'array' => 'O campo :attribute não pode ter mais do que :value elementos.',
        'file' => 'O ficheiro :attribute tem de ser igual ou menor do que :value kilobytes.',
        'numeric' => 'O campo :attribute tem de ser igual ou menor do que :value.',
        'string' => 'O campo :attribute tem de ter :value ou menos caracteres.',
    ],
    'mac_address' => 'O campo :attribute tem de ser um endereço MAC válido.',
    'max' => [
        'array' => 'O campo :attribute não pode ter mais do que :max elementos.',
        'file' => 'O ficheiro :attribute não pode ter mais do que :max kilobytes.',
        'numeric' => 'O campo :attribute não pode ser maior do que :max.',
        'string' => 'O campo :attribute não pode ter mais do que :max caracteres.',
    ],
    'max_digits' => 'O campo :attribute não pode ter mais de :max dígitos.',
    'mimes' => 'O campo :attribute tem de ser um ficheiro do tipo: :values.',
    'mimetypes' => 'O campo :attribute tem de ser um ficheiro do tipo: :values.',
    'min' => [
        'array' => 'O campo :attribute tem de ter pelo menos :min elementos.',
        'file' => 'O ficheiro :attribute tem de ter pelo menos :min kilobytes.',
        'numeric' => 'O campo :attribute tem de ser pelo menos :min.',
        'string' => 'O campo :attribute tem de ter pelo menos :min caracteres.',
    ],
    'min_digits' => 'O campo :attribute tem de ter pelo menos :min dígitos.',
    'missing' => 'O campo :attribute não pode estar presente.',
    'missing_if' => 'O campo :attribute não pode estar presente quando :other é :value.',
    'missing_unless' => 'O campo :attribute não pode estar presente a não ser que :other seja :value.',
    'missing_with' => 'O campo :attribute não pode estar presente quando :values está presente.',
    'missing_with_all' => 'O campo :attribute não pode estar presente quando :values estão presentes.',
    'multiple_of' => 'O campo :attribute tem de ser múltiplo de :value.',
    'not_in' => 'O valor selecionado em :attribute é inválido.',
    'not_regex' => 'O formato do campo :attribute é inválido.',
    'numeric' => 'O campo :attribute tem de ser um número.',
    'password' => [
        // MAIS EXPLÍCITAS DO QUE O ORIGINAL, de propósito. Em produção uma
        // palavra-passe passa por seis regras (§AppServiceProvider), e o
        // Laravel devolve uma falha de cada vez: quem lê isto está a meio de
        // entrar na aplicação e precisa de saber o que corrigir, não só o que
        // está errado.
        'letters' => 'A :attribute tem de conter pelo menos uma letra.',
        'mixed' => 'A :attribute tem de conter pelo menos uma letra maiúscula e uma minúscula.',
        'numbers' => 'A :attribute tem de conter pelo menos um algarismo.',
        'symbols' => 'A :attribute tem de conter pelo menos um símbolo, como ! ? @ ou #.',
        'uncompromised' => 'Esta :attribute já apareceu numa fuga de dados pública e não pode ser usada. Escolha outra — de preferência várias palavras sem relação entre si.',
    ],
    'present' => 'O campo :attribute tem de estar presente.',
    'present_if' => 'O campo :attribute tem de estar presente quando :other é :value.',
    'present_unless' => 'O campo :attribute tem de estar presente a não ser que :other seja :value.',
    'present_with' => 'O campo :attribute tem de estar presente quando :values está presente.',
    'present_with_all' => 'O campo :attribute tem de estar presente quando :values estão presentes.',
    'prohibited' => 'O campo :attribute não é permitido.',
    'prohibited_if' => 'O campo :attribute não é permitido quando :other é :value.',
    'prohibited_if_accepted' => 'O campo :attribute não é permitido quando :other é aceite.',
    'prohibited_if_declined' => 'O campo :attribute não é permitido quando :other é recusado.',
    'prohibited_unless' => 'O campo :attribute não é permitido a não ser que :other esteja em :values.',
    'prohibits' => 'O campo :attribute impede que :other esteja presente.',
    'regex' => 'O formato do campo :attribute é inválido.',
    'required' => 'O campo :attribute é obrigatório.',
    'required_array_keys' => 'O campo :attribute tem de conter entradas para: :values.',
    'required_if' => 'O campo :attribute é obrigatório quando :other é :value.',
    'required_if_accepted' => 'O campo :attribute é obrigatório quando :other é aceite.',
    'required_if_declined' => 'O campo :attribute é obrigatório quando :other é recusado.',
    'required_unless' => 'O campo :attribute é obrigatório a não ser que :other esteja em :values.',
    'required_with' => 'O campo :attribute é obrigatório quando :values está presente.',
    'required_with_all' => 'O campo :attribute é obrigatório quando :values estão presentes.',
    'required_without' => 'O campo :attribute é obrigatório quando :values não está presente.',
    'required_without_all' => 'O campo :attribute é obrigatório quando nenhum de :values está presente.',
    'same' => 'Os campos :attribute e :other têm de coincidir.',
    'size' => [
        'array' => 'O campo :attribute tem de conter :size elementos.',
        'file' => 'O ficheiro :attribute tem de ter :size kilobytes.',
        'numeric' => 'O campo :attribute tem de ser :size.',
        'string' => 'O campo :attribute tem de ter :size caracteres.',
    ],
    'starts_with' => 'O campo :attribute tem de começar com um dos seguintes valores: :values.',
    'string' => 'O campo :attribute tem de ser texto.',
    'timezone' => 'O campo :attribute tem de ser um fuso horário válido.',
    'ulid' => 'O campo :attribute tem de ser um ULID válido.',
    'unique' => 'O valor de :attribute já está a ser utilizado.',
    'uploaded' => 'Não foi possível carregar o ficheiro :attribute.',
    'uppercase' => 'O campo :attribute tem de estar em maiúsculas.',
    'url' => 'O campo :attribute tem de ser um URL válido.',
    'uuid' => 'O campo :attribute tem de ser um UUID válido.',

    /*
    |--------------------------------------------------------------------------
    | Nomes dos campos
    |--------------------------------------------------------------------------
    |
    | Substituem o nome técnico da coluna pelo termo que o professor reconhece.
    |
    */

    'attributes' => [
        'name' => 'nome',
        'email' => 'e-mail',
        'password' => 'palavra-passe',
        'label' => 'designação',
        'code' => 'código',
        'starts_on' => 'data de início',
        'ends_on' => 'data de fim',
        'status' => 'estado',
        'country_code' => 'país',
        'region_code' => 'região',
        'periods' => 'períodos',
        'academic_year_id' => 'ano letivo',
        'subject_id' => 'disciplina',
        'grade_level' => 'ano de escolaridade',
        'scale_id' => 'escala',
        'domains' => 'domínios',
        'assessment_profile_version_id' => 'perfil de avaliação',
        'class_number' => 'número',
        'enrolled_on' => 'data de entrada',
        'school_number' => 'número de aluno',
        'description' => 'descrição',
    ],

    'custom' => [],
];
