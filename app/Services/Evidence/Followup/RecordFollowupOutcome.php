<?php

namespace App\Services\Evidence\Followup;

use App\Models\RecordFollowupCase;

/**
 * What a write did to the follow-up: the alerts it caused (each one new, never
 * repeated) and whether it wrote an occurrence that no period covers.
 */
final class RecordFollowupOutcome
{
    /**
     * @param  list<RecordFollowupCase>  $alerted
     */
    public function __construct(
        public readonly array $alerted,
        public readonly bool $outsidePeriods,
    ) {}

    /**
     * The sentences the save toast appends — a complement to the persistent
     * alert, never a replacement for it. An alert on a case the teacher had
     * already declared registered in Inovar is not repeated here.
     *
     * @return list<string>
     */
    public function toastSentences(): array
    {
        $sentences = [];

        foreach ($this->alerted as $case) {
            if ($case->activeConfirmation !== null) {
                continue;
            }

            $sentences[] = __(':student acumulou :count :noun no :period. O alerta ficou em Registos, por tratar.', [
                'student' => optional($case->enrollment->student->identity)->display_name ?? __('(sem identidade)'),
                'count' => $case->alerted_count,
                'noun' => $case->category->occurrenceNoun((int) $case->alerted_count),
                'period' => $case->academicPeriod->label,
            ]);
        }

        if ($this->outsidePeriods) {
            $sentences[] = RecordFollowupNotices::periodsMissing();
        }

        return $sentences;
    }

    public function hasAlerts(): bool
    {
        foreach ($this->alerted as $case) {
            if ($case->activeConfirmation === null) {
                return true;
            }
        }

        return false;
    }
}
