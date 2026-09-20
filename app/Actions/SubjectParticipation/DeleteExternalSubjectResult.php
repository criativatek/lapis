<?php

namespace App\Actions\SubjectParticipation;

use App\Models\ExternalSubjectResult;
use App\Services\Audit\AuditLog;

/**
 * Apaga um resultado externo — corrige um lançamento enganado, ou remove uma
 * classificação que deixou de fazer sentido guardar.
 *
 * SEM LOCK NEM TRANSAÇÃO PRÓPRIA: ao contrário de `RecordExternalSubjectResult`,
 * não há uma segunda linha com quem competir — apagar uma linha por id não tem
 * uma corrida a evitar.
 */
class DeleteExternalSubjectResult
{
    public function __construct(protected AuditLog $auditLog) {}

    public function execute(ExternalSubjectResult $result): void
    {
        $this->auditLog->record(
            'external_result.deleted',
            $result,
            summary: __('Resultado externo removido.'),
            properties: [
                'enrollment_id' => $result->enrollment_id,
                'period_id' => $result->period_id,
                'origin' => $result->origin,
                'scale_level_id' => $result->scale_level_id,
                'level_code' => $result->level_code,
                'numeric_value' => $result->numeric_value,
                'recorded_on' => $result->recorded_on->toDateString(),
            ],
        );

        $result->delete();
    }
}
