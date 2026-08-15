<?php

namespace App\Services\Import\Correction;

use App\Domain\Import\Correction\CanonicalCorrectionGrid;
use App\Domain\Import\Correction\ImportIssue;
use App\Domain\Import\Correction\ImportMapping;
use App\Domain\Import\Correction\IssueCode;
use App\Domain\Import\Correction\IssueSeverity;
use App\Models\CorrectionImport;
use App\Models\Enrollment;
use App\Models\Instrument;
use App\Models\InstrumentItem;
use App\Models\InstrumentStatus;
use App\Support\Import\WithoutLeakingTheGrid;

/**
 * Turns what a LÁPIS grid CLAIMS into what the database will actually allow.
 *
 * This is where the file stops being believed. Everything the workbook carries
 * is an identifier a teacher could have typed into a cell, and every one of
 * them is looked up under the ordinary tenant scope and checked against the
 * import the teacher actually started:
 *
 *  - the instrument must exist, belong to THIS import's class, and be in a
 *    state where marks may still be written;
 *  - every item must belong to THAT instrument;
 *  - every enrolment must belong to THAT class.
 *
 * A ULID from another organization simply does not resolve — the global scope
 * sees to that before any of these checks run — and one from another class of
 * the same organization is refused by them. Neither produces a partial import:
 * the answer is all of it or none of it, because a grid that half-matched is a
 * grid whose other half would land somewhere nobody chose (§6).
 *
 * When it resolves, the teacher is left with a mapping already made: associate
 * with this instrument, question by question, these rows to these students. No
 * cotações to type, no domains to pick — those live on the instrument and are
 * read from it, exactly as they already are for any «associar a uma avaliação
 * existente» import (§7).
 */
class ResolveLapisGrid
{
    /**
     * Returns true when the grid resolved and the mapping was written.
     */
    public function for(CorrectionImport $import): bool
    {
        $grid = CanonicalCorrectionGridSnapshot::rehydrate($import->canonical_snapshot ?? []);

        if (! $this->looksLikeAGrid($import, $grid)) {
            return false;
        }

        $instrument = $this->instrumentFor($import, (string) $grid->instrument->externalId);

        if ($instrument === null) {
            return $this->refuse($import, $grid, __('Esta grelha LÁPIS não corresponde a nenhuma avaliação desta turma que ainda possa receber resultados.'));
        }

        $items = $this->items($grid, $instrument);

        if ($items === null) {
            return $this->refuse($import, $grid, __('Esta grelha LÁPIS tem perguntas que já não pertencem a esta avaliação. A avaliação pode ter sido alterada depois de a grelha ter sido descarregada.'));
        }

        $students = $this->students($grid, $import);

        if ($students === null) {
            return $this->refuse($import, $grid, __('Esta grelha LÁPIS tem alunos que não pertencem a esta turma.'));
        }

        $mapping = new ImportMapping(
            mode: ImportMapping::MODE_ASSOCIATE,
            instrumentId: (int) $instrument->getKey(),
            students: $students,
            items: $items,
            // Question by question, onto the instrument's own items. The same
            // path a manual «associar» import takes, with the answers filled in.
            resultMode: ImportMapping::RESULT_PER_QUESTION,
        );

        $import->forceFill([
            'mapping_snapshot' => $mapping->toArray(),
            'instrument_id' => (int) $instrument->getKey(),
        ])->save();

        return true;
    }

    /**
     * Whether this import carries a grid that named an instrument. A file that
     * declared nothing is not this class's business.
     */
    protected function looksLikeAGrid(CorrectionImport $import, CanonicalCorrectionGrid $grid): bool
    {
        return ($import->source_metadata['lapis_grid'] ?? false) === true
            && $grid->instrument->externalId !== null
            && $grid->items !== []
            && $grid->students !== [];
    }

    /**
     * The instrument the file names — if the teacher may write to it, and if it
     * belongs to the class this import was started for.
     *
     * The class check is what stops a grid downloaded for 7.º A from being
     * imported into 7.º B: both may be the same teacher's, so authorisation
     * alone would let it through, and the marks would be somebody else's.
     */
    protected function instrumentFor(CorrectionImport $import, string $ulid): ?Instrument
    {
        $instrument = Instrument::query()->where('ulid', $ulid)->first();

        if ($instrument === null || $instrument->class_id !== $import->class_id) {
            return null;
        }

        return in_array($instrument->status, [
            InstrumentStatus::Draft,
            InstrumentStatus::Prepared,
            InstrumentStatus::InCorrection,
        ], true) ? $instrument : null;
    }

    /**
     * Source key => instrument item id, or null when any of them fails.
     *
     * @return array<string, int>|null
     */
    protected function items(CanonicalCorrectionGrid $grid, Instrument $instrument): ?array
    {
        $byUlid = InstrumentItem::query()
            ->where('instrument_id', $instrument->getKey())
            ->pluck('id', 'ulid');

        $mapped = [];

        foreach ($grid->items as $item) {
            $id = $item->externalId === null ? null : $byUlid[$item->externalId] ?? null;

            if ($id === null) {
                return null;
            }

            $mapped[$item->sourceKey] = (int) $id;
        }

        // EVERY question, or none of them.
        //
        // A grid covering three of four items is not a smaller grid, it is a
        // stale one: either a column was deleted, or the evaluation gained a
        // question after the file was downloaded. Importing what matched would
        // leave the fourth silently unassessed while the screen said the import
        // was complete (§14).
        return count($mapped) === $byUlid->count() ? $mapped : null;
    }

    /**
     * Source key => enrolment id, or null when any row names somebody who is
     * not in this class.
     *
     * @return array<string, int>|null
     */
    protected function students(CanonicalCorrectionGrid $grid, CorrectionImport $import): ?array
    {
        $byUlid = Enrollment::query()
            ->where('class_id', $import->class_id)
            ->pluck('id', 'ulid');

        $mapped = [];

        foreach ($grid->students as $student) {
            $id = $student->externalId === null ? null : $byUlid[$student->externalId] ?? null;

            if ($id === null) {
                return null;
            }

            $mapped[$student->sourceKey] = (int) $id;
        }

        return $mapped;
    }

    /**
     * Records why the grid could not be used, and leaves the import unable to
     * be confirmed until the teacher decides what to do about it.
     *
     * The generic path stays open: the file is still a readable spreadsheet, and
     * a teacher who has filled in thirty marks should not have to start again
     * because the evaluation changed underneath them (§14).
     */
    protected function refuse(CorrectionImport $import, CanonicalCorrectionGrid $grid, string $reason): bool
    {
        $refused = $grid->withIssues([ImportIssue::make(
            IssueCode::UnsupportedStructure,
            $reason.' '.__('Pode importá-la como outra folha de cálculo, indicando onde estão os resultados.'),
            context: ['lapis_grid' => 'rejected'],
            severity: IssueSeverity::Error,
        )]);

        WithoutLeakingTheGrid::run(function () use ($import, $refused): void {
            $import->forceFill([
                'canonical_snapshot' => $refused->toArray(),
                // No longer claiming to be one of ours: the interface falls back
                // to the ordinary mapping questions from here.
                'source_metadata' => [...($import->source_metadata ?? []), 'lapis_grid' => false],
            ])->save();
        }, 'ao recusar uma grelha LÁPIS alterada');

        return false;
    }
}
