<?php

namespace Tests\Unit\Characterisation;

use App\Models\SupportMeasureLevel;
use App\Services\Characterisation\Import\ClassifiedColumn;
use App\Services\Characterisation\Import\ClassifyColumns;
use App\Services\Characterisation\Import\ColumnRole;
use App\Services\Characterisation\Import\TableGrid;
use Tests\Fixtures\Characterisation\CharacterisationFixture;
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

    /**
     * A column that says «apoio» without «medida» is a RESOURCES column, and
     * has no characterisation section at all.
     *
     * It used to be Needs, which meant a Centro de Recursos para a Inclusão was
     * written verbatim into a child's «Necessidades». «Um apoio mobilizado» and
     * «uma necessidade do aluno» are different facts, and the column that
     * happened to exist is not a reason to assert the second from the first.
     */
    public function test_a_support_column_is_a_resources_column_with_no_section(): void
    {
        foreach (['Apoios', 'Recursos específicos de apoio', 'Apoios e recursos mobilizados'] as $header) {
            $columns = $this->classify([$header]);

            $this->assertSame(ColumnRole::Resources, $columns[0]->role, $header);
            $this->assertNull($columns[0]->role->section(), $header);
        }
    }

    /** «Necessidades» and «Dificuldades» remain the needs column. */
    public function test_the_needs_column_still_names_needs(): void
    {
        $this->assertSame([ColumnRole::Needs], $this->roles(['Necessidades']));
        $this->assertSame([ColumnRole::Needs], $this->roles(['Dificuldades reveladas']));
    }

    public function test_a_measures_column_has_no_characterisation_section(): void
    {
        $columns = $this->classify(['Medidas']);

        $this->assertNull($columns[0]->role->section());
    }

    /**
     * The real table that motivated this whole feature does not spell out
     * "Medidas universais" — it writes the bare abbreviation "MU", exactly as
     * the diploma's own acronym reads. A column whose header names nothing but
     * a confirmed level acronym must still resolve, or the letters underneath
     * it never reach the resolver at all.
     */
    public function test_bare_level_acronyms_are_recognised_as_measures_columns(): void
    {
        $columns = $this->classify(['Aluno', 'MU', 'MS', 'MA']);

        $this->assertSame(ColumnRole::StudentName, $columns[0]->role);

        $this->assertSame(ColumnRole::Measures, $columns[1]->role);
        $this->assertSame(SupportMeasureLevel::Universal, $columns[1]->level);

        $this->assertSame(ColumnRole::Measures, $columns[2]->role);
        $this->assertSame(SupportMeasureLevel::Selective, $columns[2]->level);

        $this->assertSame(ColumnRole::Measures, $columns[3]->role);
        $this->assertSame(SupportMeasureLevel::Additional, $columns[3]->level);
    }

    /** Case and stray whitespace must not stop a bare acronym from matching. */
    public function test_bare_level_acronyms_are_recognised_regardless_of_case_or_whitespace(): void
    {
        $columns = $this->classify(['mu', ' MU ']);

        $this->assertSame(ColumnRole::Measures, $columns[0]->role);
        $this->assertSame(SupportMeasureLevel::Universal, $columns[0]->level);

        $this->assertSame(ColumnRole::Measures, $columns[1]->role);
        $this->assertSame(SupportMeasureLevel::Universal, $columns[1]->level);
    }

    /**
     * A header must BE the acronym, not merely contain it. "Outras
     * medidas/recursos" already classifies as Measures — correctly — through
     * the existing "medida" text pattern, so it proves nothing about the
     * acronym lookup specifically; "Sumula" is a header that contains the
     * letters "MU" but is not the acronym "MU", and must not match either.
     */
    public function test_a_header_that_merely_contains_an_acronym_substring_is_not_promoted(): void
    {
        $this->assertSame([ColumnRole::Unknown], $this->roles(['Sumula']));
    }

    /**
     * "Outras medidas/recursos" — the fifteenth column of the real table this
     * feature reads — must stay classified by the existing "medida" pattern
     * (Measures, no level) and must not be knocked over into anything else by
     * the acronym lookup added alongside it.
     */
    public function test_outras_medidas_recursos_is_unaffected_by_the_acronym_lookup(): void
    {
        $columns = $this->classify(['Outras medidas/recursos']);

        $this->assertSame(ColumnRole::Measures, $columns[0]->role);
        $this->assertNull($columns[0]->level);
    }

    /**
     * RTP and PEI are instruments, not measures — the dictionary lists them as
     * known-but-unconfirmed on purpose, and a column named after one of them
     * must stay Unknown rather than becoming a measures column.
     */
    public function test_rtp_pei_is_not_a_measures_column(): void
    {
        $this->assertSame([ColumnRole::Unknown], $this->roles(['RTP/PEI']));
    }

    /**
     * The full-width realistic header row this feature was built to read:
     * Aluno | RTP/PEI | MU | MS | MA | Coadjuvação | Apoio P | Apoio M |
     * Apoio Ing. | Apoio Outros | Tutoria | ATE | Apoio Ed. Especial |
     * Psicologia | Outras medidas/recursos / Observações.
     */
    public function test_the_real_headers_flat_fixture_classifies_correctly(): void
    {
        $columns = $this->classify(CharacterisationFixture::headersFlat());
        $byHeader = [];

        foreach ($columns as $column) {
            $byHeader[$column->header] = $column;
        }

        $this->assertSame(ColumnRole::StudentName, $byHeader['Aluno']->role);
        $this->assertSame(ColumnRole::Unknown, $byHeader['RTP/PEI']->role, 'RTP/PEI is an instrument, not a measure.');

        $this->assertSame(ColumnRole::Measures, $byHeader['MU']->role);
        $this->assertSame(SupportMeasureLevel::Universal, $byHeader['MU']->level);
        $this->assertSame(ColumnRole::Measures, $byHeader['MS']->role);
        $this->assertSame(SupportMeasureLevel::Selective, $byHeader['MS']->level);
        $this->assertSame(ColumnRole::Measures, $byHeader['MA']->role);
        $this->assertSame(SupportMeasureLevel::Additional, $byHeader['MA']->level);

        // The abbreviated "Apoio …" columns already say "apoio", so they were
        // and remain Resources.
        $this->assertSame(ColumnRole::Resources, $byHeader['Apoio P']->role);
        $this->assertSame(ColumnRole::Resources, $byHeader['Apoio M']->role);
        $this->assertSame(ColumnRole::Resources, $byHeader['Apoio Ing.']->role);
        $this->assertSame(ColumnRole::Resources, $byHeader['Apoio Outros']->role);
        $this->assertSame(ColumnRole::Resources, $byHeader['Apoio Ed. Especial']->role);

        // Coadjuvação, Tutoria, ATE and Psicologia are not confirmed measures
        // or resources in AcronymDictionary — ATE is known but deliberately
        // unconfirmed, and the other three are not acronyms at all — so they
        // stay Unknown. That is the designed behaviour, not a bug: inventing
        // a destination for an unconfirmed token is exactly what this class
        // must not do.
        $this->assertSame(ColumnRole::Unknown, $byHeader['Coadjuvação']->role);
        $this->assertSame(ColumnRole::Unknown, $byHeader['Tutoria']->role);
        $this->assertSame(ColumnRole::Unknown, $byHeader['ATE']->role);
        $this->assertSame(ColumnRole::Unknown, $byHeader['Psicologia']->role);
    }
}
