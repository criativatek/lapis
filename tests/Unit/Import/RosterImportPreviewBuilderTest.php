<?php

// tests/Unit/Import/RosterImportPreviewBuilderTest.php

namespace Tests\Unit\Import;

use App\Domain\Import\PhotoMatch;
use App\Domain\Import\RosterMatch;
use App\Domain\Import\RosterRow;
use App\Services\Import\RosterImportPreviewBuilder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RosterImportPreviewBuilderTest extends TestCase
{
    /**
     * Nobody on the roll answers to any row — the first import of a class.
     *
     * @return \Closure(RosterRow): RosterMatch
     */
    protected function nobody(): \Closure
    {
        return fn (RosterRow $row): RosterMatch => RosterMatch::none();
    }

    #[Test]
    public function it_matches_a_photo_to_its_roster_row_by_name(): void
    {
        $rows = [new RosterRow('Maria Teste', 1, '2013-05-04', 'X', '1001', null)];
        $photos = [new PhotoMatch('Maria Teste', 'bytes', 'jpg')];

        $preview = (new RosterImportPreviewBuilder)->build($rows, $photos, $this->nobody());

        $this->assertSame(0, $preview[0]['photo_index']);
        $this->assertSame('jpg', $preview[0]['photo_extension']);
    }

    #[Test]
    public function a_row_with_no_matching_photo_gets_a_null_photo_index(): void
    {
        $rows = [new RosterRow('Maria Teste', 1, null, 'X', null, null)];

        $preview = (new RosterImportPreviewBuilder)->build($rows, [], $this->nobody());

        $this->assertNull($preview[0]['photo_index']);
        $this->assertNull($preview[0]['photo_extension']);
    }

    #[Test]
    public function name_matching_ignores_case_and_extra_spacing(): void
    {
        $rows = [new RosterRow('  Maria   Teste ', 1, null, 'X', null, null)];
        $photos = [new PhotoMatch('maria teste', 'bytes', 'jpg')];

        $preview = (new RosterImportPreviewBuilder)->build($rows, $photos, $this->nobody());

        $this->assertSame(0, $preview[0]['photo_index']);
    }

    #[Test]
    public function a_photo_caption_with_only_first_and_last_name_matches_a_roster_row_with_middle_names(): void
    {
        // Verified against a real Intuitivo export: the Word photo sheet's
        // captions carry only first+last name, while the Excel roster
        // carries the full name with middle names. A plain exact-string
        // match (the previous behavior) never matched a single real photo
        // against a real roster.
        $rows = [new RosterRow('Genivalda Goureth E. Freitas', 1, null, 'X', null, null)];
        $photos = [new PhotoMatch('Genivalda Freitas', 'bytes', 'jpg')];

        $preview = (new RosterImportPreviewBuilder)->build($rows, $photos, $this->nobody());

        $this->assertSame(0, $preview[0]['photo_index']);
    }

    #[Test]
    public function an_abbreviated_caption_does_not_cross_match_a_similarly_named_row(): void
    {
        $rows = [
            new RosterRow('Ana Laura S. Simão', 1, null, 'X', null, null),
            new RosterRow('Valentina Silva Simão', 2, null, 'X', null, null),
        ];
        $photos = [new PhotoMatch('Ana Simão', 'bytes', 'jpg')];

        $preview = (new RosterImportPreviewBuilder)->build($rows, $photos, $this->nobody());

        $this->assertSame(0, $preview[0]['photo_index']);
        $this->assertNull($preview[1]['photo_index']);
    }

    /**
     * Uma fotografia sem legenda nunca se associa sozinha a ninguém.
     *
     * O PhotoFileParser passou a devolver também as imagens de um ficheiro
     * exportado sem «colocar o nome ao lado da foto», para que o professor as
     * possa atribuir à mão na pré-visualização. Deixá-las auto-associar seria
     * inventar uma correspondência a partir da posição — exatamente o que este
     * fluxo existe para tornar impossível (§5).
     */
    #[Test]
    public function a_photo_with_no_caption_never_matches_a_row_on_its_own(): void
    {
        $rows = [new RosterRow('Maria Teste', 1, null, 'X', null, null)];
        $photos = [new PhotoMatch('', 'bytes', 'jpg')];

        $preview = (new RosterImportPreviewBuilder)->build($rows, $photos, $this->nobody());

        $this->assertNull($preview[0]['photo_index']);
        $this->assertNull($preview[0]['photo_extension']);
    }

    #[Test]
    public function duplicate_names_within_the_file_are_flagged_and_excluded_by_default(): void
    {
        $rows = [
            new RosterRow('Maria Teste', 1, null, 'X', null, null),
            new RosterRow('Maria Teste', 2, null, 'X', null, null),
        ];

        $preview = (new RosterImportPreviewBuilder)->build($rows, [], $this->nobody());

        $this->assertTrue($preview[0]['duplicate_in_file']);
        $this->assertTrue($preview[1]['duplicate_in_file']);
        $this->assertFalse($preview[0]['include']);
        $this->assertFalse($preview[1]['include']);
    }

    #[Test]
    public function a_name_already_enrolled_in_the_class_is_an_update_and_not_a_second_enrolment(): void
    {
        $rows = [new RosterRow('Maria Teste', 1, null, 'X', null, null)];

        $preview = (new RosterImportPreviewBuilder)->build($rows, [], fn (RosterRow $row): RosterMatch => new RosterMatch(
            enrollmentId: 77,
            matchedBy: RosterMatch::BY_NAME,
            currentName: 'Maria Teste',
        ));

        $this->assertTrue($preview[0]['already_enrolled']);
        // Re-importing a class fills in what the record is missing rather than
        // skipping past the student who is already on it (§8).
        $this->assertSame(RosterImportPreviewBuilder::ACTION_UPDATE, $preview[0]['action']);
        $this->assertSame(77, $preview[0]['enrollment_id']);
        $this->assertSame(RosterMatch::BY_NAME, $preview[0]['matched_by']);
        $this->assertTrue($preview[0]['include']);
    }

    /**
     * O matcher recebe a LINHA, e não já um nome normalizado.
     *
     * A normalização mudou de sítio de propósito: quem decide a que aluno uma
     * linha pertence precisa do n.º de processo tanto quanto do nome, e um
     * nome normalizado sozinho não o transporta. Quem normaliza é agora
     * MatchRosterToEnrollments, dos dois lados da comparação (§5).
     */
    #[Test]
    public function the_matcher_receives_the_whole_row_and_not_merely_a_name(): void
    {
        $rows = [new RosterRow('  Maria   Teste ', 1, null, 'X', '1001', null)];
        $seen = null;

        $preview = (new RosterImportPreviewBuilder)->build($rows, [], function (RosterRow $row) use (&$seen): RosterMatch {
            $seen = $row;

            return new RosterMatch(enrollmentId: 5, matchedBy: RosterMatch::BY_PROCESS_NUMBER);
        });

        $this->assertInstanceOf(RosterRow::class, $seen);
        $this->assertSame('1001', $seen->processNumber);
        $this->assertSame('  Maria   Teste ', $seen->name);
        $this->assertTrue($preview[0]['already_enrolled']);
        $this->assertSame(5, $preview[0]['enrollment_id']);
        $this->assertSame(RosterMatch::BY_PROCESS_NUMBER, $preview[0]['matched_by']);
    }

    /**
     * Duas inscrições respondem à mesma evidência: ninguém é escolhido aqui.
     *
     * A linha não entra por defeito e os dois candidatos viajam para a
     * pré-visualização, onde o professor aponta o certo. Escolher um seria a
     * aplicação a decidir qual das duas Marias é esta (§3).
     */
    #[Test]
    public function an_ambiguous_row_is_neither_resolved_nor_included(): void
    {
        $rows = [new RosterRow('Maria Silva', 1, null, 'X', null, null)];

        $preview = (new RosterImportPreviewBuilder)->build($rows, [], fn (RosterRow $row): RosterMatch => new RosterMatch(
            matchedBy: RosterMatch::BY_NAME,
            ambiguous: true,
            candidates: [
                ['enrollment_id' => 11, 'name' => 'Maria Silva', 'class_number' => 1],
                ['enrollment_id' => 12, 'name' => 'Maria Silva', 'class_number' => 2],
            ],
        ));

        $this->assertSame(RosterImportPreviewBuilder::ACTION_AMBIGUOUS, $preview[0]['action']);
        $this->assertTrue($preview[0]['ambiguous']);
        $this->assertFalse($preview[0]['include'], 'Uma ambiguidade nunca se aplica sozinha.');
        $this->assertNull($preview[0]['enrollment_id']);
        $this->assertCount(2, $preview[0]['candidates']);
    }

    /**
     * «Nome atual → Nome novo»: uma grafia corrigida atualiza o aluno a quem
     * já pertence, e nunca acrescenta um segundo (§5).
     */
    #[Test]
    public function a_corrected_spelling_is_reported_as_a_name_change_on_the_same_enrolment(): void
    {
        $rows = [new RosterRow('Maria Silva Andrade', 1, null, 'X', '1001', null)];

        $preview = (new RosterImportPreviewBuilder)->build($rows, [], fn (RosterRow $row): RosterMatch => new RosterMatch(
            enrollmentId: 42,
            matchedBy: RosterMatch::BY_PROCESS_NUMBER,
            currentName: 'Maria Silva',
            hasPhoto: true,
        ));

        $this->assertTrue($preview[0]['name_changes']);
        $this->assertSame('Maria Silva', $preview[0]['current_name']);
        $this->assertSame('Maria Silva Andrade', $preview[0]['name']);
        $this->assertTrue($preview[0]['has_photo_today'], 'Substituir uma foto diz-se antes de acontecer.');
        $this->assertSame(RosterImportPreviewBuilder::ACTION_UPDATE, $preview[0]['action']);
    }

    #[Test]
    public function a_name_that_did_not_change_is_not_reported_as_a_correction(): void
    {
        $rows = [new RosterRow('  Maria   Silva ', 1, null, 'X', null, null)];

        $preview = (new RosterImportPreviewBuilder)->build($rows, [], fn (RosterRow $row): RosterMatch => new RosterMatch(
            enrollmentId: 42,
            matchedBy: RosterMatch::BY_NAME,
            currentName: 'Maria Silva',
        ));

        $this->assertFalse($preview[0]['name_changes'], 'Só o espaçamento mudou — isso não é uma correção.');
    }

    #[Test]
    public function a_student_the_class_does_not_have_yet_is_a_new_enrolment(): void
    {
        $rows = [new RosterRow('Maria Teste', 1, null, 'X', null, null)];

        $preview = (new RosterImportPreviewBuilder)->build($rows, [], $this->nobody());

        $this->assertFalse($preview[0]['already_enrolled']);
        $this->assertNull($preview[0]['enrollment_id']);
        $this->assertNull($preview[0]['matched_by']);
        $this->assertSame(RosterImportPreviewBuilder::ACTION_ENROL, $preview[0]['action']);
        $this->assertTrue($preview[0]['include']);
    }

    #[Test]
    public function an_unrecognized_situation_code_is_flagged_but_still_included(): void
    {
        $rows = [new RosterRow('Maria Teste', 1, null, 'ZZ', null, null)];

        $preview = (new RosterImportPreviewBuilder)->build($rows, [], $this->nobody());

        $this->assertFalse($preview[0]['situation_recognized']);
        $this->assertTrue($preview[0]['include']);
    }

    #[Test]
    public function a_recognized_situation_code_is_flagged_as_such(): void
    {
        $rows = [
            new RosterRow('Maria Teste', 1, null, 'X', null, null),
            new RosterRow('João Exemplo', 2, null, 'TR', null, null),
        ];

        $preview = (new RosterImportPreviewBuilder)->build($rows, [], $this->nobody());

        $this->assertTrue($preview[0]['situation_recognized']);
        $this->assertTrue($preview[1]['situation_recognized']);
    }

    /**
     * Duas linhas, um só aluno — e não é um nome repetido.
     *
     * O caso corrente numa reimportação: o mesmo n.º de processo em duas
     * linhas com o nome escrito de maneira diferente. Nenhuma das duas entra,
     * e a razão diz-se com as palavras certas: mandar procurar um «nome
     * duplicado» que não existe seria pior do que não avisar.
     */
    #[Test]
    public function two_rows_pointing_at_the_same_enrolment_are_a_duplicate_of_their_own_kind(): void
    {
        $rows = [
            new RosterRow('Maria Silva', 1, null, 'X', '1001', null),
            new RosterRow('Maria Silva Andrade', 2, null, 'X', '1001', null),
        ];

        $preview = (new RosterImportPreviewBuilder)->build($rows, [], fn (RosterRow $row): RosterMatch => new RosterMatch(
            enrollmentId: 42,
            matchedBy: RosterMatch::BY_PROCESS_NUMBER,
            currentName: 'Maria Silva',
        ));

        foreach ($preview as $row) {
            $this->assertTrue($row['duplicate_target']);
            $this->assertFalse($row['duplicate_in_file'], 'Os nomes são diferentes — não é isso que se repete.');
            $this->assertSame(RosterImportPreviewBuilder::ACTION_SKIP, $row['action']);
            $this->assertFalse($row['include']);
        }
    }

    #[Test]
    public function two_rows_reaching_different_students_are_not_duplicates_of_anything(): void
    {
        $rows = [
            new RosterRow('Maria Silva', 1, null, 'X', '1001', null),
            new RosterRow('João Costa', 2, null, 'X', '1002', null),
        ];

        $preview = (new RosterImportPreviewBuilder)->build($rows, [], fn (RosterRow $row): RosterMatch => new RosterMatch(
            enrollmentId: $row->processNumber === '1001' ? 42 : 43,
            matchedBy: RosterMatch::BY_PROCESS_NUMBER,
        ));

        foreach ($preview as $row) {
            $this->assertFalse($row['duplicate_target']);
            $this->assertSame(RosterImportPreviewBuilder::ACTION_UPDATE, $row['action']);
        }
    }
}
