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
}
