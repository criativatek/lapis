<?php

namespace App\Http\Requests;

use App\Services\Assessment\DeriveCustomScale;
use App\Services\Export\InovarCodeResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * «Personalizar escala»: de 2 a 10 níveis, cada um com nome, corte inteiro
 * «a partir de X %» e código do Inovar (texto livre, o que a escola definiu no
 * Inovar), mais o primeiro nível positivo, o nome da escala e se é a da organização.
 * As mensagens são por campo (`levels.2.from_percent`) para o diálogo as
 * mostrar junto da linha certa.
 */
class ScaleMentionsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'levels' => ['required', 'array', 'min:'.DeriveCustomScale::MIN_LEVELS, 'max:'.DeriveCustomScale::MAX_LEVELS],
            'levels.*' => ['array'],
            'levels.*.label' => ['required', 'string', 'min:1', 'max:64'],
            'levels.*.from_percent' => ['required', 'integer', 'between:0,100'],
            'levels.*.inovar_code' => ['nullable', 'string', 'max:'.InovarCodeResolver::MAX_LENGTH, 'regex:/^\S+$/u'],
            'first_positive_level' => ['required', 'integer', 'min:1', 'max:'.max(DeriveCustomScale::MIN_LEVELS, count((array) $this->input('levels', [])))],
            'profile_name' => ['required', 'string', 'max:120'],
            'scale_name' => ['required', 'string', 'max:120'],
            'use_as_organization_default' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'levels.required' => 'Defina os níveis da escala.',
            'levels.min' => 'A escala tem de ter pelo menos '.DeriveCustomScale::MIN_LEVELS.' níveis.',
            'levels.max' => 'A escala não pode ter mais de '.DeriveCustomScale::MAX_LEVELS.' níveis.',
            'levels.*.label.required' => 'Dê um nome a este nível.',
            'levels.*.label.max' => 'O nome do nível não pode ter mais de 64 caracteres.',
            'levels.*.from_percent.required' => 'Indique a partir de que percentagem começa este nível.',
            'levels.*.from_percent.integer' => 'A percentagem tem de ser um número inteiro.',
            'levels.*.from_percent.between' => 'A percentagem tem de estar entre 0 e 100.',
            'levels.*.inovar_code.max' => 'O código no Inovar não pode ter mais de '.InovarCodeResolver::MAX_LENGTH.' caracteres.',
            'levels.*.inovar_code.regex' => 'O código no Inovar não pode ter espaços.',
            'scale_name.required' => 'Dê um nome à escala.',
            'scale_name.max' => 'O nome da escala não pode ter mais de 120 caracteres.',
            'first_positive_level.required' => 'Indique a partir de que nível a classificação é positiva.',
            'first_positive_level.integer' => 'Escolha um dos níveis da escala.',
            'first_positive_level.min' => 'Escolha um dos níveis da escala.',
            'first_positive_level.max' => 'Escolha um dos níveis da escala.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $levels = array_values((array) $this->input('levels', []));
            $seen = [];
            $previousCut = null;

            foreach ($levels as $index => $level) {
                $name = mb_strtolower(trim((string) $level['label']));

                if (isset($seen[$name])) {
                    $validator->errors()->add("levels.{$index}.label", 'Dois níveis não podem ter o mesmo nome.');
                }
                $seen[$name] = true;

                $cut = (int) $level['from_percent'];

                if ($index === 0 && $cut !== 0) {
                    $validator->errors()->add("levels.{$index}.from_percent", 'O primeiro nível começa sempre em 0 %.');
                } elseif ($previousCut !== null && $cut <= $previousCut) {
                    $validator->errors()->add("levels.{$index}.from_percent", 'Tem de começar acima do nível anterior.');
                }

                $previousCut = $cut;
            }
        });
    }

    /**
     * @return list<array{label: string, from_percent: int, inovar_code: string|null}>
     */
    public function levels(): array
    {
        return array_values(array_map(
            fn (array $level): array => [
                'label' => trim((string) $level['label']),
                'from_percent' => (int) $level['from_percent'],
                'inovar_code' => isset($level['inovar_code']) && $level['inovar_code'] !== '' ? (string) $level['inovar_code'] : null,
            ],
            (array) $this->validated('levels'),
        ));
    }

    public function scaleName(): string
    {
        return trim((string) $this->validated('scale_name'));
    }

    public function usesAsOrganizationDefault(): bool
    {
        return $this->boolean('use_as_organization_default');
    }

    public function firstPositiveLevel(): int
    {
        return (int) $this->validated('first_positive_level');
    }
}
