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

        $preview = (new RosterImportPreviewBuilder)->build($rows, $photos, fn () => false);

        $this->assertSame(0, $preview[0]['photo_index']);
        $this->assertSame('jpg', $preview[0]['photo_extension']);
    }

    #[Test]
    public function a_row_with_no_matching_photo_gets_a_null_photo_index(): void
    {
        $rows = [new RosterRow('Maria Teste', 1, null, 'X', null, null)];

        $preview = (new RosterImportPreviewBuilder)->build($rows, [], fn () => false);

        $this->assertNull($preview[0]['photo_index']);
        $this->assertNull($preview[0]['photo_extension']);
    }

    #[Test]
    public function name_matching_ignores_case_and_extra_spacing(): void
    {
        $rows = [new RosterRow('  Maria   Teste ', 1, null, 'X', null, null)];
        $photos = [new PhotoMatch('maria teste', 'bytes', 'jpg')];

        $preview = (new RosterImportPreviewBuilder)->build($rows, $photos, fn () => false);

        $this->assertSame(0, $preview[0]['photo_index']);
    }

    #[Test]
    public function duplicate_names_within_the_file_are_flagged_and_excluded_by_default(): void
    {
        $rows = [
            new RosterRow('Maria Teste', 1, null, 'X', null, null),
            new RosterRow('Maria Teste', 2, null, 'X', null, null),
        ];

        $preview = (new RosterImportPreviewBuilder)->build($rows, [], fn () => false);

        $this->assertTrue($preview[0]['duplicate_in_file']);
        $this->assertTrue($preview[1]['duplicate_in_file']);
        $this->assertFalse($preview[0]['include']);
        $this->assertFalse($preview[1]['include']);
    }

    #[Test]
    public function a_name_already_enrolled_in_the_class_is_flagged_and_excluded_by_default(): void
    {
        $rows = [new RosterRow('Maria Teste', 1, null, 'X', null, null)];

        $preview = (new RosterImportPreviewBuilder)->build($rows, [], fn (string $name) => $name === 'Maria Teste');

        $this->assertTrue($preview[0]['already_enrolled']);
        $this->assertFalse($preview[0]['include']);
    }

    #[Test]
    public function an_unrecognized_situation_code_is_flagged_but_still_included(): void
    {
        $rows = [new RosterRow('Maria Teste', 1, null, 'MT', null, null)];

        $preview = (new RosterImportPreviewBuilder)->build($rows, [], fn () => false);

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

        $preview = (new RosterImportPreviewBuilder)->build($rows, [], fn () => false);

        $this->assertTrue($preview[0]['situation_recognized']);
        $this->assertTrue($preview[1]['situation_recognized']);
    }
}
