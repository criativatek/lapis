<?php

namespace Tests\Unit\Reporting;

use App\Services\Reporting\Export\DocumentHeading;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * One hierarchy on a document (§47).
 *
 * THE BUG THIS EXISTS FOR: every exported report opened with
 *
 *     Relatório de turma · 7.º A · Ano letivo
 *     Relatório de turma · 7.º A · Português · Ano letivo até ao momento
 *
 * — the stored title, written for a listing, printed directly above a subtitle
 * that repeated most of it. Two titles, said twice, on the first line of a
 * document a school sends to a família.
 */
class DocumentHeadingTest extends TestCase
{
    #[Test]
    public function a_generated_title_dissolves_into_its_own_metadata(): void
    {
        $heading = DocumentHeading::for(
            title: 'Relatório de turma · 7.º A · Ano letivo',
            typeLabel: 'Relatório de turma',
            metadata: ['7.º A', 'Português', 'Ano letivo até ao momento'],
        );

        // The exact shape asked for: the document is called by its kind, and
        // the line under it carries turma, disciplina and período.
        $this->assertSame('Relatório de turma', $heading['title']);
        $this->assertSame('7.º A · Português · Ano letivo até ao momento', $heading['subtitle']);
    }

    #[Test]
    public function the_type_label_is_never_printed_twice(): void
    {
        $heading = DocumentHeading::for(
            title: 'Relatório de turma · 7.º A · Ano letivo',
            typeLabel: 'Relatório de turma',
            metadata: ['7.º A', 'Português', 'Ano letivo até ao momento'],
        );

        $printed = $heading['title'].' '.$heading['subtitle'];

        $this->assertSame(1, substr_count($printed, 'Relatório de turma'));
        $this->assertSame(1, substr_count($printed, '7.º A'));
    }

    #[Test]
    public function a_title_the_teacher_wrote_survives_whole(): void
    {
        $heading = DocumentHeading::for(
            title: 'Balanço para o conselho de turma',
            typeLabel: 'Relatório de turma',
            metadata: ['7.º A', 'Português', '1.º Período'],
        );

        // Nothing of it is metadata, so nothing of it is dropped — and the type
        // label moves down into the line that says what the document is.
        $this->assertSame('Balanço para o conselho de turma', $heading['title']);
        $this->assertSame('Relatório de turma · 7.º A · Português · 1.º Período', $heading['subtitle']);
    }

    #[Test]
    public function a_part_the_teachers_title_already_states_is_not_repeated(): void
    {
        $heading = DocumentHeading::for(
            title: 'Balanço do 1.º Período',
            typeLabel: 'Relatório de turma',
            metadata: ['7.º A', 'Português', '1.º Período'],
        );

        $this->assertSame('Balanço do 1.º Período', $heading['title']);
        $this->assertSame('Relatório de turma · 7.º A · Português', $heading['subtitle']);
    }

    #[Test]
    public function a_short_scope_in_the_title_is_absorbed_by_the_long_one(): void
    {
        // «Ano letivo» is the short form the generated title carries and «Ano
        // letivo até ao momento» the scope label. Printing both is the
        // repetition, not two different facts.
        $heading = DocumentHeading::for(
            title: 'Relatório individual · Maria Silva · Ano letivo',
            typeLabel: 'Relatório individual',
            metadata: ['7.º A', 'Português', 'Maria Silva', 'Ano letivo até ao momento'],
        );

        $this->assertSame('Relatório individual', $heading['title']);
        $this->assertStringContainsString('Ano letivo até ao momento', $heading['subtitle']);
        $this->assertSame(1, substr_count($heading['subtitle'], 'Ano letivo'));
    }

    #[Test]
    public function a_segment_is_only_absorbed_by_a_part_that_starts_with_it(): void
    {
        // «Leitura» must never be swallowed by «Educação Literária» or by any
        // other part that merely contains the word.
        $heading = DocumentHeading::for(
            title: 'Leitura · 7.º A',
            typeLabel: 'Relatório de turma',
            metadata: ['7.º A', 'Educação Literária e Leitura', 'Ano letivo'],
        );

        $this->assertSame('Leitura', $heading['title']);
    }

    #[Test]
    public function nothing_of_the_metadata_is_invented_when_there_is_none(): void
    {
        $heading = DocumentHeading::for(
            title: 'Relatório da escola',
            typeLabel: 'Relatório da escola',
            metadata: [null, '', '  '],
        );

        $this->assertSame('Relatório da escola', $heading['title']);
        $this->assertSame('', $heading['subtitle']);
    }

    #[Test]
    public function a_repeated_metadata_part_is_stated_once(): void
    {
        $heading = DocumentHeading::for(
            title: 'Relatório de registos',
            typeLabel: 'Relatório de registos',
            metadata: ['7.º A', '7.º A', 'Ano letivo 2026/2027'],
        );

        $this->assertSame('7.º A · Ano letivo 2026/2027', $heading['subtitle']);
    }

    #[Test]
    public function a_title_made_entirely_of_metadata_still_leaves_a_document_with_a_name(): void
    {
        $heading = DocumentHeading::for(
            title: '7.º A · Português',
            typeLabel: 'Relatório de turma',
            metadata: ['7.º A', 'Português', '1.º Período'],
        );

        $this->assertSame('Relatório de turma', $heading['title']);
        $this->assertNotSame('', $heading['subtitle']);
    }
}
