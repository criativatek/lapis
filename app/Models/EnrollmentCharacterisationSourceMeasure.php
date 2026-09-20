<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Uma medida de apoio atribuída a um aluno por uma importação — destino (B)
 * da classificação de células (§4.4 do desenho). Guarda tipado, `nível` +
 * `código`, em vez de texto solto, porque é o que torna a informação
 * reutilizável por um futuro instrumento legal sem a reinterpretar.
 *
 * NÃO É UMA DETERMINAÇÃO DA APLICAÇÃO (§3.2): é o registo atribuído de o que
 * o documento da escola dizia, confirmado por uma pessoa. `raw_token` guarda
 * sempre o texto original ao lado, mesmo quando o resolvedor o interpretou
 * com sucesso, para que se veja o que o ficheiro dizia mesmo quando a
 * interpretação mudar. `unresolved_annotations` guarda o que ficou por
 * resolver — alíneas como `b)` nunca resolvem para um código (§3.4), porque
 * não há neste repositório nenhuma correspondência entre alínea e medida.
 *
 * Vive inteiramente dentro do agregado de `EnrollmentCharacterisation`: não
 * tem história própria, por isso a FK é `CASCADE` em vez de `RESTRICT` — a
 * proveniência de quando e por quem fica na revisão, não nesta linha.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $enrollment_characterisation_id
 * @property SupportMeasureLevel|null $support_measure_level
 * @property SupportMeasureCode|null $support_measure_code
 * @property string $raw_token
 * @property array<int, string>|null $unresolved_annotations
 * @property int|null $import_batch_id
 * @property int|null $confirmed_by
 * @property Carbon|null $confirmed_at
 */
#[Fillable([
    'enrollment_characterisation_id', 'support_measure_level', 'support_measure_code', 'raw_token',
    'unresolved_annotations', 'import_batch_id', 'confirmed_by', 'confirmed_at',
])]
class EnrollmentCharacterisationSourceMeasure extends Model
{
    use BelongsToOrganization, HasUlids;

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    protected function casts(): array
    {
        return [
            'support_measure_level' => SupportMeasureLevel::class,
            'support_measure_code' => SupportMeasureCode::class,
            'unresolved_annotations' => 'array',
            'confirmed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<EnrollmentCharacterisation, $this>
     */
    public function characterisation(): BelongsTo
    {
        return $this->belongsTo(EnrollmentCharacterisation::class, 'enrollment_characterisation_id');
    }

    /**
     * @return BelongsTo<CharacterisationImportBatch, $this>
     */
    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(CharacterisationImportBatch::class, 'import_batch_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
