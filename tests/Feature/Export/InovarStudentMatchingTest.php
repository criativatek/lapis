<?php

namespace Tests\Feature\Export;

use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Export\InovarExportPreviewBuilder;
use App\Services\Export\InovarTemplateReader;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InovarGridFixture;
use Tests\TestCase;

/**
 * DE QUEM É CADA LINHA DA GRELHA DO INOVAR.
 *
 * É a pergunta mais perigosa deste fluxo: uma menção escrita na linha errada
 * sai da escola como se fosse a nota daquela pessoa, e ninguém a apanha a ler.
 * Por isso a resposta é por CONFIANÇA e não por uma chave só — e por isso a
 * fronteira entre «preencho» e «pergunto» está fixada aqui, caso a caso.
 *
 * O que estes testes não deixam passar:
 *
 *  - uma turma sem N.º de processo a ficar bloqueada por causa disso;
 *  - um nome do meio a impedir uma correspondência óbvia;
 *  - «Martins» a corresponder a «Martin»;
 *  - dois candidatos plausíveis a serem reduzidos a um por escolha do sistema;
 *  - um N.º de processo divergente a ser ignorado em silêncio.
 */
class InovarStudentMatchingTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function asTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->teacher->personalOrganization(), $callback);
    }

    /**
     * Renomeia um aluno da turma e devolve o nome que passou a ter.
     *
     * O ponto de partida de quase todos estes casos: a MESMA pessoa, escrita de
     * duas maneiras diferentes por dois sistemas diferentes.
     */
    private function rename(string $from, string $to): void
    {
        $this->asTenant(function () use ($from, $to): void {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();

            foreach ($class->enrollments()->with('student.identity')->get() as $enrollment) {
                if ($enrollment->student->identity->display_name === $from) {
                    $enrollment->student->identity->update(['display_name' => $to]);

                    return;
                }
            }

            $this->fail("Não há «{$from}» na turma.");
        });
    }

    private function giveProcessNumber(string $name, ?string $number): void
    {
        $this->asTenant(function () use ($name, $number): void {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();

            foreach ($class->enrollments()->with('student.identity')->get() as $enrollment) {
                if ($enrollment->student->identity->display_name === $name) {
                    $enrollment->student->identity->update(['school_number' => $number]);

                    return;
                }
            }

            $this->fail("Não há «{$name}» na turma.");
        });
    }

    /**
     * O preview de uma grelha com estes alunos — os domínios são sempre os do
     * perfil, para que nada aqui bloqueie por outra razão.
     *
     * @param  array<string, string>  $students  N.º de processo → nome
     * @param  array<int, int>  $resolutions
     * @return array<string, mixed>
     */
    private function preview(array $students, array $resolutions = []): array
    {
        $path = (new InovarGridFixture)->build([
            'domains' => ['D' => 'Oralidade', 'E' => 'Leitura', 'F' => 'Escrita'],
            'students' => $students,
        ]);

        try {
            return $this->asTenant(function () use ($path, $resolutions): array {
                $class = SchoolClass::where('label', '7.º A')->firstOrFail();
                $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();

                return app(InovarExportPreviewBuilder::class)->build(
                    $class,
                    $period,
                    app(InovarTemplateReader::class)->read($path),
                    resolutions: $resolutions,
                );
            });
        } finally {
            @unlink($path);
        }
    }

    /**
     * @param  array<string, mixed>  $preview
     * @return array<string, mixed>
     */
    private function line(array $preview, string $processNumber): array
    {
        foreach ($preview['students'] as $student) {
            if ((string) $student['process_number'] === $processNumber) {
                return $student;
            }
        }

        $this->fail("Não há linha com o N.º de processo {$processNumber}.");
    }

    private function enrollmentIdOf(string $name): int
    {
        return $this->asTenant(function () use ($name): int {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();

            foreach ($class->enrollments()->with('student.identity')->get() as $enrollment) {
                if ($enrollment->student->identity->display_name === $name) {
                    return (int) $enrollment->getKey();
                }
            }

            $this->fail("Não há «{$name}» na turma.");
        });
    }

    // ------------------------------------------------------------- A · sem
    //                                          N.º de processo no Lapispro

    #[Test]
    public function a_number_the_lapispro_does_not_know_does_not_block_and_the_name_answers(): void
    {
        // A grelha traz 7050; o Lapispro nunca viu esse número.
        $this->rename('Ana Marques', 'Álvaro Simões');

        $preview = $this->preview(['7050' => 'Álvaro Simões']);

        $line = $this->line($preview, '7050');

        $this->assertSame('strong', $line['confidence']);
        $this->assertTrue($line['matched']);
        $this->assertFalse($line['needs_teacher']);
        $this->assertSame($this->enrollmentIdOf('Álvaro Simões'), $line['enrollment_id']);
        $this->assertStringContainsString('não existe no Lapispro', implode(' ', $line['reasons']));
        $this->assertSame([], $preview['summary']['blocking_errors']);
    }

    // ------------------------------- B · nomes do meio e diacríticos

    #[Test]
    public function middle_names_and_accents_never_stand_in_the_way_of_a_match(): void
    {
        $this->rename('Ana Marques', 'Álvaro Manuel Simões');

        // A grelha da escola escreve-o sem o nome do meio e sem acento.
        $preview = $this->preview(['7050' => 'Alvaro Simões']);

        $line = $this->line($preview, '7050');

        $this->assertSame('strong', $line['confidence']);
        $this->assertSame($this->enrollmentIdOf('Álvaro Manuel Simões'), $line['enrollment_id']);
    }

    #[Test]
    public function particles_hyphens_and_case_are_normalised_but_a_different_surname_is_not(): void
    {
        $this->rename('Ana Marques', 'Ana de Sousa');
        $preview = $this->preview(['7050' => 'ANA SOUSA']);
        $this->assertSame('strong', $this->line($preview, '7050')['confidence']);

        $this->rename('Ana de Sousa', 'Maria-João Nogueira');
        $preview = $this->preview(['7051' => 'Maria João Nogueira']);
        $this->assertSame('strong', $this->line($preview, '7051')['confidence']);
    }

    // ------------------------------------------- C · dois candidatos

    #[Test]
    public function two_students_with_the_same_first_and_last_name_are_never_chosen_between(): void
    {
        $this->rename('Ana Marques', 'Rita Costa');
        $this->rename('Bruno Teixeira', 'Rita Alexandra Costa');

        $preview = $this->preview(['7050' => 'Rita Costa']);
        $line = $this->line($preview, '7050');

        $this->assertSame('ambiguous', $line['confidence']);
        $this->assertFalse($line['matched']);
        $this->assertTrue($line['needs_teacher']);
        // Não sugere nenhum dos dois — sugerir seria escolher.
        $this->assertNull($line['enrollment_id']);
        $this->assertCount(2, $line['candidates']);
        $this->assertStringContainsString('Mais do que um aluno', implode(' ', $line['reasons']));
    }

    #[Test]
    public function the_teacher_resolves_an_ambiguity_and_only_among_the_candidates_offered(): void
    {
        $this->rename('Ana Marques', 'Rita Costa');
        $this->rename('Bruno Teixeira', 'Rita Alexandra Costa');

        $chosen = $this->enrollmentIdOf('Rita Alexandra Costa');

        $preview = $this->preview(['7050' => 'Rita Costa'], [4 => $chosen]);
        $line = $this->line($preview, '7050');

        $this->assertSame('strong', $line['confidence']);
        $this->assertTrue($line['matched']);
        $this->assertTrue($line['chosen_by_teacher']);
        $this->assertSame($chosen, $line['enrollment_id']);

        // Uma escolha que não estava entre os candidatos daquela linha é
        // descartada, e a linha volta a pedir resposta. O browser não escreve
        // em quem quiser.
        $elsewhere = $this->enrollmentIdOf('Carolina Nunes');
        $refused = $this->preview(['7050' => 'Rita Costa'], [4 => $elsewhere]);

        $this->assertSame('ambiguous', $this->line($refused, '7050')['confidence']);
        $this->assertNull($this->line($refused, '7050')['enrollment_id']);
    }

    // ---------------------------------------- D · o número coincide

    #[Test]
    public function a_process_number_that_matches_on_both_sides_is_the_strongest_match(): void
    {
        $this->giveProcessNumber('Ana Marques', '001234');

        $preview = $this->preview(['001234' => 'Ana Marques']);
        $line = $this->line($preview, '001234');

        $this->assertSame('strong', $line['confidence']);
        $this->assertStringContainsString('N.º de processo 001234 coincide', implode(' ', $line['reasons']));
    }

    #[Test]
    public function a_leading_zero_is_part_of_the_identifier_and_is_not_tidied_away(): void
    {
        $this->giveProcessNumber('Ana Marques', '001234');

        // «1234» não é «001234». Sem correspondência pelo número, e o nome não
        // é o mesmo, logo nada.
        $preview = $this->preview(['1234' => 'Outra Pessoa Qualquer']);

        $this->assertSame('none', $this->line($preview, '1234')['confidence']);
    }

    // -------------------------------- E · o número diverge, o nome é forte

    #[Test]
    public function a_diverging_process_number_asks_for_confirmation_instead_of_writing(): void
    {
        $this->giveProcessNumber('Ana Marques', '001234');

        // O nome é o mesmo; o número que a grelha traz é outro.
        $preview = $this->preview(['9999' => 'Ana Marques']);
        $line = $this->line($preview, '9999');

        $this->assertSame('probable', $line['confidence']);
        $this->assertFalse($line['matched'], 'Uma correspondência provável ainda não é uma correspondência.');
        $this->assertTrue($line['needs_teacher']);
        // Sugere quem é, para o professor poder confirmar — mas não escreve.
        $this->assertSame($this->enrollmentIdOf('Ana Marques'), $line['enrollment_id']);
        $this->assertStringContainsString('difere do que o Lapispro tem', implode(' ', $line['reasons']));
    }

    #[Test]
    public function a_process_number_that_names_one_person_and_a_name_that_names_another_is_never_silent(): void
    {
        $this->giveProcessNumber('Ana Marques', '001234');

        $preview = $this->preview(['001234' => 'Carolina Nunes']);
        $line = $this->line($preview, '001234');

        $this->assertSame('probable', $line['confidence']);
        $this->assertTrue($line['needs_teacher']);
        $this->assertStringContainsString('mas o nome na grelha é', implode(' ', $line['reasons']));
    }

    // ----------------------------------------- F · nome não confiável

    #[Test]
    public function a_surname_that_is_merely_similar_never_matches(): void
    {
        $this->rename('Ana Marques', 'Ana Martins');

        // «Martin» é plausível e não é «Martins». Nenhuma aproximação, nem
        // sequer como sugestão automática (§26).
        $preview = $this->preview(['7050' => 'Ana Martin']);
        $line = $this->line($preview, '7050');

        $this->assertSame('none', $line['confidence']);
        $this->assertNull($line['enrollment_id']);
        $this->assertFalse($line['needs_teacher']);
    }

    #[Test]
    public function a_first_name_alone_is_not_a_match(): void
    {
        $preview = $this->preview(['7050' => 'Ana']);

        $this->assertSame('none', $this->line($preview, '7050')['confidence']);
    }

    // ------------------------------ G · duas linhas para a mesma pessoa

    #[Test]
    public function the_same_student_can_never_be_claimed_by_two_lines_of_the_grid(): void
    {
        $preview = $this->preview([
            '7050' => 'Ana Marques',
            '7051' => 'Ana Maria Marques',
        ]);

        foreach (['7050', '7051'] as $number) {
            $line = $this->line($preview, $number);

            $this->assertSame('ambiguous', $line['confidence'], "linha {$number}");
            $this->assertNull($line['enrollment_id']);
            $this->assertStringContainsString('O mesmo aluno é candidato às linhas', implode(' ', $line['reasons']));
        }
    }

    // ----------------------------------------- H · o que ainda bloqueia

    #[Test]
    public function a_process_number_repeated_inside_the_grid_is_a_problem_of_the_file(): void
    {
        $this->giveProcessNumber('Ana Marques', '001234');

        $preview = $this->preview([
            '001234' => 'Ana Marques',
            '001234 ' => 'Ana Marques',
        ]);

        // Nenhuma escolha do professor resolve um ficheiro que se contradiz:
        // isto continua a ser um erro a corrigir, não uma pergunta a responder.
        $this->assertStringContainsString(
            'aparece mais do que uma vez',
            implode(' ', $preview['summary']['blocking_errors']),
        );
    }

    #[Test]
    public function the_summary_counts_the_lines_still_waiting_for_the_teacher(): void
    {
        $this->giveProcessNumber('Ana Marques', '001234');
        $this->giveProcessNumber('Carolina Nunes', '001236');

        $preview = $this->preview([
            '001234' => 'Ana Marques',   // forte
            '9999' => 'Carolina Nunes',  // provável: o número da grelha não é o dela
            '8888' => 'Ninguém Daqui',   // sem correspondência
        ]);

        $this->assertSame(1, $preview['summary']['matched_students']);
        $this->assertSame(1, $preview['summary']['students_needing_teacher']);
        $this->assertStringContainsString(
            'ainda não tem o aluno confirmado',
            implode(' ', $preview['summary']['warnings']),
        );
    }
}
