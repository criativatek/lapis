<?php

namespace Tests\Unit\Characterisation;

use App\Models\SupportMeasureLevel;
use App\Services\Characterisation\Import\ClassifiedColumn;
use App\Services\Characterisation\Import\ClassifyColumns;
use App\Services\Characterisation\Import\ColumnRole;
use App\Services\Characterisation\Import\TableGrid;
use Tests\TestCase;

/**
 * Columns are read by their headers, never by their position — a sheet with one
 * extra column inserted must not start writing every student's text into the
 * wrong field.
 */
class ClassifyColumnsTest extends TestCase
{
    /**
     * @param  list<string>  $headers
     * @return list<ClassifiedColumn>
     */
    private function classify(array $headers): array
    {
        return (new ClassifyColumns)->classify(new TableGrid($headers, []));
    }

    private function roles(array $headers): array
    {
        return array_map(fn (ClassifiedColumn $column) => $column->role, $this->classify($headers));
    }

    public function test_it_recognises_the_common_headers(): void
    {
        $this->assertSame(
            [ColumnRole::ClassNumber, ColumnRole::StudentName, ColumnRole::SchoolNumber, ColumnRole::Measures, ColumnRole::Characterisation],
            $this->roles(['N.º', 'Nome', 'N.º de processo', 'Medidas', 'Observações']),
        );
    }

    public function test_it_ignores_accents_and_case(): void
    {
        $this->assertSame([ColumnRole::Participation], $this->roles(['PARTICIPAÇÃO']));
        $this->assertSame([ColumnRole::Participation], $this->roles(['participacao']));
    }

    /**
     * «N.º de processo» must not be read as «N.º». The more specific pattern is
     * tested first, and this is the test that keeps it that way.
     */
    public function test_the_process_number_is_not_mistaken_for_the_class_number(): void
    {
        $this->assertSame(
            [ColumnRole::SchoolNumber, ColumnRole::ClassNumber],
            $this->roles(['N.º de processo', 'N.º']),
        );
    }

    public function test_an_unknown_header_is_unknown_and_not_guessed_into_a_section(): void
    {
        $roles = $this->roles(['Nome', 'Coluna estranha da escola']);

        $this->assertSame(ColumnRole::Unknown, $roles[1]);
    }

    public function test_an_empty_header_is_unknown(): void
    {
        $this->assertSame([ColumnRole::Unknown], $this->roles(['   ']));
    }

    /**
     * A measures column that names its level is what gives a bare «b)»
     * underneath it any meaning at all.
     */
    public function test_a_measures_column_carries_the_level_its_header_names(): void
    {
        $columns = $this->classify(['Medidas universais', 'Medidas seletivas', 'Medidas adicionais', 'Medidas']);

        $this->assertSame(SupportMeasureLevel::Universal, $columns[0]->level);
        $this->assertSame(SupportMeasureLevel::Selective, $columns[1]->level);
        $this->assertSame(SupportMeasureLevel::Additional, $columns[2]->level);
        $this->assertNull($columns[3]->level, 'A column that does not name a level must not be given one.');
    }

    /**
     * Two columns claiming to be the name is a sheet this parser cannot read
     * confidently. Demoting the second is how the teacher gets told, instead of
     * one silently winning.
     */
    public function test_a_duplicate_identifying_column_is_demoted_rather_than_overriding(): void
    {
        $roles = $this->roles(['Nome', 'Nome do aluno']);

        $this->assertSame(ColumnRole::StudentName, $roles[0]);
        $this->assertSame(ColumnRole::Unknown, $roles[1]);
    }

    public function test_duplicate_section_columns_are_both_kept(): void
    {
        $roles = $this->roles(['Observações', 'Notas']);

        $this->assertSame([ColumnRole::Characterisation, ColumnRole::Characterisation], $roles);
    }

    public function test_the_pedagogical_sections_map_to_their_own_destinations(): void
    {
        $columns = $this->classify(['Potencialidades', 'Interesses', 'Necessidades', 'Barreiras']);

        $this->assertSame('strengths', $columns[0]->role->section()->value);
        $this->assertSame('interests', $columns[1]->role->section()->value);
        $this->assertSame('needs', $columns[2]->role->section()->value);
        $this->assertSame('barriers', $columns[3]->role->section()->value);
    }

    /**
     * The column schools actually write is the statute's own phrase, and it
     * contains "apoio". Classified as Needs, it never reached the resolver:
     * «MU a) b); RTP» was copied verbatim into a child's «Necessidades»
     * instead of being typed into the measures destination or held back for a
     * human. The one column this feature exists to read was read wrongly.
     */
    public function test_the_statutory_measures_column_is_not_swallowed_by_the_needs_pattern(): void
    {
        $this->assertSame(
            [ColumnRole::Measures],
            $this->roles(['Medidas de apoio à aprendizagem e à inclusão']),
        );
    }

    /** A column that says «apoio» without «medida» is still a needs column. */
    public function test_a_support_column_that_names_no_measure_stays_a_needs_column(): void
    {
        $this->assertSame([ColumnRole::Needs], $this->roles(['Apoios']));
        $this->assertSame([ColumnRole::Needs], $this->roles(['Recursos específicos de apoio']));
    }

    public function test_a_measures_column_has_no_characterisation_section(): void
    {
        $columns = $this->classify(['Medidas']);

        $this->assertNull($columns[0]->role->section());
    }
}
