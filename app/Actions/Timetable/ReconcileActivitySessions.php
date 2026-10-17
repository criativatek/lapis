<?php

namespace App\Actions\Timetable;

use App\Models\TimetableActivity;
use App\Models\TimetableActivitySession;
use App\Services\Timetable\ActivityOccurrences;
use Illuminate\Support\Collection;

/**
 * O que acontece às sessões quando o BLOCO da atividade é editado (0.170.0). O
 * bloco continua no lugar; as sessões acompanham-no só quando isso não custa
 * nada.
 *
 *  - Sessão COM REGISTOS (texto não vazio ou estado diferente de «por
 *    realizar»): nunca é alterada. Mantém a data, as horas, o título, o tipo e a
 *    turma copiados quando foi criada. Se já não bate com o novo padrão (dia,
 *    horas ou vigência), é devolvida — para o professor ser avisado de que
 *    ficou onde estava.
 *  - Sessão SEM REGISTOS que deixa de bater com o padrão: sai (não há nada a
 *    perder).
 *  - Sessão SEM REGISTOS que ainda bate: atualiza a cópia (título, tipo, turma,
 *    horas, ano letivo).
 */
final class ReconcileActivitySessions
{
    public function __construct(private readonly ActivityOccurrences $occurrences) {}

    /**
     * @return Collection<int, TimetableActivitySession> as sessões com registos que já não batem com o bloco
     */
    public function afterEdit(TimetableActivity $activity): Collection
    {
        $activity->loadMissing('schoolClass');

        /** @var Collection<int, TimetableActivitySession> $preserved */
        $preserved = collect();

        foreach ($activity->sessions()->orderBy('occurs_on')->get() as $session) {
            $date = $session->occurs_on->toDateString();
            $matches = $activity->occursOn($date)
                && substr($session->starts_at, 0, 5) === substr($activity->starts_at, 0, 5)
                && substr($session->ends_at, 0, 5) === substr($activity->ends_at, 0, 5);

            if ($session->hasRecords()) {
                if (! $matches) {
                    $preserved->push($session);
                }

                continue;
            }

            if (! $matches) {
                $session->delete();

                continue;
            }

            $session->forceFill([
                'activity_kind' => $activity->kind->value,
                'activity_title' => $activity->title,
                'class_id' => $activity->class_id,
                'academic_year_id' => $this->occurrences->academicYearFor($activity, $date)?->getKey(),
                'starts_at' => $activity->starts_at,
                'ends_at' => $activity->ends_at,
            ]);

            if ($session->isDirty()) {
                $session->save();
            }
        }

        return $preserved;
    }
}
