<?php

namespace App\Services\Import\Correction;

use App\Domain\Assessment\Bc;
use App\Domain\Import\Correction\CanonicalCorrectionGrid;
use App\Domain\Import\Correction\CanonicalItem;
use App\Domain\Import\Correction\ImportMapping;
use App\Models\CorrectionImport;
use App\Models\CorrectionImportStatus;
use App\Models\Instrument;
use App\Models\InstrumentStatus;
use App\Models\ResultState;
use App\Models\StudentItemScore;
use App\Models\User;
use App\Services\Assessment\InstrumentBuilder;
use App\Services\Assessment\RecordScores;
use App\Support\Entitlements\Entitlements;
use App\Support\Import\CorrectionImportException;
use App\Support\Import\CorrectionImportTempStorage;
use Illuminate\Support\Facades\DB;

/**
 * The single door through which an imported grid becomes assessment data.
 *
 * Everything before this is a conversation: a file, a reading of it, decisions
 * the teacher made in the wizard. Nothing academic exists until this runs, and
 * when it does it runs once, inside one transaction, through the same services
 * the manual path uses — InstrumentBuilder for structure, RecordScores for
 * marks. There is deliberately no second set of rules for imported data: an
 * import that could write a mark the grid would have refused is an import that
 * has quietly forked the assessment model.
 *
 * Four refusals are load-bearing:
 *
 *  - It writes no mark for a cell the source did not judge. A «-» in Plickers
 *    means nobody answered; it is not a zero and it is certainly not an
 *    absence, which is an event a teacher records rather than something a
 *    parser infers (§25, §26 of the brief; §68/§69 of the design).
 *  - It never completes a correction. Even a grid arriving 100% filled stays
 *    «em correção», because declaring a correction finished is the teacher's
 *    decision and always has been (§3.3).
 *  - It never overwrites a mark silently. A cell that already has a different
 *    value is a conflict the teacher resolves, not a race the file wins.
 *  - It refuses to run twice. Status is re-read under a lock inside the
 *    transaction, so a double submit or an HTTP retry finds the import already
 *    imported and stops.
 */
class ImportCorrectionGrid
{
    public function __construct(
        protected InstrumentBuilder $builder,
        protected RecordScores $recordScores,
        protected CorrectionImportTempStorage $storage,
        protected Entitlements $entitlements,
    ) {}

    /**
     * Applies a fully decided import. Returns the instrument the marks landed on.
     */
    public function confirm(CorrectionImport $import, User $teacher): Instrument
    {
        if (! $this->entitlements->allowsFor($import->schoolClass->organization, 'correction_grid_import')) {
            throw CorrectionImportException::notEntitled();
        }

        $grid = $this->grid($import);
        $mapping = ImportMapping::fromArray($import->mapping_snapshot);

        $this->guard($import, $grid, $mapping);

        return DB::transaction(function () use ($import, $grid, $mapping, $teacher): Instrument {
            // Re-read under a lock, inside the transaction. Two tabs, a double
            // click and an HTTP retry all look identical from here, and all
            // three have to lose (§9).
            $locked = CorrectionImport::query()
                ->whereKey($import->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === CorrectionImportStatus::Imported) {
                throw CorrectionImportException::alreadyImported();
            }

            if (! $locked->status->canBeConfirmed()) {
                throw CorrectionImportException::notReady();
            }

            $instrument = $mapping->createsInstrument()
                ? $this->createInstrument($import, $grid, $mapping)
                : $this->existingInstrument($import, $mapping);

            $cells = $this->cells($grid, $mapping, $instrument);

            if ($cells !== []) {
                $this->recordScores->save($instrument, $cells, $teacher);
            }

            $locked->forceFill([
                'instrument_id' => $instrument->getKey(),
                'status' => CorrectionImportStatus::Imported,
                'confirmed_by' => $teacher->getKey(),
                'confirmed_at' => now(),
                // The file goes; what it said stays, minimised (§10).
                'canonical_snapshot' => $this->provenance($grid, $mapping),
                'stored_path' => null,
                'summary' => $this->summary($grid, $mapping, $cells),
            ])->save();

            // Outside nothing: the file is deleted only once the write that
            // makes it redundant has been decided. If the transaction rolls
            // back after this, the row still points at no file — which is the
            // safe direction to fail in.
            $this->storage->delete($import->stored_path);

            return $instrument;
        });
    }

    /**
     * What was read from the file, rebuilt from the stored snapshot rather than
     * from the file itself — the file may already be gone, and the snapshot is
     * what the teacher actually reviewed.
     */
    protected function grid(CorrectionImport $import): CanonicalCorrectionGrid
    {
        $snapshot = $import->canonical_snapshot;

        if (! is_array($snapshot) || ($snapshot['items'] ?? []) === []) {
            throw CorrectionImportException::nothingParsed();
        }

        return CanonicalCorrectionGridSnapshot::rehydrate($snapshot);
    }

    /**
     * Everything that must be true before a single row is written. Checked
     * before the transaction opens so a refusal writes nothing at all — the same
     * shape InstrumentBuilder and RecordScores already use.
     */
    protected function guard(CorrectionImport $import, CanonicalCorrectionGrid $grid, ImportMapping $mapping): void
    {
        if (! $import->status->canBeConfirmed()) {
            throw $import->status === CorrectionImportStatus::Imported
                ? CorrectionImportException::alreadyImported()
                : CorrectionImportException::notReady();
        }

        // A source row the teacher never looked at is not the same as one they
        // chose to leave out. The first blocks; the second does not.
        $undecided = 0;

        foreach ($grid->students as $student) {
            if (! $mapping->wasDecided($student->sourceKey)) {
                $undecided++;
            }
        }

        if ($undecided > 0) {
            throw CorrectionImportException::studentsNotDecided($undecided);
        }

        if ($mapping->createsInstrument()) {
            $withoutPoints = 0;

            foreach ($grid->items as $item) {
                if ($this->pointsFor($item, $mapping) === null) {
                    $withoutPoints++;
                }
            }

            if ($withoutPoints > 0) {
                throw CorrectionImportException::missingPoints($withoutPoints);
            }

            return;
        }

        $unmapped = 0;

        foreach ($grid->items as $item) {
            if (! isset($mapping->items[$item->sourceKey])) {
                $unmapped++;
            }
        }

        if ($unmapped > 0) {
            throw CorrectionImportException::itemsNotMapped($unmapped);
        }
    }

    /**
     * The teacher's cotação, or the source's own if it ever states one. Never a
     * default: a question with no declared worth has not been decided, and the
     * guard above refuses rather than assuming a value (§3).
     */
    protected function pointsFor(CanonicalItem $item, ImportMapping $mapping): ?string
    {
        $chosen = $mapping->points[$item->sourceKey] ?? null;

        if (is_string($chosen) && $chosen !== '' && is_numeric($chosen)) {
            return $chosen;
        }

        return $item->pointsPossible;
    }

    /**
     * Builds the instrument through InstrumentBuilder, exactly as the manual
     * form does. Not a shortcut around it: the builder is where the structural
     * invariants live, and an import that bypassed them would be the second
     * place those rules exist.
     */
    protected function createInstrument(CorrectionImport $import, CanonicalCorrectionGrid $grid, ImportMapping $mapping): Instrument
    {
        $attributes = $mapping->instrumentAttributes;

        $items = [];

        foreach ($grid->items as $item) {
            $items[] = [
                'code' => $item->code ?? 'Q'.$item->sequence,
                'label' => $item->label,
                'points_possible' => (float) $this->pointsFor($item, $mapping),
                // One implicit section: Plickers has no structure, and a
                // «Grupo 1» the teacher never made would be an invention (§13).
                'group_index' => 0,
                'domains' => array_map(
                    fn (array $allocation): array => [
                        'domain_id' => (int) $allocation['domain_id'],
                        'allocation_percent' => (float) $allocation['allocation_percent'],
                    ],
                    $mapping->domains[$item->sourceKey] ?? [],
                ),
            ];
        }

        return $this->builder->create($import->schoolClass, [
            'academic_period_id' => (int) ($attributes['academic_period_id'] ?? 0),
            'instrument_type_id' => (int) ($attributes['instrument_type_id'] ?? 0),
            'title' => (string) ($attributes['title'] ?? ''),
            'applied_on' => (string) ($attributes['applied_on'] ?? ''),
            // Marks arrive with it, so it starts where a half-marked instrument
            // belongs — and never at Completed (§29).
            'status' => InstrumentStatus::InCorrection->value,
            'counts_toward_classification' => (bool) ($attributes['counts_toward_classification'] ?? false),
            'purpose' => (string) ($attributes['purpose'] ?? 'formative'),
            'total_points' => $attributes['total_points'] ?? null,
        ], $items);
    }

    /**
     * Resolves the instrument the teacher chose, and refuses the states where
     * writing would be wrong — a finished correction, a published one, a
     * cancelled one. Reopening is explicit and is never done here (§22).
     */
    protected function existingInstrument(CorrectionImport $import, ImportMapping $mapping): Instrument
    {
        if ($mapping->instrumentId === null) {
            throw CorrectionImportException::instrumentNotChosen();
        }

        $instrument = Instrument::query()->whereKey($mapping->instrumentId)->first();

        if ($instrument === null || $instrument->class_id !== $import->class_id) {
            throw CorrectionImportException::instrumentNotInClass();
        }

        if (! in_array($instrument->status, [InstrumentStatus::Draft, InstrumentStatus::Prepared, InstrumentStatus::InCorrection], true)) {
            throw CorrectionImportException::instrumentNotEligible($instrument->status->label());
        }

        return $instrument;
    }

    /**
     * Turns judged responses into grid cells.
     *
     * Only cells the source actually judged produce a mark. Everything else —
     * an unanswered question, a student who took no part — is simply not sent,
     * so it stays exactly as unassessed as it was. Omitting is the whole
     * mechanism: RecordScores would delete a row for a `pending` cell, and
     * deleting a mark the teacher had already entered is not this feature's
     * business.
     *
     * @return list<array{enrollment_id: int, instrument_item_id: int, result_state: string, points_earned: float|null}>
     */
    protected function cells(CanonicalCorrectionGrid $grid, ImportMapping $mapping, Instrument $instrument): array
    {
        $itemIds = $this->itemIds($grid, $mapping, $instrument);
        $existing = $this->existingScores($instrument);

        $cells = [];

        foreach ($grid->results as $result) {
            $enrollmentId = $mapping->enrollmentFor($result->studentSourceKey);
            $itemId = $itemIds[$result->itemSourceKey] ?? null;

            if ($enrollmentId === null || $itemId === null) {
                continue; // Row ignored by the teacher, or a question left out.
            }

            $item = $grid->item($result->itemSourceKey);

            if ($item === null || $result->isCorrect === null) {
                continue; // Nothing was judged here; nothing may be written.
            }

            $points = $this->pointsForPersistence($item, $mapping, $instrument, $itemId);

            if ($points === null) {
                continue;
            }

            $earned = $result->resolvedAgainst($points)->pointsEarned;

            if ($earned === null) {
                continue;
            }

            $current = $existing[$enrollmentId.':'.$itemId] ?? null;

            if ($current !== null && $this->sameMark($current, $earned)) {
                continue; // Already says exactly this. Writing it again is noise.
            }

            if ($current !== null && $mapping->conflictChoice($enrollmentId, $itemId) !== ImportMapping::CONFLICT_IMPORT) {
                continue; // A different mark already exists and the teacher did
                // not say to replace it. Silence here means keep.
            }

            $cells[] = [
                'enrollment_id' => $enrollmentId,
                'instrument_item_id' => $itemId,
                'result_state' => ResultState::Assessed->value,
                'points_earned' => (float) $earned,
            ];
        }

        return $cells;
    }

    /**
     * In associate mode the instrument's own cotação wins: the file has none,
     * and quietly rewriting what the teacher already configured would change
     * their assessment on the way past (§24).
     */
    protected function pointsForPersistence(CanonicalItem $item, ImportMapping $mapping, Instrument $instrument, int $itemId): ?string
    {
        if ($mapping->createsInstrument()) {
            return $this->pointsFor($item, $mapping);
        }

        $existing = $instrument->items->firstWhere('id', $itemId);

        return $existing === null ? null : (string) $existing->points_possible;
    }

    /**
     * Source question => InstrumentItem id, whichever way the instrument arrived.
     *
     * @return array<string, int>
     */
    protected function itemIds(CanonicalCorrectionGrid $grid, ImportMapping $mapping, Instrument $instrument): array
    {
        if (! $mapping->createsInstrument()) {
            return array_map(intval(...), $mapping->items);
        }

        // Freshly created: the builder wrote them in the order we handed them
        // over, so sequence lines them up. Codes deliberately do not — the same
        // code may legitimately exist twice in one instrument (§23).
        $instrument->load('items');
        $bySequence = $instrument->items->sortBy('sequence')->values();

        $map = [];

        foreach ($grid->items as $index => $item) {
            $created = $bySequence[$index] ?? null;

            if ($created !== null) {
                $map[$item->sourceKey] = (int) $created->id;
            }
        }

        return $map;
    }

    /**
     * @return array<string, string>
     */
    protected function existingScores(Instrument $instrument): array
    {
        return StudentItemScore::query()
            ->where('instrument_id', $instrument->getKey())
            ->get(['enrollment_id', 'instrument_item_id', 'points_earned'])
            ->mapWithKeys(fn (StudentItemScore $score): array => [
                $score->enrollment_id.':'.$score->instrument_item_id => (string) $score->points_earned,
            ])
            ->all();
    }

    /**
     * Decimal comparison through the project's own helper, not through floats
     * and not through string equality: «4» and «4.0000» are the same mark, and
     * treating them as different would turn every re-import into a conflict.
     */
    protected function sameMark(string $current, string $incoming): bool
    {
        return Bc::compare(Bc::of($current), Bc::of($incoming)) === 0;
    }

    /**
     * What survives once the file is deleted.
     *
     * Enough to audit a mark back to its origin — which question, what the key
     * was, what the student answered, whether it was judged correct — and
     * nothing beyond it. In particular the source's own names are dropped: the
     * students are mapped to enrolments by this point, and keeping a second,
     * unmanaged copy of children's names would be retaining data the import no
     * longer needs (§10).
     *
     * @return array<string, mixed>
     */
    protected function provenance(CanonicalCorrectionGrid $grid, ImportMapping $mapping): array
    {
        $items = array_map(fn (CanonicalItem $item): array => [
            'source_key' => $item->sourceKey,
            'external_id' => $item->externalId,
            'code' => $item->code,
            'question_text' => $item->questionText,
            'answer_key' => $item->answerKey,
            'points_possible' => $this->pointsFor($item, $mapping),
        ], $grid->items);

        $results = [];

        foreach ($grid->results as $result) {
            $enrollmentId = $mapping->enrollmentFor($result->studentSourceKey);

            if ($enrollmentId === null) {
                continue; // Ignored rows leave no trace beyond the counts.
            }

            $results[] = [
                'enrollment_id' => $enrollmentId,
                'item_source_key' => $result->itemSourceKey,
                'raw_response' => $result->rawResponse,
                'is_correct' => $result->isCorrect,
                'source_value' => $result->sourceValue,
            ];
        }

        return [
            'source' => $grid->source->value,
            'instrument' => $grid->instrument->toArray(),
            'items' => $items,
            'results' => $results,
            'source_metadata' => $grid->sourceMetadata,
            // Names removed on purpose; the mapping already says who is who.
            'students_minimised' => true,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $cells
     * @return array<string, mixed>
     */
    protected function summary(CanonicalCorrectionGrid $grid, ImportMapping $mapping, array $cells): array
    {
        $ignored = 0;
        $nonParticipants = 0;

        foreach ($grid->students as $student) {
            if ($mapping->enrollmentFor($student->sourceKey) === null) {
                $ignored++;
            }

            if ($student->answeredNothing()) {
                $nonParticipants++;
            }
        }

        return [
            'students_in_file' => count($grid->students),
            'students_ignored' => $ignored,
            'students_without_participation' => $nonParticipants,
            'questions' => count($grid->items),
            'responses' => count($grid->results),
            'marks_written' => count($cells),
        ];
    }
}
