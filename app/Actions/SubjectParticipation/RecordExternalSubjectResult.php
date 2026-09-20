<?php

namespace App\Actions\SubjectParticipation;

use App\Models\Enrollment;
use App\Models\ExternalSubjectResult;
use App\Services\Audit\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Regista, ou corrige, a classificação externa de uma inscrição para um
 * período — ou para o ano completo, quando `period` é null.
 *
 * PELO MENOS UM VALOR. Uma linha sem `scale_level_id`, sem `level_code` e sem
 * `numeric_value` não diz nada — a mesma regra que a CHECK
 * `external_subject_results_value_present_check` impõe na base de dados, dita
 * aqui em português legível antes de a base de dados a recusar em silêncio.
 *
 * A LINHA DE ANO COMPLETO (`period_id = NULL`) É VERIFICADA À MÃO. A UNIQUE
 * `external_subject_results_enrollment_period_unique` não a protege — o MySQL
 * trata cada `NULL` como distinto (ver o docblock da migração) — pelo que
 * `lockForUpdate()` sobre a mesma pergunta que a UNIQUE faria é a única forma
 * de duas escritas simultâneas não criarem duas linhas de ano completo para a
 * mesma inscrição.
 */
class RecordExternalSubjectResult
{
    public function __construct(protected AuditLog $auditLog) {}

    public function execute(
        Enrollment $enrollment,
        ?int $periodId,
        string $origin,
        string $recordedOn,
        ?int $scaleLevelId = null,
        ?string $levelCode = null,
        ?string $numericValue = null,
        ?string $note = null,
    ): ExternalSubjectResult {
        if ($scaleLevelId === null && $levelCode === null && $numericValue === null) {
            throw ValidationException::withMessages([
                'scale_level_id' => __('Indique pelo menos um nível de escala, um código de nível ou um valor numérico.'),
            ]);
        }

        return DB::transaction(function () use (
            $enrollment,
            $periodId,
            $origin,
            $recordedOn,
            $scaleLevelId,
            $levelCode,
            $numericValue,
            $note,
        ): ExternalSubjectResult {
            $query = ExternalSubjectResult::query()
                ->where('enrollment_id', $enrollment->getKey())
                ->lockForUpdate();

            $existing = $periodId === null
                ? $query->whereNull('period_id')->first()
                : $query->where('period_id', $periodId)->first();

            $attributes = [
                'enrollment_id' => $enrollment->getKey(),
                'period_id' => $periodId,
                'origin' => $origin,
                'scale_level_id' => $scaleLevelId,
                'level_code' => $levelCode,
                'numeric_value' => $numericValue,
                'recorded_on' => $recordedOn,
                'note' => $note,
            ];

            if ($existing !== null) {
                $existing->update($attributes);

                $this->auditLog->record(
                    'external_result.updated',
                    $existing,
                    summary: __('Resultado externo atualizado.'),
                    properties: [
                        'enrollment_id' => $enrollment->getKey(),
                        'period_id' => $periodId,
                        'origin' => $origin,
                        'scale_level_id' => $scaleLevelId,
                        'level_code' => $levelCode,
                        'numeric_value' => $numericValue,
                        'recorded_on' => $recordedOn,
                    ],
                );

                return $existing;
            }

            $result = ExternalSubjectResult::create($attributes);

            $this->auditLog->record(
                'external_result.recorded',
                $result,
                summary: __('Resultado externo registado.'),
                properties: [
                    'enrollment_id' => $enrollment->getKey(),
                    'period_id' => $periodId,
                    'origin' => $origin,
                    'scale_level_id' => $scaleLevelId,
                    'level_code' => $levelCode,
                    'numeric_value' => $numericValue,
                    'recorded_on' => $recordedOn,
                ],
            );

            return $result;
        });
    }
}
