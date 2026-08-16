<?php

namespace Tests\Feature\Export;

use App\Services\Export\InovarTemplateReader;
use App\Support\Export\InovarTemplateException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InovarGridFixture;
use Tests\TestCase;

/**
 * Reading the grid INOVAR exports, and refusing everything else.
 *
 * A grid filled in the wrong columns is worse than one not filled at all: the
 * school would upload it and nobody would find out until the marks were wrong.
 * So everything is located by content and anything unrecognised throws.
 */
class InovarTemplateReaderTest extends TestCase
{
    private function reader(): InovarTemplateReader
    {
        return app(InovarTemplateReader::class);
    }

    private function grid(array $options = []): string
    {
        return (new InovarGridFixture)->build($options);
    }

    // ------------------------------------------- 1. o que reconhece

    #[Test]
    public function it_reads_the_sheet_the_students_and_the_domains(): void
    {
        $template = $this->reader()->read($this->grid());

        $this->assertSame('Português', $template->sheet);
        $this->assertSame(3, $template->headerRow);
        $this->assertSame(['D' => 'Oralidade', 'E' => 'Leitura', 'F' => 'Escrita'], $template->domainColumns);
        $this->assertCount(3, $template->students);

        $this->assertSame(4, $template->students[0]->row);
        $this->assertSame('Aurora Pimentel', $template->students[0]->name);
    }

    #[Test]
    public function a_process_number_is_a_string_whether_the_cell_holds_text_or_a_number(): void
    {
        $template = $this->reader()->read($this->grid());

        $numbers = array_map(fn ($student) => $student->processNumber, $template->students);

        // The leading zeros survive, and the numeric cell arrives as a string —
        // a real export carried both kinds and casting would lose a person's
        // identifier.
        $this->assertSame(['001234', '001235', '4471'], $numbers);

        foreach ($numbers as $number) {
            $this->assertIsString($number);
        }
    }

    #[Test]
    public function the_unnamed_column_inside_the_banner_is_not_taken_for_a_domain(): void
    {
        $template = $this->reader()->read($this->grid());

        // The banner spans D:I and the real grid names only D..H. Column I has
        // no confirmed meaning, so it is not a domain and will not be written.
        $this->assertArrayNotHasKey('I', $template->domainColumns);
    }

    #[Test]
    public function a_grid_with_other_domains_is_read_just_as_well(): void
    {
        $template = $this->reader()->read($this->grid([
            'sheet' => 'Matemática',
            'domains' => ['D' => 'Números', 'E' => 'Geometria', 'F' => 'Dados', 'G' => 'Álgebra'],
        ]));

        // Nothing here knows the domains of any subject.
        $this->assertSame('Matemática', $template->sheet);
        $this->assertCount(4, $template->domainColumns);
        $this->assertSame('Álgebra', $template->domainColumns['G']);
    }

    // ------------------------------------------------ 2. o que recusa

    #[Test]
    public function a_file_with_more_than_one_sheet_is_refused(): void
    {
        $this->expectException(InovarTemplateException::class);
        $this->expectExceptionMessageMatches('/mais do que uma folha/');

        $this->reader()->read($this->grid(['extra_sheet' => true]));
    }

    #[Test]
    public function a_grid_with_no_domain_names_is_refused_rather_than_guessed(): void
    {
        $this->expectException(InovarTemplateException::class);
        $this->expectExceptionMessageMatches('/nomes dos domínios/');

        $this->reader()->read($this->grid(['no_header' => true]));
    }

    #[Test]
    public function a_grid_with_a_single_named_domain_is_refused_as_ambiguous(): void
    {
        // One filled cell across the span is what the banner row looks like;
        // a header row names every domain it covers.
        $this->expectException(InovarTemplateException::class);

        $this->reader()->read($this->grid(['domains' => ['D' => 'Oralidade']]));
    }

    #[Test]
    public function a_grid_with_no_students_is_refused(): void
    {
        $this->expectException(InovarTemplateException::class);
        $this->expectExceptionMessageMatches('/nenhum aluno/');

        $this->reader()->read($this->grid(['students' => []]));
    }

    #[Test]
    public function something_that_is_not_a_spreadsheet_at_all_is_refused(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'nao_e_xls_').'.xls';
        file_put_contents($path, 'isto não é uma folha de cálculo');

        $this->expectException(InovarTemplateException::class);

        try {
            $this->reader()->read($path);
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function the_reader_never_calculates_a_formula(): void
    {
        $source = (string) file_get_contents(app_path('Services/Export/InovarTemplateReader.php'));

        // The file is untrusted input: what is stored is what is read.
        $this->assertStringNotContainsString('getCalculatedValue', $source);
        $this->assertStringContainsString('getValue()', $source);
    }
}
