<?php

namespace Tests\Unit\Assessment;

use App\Models\Scale;
use App\Models\ScaleLevel;
use App\Support\Assessment\SelfAssessmentAnswerScale;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * How a self-assessment question is answered is read from its scale and nowhere
 * else: a closed list of levels, or a number inside the scale's own limits.
 * These tests build the scales in memory — the description depends on nothing
 * but the scale and the precision the profile version asks for.
 */
class SelfAssessmentAnswerScaleTest extends TestCase
{
    protected function levelScale(int $levels = 5): Scale
    {
        $labels = ['Fraco', 'Insuficiente', 'Suficiente', 'Bom', 'Muito Bom'];

        $scale = new Scale(['name' => 'Escala 1 a 5', 'kind' => 'level', 'min_value' => 1, 'max_value' => 5]);
        $scale->forceFill(['id' => 1]);
        $scale->setRelation('levels', new Collection(array_map(
            function (int $index) use ($labels): ScaleLevel {
                $level = new ScaleLevel(['code' => (string) ($index + 1), 'label' => $labels[$index], 'sequence' => $index + 1]);
                $level->forceFill(['id' => 100 + $index]);

                return $level;
            },
            array_slice(range(0, 4), 0, $levels),
        )));

        return $scale;
    }

    protected function intervalScale(string $name, string $kind, int|string|null $min, int|string|null $max): Scale
    {
        $scale = new Scale(['name' => $name, 'kind' => $kind, 'min_value' => $min, 'max_value' => $max]);
        $scale->setRelation('levels', new Collection);

        return $scale;
    }

    #[Test]
    public function a_scale_of_levels_is_answered_by_choosing_one_of_them(): void
    {
        $answer = SelfAssessmentAnswerScale::for($this->levelScale(), 0);

        $this->assertSame('levels', $answer->mode());
        $this->assertNull($answer->problem());
        $this->assertCount(5, $answer->levels());
        $this->assertSame(['id' => 100, 'code' => '1', 'label' => 'Fraco'], $answer->levels()[0]);
        $this->assertSame('Escolhe um nível de 1 (Fraco) a 5 (Muito Bom).', $answer->instruction());
        $this->assertNull($answer->min());
        $this->assertSame('levels', $answer->toPayload()['mode']);
    }

    #[Test]
    public function zero_to_twenty_is_a_number_with_the_precision_of_the_version(): void
    {
        $scale = $this->intervalScale('Escala 0 a 20', 'numeric', 0, 20);

        $whole = SelfAssessmentAnswerScale::for($scale, 0);
        $this->assertSame('number', $whole->mode());
        $this->assertSame('0', $whole->min());
        $this->assertSame('20', $whole->max());
        $this->assertSame('1', $whole->step());
        $this->assertSame(0, $whole->decimals());
        $this->assertSame('Escreve um valor de 0 a 20, sem casas decimais.', $whole->instruction());
        $this->assertSame([], $whole->levels());
        $this->assertNull($whole->toPayload()['unit']);

        $tenths = SelfAssessmentAnswerScale::for($scale, 1);
        $this->assertSame('0.1', $tenths->step());
        $this->assertSame('Escreve um valor de 0 a 20, com até 1 casa decimal.', $tenths->instruction());

        $hundredths = SelfAssessmentAnswerScale::for($scale, 2);
        $this->assertSame('0.01', $hundredths->step());
        $this->assertSame('Escreve um valor de 0 a 20, com até 2 casas decimais.', $hundredths->instruction());
    }

    #[Test]
    public function a_percentage_says_percentage_and_carries_its_unit(): void
    {
        $answer = SelfAssessmentAnswerScale::for($this->intervalScale('Percentagem (0 a 100)', 'percentage', 0, 100), 0);

        $this->assertSame('number', $answer->mode());
        $this->assertTrue($answer->isPercentage());
        $this->assertSame('%', $answer->toPayload()['unit']);
        $this->assertSame('Escreve uma percentagem de 0 a 100, sem casas decimais.', $answer->instruction());
    }

    #[Test]
    public function a_scale_of_levels_without_levels_cannot_be_answered(): void
    {
        $answer = SelfAssessmentAnswerScale::for($this->levelScale(0), 0);

        $this->assertSame('unavailable', $answer->mode());
        $this->assertSame('levels_missing', $answer->problem());
        $this->assertSame('A escala «Escala 1 a 5» é de níveis, mas não tem nenhum nível definido.', $answer->teacherProblem());
        $this->assertSame('Esta pergunta não pode ser respondida por agora. Avisa o teu professor.', $answer->instruction());
    }

    #[Test]
    public function a_custom_scale_without_limits_cannot_be_answered(): void
    {
        $answer = SelfAssessmentAnswerScale::for($this->intervalScale('Minha escala', 'custom', null, null), 0);

        $this->assertSame('unavailable', $answer->mode());
        $this->assertSame('limits_missing', $answer->problem());
        $this->assertSame('A escala «Minha escala» não tem limites mínimo e máximo válidos.', $answer->teacherProblem());

        $inverted = SelfAssessmentAnswerScale::for($this->intervalScale('Minha escala', 'custom', 10, 10), 0);
        $this->assertSame('limits_missing', $inverted->problem());
    }

    #[Test]
    public function no_scale_cannot_be_answered(): void
    {
        $answer = SelfAssessmentAnswerScale::for(null, 0);

        $this->assertSame('unavailable', $answer->mode());
        $this->assertSame('no_scale', $answer->problem());
        $this->assertStringContainsString('não tem uma escala de avaliação associada', (string) $answer->teacherProblem());
        $this->assertSame('unavailable', $answer->toPayload()['mode']);
        $this->assertNotNull($answer->toPayload()['problem']);
    }

    #[Test]
    public function a_comma_or_a_point_is_accepted_and_stored_with_a_point(): void
    {
        $answer = SelfAssessmentAnswerScale::for($this->intervalScale('Escala 0 a 20', 'numeric', 0, 20), 1);

        $this->assertNull($answer->validate('16,5'));
        $this->assertNull($answer->validate('16.5'));
        $this->assertSame('16.5', $answer->normalise('16,5'));
        $this->assertSame('16.5', $answer->normalise(' 16.50 '));
        $this->assertSame('16', $answer->normalise('16'));
        $this->assertSame('16', $answer->normalise('16,0'));
    }

    #[Test]
    public function empty_is_valid_and_is_not_zero(): void
    {
        $answer = SelfAssessmentAnswerScale::for($this->intervalScale('Escala 0 a 20', 'numeric', 0, 20), 0);

        foreach ([null, '', '   '] as $empty) {
            $this->assertNull($answer->validate($empty));
            $this->assertNull($answer->normalise($empty));
        }

        $this->assertSame('0', $answer->normalise('0'));
    }

    #[Test]
    public function the_limits_are_inclusive(): void
    {
        $answer = SelfAssessmentAnswerScale::for($this->intervalScale('Escala 0 a 20', 'numeric', 0, 20), 0);

        $this->assertNull($answer->validate('0'));
        $this->assertNull($answer->validate('20'));
        $this->assertSame('Indica um valor de 0 a 20, sem casas decimais.', $answer->validate('21'));
        $this->assertNotNull($answer->validate('-1'));
        $this->assertNotNull($answer->validate('20,01'));
    }

    #[Test]
    public function more_decimals_than_the_version_asks_for_are_refused(): void
    {
        $whole = SelfAssessmentAnswerScale::for($this->intervalScale('Escala 0 a 20', 'numeric', 0, 20), 0);
        $tenths = SelfAssessmentAnswerScale::for($this->intervalScale('Escala 0 a 20', 'numeric', 0, 20), 1);

        $this->assertNotNull($whole->validate('15.5'));
        $this->assertNull($whole->validate('15.0'));
        $this->assertNull($tenths->validate('15.5'));
        $this->assertSame('Indica um valor de 0 a 20, com até 1 casa decimal.', $tenths->validate('15.55'));
    }

    #[Test]
    public function text_and_malformed_numbers_are_refused_in_the_same_words(): void
    {
        $percentage = SelfAssessmentAnswerScale::for($this->intervalScale('Percentagem (0 a 100)', 'percentage', 0, 100), 0);

        foreach (['abc', '1e2', '1,2,3', '--3', '12 3', '+5'] as $malformed) {
            $this->assertSame('Indica uma percentagem de 0 a 100, sem casas decimais.', $percentage->validate($malformed), $malformed);
        }

        $this->assertNotNull($percentage->validate('100.5'));
        $this->assertNull($percentage->validate('80'));
    }

    #[Test]
    public function a_scale_with_a_negative_minimum_accepts_negative_numbers(): void
    {
        $answer = SelfAssessmentAnswerScale::for($this->intervalScale('De -5 a 5', 'custom', -5, 5), 0);

        $this->assertSame('-5', $answer->min());
        $this->assertNull($answer->validate('-5'));
        $this->assertNull($answer->validate('-3'));
        $this->assertSame('-3', $answer->normalise('-3'));
        $this->assertNotNull($answer->validate('-6'));
        $this->assertSame('Escreve um valor de -5 a 5, sem casas decimais.', $answer->instruction());
    }

    #[Test]
    public function a_stored_number_is_read_with_the_comma_and_the_scale_it_was_given_on(): void
    {
        $interval = SelfAssessmentAnswerScale::for($this->intervalScale('Escala 0 a 20', 'numeric', 0, 20), 1);

        $this->assertSame([
            'code' => '15,5',
            'label' => '15,5 numa escala de 0 a 20',
            'display' => '15,5',
            'sequence' => null,
            'is_negative' => false,
            'scale' => 'Escala 0 a 20',
        ], $interval->readable('15.500'));

        $this->assertSame('16', $interval->readable('16.000')['code']);

        $percentage = SelfAssessmentAnswerScale::for($this->intervalScale('Percentagem (0 a 100)', 'percentage', 0, 100), 0);

        $this->assertSame('80', $percentage->readable('80.000')['code']);
        $this->assertSame('80%', $percentage->readable('80.000')['label']);
        $this->assertSame('80%', $percentage->readable('80.000')['display']);
        $this->assertNull($percentage->readable('80.000')['sequence']);
    }

    #[Test]
    public function the_decimals_fit_what_the_column_can_keep(): void
    {
        $answer = SelfAssessmentAnswerScale::for($this->intervalScale('Escala 0 a 20', 'numeric', 0, 20), 9);

        $this->assertSame(3, $answer->decimals());
        $this->assertSame('0.001', $answer->step());
        $this->assertSame(0, SelfAssessmentAnswerScale::for($this->intervalScale('Escala 0 a 20', 'numeric', 0, 20), -2)->decimals());
    }
}
