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
    'active_url' => 'O campo :attribute não é um URL válido.',
    'after' => 'O campo :attribute tem de ser uma data posterior a :date.',
    'after_or_equal' => 'O campo :attribute tem de ser uma data igual ou posterior a :date.',
    'alpha' => 'O campo :attribute só pode conter letras.',
    'alpha_dash' => 'O campo :attribute só pode conter letras, números, hífenes e underscores.',
    'alpha_num' => 'O campo :attribute só pode conter letras e números.',
    'array' => 'O campo :attribute tem de ser uma lista.',
    'before' => 'O campo :attribute tem de ser uma data anterior a :date.',
    'before_or_equal' => 'O campo :attribute tem de ser uma data igual ou anterior a :date.',
    'between' => [
        'array' => 'O campo :attribute tem de ter entre :min e :max elementos.',
        'file' => 'O ficheiro :attribute tem de ter entre :min e :max kilobytes.',
        'numeric' => 'O campo :attribute tem de estar entre :min e :max.',
        'string' => 'O campo :attribute tem de ter entre :min e :max caracteres.',
    ],
    'boolean' => 'O campo :attribute tem de ser verdadeiro ou falso.',
    'confirmed' => 'A confirmação de :attribute não coincide.',
    'current_password' => 'A palavra-passe está incorreta.',
    'date' => 'O campo :attribute não é uma data válida.',
    'date_equals' => 'O campo :attribute tem de ser uma data igual a :date.',
    'date_format' => 'O campo :attribute não corresponde ao formato :format.',
    'decimal' => 'O campo :attribute tem de ter :decimal casas decimais.',
    'different' => 'Os campos :attribute e :other têm de ser diferentes.',
    'digits' => 'O campo :attribute tem de ter :digits dígitos.',
    'digits_between' => 'O campo :attribute tem de ter entre :min e :max dígitos.',
    'email' => 'O campo :attribute tem de ser um endereço de e-mail válido.',
    'ends_with' => 'O campo :attribute tem de terminar com um dos seguintes valores: :values.',
    'enum' => 'O valor selecionado em :attribute é inválido.',
    'exists' => 'O valor selecionado em :attribute é inválido.',
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
    'image' => 'O campo :attribute tem de ser uma imagem.',
    'in' => 'O valor selecionado em :attribute é inválido.',
    'integer' => 'O campo :attribute tem de ser um número inteiro.',
    'json' => 'O campo :attribute tem de ser um texto JSON válido.',
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
    'max' => [
        'array' => 'O campo :attribute não pode ter mais do que :max elementos.',
        'file' => 'O ficheiro :attribute não pode ter mais do que :max kilobytes.',
        'numeric' => 'O campo :attribute não pode ser maior do que :max.',
        'string' => 'O campo :attribute não pode ter mais do que :max caracteres.',
    ],
    'mimes' => 'O campo :attribute tem de ser um ficheiro do tipo: :values.',
    'mimetypes' => 'O campo :attribute tem de ser um ficheiro do tipo: :values.',
    'min' => [
        'array' => 'O campo :attribute tem de ter pelo menos :min elementos.',
        'file' => 'O ficheiro :attribute tem de ter pelo menos :min kilobytes.',
        'numeric' => 'O campo :attribute tem de ser pelo menos :min.',
        'string' => 'O campo :attribute tem de ter pelo menos :min caracteres.',
    ],
    'not_in' => 'O valor selecionado em :attribute é inválido.',
    'not_regex' => 'O formato do campo :attribute é inválido.',
    'numeric' => 'O campo :attribute tem de ser um número.',
    'present' => 'O campo :attribute tem de estar presente.',
    'prohibited' => 'O campo :attribute não é permitido.',
    'regex' => 'O formato do campo :attribute é inválido.',
    'required' => 'O campo :attribute é obrigatório.',
    'required_if' => 'O campo :attribute é obrigatório quando :other é :value.',
    'required_unless' => 'O campo :attribute é obrigatório a não ser que :other esteja em :values.',
    'required_with' => 'O campo :attribute é obrigatório quando :values está presente.',
    'required_without' => 'O campo :attribute é obrigatório quando :values não está presente.',
    'same' => 'Os campos :attribute e :other têm de coincidir.',
    'size' => [
        'array' => 'O campo :attribute tem de conter :size elementos.',
        'file' => 'O ficheiro :attribute tem de ter :size kilobytes.',
        'numeric' => 'O campo :attribute tem de ser :size.',
        'string' => 'O campo :attribute tem de ter :size caracteres.',
    ],
    'starts_with' => 'O campo :attribute tem de começar com um dos seguintes valores: :values.',
    'string' => 'O campo :attribute tem de ser texto.',
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
