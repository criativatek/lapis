<?php

namespace Tests\Feature\Assessment;

use App\Models\ClassificationScope;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Assessment\BuildEvaluationSheet;
use App\Services\Assessment\CaptureEvaluationSheet;
use App\Support\Assessment\CoverageWording;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TRÊS ESTADOS DE COBERTURA, TRÊS FRASES — e nunca a frase errada.
 *
 * A frase «nem todos os elementos previstos foram realizados» só é verdadeira
 * sobre um aluno que FOI avaliado. Dita sobre alguém sem avaliação nenhuma,
 * afirma uma avaliação que não existiu — e é essa a confusão que estes testes
 * tornam impossível de reintroduzir sem alguém ficar a vermelho.
 */
class CoverageWordingTest extends TestCase
{
    use RefreshDatabase;

    private function seedDemo(): User
    {
        // Dentro do 1.º Semestre da demonstração: uma pauta só se guarda numa
        // data que o período contenha, e nunca no futuro.
        $this->travelTo(Carbon::parse('2026-12-15 10:00:00'));

        $teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        return $teacher;
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function asTenant(User $teacher, callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), $callback);
    }

    // ------------------------------------------------------------- a regra

    #[Test]
    public function the_state_is_read_from_the_engine_flag_and_the_existence_of_a_value(): void
    {
        // Sem bandeira não há nada a assinalar, haja ou não valor.
        $this->assertSame(CoverageWording::COMPLETE, CoverageWording::state(false, true));
        $this->assertSame(CoverageWording::COMPLETE, CoverageWording::state(false, false));

        // Com bandeira, é a existência do valor que separa «parcial» de «sem
        // elementos» — a mesma leitura que o ⚠ do ecrã faz.
        $this->assertSame(CoverageWording::PARTIAL, CoverageWording::state(true, true));
        $this->assertSame(CoverageWording::NONE, CoverageWording::state(true, false));
    }

    #[Test]
    public function only_partial_coverage_says_that_there_was_an_assessment(): void
    {
        $this->assertStringContainsString('Embora tenha havido avaliação', CoverageWording::partial());
        $this->assertStringContainsString('nem todos os elementos previstos foram realizados', CoverageWording::partial());

        // A ausência de cobertura NÃO pode conter a concessiva: dizer «embora
        // tenha havido avaliação» sobre quem não teve nenhuma é uma afirmação
        // falsa, não uma imprecisão de estilo.
        $this->assertStringNotContainsString('Embora', CoverageWording::none());
        $this->assertStringNotContainsString('avaliação', CoverageWording::none());
        $this->assertSame('', CoverageWording::sentence(CoverageWording::COMPLETE));
    }

    #[Test]
    public function the_wording_names_the_domain_or_the_moment_but_never_a_hardcoded_period_word(): void
    {
        $this->assertStringContainsString('neste domínio', CoverageWording::partial('domain'));
        $this->assertStringContainsString('neste momento', CoverageWording::partial('overall'));

        foreach ([CoverageWording::partial('overall'), CoverageWording::none('overall')] as $sentence) {
            $this->assertStringNotContainsStringIgnoringCase('semestre', $sentence);
            $this->assertStringNotContainsStringIgnoringCase('período', $sentence);
        }
    }

    // ------------------------------------- os três estados na pauta guardada

    #[Test]
    public function a_kept_pauta_never_claims_an_assessment_that_did_not_happen(): void
    {
        $teacher = $this->seedDemo();

        $warnings = $this->asTenant($teacher, function () use ($teacher): array {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();

            $export = app(CaptureEvaluationSheet::class)->capture(
                $class,
                $period,
                ClassificationScope::Period,
                'Momento de teste',
                Carbon::parse(app(CaptureEvaluationSheet::class)->defaultEffectiveDate($period)->toDateString()),
                $teacher,
            );

            return $export->payload['warnings'];
        });

        // Quem não tem elementos nenhuns é nomeado como tal — e a frase da
        // cobertura parcial nunca aparece ao lado desse nome.
        $withoutElements = array_values(array_filter(
            $warnings,
            fn (string $line): bool => str_contains($line, 'sem elementos avaliados'),
        ));

        $this->assertNotSame([], $withoutElements, 'A demonstração tem alunos sem qualquer elemento avaliado.');

        foreach ($withoutElements as $line) {
            $this->assertStringNotContainsString('embora tenha havido avaliação', $line);
        }

        // E a frase antiga, que dizia a mesma coisa sem a concessiva, saiu de
        // circulação — é o que §15 pediu para rever.
        foreach ($warnings as $line) {
            $this->assertStringNotContainsString('cobertura parcial — nem todos os elementos previstos foram avaliados', $line);
        }
    }

    #[Test]
    public function a_student_assessed_only_in_part_gets_the_concessive_sentence(): void
    {
        $teacher = $this->seedDemo();

        [$warnings, $partialNames] = $this->asTenant($teacher, function () use ($teacher): array {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();
            $sheet = app(BuildEvaluationSheet::class)->for($class, $period);

            // Quem TEM resultado global e ainda assim traz bandeira: é
            // exatamente destes que a frase concessiva fala.
            $names = [];
            foreach ($sheet['students'] as $student) {
                if ($student['overall']['has_coverage_warning'] === true
                    && $student['overall']['normalized_value'] !== null) {
                    $names[] = $student['name'];
                }
            }

            $export = app(CaptureEvaluationSheet::class)->capture(
                $class,
                $period,
                ClassificationScope::Period,
                'Momento de teste',
                Carbon::parse(app(CaptureEvaluationSheet::class)->defaultEffectiveDate($period)->toDateString()),
                $teacher,
            );

            return [$export->payload['warnings'], $names];
        });

        if ($partialNames === []) {
            $this->markTestSkipped('A demonstração não tem, neste período, nenhum resultado de cobertura parcial.');
        }

        foreach ($partialNames as $name) {
            $line = array_values(array_filter($warnings, fn (string $row): bool => str_starts_with($row, "{$name}: embora")));
            $this->assertNotSame([], $line, "«{$name}» tem cobertura parcial e a pauta guardada não o diz com a frase certa.");
        }
    }
}
