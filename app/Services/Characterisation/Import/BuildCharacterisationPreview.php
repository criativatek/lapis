<?php

namespace App\Services\Characterisation\Import;

use App\Models\Enrollment;
use App\Models\EnrollmentCharacterisation;
use App\Models\Intervention;
use App\Models\InterventionStatus;
use App\Models\SchoolClass;
use App\Support\Characterisation\CodeResolution;
use App\Support\Characterisation\LegalCodeResolver;

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
    public function __construct(
        private readonly ClassifyColumns $classifier,
        private readonly MatchCharacterisationRows $matcher,
        private readonly LegalCodeResolver $resolver,
        private readonly MergeCharacterisationSections $merger,
    ) {}

    public function build(SchoolClass $class, TableGrid $grid): CharacterisationPreview
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

            $resolved = $this->resolutionsFor($grid, $row, $columns);
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
     * @return array{measures: list<CodeResolution>, resources: list<CodeResolution>, unresolved: list<CodeResolution>}
     */
    private function resolutionsFor(TableGrid $grid, array $row, array $columns): array
    {
        $measures = [];
        $resources = [];
        $unresolved = [];

        foreach ($columns as $column) {
            if ($column->role !== ColumnRole::Measures && $column->role !== ColumnRole::Resources) {
                continue;
            }

            $value = $grid->cell($row, $column->index);

            if ($value === '') {
                continue;
            }

            foreach ($this->resolver->resolveCell($value, $column->level) as $resolution) {
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

        return [
            'measures' => $this->deduplicate($measures),
            'resources' => $this->deduplicate($resources),
            'unresolved' => $this->deduplicate($unresolved),
        ];
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
