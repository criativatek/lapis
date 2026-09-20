<?php

namespace App\Services\Characterisation\Import;

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
 */
class BuildCharacterisationPreview
{
    public function __construct(
        private readonly ClassifyColumns $classifier,
        private readonly MatchCharacterisationRows $matcher,
        private readonly LegalCodeResolver $resolver,
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

            $previewRow = new PreviewRow(
                rowNumber: $index + 1,
                rawName: trim($name),
                rawProcessNumber: $processNumber,
                match: $match($name, $processNumber),
                sections: $this->sectionsFor($grid, $row, $columns),
                measures: $resolved['measures'],
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
