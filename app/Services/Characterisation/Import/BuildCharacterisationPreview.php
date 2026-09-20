<?php

namespace App\Services\Characterisation\Import;

use App\Models\Enrollment;
use App\Models\EnrollmentCharacterisation;
use App\Models\Intervention;
use App\Models\InterventionStatus;
use App\Models\SchoolClass;
use App\Support\Characterisation\AcronymSuggestion;
use App\Support\Characterisation\CodeResolution;
use App\Support\Characterisation\LegalCodeResolver;
use App\Support\Characterisation\SuggestAcronymCorrection;

/**
 * Turns a table into a proposal: who each row is about, what it says, and where
 * each part of it would go.
 *
 * THIS CLASS CANNOT WRITE. It has no models to save and no transaction to open,
 * and that is a structural guarantee rather than a discipline: there is no path
 * from parsing to the database, so «gravou sem confirmar» is not a bug that can
 * be introduced here by accident. Writing happens in a separate action that
 * only knows how to take decisions, never a parse.
 *
 * IT STILL READS. Showing "já registado" beside a section, or "já registada —
 * não será duplicada" beside a measure, needs to know what is already there —
 * a plain SELECT, never a write. That is why this class holds
 * MergeCharacterisationSections too: the same comparison the write path uses
 * to decide ADD vs ALREADY_PRESENT is used here to decide what the preview
 * shows, so the two can never disagree about what counts as new.
 */
class BuildCharacterisationPreview
{
    /**
     * Below this EXTRACTION confidence, a token is treated as "may have been
     * misread" — the trigger for both showing the low-confidence indicator
     * and attempting a §19 suggestion. Mirrors the 0.7 threshold the §38
     * structural step already uses client-side (CharacterisationImportDialog.vue),
     * so the two steps never disagree about what counts as "low".
     */
    private const LOW_EXTRACTION_CONFIDENCE = 0.7;

    public function __construct(
        private readonly ClassifyColumns $classifier,
        private readonly MatchCharacterisationRows $matcher,
        private readonly LegalCodeResolver $resolver,
        private readonly MergeCharacterisationSections $merger,
        private readonly SuggestAcronymCorrection $suggester,
    ) {}

    /**
     * @param  array<string, string>  $corrections  §19 acceptances: raw token (as it
     *                                              appeared in the source) => the accepted
     *                                              replacement. Applied to a cell's text BEFORE
     *                                              it reaches the resolver, so accepting a
     *                                              suggestion never bypasses LegalCodeResolver —
     *                                              it only changes what gets handed to it, exactly
     *                                              as if the teacher had typed the correction
     *                                              herself.
     */
    public function build(SchoolClass $class, TableGrid $grid, array $corrections = []): CharacterisationPreview
    {
        $columns = $this->classifier->classify($grid);
        $match = $this->matcher->forClass($class);

        $nameColumn = $this->columnFor($columns, ColumnRole::StudentName);
        $processColumn = $this->columnFor($columns, ColumnRole::SchoolNumber);

        $rows = [];

        foreach ($grid->rows as $index => $row) {
            $name = $nameColumn === null ? '' : $grid->cell($row, $nameColumn->index);
            $processNumber = $processColumn === null ? null : ($grid->cell($row, $processColumn->index) ?: null);

            if (trim($name) === '' && $processNumber === null) {
                // A row that identifies nobody is not a student with a missing
                // name — it is a footer, a total, or a blank. Skipped, not
                // reported as not-found, because «Total Alunos - 27» is not a
                // child the teacher forgot to enrol.
                continue;
            }

            $resolved = $this->resolutionsFor($grid, $index, $row, $columns, $corrections);
            $rowMatch = $match($name, $processNumber);
            $sections = $this->sectionsFor($grid, $row, $columns);
            $enrollment = $rowMatch->enrollmentUlid === null ? null : $this->enrollmentFor($class, $rowMatch->enrollmentUlid);

            $previewRow = new PreviewRow(
                rowNumber: $index + 1,
                rawName: trim($name),
                rawProcessNumber: $processNumber,
                match: $rowMatch,
                sections: $sections,
                sectionMerges: $enrollment === null ? [] : $this->sectionMergesFor($enrollment, $sections),
                measures: $resolved['measures'],
                alreadyActiveMeasureCodes: $enrollment === null ? [] : $this->alreadyActiveMeasureCodes($enrollment, $resolved['measures']),
                resources: $resolved['resources'],
                unresolved: $resolved['unresolved'],
                extractionConfidence: $resolved['extractionConfidence'],
                suggestions: $resolved['suggestions'],
            );

            if ($previewRow->hasContent()) {
                $rows[] = $previewRow;
            }
        }

        return new CharacterisationPreview($columns, $rows, $nameColumn !== null || $processColumn !== null);
    }

    /**
     * @param  list<ClassifiedColumn>  $columns
     * @param  list<string>  $row
     * @return array<string, string>
     */
    private function sectionsFor(TableGrid $grid, array $row, array $columns): array
    {
        $sections = [];

        foreach ($columns as $column) {
            $section = $column->role->section();

            if ($section === null) {
                continue;
            }

            $value = $grid->cell($row, $column->index);

            if ($value === '') {
                continue;
            }

            // Two columns mapping to the same section are joined rather than
            // one silently winning. Losing half of what a teacher wrote because
            // their sheet had «Observações» twice would be indefensible.
            $sections[$section->value] = isset($sections[$section->value])
                ? $sections[$section->value]."\n\n".$value
                : $value;
        }

        return $sections;
    }

    /**
     * Reads every column that can carry a code, and sorts what comes back into
     * the three destinations that are not free text.
     *
     * Resource columns are read too, and deliberately NOT mapped to a section.
     * «Apoios» used to classify as «Necessidades», which meant a Centro de
     * Recursos para a Inclusão was written into a child's needs — an assertion
     * nobody made, produced by the column that happened to exist. A resource
     * now reaches (C), is named, and is stored by nobody.
     *
     * @param  list<ClassifiedColumn>  $columns
     * @param  list<string>  $row
     * @param  array<string, string>  $corrections
     * @return array{measures: list<CodeResolution>, resources: list<CodeResolution>, unresolved: list<CodeResolution>, extractionConfidence: array<int, ?float>, suggestions: array<int, AcronymSuggestion>}
     */
    private function resolutionsFor(TableGrid $grid, int $rowIndex, array $row, array $columns, array $corrections): array
    {
        $measures = [];
        $resources = [];
        $unresolved = [];

        // §18: keyed on spl_object_id($resolution), never written onto the
        // CodeResolution object itself — that is what makes it structurally
        // impossible for extraction confidence to leak into CodeResolution's
        // own $confidence (domain confidence), a different question this
        // class has no opinion on (see CodeResolution's own note and
        // ExtractedCell's docblock). A plain array, not a WeakMap: the
        // resolutions it describes are kept alive by $measures/$resources/
        // $unresolved for exactly as long as this array is, so nothing here
        // needs weak references — only the same "never on the object" seam.
        /** @var array<int, ?float> */
        $extractionConfidence = [];

        foreach ($columns as $column) {
            if ($column->role !== ColumnRole::Measures && $column->role !== ColumnRole::Resources) {
                continue;
            }

            $value = $grid->cell($row, $column->index);

            if ($value === '') {
                continue;
            }

            // §19: a correction is applied to the CELL TEXT, before the
            // resolver ever sees it — never as a shortcut that hands out a
            // resolution directly. Accepting "ACN5 -> ACNS" therefore
            // resolves through the exact same LegalCodeResolver::resolveCell()
            // call an exact "ACNS" typed by hand would.
            $value = $this->applyCorrections($value, $corrections);

            // The EXTRACTION confidence answers "did I read this cell right"
            // — it is a property of the CELL, not of any one statement inside
            // it, so every resolution this cell produces shares it.
            $cellConfidence = $grid->confidence($rowIndex, $column->index);

            foreach ($this->resolver->resolveCell($value, $column->level) as $resolution) {
                $extractionConfidence[spl_object_id($resolution)] = $cellConfidence;

                match (true) {
                    // (B) a named measure, and the only one of the three that
                    // anything will write.
                    $resolution->isStorable() => $measures[] = $resolution,
                    // (C) understood, and with nowhere structured to go.
                    $resolution->isResource() => $resources[] = $resolution,
                    // (D) not understood. Exists in the preview so a person can
                    // decide, and vanishes if ignored.
                    default => $unresolved[] = $resolution,
                };
            }
        }

        $unresolved = $this->deduplicate($unresolved);

        return [
            'measures' => $this->deduplicate($measures),
            'resources' => $this->deduplicate($resources),
            'unresolved' => $unresolved,
            'extractionConfidence' => $extractionConfidence,
            'suggestions' => $this->suggestionsFor($unresolved, $extractionConfidence),
        ];
    }

    /**
     * Never rewrites anything silently: a correction is applied only where
     * the exact raw token the teacher accepted a suggestion for appears,
     * whole-word, case-insensitively — the same shape the OCR misreads this
     * exists for (ACN5, not a substring of some longer word that happens to
     * contain it).
     *
     * @param  array<string, string>  $corrections
     */
    private function applyCorrections(string $value, array $corrections): string
    {
        foreach ($corrections as $original => $accepted) {
            if ($original === '') {
                continue;
            }

            $value = preg_replace('/\b'.preg_quote($original, '/').'\b/ui', $accepted, $value) ?? $value;
        }

        return $value;
    }

    /**
     * §19: a suggestion is only ever attempted for a token that (a) failed to
     * resolve AND (b) came from a cell with a LOW extraction confidence — the
     * combination that actually means "this might be a misread", not merely
     * "this is a token nobody taught the dictionary". A token read exactly
     * (extraction confidence null, every source but OCR) never reaches this:
     * "the school's file literally says ACN5" is a different fact from "the
     * pixels might have been misread", and correcting the first would be
     * rewriting what the school actually wrote.
     *
     * @param  list<CodeResolution>  $unresolved
     * @param  array<int, ?float>  $extractionConfidence
     * @return array<int, AcronymSuggestion>
     */
    private function suggestionsFor(array $unresolved, array $extractionConfidence): array
    {
        $suggestions = [];

        foreach ($unresolved as $resolution) {
            $confidence = $extractionConfidence[spl_object_id($resolution)] ?? null;

            if ($confidence === null || $confidence >= self::LOW_EXTRACTION_CONFIDENCE) {
                continue;
            }

            $suggestion = $this->suggester->suggest($resolution->rawToken);

            if ($suggestion !== null) {
                $suggestions[spl_object_id($resolution)] = $suggestion;
            }
        }

        return $suggestions;
    }

    /**
     * The same measure listed in two columns is one measure.
     *
     * @param  list<CodeResolution>  $resolutions
     * @return list<CodeResolution>
     */
    private function deduplicate(array $resolutions): array
    {
        $seen = [];

        foreach ($resolutions as $resolution) {
            // Keyed on the code when there is one, and on the source text
            // otherwise. Keying on the code alone would give every resource the
            // same empty key — a row naming a CRI and a technician would keep
            // only the first, and the loss would look like a parsing failure.
            $key = $resolution->code !== null
                ? 'code:'.$resolution->code->value
                : 'raw:'.mb_strtolower($resolution->rawToken);

            $seen[$key] ??= $resolution;
        }

        return array_values($seen);
    }

    /**
     * The enrolment a row matched, re-resolved through the class — exactly
     * the same boundary `ApplyCharacterisationImport` enforces at write time.
     * A row matched by name/process-number logic alone, with no such
     * safeguard here, would let the preview happily show "já registado" text
     * that in fact belongs to a student outside this class.
     */
    private function enrollmentFor(SchoolClass $class, string $enrollmentUlid): ?Enrollment
    {
        return $class->enrollments()->where('ulid', $enrollmentUlid)->first();
    }

    /**
     * What confirming this row would do to each section it names, given what
     * is already recorded for the matched student — the same computation
     * `ApplyCharacterisationImport` performs at write time, run here read-only
     * so the preview can render "já registado" / "a acrescentar" honestly.
     *
     * @param  array<string, string>  $sections
     * @return array<string, SectionMergeResult>
     */
    private function sectionMergesFor(Enrollment $enrollment, array $sections): array
    {
        $characterisation = EnrollmentCharacterisation::query()
            ->where('enrollment_id', $enrollment->getKey())
            ->first();

        $current = [];

        foreach (array_keys($sections) as $key) {
            $current[$key] = $characterisation?->{$key};
        }

        $results = $this->merger->merge($current, $sections);

        return array_intersect_key($results, $sections);
    }

    /**
     * Which of these measures already have an active Intervention for this
     * student (§30) — so the preview can say "já registada — não será
     * duplicada" instead of implying every recognised measure is new.
     *
     * @param  list<CodeResolution>  $measures
     * @return list<string>
     */
    private function alreadyActiveMeasureCodes(Enrollment $enrollment, array $measures): array
    {
        $codes = array_values(array_unique(array_filter(
            array_map(fn (CodeResolution $r) => $r->code?->value, $measures),
        )));

        if ($codes === []) {
            return [];
        }

        /** @var list<string> */
        return Intervention::query()
            ->where('enrollment_id', $enrollment->getKey())
            ->whereIn('support_measure_code', $codes)
            ->whereIn('status', [InterventionStatus::New->value, InterventionStatus::InProgress->value])
            ->pluck('support_measure_code')
            ->map(fn ($code) => $code instanceof \BackedEnum ? (string) $code->value : (string) $code)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<ClassifiedColumn>  $columns
     */
    private function columnFor(array $columns, ColumnRole $role): ?ClassifiedColumn
    {
        foreach ($columns as $column) {
            if ($column->role === $role) {
                return $column;
            }
        }

        return null;
    }
}
