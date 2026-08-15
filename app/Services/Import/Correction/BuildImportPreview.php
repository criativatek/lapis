<?php

namespace App\Services\Import\Correction;

use App\Domain\Assessment\Bc;
use App\Domain\Import\Correction\CanonicalCorrectionGrid;
use App\Domain\Import\Correction\CanonicalItem;
use App\Domain\Import\Correction\ImportIssue;
use App\Domain\Import\Correction\ImportMapping;
use App\Domain\Import\Correction\IssueCode;
use App\Domain\Import\Correction\IssueSeverity;
use App\Domain\Import\Correction\OverallResultItem;
use App\Models\CorrectionImport;
use App\Models\Instrument;
use App\Models\InstrumentStatus;
use App\Models\SchoolClass;
use App\Models\StudentItemScore;
use App\Services\Assessment\InstrumentCompleteness;
use Illuminate\Support\Carbon;

/**
 * Everything the wizard shows, and the single answer to "may this be confirmed?".
 *
 * Readiness is computed here rather than in the interface for the same reason
 * the completion rule lives in InstrumentCompleteness: a teacher who is shown a
 * green button must be allowed to press it, and one who is shown a blocked
 * button must be told exactly what is missing. Two copies of that rule would
 * eventually disagree, and the disagreement would surface as a confirmation
 * that fails after the teacher believed they were done.
 *
 * Nothing here writes. It reads the stored grid, the teacher's decisions so far,
 * and the class, and it produces a description.
 */
class BuildImportPreview
{
    public function __construct(protected MatchSourceStudents $matcher) {}

    /**
     * @return array<string, mixed>
     */
    public function for(CorrectionImport $import): array
    {
        $grid = CanonicalCorrectionGridSnapshot::rehydrate($import->canonical_snapshot ?? []);
        $mapping = ImportMapping::fromArray($import->mapping_snapshot);
        $class = $import->schoolClass;

        $students = $this->matcher->for($grid, $class, $mapping->students);
        $instrument = $mapping->instrumentId === null ? null : Instrument::find($mapping->instrumentId);

        $items = $this->items($grid, $mapping, $instrument);

        $students = $mapping->importsOverallResult()
            ? $this->withOverallResult($students, $grid, $mapping, $instrument)
            : $this->withLapisResult($students, $grid, $items);

        $issues = $this->issues($grid, $mapping, $students, $items, $class, $instrument);
        $blocking = array_values(array_filter($issues, fn (array $issue): bool => $issue['severity'] === 'error'));

        return [
            'source' => $grid->source->value,
            'source_label' => $grid->source->label(),
            'suggested_title' => $grid->instrument->title,
            'mode' => $mapping->mode,
            'result_mode' => $mapping->resultMode,
            'instrument_id' => $mapping->instrumentId,
            'instrument_attributes' => $mapping->instrumentAttributes,
            'overall_domains' => $mapping->overallDomains,
            'overall_item_id' => $mapping->overallItemId,
            'groups' => array_map(fn ($group): array => $group->toArray(), $grid->groups),
            'items' => $items,
            'students' => $students,
            'counts' => $this->counts($grid, $students, $items),
            'issues' => $issues,
            'can_confirm' => $blocking === [],
            'eligible_instruments' => $this->eligibleInstruments($class),
        ];
    }

    /**
     * Questions, with whatever cotação has been decided so far and what the
     * source itself said. A null `points_possible` is shown as undecided rather
     * than as a zero — the wizard has to be able to say «não está definido».
     *
     * @return list<array<string, mixed>>
     */
    protected function items(CanonicalCorrectionGrid $grid, ImportMapping $mapping, ?Instrument $instrument): array
    {
        return array_map(function (CanonicalItem $item) use ($mapping, $instrument): array {
            $chosen = $mapping->points[$item->sourceKey] ?? null;
            $mappedId = $mapping->items[$item->sourceKey] ?? null;

            // Associating with an existing instrument: the cotação already
            // configured in LÁPIS wins, and is shown so the teacher sees which
            // number is going to be used (§24).
            $existingPoints = null;

            if ($instrument !== null && $mappedId !== null) {
                $existingPoints = $instrument->items->firstWhere('id', (int) $mappedId)?->points_possible;
            }

            return [
                'source_key' => $item->sourceKey,
                'sequence' => $item->sequence,
                'code' => $item->code,
                'label' => $item->label,
                'question_text' => $item->questionText,
                'answer_key' => $item->answerKey,
                'external_id' => $item->externalId,
                'source_points' => $item->pointsPossible,
                'points' => $existingPoints !== null ? (string) $existingPoints : ($chosen ?? $item->pointsPossible),
                'points_locked' => $existingPoints !== null,
                'instrument_item_id' => $mappedId === null ? null : (int) $mappedId,
                'domains' => $mapping->domains[$item->sourceKey] ?? [],
            ];
        }, $grid->items);
    }

    /**
     * What the questions would be worth once the teacher's cotação is applied —
     * shown beside the platform's own score so the two can be compared.
     *
     * They are different things and the interface says so. Plickers weights
     * every question equally; the moment a teacher gives one question three
     * points and another one, the two numbers legitimately part ways. That is
     * not an error and is never presented as one (§14).
     *
     * The denominator counts only questions this student was actually judged on.
     * An unanswered question is not a zero, so it does not silently drag the
     * percentage down — the same rule the engine keeps everywhere else.
     *
     * @param  list<array<string, mixed>>  $students
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    protected function withLapisResult(array $students, CanonicalCorrectionGrid $grid, array $items): array
    {
        $points = [];

        foreach ($items as $item) {
            $value = $item['points'];

            if (is_string($value) && $value !== '' && is_numeric($value)) {
                $points[$item['source_key']] = $value;
            }
        }

        return array_map(function (array $student) use ($grid, $points): array {
            $earned = '0';
            $possible = '0';
            $judged = 0;

            foreach ($grid->resultsForStudent($student['source_key']) as $result) {
                $worth = $points[$result->itemSourceKey] ?? null;

                if ($worth === null || $result->isCorrect === null) {
                    continue;
                }

                $judged++;
                $possible = Bc::add($possible, Bc::of($worth));
                $earned = Bc::add($earned, Bc::of($result->resolvedAgainst($worth)->pointsEarned ?? '0'));
            }

            // No cotação decided yet, or nothing judged: no LÁPIS result to show.
            // Deliberately null rather than 0 — the distinction the whole feature
            // is built on.
            $student['lapis_percentage'] = ($judged === 0 || Bc::isZero($possible))
                ? null
                : Bc::round(Bc::mul(Bc::div($earned, $possible), '100'), 1, 'half_up');

            return $student;
        }, $students);
    }

    /**
     * The same number, in the simple mode: what the platform said.
     *
     * There is no arithmetic to disagree about here — the classification was
     * decided on the platform and LÁPIS records it, so the two columns of the
     * review screen agree by construction. It is still computed rather than
     * copied, because on an existing instrument the result lands on an item with
     * its own cotação, and this is where that would show if it ever failed to
     * round-trip.
     *
     * Null stays null the whole way down: no score, or no participation, means
     * no result — never a zero (§3).
     *
     * @param  list<array<string, mixed>>  $students
     * @return list<array<string, mixed>>
     */
    protected function withOverallResult(array $students, CanonicalCorrectionGrid $grid, ImportMapping $mapping, ?Instrument $instrument): array
    {
        $possible = OverallResultItem::POINTS_POSSIBLE;

        if (! $mapping->createsInstrument()) {
            $target = $instrument?->items->firstWhere('id', $mapping->overallItemId);
            $possible = $target === null ? null : (string) $target->points_possible;
        }

        $fromSource = [];

        foreach ($grid->students as $student) {
            $fromSource[$student->sourceKey] = [$student->scorePercent(), $student->answeredNothing()];
        }

        return array_map(function (array $student) use ($fromSource, $possible): array {
            [$percent, $answeredNothing] = $fromSource[$student['source_key']] ?? [null, false];

            if ($percent === null || $answeredNothing || $possible === null || Bc::isZero(Bc::of($possible))) {
                $student['lapis_percentage'] = null;

                return $student;
            }

            $student['lapis_percentage'] = Bc::round(
                Bc::mul(Bc::div(Bc::of(OverallResultItem::earned($percent, $possible)), Bc::of($possible)), '100'),
                1,
                'half_up',
            );

            return $student;
        }, $students);
    }

    /**
     * @param  list<array<string, mixed>>  $students
     * @param  list<array<string, mixed>>  $items
     * @return array<string, int>
     */
    protected function counts(CanonicalCorrectionGrid $grid, array $students, array $items): array
    {
        $judged = 0;
        $unresolved = 0;

        foreach ($grid->results as $result) {
            $result->isCorrect === null ? $unresolved++ : $judged++;
        }

        return [
            'students_in_file' => count($grid->students),
            'questions' => count($items),
            'responses' => count($grid->results),
            'responses_judged' => $judged,
            'responses_unresolved' => $unresolved,
            'matched' => count(array_filter($students, fn (array $row): bool => $row['status'] === MatchSourceStudents::STATUS_MATCHED)),
            'ambiguous' => count(array_filter($students, fn (array $row): bool => $row['status'] === MatchSourceStudents::STATUS_AMBIGUOUS)),
            'unmatched' => count(array_filter($students, fn (array $row): bool => $row['status'] === MatchSourceStudents::STATUS_UNMATCHED)),
            'ignored' => count(array_filter($students, fn (array $row): bool => $row['status'] === MatchSourceStudents::STATUS_IGNORED)),
            'non_participants' => count(array_filter($students, fn (array $row): bool => $row['participated'] === false)),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $students
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    protected function issues(CanonicalCorrectionGrid $grid, ImportMapping $mapping, array $students, array $items, SchoolClass $class, ?Instrument $instrument): array
    {
        // Whatever the parser already said about the file itself, minus the
        // cotação complaint — that one is answered by the wizard, so repeating
        // it after the teacher has answered it would be noise.
        $issues = array_values(array_filter(
            $grid->issues,
            fn (ImportIssue $issue): bool => $issue->code !== IssueCode::MissingPoints,
        ));

        $issues = array_map(fn (ImportIssue $issue): array => $issue->toArray(), $issues);

        $undecided = count(array_filter(
            $students,
            fn (array $row): bool => in_array($row['status'], [MatchSourceStudents::STATUS_AMBIGUOUS, MatchSourceStudents::STATUS_UNMATCHED], true),
        ));

        if ($undecided > 0) {
            $issues[] = ImportIssue::make(
                IssueCode::UnknownStudent,
                'Há alunos do ficheiro por corresponder. Escolha o aluno ou marque a linha como ignorada.',
                ['students' => $undecided],
                IssueSeverity::Error,
            )->toArray();
        }

        foreach ($this->configurationIssues($grid, $mapping, $items, $instrument) as $issue) {
            $issues[] = $issue;
        }

        foreach ($this->applicabilityIssues($grid, $mapping, $class, $instrument) as $issue) {
            $issues[] = $issue;
        }

        foreach ($this->missingFromFile($students, $class) as $issue) {
            $issues[] = $issue;
        }

        return $issues;
    }

    /**
     * What is still missing, named field by field.
     *
     * A single «falta preencher a configuração» tells a teacher that something
     * is wrong and nothing about what, which is how they end up staring at a
     * grey button with every visible field filled in. Each message here points
     * at one control (§11).
     *
     * Nothing about cotações or question structure is ever asked for in the
     * simple mode. The platform already produced the classification; demanding
     * twenty cotações before LÁPIS will accept a number it is not going to use
     * would be the whole problem this rewrite exists to remove.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    protected function configurationIssues(CanonicalCorrectionGrid $grid, ImportMapping $mapping, array $items, ?Instrument $instrument): array
    {
        $issues = [];
        $overall = $mapping->importsOverallResult();

        // A property of the file, not of the teacher's decisions, so it is worth
        // saying at once rather than at the last step: this export carries no
        // classification for anybody, and the simple mode has nothing to import.
        if ($overall && ! $this->carriesOverallResults($grid)) {
            $issues[] = ImportIssue::make(
                IssueCode::UnsupportedStructure,
                'Este ficheiro não traz uma classificação por aluno. Ative «Importar também o detalhe das perguntas» para importar a correção pergunta a pergunta.',
                ['field' => 'result_mode'],
            )->toArray();

            return $issues;
        }

        if ($mapping->createsInstrument()) {
            $fields = [
                'title' => 'Indique a designação da avaliação.',
                'applied_on' => 'Indique a data de aplicação.',
                'instrument_type_id' => 'Selecione o tipo de avaliação.',
                'academic_period_id' => 'Selecione o período a que esta avaliação pertence.',
            ];

            foreach ($fields as $key => $message) {
                $value = $mapping->instrumentAttributes[$key] ?? null;

                if ($value === null || $value === '') {
                    $issues[] = ImportIssue::make(IssueCode::UnsupportedStructure, $message, ['field' => $key])->toArray();
                }
            }

            if ($overall) {
                // Only when it counts. An item with no allocation enters no
                // domain, which the model allows (§4.3) and which is harmless
                // for an instrument that does not enter the calculation at all.
                $counts = (bool) ($mapping->instrumentAttributes['counts_toward_classification'] ?? false);

                if ($counts && ! $mapping->overallDomainIsDecided()) {
                    $issues[] = ImportIssue::make(
                        IssueCode::UnsupportedStructure,
                        'Selecione o domínio avaliado. É ele que diz para onde conta este resultado.',
                        ['field' => 'overall_domains'],
                    )->toArray();
                }

                return $issues;
            }
        } else {
            if ($instrument === null) {
                $issues[] = ImportIssue::make(
                    IssueCode::UnsupportedStructure,
                    'Escolha a avaliação a que os resultados se destinam.',
                    ['field' => 'instrument_id'],
                )->toArray();

                return $issues;
            }

            if ($overall) {
                if ($mapping->overallItemId === null) {
                    $issues[] = ImportIssue::make(
                        IssueCode::UnsupportedStructure,
                        'Indique qual a pergunta da avaliação que recebe o resultado global.',
                        ['field' => 'overall_item_id'],
                    )->toArray();
                }

                return $issues;
            }

            $unmapped = count(array_filter($items, fn (array $item): bool => $item['instrument_item_id'] === null));

            if ($unmapped > 0) {
                $issues[] = ImportIssue::make(
                    IssueCode::UnmappedItem,
                    trans_choice(
                        '{1}Há 1 pergunta do ficheiro por associar a uma pergunta da avaliação.|[2,*]Há :count perguntas do ficheiro por associar às perguntas da avaliação.',
                        $unmapped,
                        ['count' => $unmapped],
                    ),
                    ['questions' => $unmapped],
                    IssueSeverity::Error,
                )->toArray();
            }
        }

        // Detailed mode only, both ways in: the cotação is what turns an answer
        // into a mark, and nothing here invents one.
        $withoutPoints = count(array_filter($items, fn (array $item): bool => $item['points'] === null || $item['points'] === ''));

        if ($withoutPoints > 0) {
            $issues[] = ImportIssue::make(
                IssueCode::MissingPoints,
                trans_choice(
                    '{1}Existe 1 pergunta sem cotação.|[2,*]Existem :count perguntas sem cotação.',
                    $withoutPoints,
                    ['count' => $withoutPoints],
                ),
                ['questions' => $withoutPoints],
            )->toArray();
        }

        return $issues;
    }

    /**
     * Whether the export states a classification for anybody at all. One is
     * enough — a class where only two students sat the test is an ordinary
     * class, not a broken file.
     */
    protected function carriesOverallResults(CanonicalCorrectionGrid $grid): bool
    {
        foreach ($grid->students as $student) {
            if ($student->scorePercent() !== null && ! $student->answeredNothing()) {
                return true;
            }
        }

        return false;
    }

    /**
     * A student the instrument does not apply to — one who joined after it was
     * applied, or left before — carrying a result in the file. Never discarded
     * silently: the file says something the calendar says is impossible, and the
     * teacher should see the contradiction rather than have it resolved for them
     * (§22).
     *
     * @return list<array<string, mixed>>
     */
    protected function applicabilityIssues(CanonicalCorrectionGrid $grid, ImportMapping $mapping, SchoolClass $class, ?Instrument $instrument): array
    {
        // An existing instrument states its own date; a new one only has the one
        // the teacher has typed so far, which may still be empty.
        $chosenDate = $mapping->instrumentAttributes['applied_on'] ?? null;

        $appliedOn = $instrument !== null
            ? $instrument->applied_on
            : (is_string($chosenDate) && $chosenDate !== '' ? Carbon::parse($chosenDate) : null);

        if ($appliedOn === null) {
            return [];
        }

        $enrollments = $class->enrollments()->get()->keyBy('id');
        $affected = 0;

        foreach ($grid->students as $student) {
            $enrollmentId = $mapping->enrollmentFor($student->sourceKey);

            // Only a row that actually carries answers can contradict the date.
            // A student who took no part contradicts nothing.
            if ($enrollmentId === null || $student->answeredNothing()) {
                continue;
            }

            $enrollment = $enrollments->get($enrollmentId);

            if ($enrollment === null) {
                continue;
            }

            if (! InstrumentCompleteness::isApplicable($enrollment, $appliedOn)) {
                $affected++;
            }
        }

        if ($affected === 0) {
            return [];
        }

        return [ImportIssue::make(
            IssueCode::NotApplicableStudent,
            'O ficheiro traz resultados para alunos a quem este instrumento não é aplicável na data indicada (entrada posterior ou saída anterior). Confirme a data ou a correspondência.',
            ['students' => $affected],
        )->toArray()];
    }

    /**
     * Students of the class with no row in the file. Informative only: they stay
     * unassessed, which is not a zero and not an absence (§21).
     *
     * @param  list<array<string, mixed>>  $students
     * @return list<array<string, mixed>>
     */
    protected function missingFromFile(array $students, SchoolClass $class): array
    {
        $mapped = array_filter(array_column($students, 'enrollment_id'), fn (?int $id): bool => $id !== null);
        $missing = $class->enrollments()->count() - count(array_unique($mapped));

        if ($missing <= 0) {
            return [];
        }

        return [ImportIssue::make(
            IssueCode::StudentMissingFromSource,
            'Alunos da turma que não constam do ficheiro. Ficam por avaliar — não recebem zero nem falta.',
            ['students' => $missing],
        )->toArray()];
    }

    /**
     * Instruments this import could be attached to: this class's, in a state
     * where writing marks is allowed. A finished correction is deliberately not
     * offered — reopening it is an explicit act that happens elsewhere (§23).
     *
     * @return list<array<string, mixed>>
     */
    protected function eligibleInstruments(SchoolClass $class): array
    {
        $instruments = $class->instruments()
            ->whereIn('status', [InstrumentStatus::Draft->value, InstrumentStatus::Prepared->value, InstrumentStatus::InCorrection->value])
            ->with(['items.group'])
            ->orderByDesc('applied_on')
            ->get();

        $eligible = [];

        foreach ($instruments as $instrument) {
            $items = [];

            foreach ($instrument->items as $item) {
                $items[] = [
                    'id' => (int) $item->id,
                    'code' => $item->code,
                    'label' => $item->label,
                    // The group is what disambiguates two questions sharing a
                    // code, so the teacher can tell them apart in the dropdown
                    // (§24).
                    'group' => $item->group?->label,
                    'points_possible' => (string) $item->points_possible,
                ];
            }

            $eligible[] = [
                'id' => (int) $instrument->getKey(),
                'ulid' => $instrument->ulid,
                'title' => $instrument->title,
                'applied_on' => $instrument->applied_on->toDateString(),
                'status' => $instrument->status->value,
                'status_label' => $instrument->status->label(),
                'items' => $items,
            ];
        }

        return $eligible;
    }

    /**
     * Marks the file would change. Shown before anything is written, so
     * replacing a mark is always a choice rather than a discovery (§25).
     *
     * @return list<array{enrollment_id: int, instrument_item_id: int, current: string, incoming: string}>
     */
    public function conflicts(CorrectionImport $import): array
    {
        $mapping = ImportMapping::fromArray($import->mapping_snapshot);

        if ($mapping->createsInstrument() || $mapping->instrumentId === null) {
            return []; // A new instrument has nothing to disagree with.
        }

        $instrument = Instrument::find($mapping->instrumentId);

        if ($instrument === null) {
            return [];
        }

        $grid = CanonicalCorrectionGridSnapshot::rehydrate($import->canonical_snapshot ?? []);

        $existing = StudentItemScore::query()
            ->where('instrument_id', $instrument->getKey())
            ->get(['enrollment_id', 'instrument_item_id', 'points_earned'])
            ->mapWithKeys(fn (StudentItemScore $score): array => [
                $score->enrollment_id.':'.$score->instrument_item_id => (string) $score->points_earned,
            ]);

        $conflicts = [];

        foreach ($grid->results as $result) {
            $enrollmentId = $mapping->enrollmentFor($result->studentSourceKey);
            $itemId = $mapping->items[$result->itemSourceKey] ?? null;
            $item = $grid->item($result->itemSourceKey);

            if ($enrollmentId === null || $itemId === null || $item === null || $result->isCorrect === null) {
                continue;
            }

            $points = $instrument->items->firstWhere('id', (int) $itemId)?->points_possible;

            if ($points === null) {
                continue;
            }

            $incoming = $result->resolvedAgainst((string) $points)->pointsEarned;
            $current = $existing->get($enrollmentId.':'.$itemId);

            if ($incoming === null || $current === null) {
                continue;
            }

            if (Bc::compare(Bc::of($current), Bc::of($incoming)) === 0) {
                continue;
            }

            $conflicts[] = [
                'enrollment_id' => (int) $enrollmentId,
                'instrument_item_id' => (int) $itemId,
                'current' => $current,
                'incoming' => $incoming,
            ];
        }

        return $conflicts;
    }
}
