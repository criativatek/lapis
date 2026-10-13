<?php

namespace Tests\Unit\Evidence;

use App\Models\RecordFollowupCategory;
use App\Services\Evidence\Followup\InovarFollowupMessage;
use App\Support\Language\PortugueseCardinal;
use App\Support\Language\StudentNameParts;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The three Inovar texts the product owner wrote (2026-10-12), filled from the
 * records — the exact wording, the count in words matching the dates given,
 * and no «null» or stray comma when a piece of the identification is missing.
 */
class InovarFollowupMessageTest extends TestCase
{
    /**
     * @return list<CarbonImmutable>
     */
    private function dates(string ...$days): array
    {
        return array_values(array_map(fn (string $day): CarbonImmutable => CarbonImmutable::parse($day), $days));
    }

    #[Test]
    public function missing_material_follows_the_owners_model_word_for_word(): void
    {
        $message = InovarFollowupMessage::compose(
            RecordFollowupCategory::MissingMaterial, 5, 'Ana Costa', 'Matemática', 'no 1.º Período',
            $this->dates('2026-10-03', '2026-10-10', '2026-10-17'),
        );

        $this->assertSame(
            'O aluno n.º 5, Ana Costa, acumulou três faltas de material na disciplina de Matemática, no 1.º Período, nos dias 03/10, 10/10 e 17/10. Solicita-se acompanhamento no sentido de garantir que o aluno traz o material necessário às aulas.',
            $message['text'],
        );
        $this->assertSame([], $message['missing']);
    }

    #[Test]
    public function lateness_follows_the_owners_model_word_for_word(): void
    {
        $message = InovarFollowupMessage::compose(
            RecordFollowupCategory::Lateness, 12, 'Rui Sousa', 'Português', 'no 2.º Semestre',
            $this->dates('2027-02-01', '2027-02-08', '2027-02-15'),
        );

        $this->assertSame(
            'O aluno n.º 12, Rui Sousa, acumulou três faltas por atraso na disciplina de Português, no 2.º Semestre, nos dias 01/02, 08/02 e 15/02. Solicita-se acompanhamento no sentido de promover o cumprimento do horário de início das aulas.',
            $message['text'],
        );
    }

    #[Test]
    public function homework_not_done_follows_the_owners_model_word_for_word(): void
    {
        $message = InovarFollowupMessage::compose(
            RecordFollowupCategory::HomeworkNotDone, 7, 'Inês Lopes', 'Inglês', 'no 1.º Período',
            $this->dates('2026-09-21', '2026-09-28', '2026-10-05'),
        );

        $this->assertSame(
            'Foram registadas três situações de não realização dos trabalhos de casa pelo aluno n.º 7, Inês Lopes, na disciplina de Inglês, no 1.º Período, nos dias 21/09, 28/09 e 05/10. Solicita-se acompanhamento no sentido de assegurar a realização das tarefas propostas.',
            $message['text'],
        );
    }

    #[Test]
    public function four_occurrences_say_four_and_list_four_dates_never_three(): void
    {
        $message = InovarFollowupMessage::compose(
            RecordFollowupCategory::Lateness, 5, 'Ana Costa', 'Matemática', 'no 1.º Período',
            $this->dates('2026-10-17', '2026-10-03', '2026-10-24', '2026-10-10'),
        );

        $this->assertStringContainsString('acumulou quatro faltas por atraso', $message['text']);
        $this->assertStringContainsString('nos dias 03/10, 10/10, 17/10 e 24/10.', $message['text']);
        $this->assertStringNotContainsString('três', $message['text']);
    }

    #[Test]
    public function two_occurrences_on_the_same_day_stay_one_day_with_two_occurrences(): void
    {
        $message = InovarFollowupMessage::compose(
            RecordFollowupCategory::Lateness, 5, 'Ana Costa', 'Matemática', 'no 1.º Período',
            $this->dates('2026-10-03', '2026-10-10', '2026-10-10'),
        );

        $this->assertStringContainsString('acumulou três faltas por atraso', $message['text']);
        $this->assertStringContainsString('nos dias 03/10 e 10/10 (duas ocorrências).', $message['text']);
    }

    #[Test]
    public function three_occurrences_on_one_day_read_as_one_day(): void
    {
        $message = InovarFollowupMessage::compose(
            RecordFollowupCategory::MissingMaterial, 5, 'Ana Costa', 'Matemática', 'no 1.º Período',
            $this->dates('2026-10-10', '2026-10-10', '2026-10-10'),
        );

        $this->assertStringContainsString('no dia 10/10 (três ocorrências).', $message['text']);
        $this->assertStringNotContainsString('nos dias', $message['text']);
    }

    #[Test]
    public function a_single_occurrence_is_written_in_the_singular(): void
    {
        $homework = InovarFollowupMessage::compose(RecordFollowupCategory::HomeworkNotDone, 5, 'Ana Costa', 'Matemática', 'no 1.º Período', $this->dates('2026-10-10'));
        $lateness = InovarFollowupMessage::compose(RecordFollowupCategory::Lateness, 5, 'Ana Costa', 'Matemática', 'no 1.º Período', $this->dates('2026-10-10'));

        $this->assertStringStartsWith('Foi registada uma situação de não realização', $homework['text']);
        $this->assertStringContainsString('no dia 10/10.', $homework['text']);
        $this->assertStringContainsString('acumulou uma falta por atraso', $lateness['text']);
    }

    #[Test]
    public function a_missing_number_drops_that_piece_without_leaving_a_gap(): void
    {
        $message = InovarFollowupMessage::compose(RecordFollowupCategory::MissingMaterial, null, 'Ana Costa', 'Matemática', 'no 1.º Período', $this->dates('2026-10-03', '2026-10-10', '2026-10-17'));
        $homework = InovarFollowupMessage::compose(RecordFollowupCategory::HomeworkNotDone, null, 'Ana Costa', 'Matemática', 'no 1.º Período', $this->dates('2026-10-03', '2026-10-10', '2026-10-17'));

        $this->assertStringStartsWith('O aluno Ana Costa acumulou três faltas de material', $message['text']);
        $this->assertStringContainsString('pelo aluno Ana Costa, na disciplina', $homework['text']);
        $this->assertSame(['number'], $message['missing']);

        foreach ([$message['text'], $homework['text']] as $text) {
            $this->assertStringNotContainsString('null', $text);
            $this->assertStringNotContainsString('n.º ,', $text);
            $this->assertStringNotContainsString(', ,', $text);
            $this->assertStringNotContainsString('  ', $text);
        }
    }

    #[Test]
    public function a_missing_name_drops_that_piece_without_leaving_a_gap(): void
    {
        $message = InovarFollowupMessage::compose(RecordFollowupCategory::Lateness, 5, null, 'Matemática', 'no 1.º Período', $this->dates('2026-10-03', '2026-10-10', '2026-10-17'));
        $homework = InovarFollowupMessage::compose(RecordFollowupCategory::HomeworkNotDone, 5, '   ', 'Matemática', 'no 1.º Período', $this->dates('2026-10-03', '2026-10-10', '2026-10-17'));

        $this->assertStringStartsWith('O aluno n.º 5 acumulou três faltas por atraso', $message['text']);
        $this->assertStringContainsString('pelo aluno n.º 5, na disciplina', $homework['text']);
        $this->assertSame(['name'], $message['missing']);
        $this->assertSame(['name'], $homework['missing']);
    }

    #[Test]
    public function with_neither_number_nor_name_no_stray_comma_is_left(): void
    {
        $homework = InovarFollowupMessage::compose(RecordFollowupCategory::HomeworkNotDone, null, null, 'Inglês', 'no 1.º Período', $this->dates('2026-10-03', '2026-10-10', '2026-10-17'));
        $lateness = InovarFollowupMessage::compose(RecordFollowupCategory::Lateness, null, null, 'Inglês', 'no 1.º Período', $this->dates('2026-10-03', '2026-10-10', '2026-10-17'));

        $this->assertStringContainsString('dos trabalhos de casa pelo aluno na disciplina de Inglês', $homework['text']);
        $this->assertStringStartsWith('O aluno acumulou três faltas por atraso', $lateness['text']);
        $this->assertSame(['number', 'name'], $homework['missing']);
    }

    #[Test]
    public function dates_from_two_calendar_years_carry_the_year_and_never_merge(): void
    {
        $message = InovarFollowupMessage::compose(RecordFollowupCategory::Lateness, 5, 'Ana Costa', 'Matemática', 'no período de 01/09/2025 a 31/10/2026', $this->dates('2025-10-03', '2026-10-03', '2026-10-10'));

        $this->assertStringContainsString('nos dias 03/10/2025, 03/10/2026 e 10/10/2026.', $message['text']);
        $this->assertStringNotContainsString('ocorrências)', $message['text']);
    }

    #[Test]
    public function a_custom_range_is_named_by_its_dates(): void
    {
        $message = InovarFollowupMessage::compose(RecordFollowupCategory::Lateness, 5, 'Ana Costa', 'Matemática', 'no período de 01/10/2026 a 31/10/2026', $this->dates('2026-10-03', '2026-10-10', '2026-10-17'));

        $this->assertStringContainsString('na disciplina de Matemática, no período de 01/10/2026 a 31/10/2026, nos dias', $message['text']);
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function cardinals(): array
    {
        return [
            'um' => [1, 'uma'],
            'dois' => [2, 'duas'],
            'três' => [3, 'três'],
            'dezasseis' => [16, 'dezasseis'],
            'vinte' => [20, 'vinte'],
            'vinte e um' => [21, 'vinte e uma'],
            'trinta e dois' => [32, 'trinta e duas'],
            'cem' => [100, 'cem'],
            'cento e um' => [101, 'cento e uma'],
            'duzentos' => [200, 'duzentas'],
            'mil' => [1000, 'mil'],
            'mil e duzentos' => [1200, 'mil e duzentas'],
            'dois mil trezentos e cinco' => [2305, 'duas mil trezentas e cinco'],
        ];
    }

    #[Test]
    #[DataProvider('cardinals')]
    public function counts_are_written_out_in_the_feminine(int $number, string $words): void
    {
        $this->assertSame($words, PortugueseCardinal::feminine($number));
    }

    #[Test]
    public function first_and_last_name_come_from_the_single_full_name(): void
    {
        $this->assertSame('Ana Costa', StudentNameParts::firstAndLast('Ana Sofia Martins Costa'));
        $this->assertSame('Pedro Costa Júnior', StudentNameParts::firstAndLast('Pedro Miguel Costa Júnior'));
        $this->assertSame('Maria Santos', StudentNameParts::firstAndLast('  Maria   dos  Santos '));
        $this->assertSame('Leonor', StudentNameParts::firstAndLast('Leonor'));
        $this->assertNull(StudentNameParts::firstAndLast('   '));
        $this->assertNull(StudentNameParts::firstAndLast(null));
    }
}
