<?php

namespace App\Services\Assessment\Progress;

use App\Models\AcademicPeriod;
use App\Models\Enrollment;
use App\Models\EvidenceKind;
use App\Models\EvidenceRecord;
use App\Models\HomeworkStatus;
use App\Models\SchoolClass;
use App\Models\SelfAssessment;
use App\Models\SelfAssessmentStatus;
use Illuminate\Support\Collection;

/**
 * Short factual sentences, on Base, out of facts the panel already has (§1 of
 * the Acompanhamento do Aluno brief).
 *
 * NO INTERPRETATION, ANYWHERE. Every sentence this class produces is a count
 * or a name read off a record that already exists — never a trend, never a
 * comparison to a previous period, never a word like "preocupante" or
 * "insuficiente". That reading is StudentProgressInsights' job, and it is
 * Pro; this stays Base because counting what is already on the page is not an
 * analysis, it is arithmetic a teacher would otherwise do by scrolling.
 *
 * NO ARBITRARY WINDOW. There is no "últimas 6 semanas" anywhere in this
 * class: nothing in the schema names a rolling window, so the counts below
 * are scoped to the SELECTED PERIOD — the same one `BuildStudentProgress`
 * already resolved and the page already shows — or to the whole academic
 * year when no period is selected. A number nobody chose is not a substitute
 * for a real one.
 *
 * FED BY ITS OWN QUERY OVER EvidenceRecord, exactly as
 * `BuildStudentProgress::records()` already is. This is not a second
 * opinion about a canonical figure — nothing here recomputes a result, a
 * classification or a self-assessment — it is a NEW reading, over data the
 * base builder also reads independently for its own timeline. The domain and
 * intervention counts, however, are read straight out of the progress
 * payload already assembled, because those two are already the right answer
 * and asking again would be the second walk over the same year this
 * application refuses elsewhere (§56 of the BuildStudentProgress docblock).
 */
class BuildStudentFactualAlerts
{
    /**
     * The human-readable name for each attention-worthy alert this class can
     * raise, keyed the same way `for()` returns it. The single place that
     * names these categories, so that BuildStudentInsights::positiveSignals()
     * can say WHICH one improved without typing its own copy of these words
     * and risking it drifting from the sentence this class builds around the
     * same key (§4 of the Acompanhamento do Aluno panel review).
     *
     * @var array<string, string>
     */
    public const NEGATIVE_LABELS = [
        'disciplinary_incidents' => 'Ocorrências disciplinares',
        'difficulty_records' => 'Registos de dificuldade',
        'unresolved_homework' => 'TPC não realizado',
    ];

    /**
     * @param  array<string, mixed>  $progress  the payload BuildStudentProgress::for() already built
     * @return list<array{key: string, sentence: string, count: int}>
     */
    public function for(SchoolClass $class, Enrollment $enrollment, array $progress, ?AcademicPeriod $period): array
    {
        $allRecords = EvidenceRecord::query()
            ->where('class_id', $class->getKey())
            ->where('enrollment_id', $enrollment->getKey())
            ->get();

        $periodRecords = $period === null
            ? $allRecords
            : $allRecords->filter(fn (EvidenceRecord $record): bool => ! $record->occurred_at->toImmutable()->lt($period->starts_on)
                && ! $record->occurred_at->toImmutable()->gt($period->ends_on));

        $periodPhrase = $period === null ? 'no ano letivo até à data' : 'no '.$period->label;

        return array_values(array_filter([
            $this->unresolvedHomework($periodRecords, $periodPhrase),
            $this->disciplinaryIncidents($periodRecords, $periodPhrase),
            $this->difficultyRecords($periodRecords, $periodPhrase),
            $this->interventionsNeedingReview($progress),
            $this->domainsWithNoEvidence($allRecords, $progress),
            $this->selfAssessmentAwaitingReview($enrollment, $period),
        ]));
    }

    /**
     * @param  Collection<int, EvidenceRecord>  $records
     * @return array{key: string, sentence: string, count: int}|null
     */
    protected function unresolvedHomework(Collection $records, string $periodPhrase): ?array
    {
        $count = $records
            ->where('kind', EvidenceKind::Homework)
            ->where('homework_status', HomeworkStatus::NotDone)
            ->count();

        if ($count === 0) {
            return null;
        }

        return [
            'key' => 'unresolved_homework',
            'sentence' => $this->plural($count, 'Existe :n TPC não realizado', 'Existem :n TPC não realizados').' '.$periodPhrase.'.',
            'count' => $count,
        ];
    }

    /**
     * @param  Collection<int, EvidenceRecord>  $records
     * @return array{key: string, sentence: string, count: int}|null
     */
    protected function disciplinaryIncidents(Collection $records, string $periodPhrase): ?array
    {
        $incidents = $records->where('kind', EvidenceKind::Incident);
        $count = $incidents->count();

        if ($count === 0) {
            return null;
        }

        $mostSevere = $incidents
            ->pluck('disciplinary_severity')
            ->filter()
            ->sortByDesc(fn ($severity): string => $severity->value)
            ->first();

        $sentence = $this->plural(
            $count,
            'Existe :n ocorrência disciplinar registada',
            'Existem :n ocorrências disciplinares registadas',
        ).' '.$periodPhrase.'.';

        if ($mostSevere !== null) {
            $sentence .= ' A mais grave está classificada como '.$mostSevere->label().'.';
        }

        return ['key' => 'disciplinary_incidents', 'sentence' => $sentence, 'count' => $count];
    }

    /**
     * @param  Collection<int, EvidenceRecord>  $records
     * @return array{key: string, sentence: string, count: int}|null
     */
    protected function difficultyRecords(Collection $records, string $periodPhrase): ?array
    {
        $count = $records->where('kind', EvidenceKind::Difficulty)->count();

        if ($count === 0) {
            return null;
        }

        return [
            'key' => 'difficulty_records',
            'sentence' => $this->plural($count, 'Existe :n registo de dificuldade', 'Existem :n registos de dificuldade').' '.$periodPhrase.'.',
            'count' => $count,
        ];
    }

    /**
     * The teacher's own review date, already resolved by
     * `Intervention::needsReview()` inside BuildStudentProgress — read here,
     * never recomputed (§37 of the module brief: no "30 dias sem revisão"
     * rule exists anywhere, and this class will not invent one either).
     *
     * @param  array<string, mixed>  $progress
     * @return array{key: string, sentence: string, count: int}|null
     */
    protected function interventionsNeedingReview(array $progress): ?array
    {
        $count = (int) ($progress['interventions']['needing_review'] ?? 0);

        if ($count === 0) {
            return null;
        }

        return [
            'key' => 'interventions_needing_review',
            'sentence' => $this->plural(
                $count,
                'Existe :n intervenção com revisão pendente',
                'Existem :n intervenções com revisão pendente',
            ).'.',
            'count' => $count,
        ];
    }

    /**
     * A domain this student has SOME evidence in, and other domains that have
     * NONE at all — the informative case. A student with no records at all
     * already shows that plainly elsewhere on the page, so this stays silent
     * rather than repeating it domain by domain.
     *
     * NEVER A BARE "registos". `$allRecords`/`$withEvidence` come from
     * EvidenceRecord — the acompanhamento logbook (observations, TPC,
     * incidents…) — a completely different, unrelated source from the
     * `progress['domains']['rows']` results this same domain may well already
     * carry (StudentItemScore-derived averages). A domain can have a real,
     * positive result and still have no logbook entry; saying it has "no
     * registos" without qualifying WHICH kind would read as if it had no
     * evidence at all, when what is actually missing is only the
     * acompanhamento trail (§8 of the panel review).
     *
     * @param  Collection<int, EvidenceRecord>  $allRecords
     * @param  array<string, mixed>  $progress
     * @return array{key: string, sentence: string, count: int}|null
     */
    protected function domainsWithNoEvidence(Collection $allRecords, array $progress): ?array
    {
        if ($allRecords->isEmpty()) {
            return null;
        }

        $withEvidence = $allRecords->pluck('domain_id')->filter()->unique()->all();
        $domains = (array) ($progress['domains']['rows'] ?? []);

        $missing = array_values(array_filter(
            $domains,
            fn (array $row): bool => ! in_array((int) $row['domain_id'], $withEvidence, strict: true),
        ));

        if ($missing === [] || count($missing) === count($domains)) {
            return null;
        }

        $names = array_map(fn (array $row): string => (string) $row['name'], $missing);

        return [
            'key' => 'domains_without_evidence',
            'sentence' => $this->plural(
                count($names),
                'Não existe nenhum registo de acompanhamento associado ao domínio «'.$names[0].'».',
                'Não existem registos de acompanhamento associados aos domínios «'.implode('», «', $names).'».',
            ),
            'count' => count($names),
        ];
    }

    /**
     * A self-assessment the student has SUBMITTED and the teacher has not yet
     * reviewed — the only one of the three states that asks anything of them.
     *
     * «ATENÇÃO» IS NOT A LIST OF EVENTS (§ panel review). "O aluno submeteu
     * autoavaliação" was true of a reviewed one too, so it stated a fact that
     * explained nothing: there was no longer anything to do about it. The
     * question this block answers is «why does this need me?», and only
     * `Submitted` answers it — `Draft` is the student's own unfinished work,
     * and `Reviewed` is already closed.
     *
     * READ FROM THE SelfAssessment ITSELF, not from the progress payload: the
     * payload carries the LEVEL the student chose (what they said), never the
     * status (whether anybody has looked at it), so the status is simply not
     * answerable from there. Its own query, exactly like this class already
     * does for EvidenceRecord.
     *
     * @return array{key: string, sentence: string, count: int}|null
     */
    protected function selfAssessmentAwaitingReview(Enrollment $enrollment, ?AcademicPeriod $period): ?array
    {
        if ($period === null) {
            return null;
        }

        $awaiting = SelfAssessment::query()
            ->where('enrollment_id', $enrollment->getKey())
            ->where('academic_period_id', $period->getKey())
            ->where('status', SelfAssessmentStatus::Submitted)
            ->exists();

        if (! $awaiting) {
            return null;
        }

        return [
            'key' => 'self_assessment_awaiting_review',
            'sentence' => 'Existe uma autoavaliação submetida para o '.$period->label.' por analisar.',
            'count' => 1,
        ];
    }

    /**
     * Portuguese has exactly two forms here and no library dependency is
     * worth pulling in for it. `:n` is replaced with the count; the plural
     * template is used for anything other than exactly one.
     */
    protected function plural(int $count, string $singular, string $plural): string
    {
        return str_replace(':n', (string) $count, $count === 1 ? $singular : $plural);
    }
}
