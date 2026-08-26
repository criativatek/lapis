<?php

namespace App\Services\Assessment\Progress;

use App\Models\AcademicPeriod;
use App\Models\Enrollment;
use App\Models\EvidenceKind;
use App\Models\EvidenceRecord;
use App\Models\HomeworkStatus;
use App\Models\InterventionEffectiveness;
use App\Models\SchoolClass;
use App\Services\Reporting\Narrative\Phrase;
use Illuminate\Support\Collection;

/**
 * «Pontos fortes», as an explicit Base section (§2 of the Acompanhamento do
 * Aluno brief) — never left for a teacher to piece together from a table of
 * numbers and a scroll through the timeline.
 *
 * ATTENTION + PROGRESS + POTENTIAL, never only the first. This class is the
 * reason the panel is not a list of problems: the highest domain, a rise
 * already computed, a positive record, an objective a follow-up actually
 * marked as met — all of it was already true, already stored, and simply
 * never gathered in one place before now.
 *
 * STILL FACTS, NOT PRAISE. «O resultado mais elevado regista-se em Leitura»
 * is what `domainHighlights()` already says; putting it under a heading
 * called «Pontos fortes» does not turn it into an opinion, and nothing here
 * adds an adjective the data does not support.
 */
class BuildStudentStrengths
{
    /**
     * @param  array<string, mixed>  $progress  the payload BuildStudentProgress::for() already built
     * @return list<array{key: string, sentence: string}>
     */
    public function for(SchoolClass $class, Enrollment $enrollment, array $progress, ?AcademicPeriod $period): array
    {
        $records = EvidenceRecord::query()
            ->where('class_id', $class->getKey())
            ->where('enrollment_id', $enrollment->getKey())
            ->get();

        if ($period !== null) {
            $records = $records->filter(fn (EvidenceRecord $record): bool => ! $record->occurred_at->toImmutable()->lt($period->starts_on)
                && ! $record->occurred_at->toImmutable()->gt($period->ends_on));
        }

        $periodPhrase = $period === null ? 'no ano letivo até à data' : 'no '.$period->label;

        return array_values(array_filter([
            $this->highestDomain($progress),
            $this->largestRise($progress),
            $this->positiveBehaviour($records, $periodPhrase),
            $this->homeworkDone($records, $periodPhrase),
            $this->favourableIntervention($progress),
        ]));
    }

    /**
     * @param  array<string, mixed>  $progress
     * @return array{key: string, sentence: string}|null
     */
    protected function highestDomain(array $progress): ?array
    {
        $highest = $progress['domains']['highlights']['highest'] ?? null;

        if (! is_array($highest)) {
            return null;
        }

        $value = Phrase::percentage($highest['value'] ?? null);

        if ($value === null) {
            return null;
        }

        $row = collect((array) ($progress['domains']['rows'] ?? []))->firstWhere('domain_id', $highest['domain_id']);
        $mention = is_array($row) ? ($row['mention']['label'] ?? null) : null;

        return [
            'key' => 'highest_domain',
            'sentence' => $highest['name'].' — ponto forte atual — '.$value.($mention === null ? '' : ' · '.$mention).'.',
        ];
    }

    /**
     * @param  array<string, mixed>  $progress
     * @return array{key: string, sentence: string}|null
     */
    protected function largestRise(array $progress): ?array
    {
        $rise = $progress['domains']['highlights']['largest_rise'] ?? null;

        if (! is_array($rise)) {
            return null;
        }

        $points = Phrase::number(ltrim((string) ($rise['value'] ?? ''), '+-'));

        if ($points === null) {
            return null;
        }

        return [
            'key' => 'largest_rise',
            'sentence' => $rise['name'].' — domínio em progressão — +'.$points.' p.p.',
        ];
    }

    /**
     * @param  Collection<int, EvidenceRecord>  $records
     * @return array{key: string, sentence: string}|null
     */
    protected function positiveBehaviour(Collection $records, string $periodPhrase): ?array
    {
        $count = $records->where('kind', EvidenceKind::PositiveBehaviour)->count();

        if ($count === 0) {
            return null;
        }

        return [
            'key' => 'positive_behaviour',
            'sentence' => $this->plural(
                $count,
                'Existe :n registo de comportamento meritório',
                'Existem :n registos de comportamento meritório',
            ).' '.$periodPhrase.'.',
        ];
    }

    /**
     * @param  Collection<int, EvidenceRecord>  $records
     * @return array{key: string, sentence: string}|null
     */
    protected function homeworkDone(Collection $records, string $periodPhrase): ?array
    {
        $count = $records
            ->where('kind', EvidenceKind::Homework)
            ->where('homework_status', HomeworkStatus::Done)
            ->count();

        if ($count === 0) {
            return null;
        }

        return [
            'key' => 'homework_done',
            'sentence' => $this->plural($count, 'Existe :n TPC realizado', 'Existem :n TPC realizados').' '.$periodPhrase.'.',
        ];
    }

    /**
     * A follow-up the teacher themselves marked «evolução favorável
     * observada» — never derived from a result that moved, exactly as
     * `Intervention::currentEffectiveness()` already refuses to (§35 of
     * BuildStudentProgress: a coincidence is never a cause, in either
     * direction).
     *
     * @param  array<string, mixed>  $progress
     * @return array{key: string, sentence: string}|null
     */
    protected function favourableIntervention(array $progress): ?array
    {
        foreach ((array) ($progress['interventions']['rows'] ?? []) as $row) {
            if (($row['effectiveness'] ?? null) === InterventionEffectiveness::Effective->shortLabel()) {
                $title = $row['title'] ?? null;

                return [
                    'key' => 'favourable_intervention',
                    'sentence' => $title === null
                        ? 'Uma intervenção regista evolução favorável observada.'
                        : 'A intervenção «'.$title.'» regista evolução favorável observada.',
                ];
            }
        }

        return null;
    }

    protected function plural(int $count, string $singular, string $plural): string
    {
        return str_replace(':n', (string) $count, $count === 1 ? $singular : $plural);
    }
}
