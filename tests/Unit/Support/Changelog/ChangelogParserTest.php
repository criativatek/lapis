<?php

namespace Tests\Unit\Support\Changelog;

use App\Support\Changelog\ChangelogParser;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChangelogParserTest extends TestCase
{
    protected string $tempPath;

    protected function tearDown(): void
    {
        if (isset($this->tempPath) && file_exists($this->tempPath)) {
            unlink($this->tempPath);
        }
        parent::tearDown();
    }

    protected function writeFixture(string $contents): string
    {
        $this->tempPath = tempnam(sys_get_temp_dir(), 'changelog').'.md';
        file_put_contents($this->tempPath, $contents);

        return $this->tempPath;
    }

    #[Test]
    public function it_parses_versions_dates_categories_and_items_in_order(): void
    {
        $path = $this->writeFixture(<<<'MD'
        # Changelog — Lapispro

        Formato: Keep a Changelog.

        ## [0.2.0] — 2026-08-01

        ### Adicionado

        - **Primeira funcionalidade.** Descrição da funcionalidade.
        - **Segunda funcionalidade.** Outra descrição.

        ### Corrigido

        - **Um erro.** Explicação do erro.

        ## [0.1.0] — 2026-07-30

        ### Adicionado

        - **Lançamento inicial.** Primeira versão.
        MD);

        $entries = (new ChangelogParser)->parse($path);

        $this->assertCount(2, $entries);

        $this->assertSame('0.2.0', $entries[0]->version);
        $this->assertSame('2026-08-01', $entries[0]->date);
        $this->assertCount(2, $entries[0]->sections);
        $this->assertSame('Adicionado', $entries[0]->sections[0]['category']);
        $this->assertSame([
            '**Primeira funcionalidade.** Descrição da funcionalidade.',
            '**Segunda funcionalidade.** Outra descrição.',
        ], $entries[0]->sections[0]['items']);
        $this->assertSame('Corrigido', $entries[0]->sections[1]['category']);
        $this->assertSame(['**Um erro.** Explicação do erro.'], $entries[0]->sections[1]['items']);

        $this->assertSame('0.1.0', $entries[1]->version);
        $this->assertSame('2026-07-30', $entries[1]->date);
        $this->assertSame(['**Lançamento inicial.** Primeira versão.'], $entries[1]->sections[0]['items']);
    }

    #[Test]
    public function it_returns_an_empty_list_for_a_missing_file(): void
    {
        $entries = (new ChangelogParser)->parse(sys_get_temp_dir().'/does-not-exist-'.uniqid().'.md');

        $this->assertSame([], $entries);
    }
}
