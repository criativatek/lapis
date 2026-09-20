<?php

namespace App\Http\Requests\SubjectParticipation;

use App\Models\Enrollment;
use App\Models\ProfileVersionPeriod;
use App\Models\ScaleLevel;
use App\Models\SchoolClass;
use App\Models\SubjectParticipation;
use App\Rules\BelongsToCurrentOrganization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Regista, ou corrige, a classificação externa de uma inscrição.
 *
 * PELO MENOS UM VALOR é verificado outra vez em `RecordExternalSubjectResult`
 * — a mesma condição, dita aqui só para dar um erro de campo legível em vez
 * de deixar a ação ser a primeira a recusar.
 *
 * `period_id` NÃO USA `BelongsToCurrentOrganization`: `profile_version_periods`
 * não tem `organization_id` próprio — pertence a uma versão de perfil, que
 * pertence a uma disciplina, e é a TURMA da rota (já resolvida pelo global
 * scope de `SchoolClass`) que decide que períodos são válidos: só os da sua
 * própria versão de perfil. Um período de outra versão é tão inválido como um
 * de outra organização, e é essa pertença que a regra verifica.
 *
 * `scale_level_id` SÓ PODE SER UM NÍVEL DA ESCALA DESTA TURMA (req H4.3) —
 * nunca «de qualquer escala desta organização ou do sistema». `resolveExternalLevel()`
 * em `BuildClassStatistics` já impõe a mesma condição do lado da leitura: um
 * nível de outra escala colocaria o aluno numa banda que a escala desta turma
 * nunca decidiu. A verificação de organização/sistema fica como SEGUNDA linha
 * de defesa, não a única: um id de nível que nem pertença à organização é
 * ainda mais claramente inválido.
 */
class RecordExternalSubjectResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $schoolClass = $this->route('class');

        return $user !== null
            && $schoolClass instanceof SchoolClass
            && $user->can('manage', [SubjectParticipation::class, $schoolClass]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $schoolClass = $this->route('class');
        $versionId = $schoolClass instanceof SchoolClass ? $schoolClass->assessment_profile_version_id : null;

        return [
            'enrollment_id' => ['required', 'integer', new BelongsToCurrentOrganization(Enrollment::class)],
            'origin' => ['required', 'string', 'max:64'],
            'recorded_on' => ['required', 'date_format:Y-m-d'],
            'period_id' => [
                'nullable',
                'integer',
                Rule::exists(ProfileVersionPeriod::class, 'id')->where('assessment_profile_version_id', $versionId),
            ],
            'scale_level_id' => ['nullable', 'integer'],
            // 16, E NÃO 64: é a largura real de
            // `external_subject_results.level_code`. Validar mais do que a
            // coluna aceita empurraria a recusa para o MySQL, que a devolveria
            // como erro de servidor em vez de um erro de campo legível ao lado
            // da caixa onde o professor escreveu o nível.
            'level_code' => ['nullable', 'string', 'max:16'],
            // A coluna é `DECIMAL(6,3)` — 999.999 é o maior valor que cabe lá
            // dentro, e nenhuma escala desta aplicação (0–20, 0–100, 1–5) se
            // aproxima disso. Sem estes limites um valor maior passaria a
            // validação e só rebentaria no MySQL, como erro de servidor em vez
            // de erro de campo. Negativo não é recusado por ser impossível numa
            // escala, mas por nenhuma das escalas suportadas o admitir.
            'numeric_value' => ['nullable', 'numeric', 'min:0', 'max:999.999'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $scaleLevelId = $this->input('scale_level_id');
            $levelCode = $this->input('level_code');
            $numericValue = $this->input('numeric_value');

            // A MESMA CONDIÇÃO QUE RecordExternalSubjectResult::execute() já
            // impõe — dita aqui outra vez só para produzir um erro de campo
            // legível em vez de deixar a ação recusar primeiro.
            if ($scaleLevelId === null && $levelCode === null && $numericValue === null) {
                $validator->errors()->add(
                    'scale_level_id',
                    __('Indique pelo menos um nível de escala, um código de nível ou um valor numérico.'),
                );
            }

            if ($scaleLevelId !== null) {
                $organizationId = app(CurrentOrganization::class)->id();
                $schoolClass = $this->route('class');
                $classScaleId = $schoolClass instanceof SchoolClass
                    ? $schoolClass->profileVersion?->scale_id
                    : null;

                // H4.3: PRIMEIRA LINHA — pertence à escala DESTA TURMA. Sem
                // ela, um `scale_level_id` de outra disciplina (ou de outra
                // escala qualquer) colocaria o aluno numa banda que a escala
                // desta turma nunca decidiu — a mesma condição que
                // `resolveExternalLevel()` já impõe do lado da leitura.
                $belongsToClassScale = $classScaleId !== null && ScaleLevel::query()
                    ->whereKey($scaleLevelId)
                    ->where('scale_id', $classScaleId)
                    ->exists();

                if (! $belongsToClassScale) {
                    $validator->errors()->add('scale_level_id', __('O nível de escala selecionado não pertence à escala desta turma.'));

                    return;
                }

                // SEGUNDA LINHA — a escala tem de pertencer à organização ou
                // ao sistema, tal como qualquer outra referência a `scales`.
                $belongsToOrganizationOrSystem = ScaleLevel::query()
                    ->whereKey($scaleLevelId)
                    ->whereHas('scale', function ($query) use ($organizationId): void {
                        $query->whereNull('organization_id')->orWhere('organization_id', $organizationId);
                    })
                    ->exists();

                if (! $belongsToOrganizationOrSystem) {
                    $validator->errors()->add('scale_level_id', __('O nível de escala selecionado é inválido.'));
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'origin.required' => __('Indique a origem desta classificação.'),
            'recorded_on.required' => __('Indique a data em que foi obtida.'),
            'recorded_on.date_format' => __('Indique uma data válida.'),
            'period_id.exists' => __('O período selecionado não pertence ao perfil de avaliação desta turma.'),
        ];
    }
}
