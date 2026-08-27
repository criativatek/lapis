<?php

namespace App\Domain\Import\Correction;

/**
 * A correction grid, read, and said in one vocabulary.
 *
 * This is the seam of the whole feature. A parser's only job is to turn its own
 * format into this; everything downstream — preview, mapping, persistence —
 * speaks only this. That is what stops the second format from rewriting the
 * first one's assumptions, and the third from being impossible.
 *
 * Nothing here knows about rows, columns, sheets or cells. Those are facts about
 * a file, not about an assessment, and they live in `sourceMetadata` where a
 * parser can keep whatever it needs to explain itself without the rest of the
 * application learning the shape of a spreadsheet.
 *
 * Three separations are deliberate and are the reason this is not simply a
 * two-dimensional table:
 *
 *  - GROUP is not DOMAIN. Where a question sits and what it assesses are
 *    different decisions, and a source that names its sections «Leitura» has
 *    still said nothing about curriculum (§9).
 *  - SUMMARY is not RESULT. A source's own total is a conclusion it drew; Lapispro
 *    draws its own and remains sovereign (§19, §75).
 *  - UNRESOLVED is not ZERO. A blank is a question about a student, not an
 *    answer about them (§18).
 */
final readonly class CanonicalCorrectionGrid
{
    /**
     * @param  array<string, mixed>  $sourceMetadata  Whatever the parser needs to explain itself. Never personal data.
     * @param  list<CanonicalGroup>  $groups
     * @param  list<CanonicalItem>  $items
     * @param  list<CanonicalStudent>  $students
     * @param  list<CanonicalResult>  $results
     * @param  list<CanonicalSummary>  $summaries
     * @param  list<ImportIssue>  $issues
     */
    public function __construct(
        public CorrectionGridSource $source,
        public CanonicalInstrument $instrument,
        public array $groups = [],
        public array $items = [],
        public array $students = [],
        public array $results = [],
        public array $summaries = [],
        public array $issues = [],
        public array $sourceMetadata = [],
    ) {}

    /**
     * Nothing may be confirmed while an Error stands. Warnings are for the
     * teacher to accept knowingly; Errors are the import saying it cannot do
     * this correctly, which is not something a confirmation button may override.
     */
    public function canBeConfirmed(): bool
    {
        return $this->errors() === [];
    }

    /**
     * @return list<ImportIssue>
     */
    public function errors(): array
    {
        return array_values(array_filter($this->issues, fn (ImportIssue $issue): bool => $issue->blocksConfirmation()));
    }

    /**
     * @return list<ImportIssue>
     */
    public function warnings(): array
    {
        return array_values(array_filter(
            $this->issues,
            fn (ImportIssue $issue): bool => $issue->severity === IssueSeverity::Warning,
        ));
    }

    /**
     * Questions the source gave no worth for. Not a failure — Plickers simply
     * does not say what a question is worth — but the import cannot produce
     * marks until somebody decides (§51).
     *
     * @return list<CanonicalItem>
     */
    public function itemsWithoutDeclaredWorth(): array
    {
        return array_values(array_filter($this->items, fn (CanonicalItem $item): bool => ! $item->hasDeclaredWorth()));
    }

    public function group(string $sourceKey): ?CanonicalGroup
    {
        foreach ($this->groups as $group) {
            if ($group->sourceKey === $sourceKey) {
                return $group;
            }
        }

        return null;
    }

    public function item(string $sourceKey): ?CanonicalItem
    {
        foreach ($this->items as $item) {
            if ($item->sourceKey === $sourceKey) {
                return $item;
            }
        }

        return null;
    }

    public function student(string $sourceKey): ?CanonicalStudent
    {
        foreach ($this->students as $student) {
            if ($student->sourceKey === $sourceKey) {
                return $student;
            }
        }

        return null;
    }

    /**
     * @return list<CanonicalResult>
     */
    public function resultsForStudent(string $studentSourceKey): array
    {
        return array_values(array_filter(
            $this->results,
            fn (CanonicalResult $result): bool => $result->studentSourceKey === $studentSourceKey,
        ));
    }

    /**
     * @return list<CanonicalSummary>
     */
    public function summariesForStudent(string $studentSourceKey): array
    {
        return array_values(array_filter(
            $this->summaries,
            fn (CanonicalSummary $summary): bool => $summary->scope === CanonicalSummary::SCOPE_STUDENT
                && $summary->subjectSourceKey === $studentSourceKey,
        ));
    }

    /**
     * Items belonging to one section, in the order the source put them.
     *
     * @return list<CanonicalItem>
     */
    public function itemsInGroup(string $groupSourceKey): array
    {
        $items = array_values(array_filter(
            $this->items,
            fn (CanonicalItem $item): bool => $item->groupSourceKey === $groupSourceKey,
        ));

        usort($items, fn (CanonicalItem $a, CanonicalItem $b): int => $a->sequence <=> $b->sequence);

        return $items;
    }

    /**
     * @param  list<ImportIssue>  $issues
     */
    public function withIssues(array $issues): self
    {
        return new self(
            source: $this->source,
            instrument: $this->instrument,
            groups: $this->groups,
            items: $this->items,
            students: $this->students,
            results: $this->results,
            summaries: $this->summaries,
            issues: [...$this->issues, ...$issues],
            sourceMetadata: $this->sourceMetadata,
        );
    }

    /**
     * @param  list<CanonicalItem>  $items
     * @param  list<CanonicalResult>  $results
     */
    public function withItemsAndResults(array $items, array $results): self
    {
        return new self(
            source: $this->source,
            instrument: $this->instrument,
            groups: $this->groups,
            items: $items,
            students: $this->students,
            results: $results,
            summaries: $this->summaries,
            issues: $this->issues,
            sourceMetadata: $this->sourceMetadata,
        );
    }

    /**
     * The shape stored in `canonical_snapshot` and handed to the interface.
     * Plain arrays on purpose: it has to survive a JSON round-trip through the
     * database and through Inertia without either end knowing these classes.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source->value,
            'instrument' => $this->instrument->toArray(),
            'groups' => array_map(fn (CanonicalGroup $group): array => $group->toArray(), $this->groups),
            'items' => array_map(fn (CanonicalItem $item): array => $item->toArray(), $this->items),
            'students' => array_map(fn (CanonicalStudent $student): array => $student->toArray(), $this->students),
            'results' => array_map(fn (CanonicalResult $result): array => $result->toArray(), $this->results),
            'summaries' => array_map(fn (CanonicalSummary $summary): array => $summary->toArray(), $this->summaries),
            'issues' => array_map(fn (ImportIssue $issue): array => $issue->toArray(), $this->issues),
            'source_metadata' => $this->sourceMetadata,
        ];
    }
}
