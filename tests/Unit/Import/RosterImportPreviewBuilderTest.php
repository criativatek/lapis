<?php

// tests/Unit/Import/RosterImportPreviewBuilderTest.php

namespace Tests\Unit\Import;

use App\Domain\Import\PhotoMatch;
use App\Domain\Import\RosterRow;
use App\Services\Import\RosterImportPreviewBuilder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RosterImportPreviewBuilderTest extends TestCase
{
    #[Test]
    public function it_matches_a_photo_to_its_roster_row_by_name(): void
    {
        $rows = [new RosterRow('Maria Teste', 1, '2013-05-04', 'X', '1001', null)];
        $photos = [new PhotoMatch('Maria Teste', 'bytes', 'jpg')];

        $preview = (new RosterImportPreviewBuilder)->build($rows, $photos, fn () => null);

        $this->assertSame(0, $preview[0]['photo_index']);
        $this->assertSame('jpg', $preview[0]['photo_extension']);
    }

    #[Test]
    public function a_row_with_no_matching_photo_gets_a_null_photo_index(): void
    {
        $rows = [new RosterRow('Maria Teste', 1, null, 'X', null, null)];

        $preview = (new RosterImportPreviewBuilder)->build($rows, [], fn () => null);

        $this->assertNull($preview[0]['photo_index']);
        $this->assertNull($preview[0]['photo_extension']);
    }

    #[Test]
    public function name_matching_ignores_case_and_extra_spacing(): void
    {
        $rows = [new RosterRow('  Maria   Teste ', 1, null, 'X', null, null)];
        $photos = [new PhotoMatch('maria teste', 'bytes', 'jpg')];

        $preview = (new RosterImportPreviewBuilder)->build($rows, $photos, fn () => null);

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

        $preview = (new RosterImportPreviewBuilder)->build($rows, $photos, fn () => null);

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

        $preview = (new RosterImportPreviewBuilder)->build($rows, $photos, fn () => null);

        $this->assertSame(0, $preview[0]['photo_index']);
        $this->assertNull($preview[1]['photo_index']);
    }

    #[Test]
    public function duplicate_names_within_the_file_are_flagged_and_excluded_by_default(): void
    {
        $rows = [
            new RosterRow('Maria Teste', 1, null, 'X', null, null),
            new RosterRow('Maria Teste', 2, null, 'X', null, null),
        ];

        $preview = (new RosterImportPreviewBuilder)->build($rows, [], fn () => null);

        $this->assertTrue($preview[0]['duplicate_in_file']);
        $this->assertTrue($preview[1]['duplicate_in_file']);
        $this->assertFalse($preview[0]['include']);
        $this->assertFalse($preview[1]['include']);
    }

    #[Test]
    public function a_name_already_enrolled_in_the_class_is_an_update_and_not_a_second_enrolment(): void
    {
        $rows = [new RosterRow('Maria Teste', 1, null, 'X', null, null)];

        $preview = (new RosterImportPreviewBuilder)->build($rows, [], fn (string $name) => $name === 'maria teste' ? 77 : null);

        $this->assertTrue($preview[0]['already_enrolled']);
        // Re-importing a class fills in what the record is missing rather than
        // skipping past the student who is already on it (§8).
        $this->assertSame(RosterImportPreviewBuilder::ACTION_UPDATE, $preview[0]['action']);
        $this->assertSame(77, $preview[0]['enrollment_id']);
        $this->assertTrue($preview[0]['include']);
    }

    #[Test]
    public function the_already_enrolled_callback_receives_an_already_normalized_name(): void
    {
        $rows = [new RosterRow('  Maria   Teste ', 1, null, 'X', null, null)];

        $preview = (new RosterImportPreviewBuilder)->build($rows, [], fn (string $name) => $name === 'maria teste' ? 5 : null);

        $this->assertTrue($preview[0]['already_enrolled']);
        $this->assertSame(5, $preview[0]['enrollment_id']);
    }

    #[Test]
    public function a_student_the_class_does_not_have_yet_is_a_new_enrolment(): void
    {
        $rows = [new RosterRow('Maria Teste', 1, null, 'X', null, null)];

        $preview = (new RosterImportPreviewBuilder)->build($rows, [], fn () => null);

        $this->assertFalse($preview[0]['already_enrolled']);
        $this->assertNull($preview[0]['enrollment_id']);
        $this->assertSame(RosterImportPreviewBuilder::ACTION_ENROL, $preview[0]['action']);
        $this->assertTrue($preview[0]['include']);
    }

    #[Test]
    public function an_unrecognized_situation_code_is_flagged_but_still_included(): void
    {
        $rows = [new RosterRow('Maria Teste', 1, null, 'MT', null, null)];

        $preview = (new RosterImportPreviewBuilder)->build($rows, [], fn () => null);

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

        $preview = (new RosterImportPreviewBuilder)->build($rows, [], fn () => null);

        $this->assertTrue($preview[0]['situation_recognized']);
        $this->assertTrue($preview[1]['situation_recognized']);
    }
}
