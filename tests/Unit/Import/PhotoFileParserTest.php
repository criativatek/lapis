<?php

// tests/Unit/Import/PhotoFileParserTest.php

namespace Tests\Unit\Import;

use App\Services\Import\PhotoFileParser;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\DocxFixtureBuilder;
use Tests\TestCase;

class PhotoFileParserTest extends TestCase
{
    protected string $tempPath;

    protected function tearDown(): void
    {
        if (isset($this->tempPath) && file_exists($this->tempPath)) {
            unlink($this->tempPath);
        }
        parent::tearDown();
    }

    #[Test]
    public function it_extracts_each_name_and_photo_pair_in_document_order(): void
    {
        $jpeg = DocxFixtureBuilder::tinyJpeg();
        $this->tempPath = DocxFixtureBuilder::build([
            ['name' => 'Maria Teste', 'imageBytes' => $jpeg],
            ['name' => 'João Exemplo', 'imageBytes' => $jpeg],
        ]);

        $matches = (new PhotoFileParser)->parse($this->tempPath);

        $this->assertCount(2, $matches);
        $this->assertSame('Maria Teste', $matches[0]->name);
        $this->assertSame($jpeg, $matches[0]->imageBytes);
        $this->assertSame('jpg', $matches[0]->extension);
        $this->assertSame('João Exemplo', $matches[1]->name);
    }

    #[Test]
    public function it_strips_html_from_the_caption(): void
    {
        $jpeg = DocxFixtureBuilder::tinyJpeg();
        $this->tempPath = DocxFixtureBuilder::build([
            ['name' => 'Ana Simão', 'imageBytes' => $jpeg],
        ]);

        $matches = (new PhotoFileParser)->parse($this->tempPath);

        $this->assertSame('Ana Simão', $matches[0]->name);
    }

    #[Test]
    public function an_empty_document_yields_no_matches(): void
    {
        $this->tempPath = DocxFixtureBuilder::build([]);

        $matches = (new PhotoFileParser)->parse($this->tempPath);

        $this->assertSame([], $matches);
    }

    /**
     * Confirmed directly against the real production "Intuitivo" photo
     * export during the final review: its actual internal structure lays out
     * a whole grid ROW of images first, then that row's captions afterward
     * (e.g. 6 images, then 6 captions, repeating) — NOT the simple
     * alternating image/caption pattern the other tests in this file use. A
     * single "pending image" slot only survives the LAST image of every
     * group of 6, discarding the rest, and produced just 5 correct matches
     * out of 30 against the real file. This test reproduces that grouped
     * shape at a small scale (2 groups of 3) and must still pair every image
     * with its own caption, in order.
     */
    #[Test]
    public function it_pairs_every_image_with_its_own_caption_when_the_document_groups_all_images_before_all_captions(): void
    {
        $jpeg = DocxFixtureBuilder::tinyJpeg();
        $entries = [
            ['name' => 'Aluno Um', 'imageBytes' => $jpeg.'-1'],
            ['name' => 'Aluno Dois', 'imageBytes' => $jpeg.'-2'],
            ['name' => 'Aluno Três', 'imageBytes' => $jpeg.'-3'],
            ['name' => 'Aluno Quatro', 'imageBytes' => $jpeg.'-4'],
            ['name' => 'Aluno Cinco', 'imageBytes' => $jpeg.'-5'],
            ['name' => 'Aluno Seis', 'imageBytes' => $jpeg.'-6'],
        ];
        $this->tempPath = DocxFixtureBuilder::buildGrouped($entries, 3);

        $matches = (new PhotoFileParser)->parse($this->tempPath);

        $this->assertCount(6, $matches);

        foreach ($entries as $index => $entry) {
            $this->assertSame($entry['name'], $matches[$index]->name, "name at index {$index}");
            $this->assertSame($entry['imageBytes'], $matches[$index]->imageBytes, "image bytes at index {$index}");
        }
    }
}
