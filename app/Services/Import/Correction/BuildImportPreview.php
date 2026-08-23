<?php

namespace App\Services\Import\Correction;

use App\Domain\Assessment\Bc;
use App\Domain\Import\Correction\CanonicalCorrectionGrid;
use App\Domain\Import\Correction\CanonicalItem;
use App\Domain\Import\Correction\GroupResultItem;
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
    /**
     * How far two totals may differ before it is worth telling the teacher.
     *
     * One cent, which is the precision Intuitivo itself writes: a test with
     * marks of 3.33 and 1.67 produces sums that land a hundredth apart without
     * anything being wrong. Anything larger is a real disagreement and is shown.
     */
    protected const TOLERANCE = '0.01';

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

        $groups = $this->groups($grid, $mapping);

        $students = match (true) {
            $mapping->importsOverallResult() => $this->withOverallResult($students, $grid, $mapping, $instrument),
            $mapping->importsGroupResults() => $this->withGroupResults($students, $grid, $groups),
            default => $this->withLapisResult($students, $grid, $items),
        };

        $issues = $this->issues($grid, $mapping, $students, $items, $class, $instrument);
        $blocking = array_values(array_filter($issues, fn (array $issue): bool => $issue['severity'] === 'error'));

        return [
            'source' => $grid->source->value,
            'source_label' => $grid->source->label(),
            // What this source is entitled to say about itself, so no screen has
            // to branch on a provider name to know whether «não participou» is a
            // fact or an invention (§6).
            'source_states_participation' => $grid->source->statesParticipation(),
            'source_result_label' => $grid->source->resultLabel(),
            'source_needs_describing' => $grid->source->needsToBeDescribed(),
            'suggested_title' => $grid->instrument->title,
            'mode' => $mapping->mode,
            'result_mode' => $mapping->resultMode,
            'instrument_id' => $mapping->instrumentId,
            'instrument_attributes' => $mapping->instrumentAttributes,
            'overall_domains' => $mapping->overallDomains,
            'overall_item_id' => $mapping->overallItemId,
            'groups' => $groups,
            'reconciliation' => $this->reconciliation($grid, $groups),
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

                // Judged either way: a right/wrong verdict that the cotação turns
                // into a mark, or a mark the source stated outright.
                if ($worth === null || ($result->isCorrect === null && $result->pointsEarned === null)) {
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
     * The sections of the test, with what each is worth and where the teacher
     * has said it counts.
     *
     * The cotação is the sum of the section's own questions, exactly as the
     * source declares them — never a share of the total, never inferred. The
     * domain is empty until a person chooses one: a group named «GRUPO III» has
     * said nothing about curriculum (§4).
     *
     * @return list<array<string, mixed>>
     */
    protected function groups(CanonicalCorrectionGrid $grid, ImportMapping $mapping): array
    {
        $rows = [];

        foreach ($grid->groups as $group) {
            $maximum = '0';
            $questions = 0;

            foreach ($grid->items as $item) {
                if ($item->groupSourceKey !== $group->sourceKey) {
                    continue;
                }

                $questions++;

                if ($item->pointsPossible !== null) {
                    $maximum = Bc::add($maximum, Bc::of($item->pointsPossible));
                }
            }

            $rows[] = [
                'source_key' => $group->sourceKey,
                'sequence' => $group->sequence,
                'label' => $group->label,
                'questions' => $questions,
                'points_possible' => GroupResultItem::points($maximum),
                'domains' => $mapping->domainsForGroup($group->sourceKey),
            ];
        }

        return $rows;
    }

    /**
     * What each student scored in each section — the number the grouped import
     * would record, shown before anything is written.
     *
     * A section with any unmarked question shows «—» rather than a partial sum,
     * because a partial sum is a lower score wearing a complete one's clothes.
     *
     * @param  list<array<string, mixed>>  $students
     * @param  list<array<string, mixed>>  $groups
     * @return list<array<string, mixed>>
     */
    protected function withGroupResults(array $students, CanonicalCorrectionGrid $grid, array $groups): array
    {
        $questionsOf = [];

        foreach ($grid->items as $item) {
            if ($item->groupSourceKey !== null) {
                $questionsOf[$item->groupSourceKey][] = $item->sourceKey;
            }
        }

        return array_map(function (array $student) use ($grid, $groups, $questionsOf): array {
            $marks = [];

            foreach ($grid->resultsForStudent($student['source_key']) as $result) {
                $marks[$result->itemSourceKey] = $result->pointsEarned;
            }

            $perGroup = [];
            $overall = '0';
            $complete = true;

            foreach ($groups as $group) {
                $earned = GroupResultItem::sum($marks, $questionsOf[$group['source_key']] ?? []);

                $perGroup[] = [
                    'source_key' => $group['source_key'],
                    'label' => $group['label'],
                    'earned' => $earned,
                    'possible' => $group['points_possible'],
                ];

                if ($earned === null) {
                    $complete = false;

                    continue;
                }

                $overall = Bc::add($overall, Bc::of($earned));
            }

            $student['group_results'] = $perGroup;
            $student['lapis_total'] = $complete ? GroupResultItem::points($overall) : null;

            // The percentage the instrument would produce, for the review
            // screen. The engine draws its own; this is a preview of it.
            $possible = '0';

            foreach ($groups as $group) {
                $possible = Bc::add($possible, Bc::of($group['points_possible']));
            }

            $student['lapis_percentage'] = ($complete && ! Bc::isZero($possible))
                ? Bc::round(Bc::mul(Bc::div($overall, $possible), '100'), 1, 'half_up')
                : null;

            return $student;
        }, $students);
    }

    /**
     * Where LÁPIS and the source disagree, and by how much.
     *
     * Two checks the file makes possible: the sections' cotações against the
     * total the export declares, and each student's marks against the total the
     * export computed for them. A disagreement is REPORTED and never resolved —
     * the source total is provenance, and CalculationEngine stays sovereign
     * (§27, §28).
     *
     * A cent of rounding is not a disagreement. Intuitivo itself writes 3.33 and
     * 1.67, so sums land a hundredth away from each other legitimately; the
     * tolerance is one cent, which is the precision the file itself uses.
     *
     * @param  list<array<string, mixed>>  $groups
     * @return array<string, mixed>
     */
    protected function reconciliation(CanonicalCorrectionGrid $grid, array $groups): array
    {
        if ($groups === []) {
            return ['applicable' => false];
        }

        $sumOfGroups = '0';

        foreach ($groups as $group) {
            $sumOfGroups = Bc::add($sumOfGroups, Bc::of($group['points_possible']));
        }

        $declared = $grid->instrument->sourceTotal;
        $maximumAgrees = $declared === null
            || Bc::compare(Bc::round(Bc::sub($sumOfGroups, Bc::of($declared)), 4, 'half_up'), '0') === 0;

        $studentsDisagreeing = 0;

        foreach ($grid->students as $student) {
            if ($student->sourceScore === null) {
                continue;
            }

            $sum = '0';
            $complete = true;

            foreach ($grid->resultsForStudent($student->sourceKey) as $result) {
                if ($result->pointsEarned === null) {
                    $complete = false;

                    break;
                }

                $sum = Bc::add($sum, Bc::of($result->pointsEarned));
            }

            if (! $complete) {
                continue;
            }

            $difference = Bc::sub(Bc::of($sum), Bc::of($student->sourceScore));

            if (Bc::compare($this->absolute($difference), Bc::of(self::TOLERANCE)) > 0) {
                $studentsDisagreeing++;
            }
        }

        return [
            'applicable' => true,
            'groups_total' => GroupResultItem::points($sumOfGroups),
            'source_total' => $declared,
            'maximum_agrees' => $maximumAgrees,
            'students_disagreeing' => $studentsDisagreeing,
        ];
    }

    /**
     * @param  numeric-string  $value
     * @return numeric-string
     */
    protected function absolute(string $value): string
    {
        return Bc::compare($value, '0') < 0 ? Bc::sub('0', $value) : $value;
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
            $result->isCorrect === null && $result->pointsEarned === null ? $unresolved++ : $judged++;
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

        foreach ($this->reconciliationIssues($grid, $mapping) as $issue) {
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
        //
        // Unless the parser already said something more specific. A spreadsheet
        // whose result column has not been given a maximum has no percentages
        // YET, which is not the same as carrying none — telling the teacher
        // their file has no classifications when it plainly does would send them
        // looking for the wrong problem.
        if ($overall && ! $this->carriesOverallResults($grid) && $grid->errors() === []) {
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

            // Only when it counts. An item with no allocation enters no domain,
            // which the model allows (§4.3) and which is harmless for an
            // instrument that does not enter the calculation at all.
            $counts = (bool) ($mapping->instrumentAttributes['counts_toward_classification'] ?? false);

            if ($overall) {
                if ($counts && ! $mapping->overallDomainIsDecided()) {
                    $issues[] = ImportIssue::make(
                        IssueCode::UnsupportedStructure,
                        'Selecione o domínio avaliado. É ele que diz para onde conta este resultado.',
                        ['field' => 'overall_domains'],
                    )->toArray();
                }

                return $issues;
            }

            if ($mapping->importsGroupResults()) {
                $withoutDomain = [];

                foreach ($grid->groups as $group) {
                    if ($mapping->domainsForGroup($group->sourceKey) === []) {
                        $withoutDomain[] = $group->label ?? $group->sourceKey;
                    }
                }

                if ($counts && $withoutDomain !== []) {
                    $issues[] = ImportIssue::make(
                        IssueCode::UnsupportedStructure,
                        count($withoutDomain) === 1
                            ? 'Indique o domínio avaliado por «'.$withoutDomain[0].'».'
                            : 'Indique o domínio avaliado por: '.implode(', ', $withoutDomain).'.',
                        ['field' => 'group_domains', 'groups' => count($withoutDomain)],
                    )->toArray();
                }

                return $issues;
            }
        } else {
            if ($mapping->importsGroupResults()) {
                // Grouped results build their own structure; laying them onto
                // somebody else's instrument would mean guessing which of its
                // questions each group corresponds to.
                $issues[] = ImportIssue::make(
                    IssueCode::UnsupportedStructure,
                    'Os resultados por grupos só podem criar uma avaliação nova. Para uma avaliação existente, use o resultado global ou o detalhe das perguntas.',
                    ['field' => 'result_mode'],
                )->toArray();

                return $issues;
            }

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
     * Where LÁPIS and the source disagree, said out loud.
     *
     * Never resolved silently and never allowed to change a mark: the source
     * total is provenance, LÁPIS computes its own, and a source that weights its
     * questions differently will legitimately disagree (§27, §28). So these are
     * warnings the teacher accepts knowingly, not errors that block.
     *
     * @return list<array<string, mixed>>
     */
    protected function reconciliationIssues(CanonicalCorrectionGrid $grid, ImportMapping $mapping): array
    {
        if (! $mapping->importsGroupResults() || $grid->groups === []) {
            return [];
        }

        $reconciliation = $this->reconciliation($grid, $this->groups($grid, $mapping));
        $issues = [];

        if ($reconciliation['maximum_agrees'] === false) {
            $issues[] = ImportIssue::make(
                IssueCode::SourceTotalMismatch,
                'As cotações dos grupos somam '.$reconciliation['groups_total']
                    .', mas o ficheiro declara um total de '.$reconciliation['source_total']
                    .'. O LÁPIS usa a soma dos grupos.',
                ['groups_total' => $reconciliation['groups_total'], 'source_total' => $reconciliation['source_total']],
            )->toArray();
        }

        if ($reconciliation['students_disagreeing'] > 0) {
            $issues[] = ImportIssue::make(
                IssueCode::SourceTotalMismatch,
                trans_choice(
                    '{1}Há 1 aluno cujo total no ficheiro não bate certo com as suas próprias classificações.'
                        .'|[2,*]Há :count alunos cujo total no ficheiro não bate certo com as suas próprias classificações.',
                    $reconciliation['students_disagreeing'],
                    ['count' => $reconciliation['students_disagreeing']],
                ),
                ['students' => $reconciliation['students_disagreeing']],
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
            'O ficheiro traz resultados para alunos a quem este elemento de avaliação não é aplicável na data indicada (entrada posterior ou saída anterior). Confirme a data ou a correspondência.',
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
            ->whereIn('status', [InstrumentStatus::Prepared->value, InstrumentStatus::InCorrection->value])
            ->with(['items.group', 'academicPeriod'])
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
                // The evaluation's own date and period, so the review step can
                // SHOW them instead of asking again. They belong to the
                // instrument and the file never gets a say in either (§18).
                'period' => $instrument->academicPeriod?->label,
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

            if ($enrollmentId === null || $itemId === null || $item === null
                || ($result->isCorrect === null && $result->pointsEarned === null)) {
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
