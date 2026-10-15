<?php

namespace Tests\Unit\Assessment;

use App\Services\Assessment\Progress\BuildStudentProgress;
use App\Services\Reporting\Sections\StudentSelfAssessmentComposer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «Acima» e «abaixo» só entre bandas da MESMA escala.
 *
 * Depois de uma nova autoavaliação, um aluno pode ter, para o mesmo período,
 * uma resposta dada com os critérios anteriores — noutra escala — e a
 * classificação atribuída na escala atual da turma. Um 4 numa escala não é um
 * 4 na outra: comparar as sequências diria ao professor que o aluno se avaliou
 * «acima» de uma nota com que a resposta nunca foi medida.
 */
class SelfAssessmentComparisonScaleTest extends TestCase
{
    protected bool $seed = false;

    /**
     * @param  array<string, mixed>  $self
     * @param  array<string, mixed>  $assigned
     * @return array<string, mixed>|null
     */
    private function progressComparison(array $self, array $assigned): ?array
    {
        $line = [[
            'period_id' => 1,
            'period_label' => '1.º Semestre',
            'self_assessment' => $self,
            'classification' => ['status' => 'published', 'final' => $assigned],
        ]];

        $builder = app(BuildStudentProgress::class);
        $rows = (fn (array $line): array => $this->selfAssessments($line))->call($builder, $line);

        return $rows[0]['comparison'];
    }

    /**
     * @param  array<string, mixed>  $self
     * @param  array<string, mixed>  $assigned
     */
    private function reportSentence(array $self, array $assigned): ?string
    {
        $composer = app(StudentSelfAssessmentComposer::class);

        return (fn (array $student, array $self): ?string => $this->comparisonSentence($student, $self))
            ->call($composer, ['assigned' => $assigned], $self);
    }

    #[Test]
    public function two_bands_of_the_same_scale_are_compared(): void
    {
        $comparison = $this->progressComparison(
            ['code' => '4', 'label' => 'Bom', 'sequence' => 4, 'scale_id' => 1],
            ['code' => '3', 'label' => 'Suficiente', 'sequence' => 3, 'scale_id' => 1],
        );

        $this->assertSame(['difference' => 1, 'direction' => 'above'], $comparison);
        $this->assertNotNull($this->reportSentence(
            ['code' => '4', 'label' => 'Bom', 'sequence' => 4, 'scale_id' => 1],
            ['code' => '3', 'label' => 'Suficiente', 'sequence' => 3, 'scale_id' => 1],
        ));
    }

    #[Test]
    public function bands_of_two_different_scales_are_never_compared(): void
    {
        $self = ['code' => '4', 'label' => 'Bom', 'sequence' => 4, 'scale_id' => 1];
        $assigned = ['code' => 'B', 'label' => 'Satisfaz', 'sequence' => 2, 'scale_id' => 9];

        $this->assertNull($this->progressComparison($self, $assigned));
        $this->assertNull($this->reportSentence($self, $assigned));
    }

    #[Test]
    public function a_numeric_answer_has_no_band_to_compare(): void
    {
        $self = ['code' => '16', 'label' => '16 numa escala de 0 a 20', 'sequence' => null, 'scale_id' => 2];
        $assigned = ['code' => '3', 'label' => 'Suficiente', 'sequence' => 3, 'scale_id' => 2];

        $this->assertNull($this->progressComparison($self, $assigned));
        $this->assertNull($this->reportSentence($self, $assigned));
    }
}
