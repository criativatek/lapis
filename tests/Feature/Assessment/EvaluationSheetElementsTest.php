<?php

namespace Tests\Feature\Assessment;

use App\Domain\Assessment\Bc;
use App\Models\AcademicPeriod;
use App\Models\ClassificationScope;
use App\Models\Instrument;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Assessment\BuildEvaluationSheet;
use App\Services\Assessment\CaptureEvaluationSheet;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A origem de cada quantitativo da pauta: os instrumentos que entraram (ou
 * ficaram de fora) de cada domínio. Nada aqui calcula — o que se verifica é que
 * a lista reproduz o que o motor já tinha dito.
 */
class EvaluationSheetElementsTest extends TestCase
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

    /** @return array{SchoolClass, AcademicPeriod} */
    private function context(): array
    {
        return $this->asTenant(function (): array {
            $schoolClass = SchoolClass::where('label', '7.º A')->firstOrFail();

            return [$schoolClass, $schoolClass->academicYear->periods()->where('sequence', 1)->firstOrFail()];
        });
    }

    /** @return array<string, mixed> */
    private function sheet(bool $withElements = true): array
    {
        [$schoolClass, $period] = $this->context();

        return $this->asTenant(fn (): array => app(BuildEvaluationSheet::class)
            ->for($schoolClass, $period, withElements: $withElements));
    }

    /**
     * @param  array<string, mixed>  $sheet
     * @return array<string, mixed>
     */
    private function student(array $sheet, string $name): array
    {
        $student = collect($sheet['students'])->firstWhere('name', $name);
        $this->assertNotNull($student, "{$name} missing from the sheet");

        return $student;
    }

    #[Test]
    public function the_live_sheet_lists_the_instruments_behind_each_domain(): void
    {
        $sheet = $this->sheet();
        $carolina = $this->student($sheet, 'Carolina Nunes');

        $titles = $this->asTenant(fn (): array => Instrument::query()->pluck('title')->all());
        $counted = 0;

        foreach ($carolina['domains'] as $domain) {
            $this->assertIsArray($domain['elements']);

            foreach ($domain['elements'] as $element) {
                $this->assertContains($element['title'], $titles);
                $this->assertMatchesRegularExpression('/^\d{2}\/\d{2}\/\d{4}$/', $element['applied_on_label']);

                if ($element['counted']) {
                    $counted++;
                    $this->assertNotSame('', $element['title']);
                }
            }
        }

        $this->assertGreaterThan(0, $counted);

        foreach ($this->sheet(withElements: false)['students'] as $student) {
            foreach ($student['domains'] as $domain) {
                $this->assertArrayNotHasKey('elements', $domain);
            }
        }
    }

    #[Test]
    public function the_counted_elements_reproduce_the_domain_value(): void
    {
        $checked = 0;

        foreach ($this->sheet()['students'] as $student) {
            foreach ($student['domains'] as $domain) {
                if ($domain['normalized_value'] === null) {
                    continue;
                }

                $earned = '0';
                $possible = '0';
                $weights = '0';

                foreach ($domain['elements'] as $element) {
                    if (! $element['counted']) {
                        continue;
                    }

                    $earned = Bc::add($earned, $element['points_earned']);
                    $possible = Bc::add($possible, $element['points_possible']);
                    $weights = Bc::add($weights, $element['weight_percent'] ?? '0');
                }

                $this->assertSame(
                    $domain['normalized_value'],
                    Bc::truncate(Bc::mul(Bc::div($earned, $possible), '100'), 6),
                    "{$student['name']} / {$domain['name']}",
                );

                // A truncagem de cada fatia pode perder até 1e-6 por elemento;
                // a soma nunca passa de 100 e nunca fica a mais de 0,01 dele.
                $this->assertLessThanOrEqual(0, Bc::compare($weights, '100'));
                $this->assertGreaterThanOrEqual(0, Bc::compare($weights, '99.99'));
                $checked++;
            }
        }

        $this->assertGreaterThan(0, $checked);
    }

    #[Test]
    public function an_absence_appears_as_an_element_that_did_not_count(): void
    {
        $diogo = $this->student($this->sheet(), 'Diogo Ferreira');

        $absences = collect($diogo['domains'])
            ->flatMap(fn (array $domain): array => $domain['elements'])
            ->filter(fn (array $element): bool => $element['counted'] === false);

        $this->assertNotEmpty($absences);

        $absence = $absences->first(fn (array $element): bool => collect($element['reasons'])
            ->contains(fn (array $reason): bool => in_array($reason['reason'], ['absent', 'absent_justified'], true)));

        $this->assertNotNull($absence);
        $this->assertNull($absence['normalized_value']);
        $this->assertNull($absence['points_earned']);
        $this->assertGreaterThan(0, $absence['excluded_item_count']);
    }

    #[Test]
    public function a_kept_pauta_does_not_carry_the_elements(): void
    {
        [$schoolClass, $period] = $this->context();

        $export = $this->asTenant(fn () => app(CaptureEvaluationSheet::class)->capture(
            $schoolClass,
            $period,
            ClassificationScope::Period,
            'Final do 1.º Semestre',
            Carbon::now(),
            $this->teacher,
        ));

        $payload = $export->payload;
        $this->assertSame(CaptureEvaluationSheet::CURRENT_VERSION, $payload['version']);
        $this->assertNotEmpty($payload['students']);

        foreach ($payload['students'] as $student) {
            foreach ($student['domains'] as $domain) {
                $this->assertArrayNotHasKey('elements', $domain);
            }
        }
    }
}
