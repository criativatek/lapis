<?php

namespace Tests\Feature\Assessment;

use App\Domain\Assessment\Analysis\AnalysisBand;
use App\Domain\Assessment\Analysis\ScaleThreshold;
use App\Models\Scale;
use App\Models\User;
use App\Services\Assessment\DeriveCustomScale;
use App\Services\Assessment\ScaleProposalResolver;
use App\Services\Export\InovarCodeResolver;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A escala derivada É uma escala de níveis a sério: 2 a 10 níveis, cada um
 * com os mesmos campos da «Escala 1 a 5» do sistema. É isso que faz a pauta, as
 * fotografias e o Inovar funcionarem sem uma linha alterada.
 */
class DeriveCustomScaleTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    protected function inTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->user->personalOrganization(), $callback);
    }

    protected function system(): Scale
    {
        return Scale::withoutGlobalScope('scaleVisibility')->where('name', 'Escala 1 a 5')->with('levels')->firstOrFail();
    }

    /**
     * @return list<array{label: string, from_percent: int, inovar_code: string|null}>
     */
    protected function defaultLevels(): array
    {
        return [
            ['label' => 'Fraco', 'from_percent' => 0, 'inovar_code' => 'F'],
            ['label' => 'Insuficiente', 'from_percent' => 20, 'inovar_code' => 'I'],
            ['label' => 'Suficiente', 'from_percent' => 50, 'inovar_code' => 'S'],
            ['label' => 'Bom', 'from_percent' => 70, 'inovar_code' => 'B'],
            ['label' => 'Muito Bom', 'from_percent' => 90, 'inovar_code' => 'MB'],
        ];
    }

    /**
     * Quatro menções, como a escola do professor que originou o pedido.
     *
     * @return list<array{label: string, from_percent: int, inovar_code: string|null}>
     */
    protected function fourLevels(): array
    {
        return [
            ['label' => 'Insuficiente', 'from_percent' => 0, 'inovar_code' => 'I'],
            ['label' => 'Suficiente', 'from_percent' => 50, 'inovar_code' => 'S'],
            ['label' => 'Bom', 'from_percent' => 70, 'inovar_code' => 'B'],
            ['label' => 'Muito Bom', 'from_percent' => 90, 'inovar_code' => 'MB'],
        ];
    }

    /**
     * @return list<array{label: string, from_percent: int, inovar_code: string|null}>
     */
    protected function sevenLevels(): array
    {
        return [
            ['label' => 'Um', 'from_percent' => 0, 'inovar_code' => 'F'],
            ['label' => 'Dois', 'from_percent' => 10, 'inovar_code' => 'F'],
            ['label' => 'Três', 'from_percent' => 30, 'inovar_code' => 'I'],
            ['label' => 'Quatro', 'from_percent' => 50, 'inovar_code' => 'S'],
            ['label' => 'Cinco', 'from_percent' => 65, 'inovar_code' => 'B'],
            ['label' => 'Seis', 'from_percent' => 80, 'inovar_code' => 'B'],
            ['label' => 'Sete', 'from_percent' => 95, 'inovar_code' => null],
        ];
    }

    #[Test]
    public function the_default_levels_reproduce_the_system_scale_field_by_field(): void
    {
        $derived = $this->inTenant(fn () => app(DeriveCustomScale::class)->for($this->system(), $this->defaultLevels(), 3, 'Português'));

        $this->assertSame('Escala 1 a 5 · Português', $derived->name);
        $this->assertSame('level', $derived->kind);
        $this->assertSame($this->user->personalOrganization()->id, $derived->organization_id);
        $this->assertSame($this->system()->id, $derived->derived_from_scale_id);
        $this->assertNull($derived->frozen_at);
        $this->assertSame('1.000', (string) $derived->min_value);
        $this->assertSame('5.000', (string) $derived->max_value);

        $source = $this->system()->levels->values();
        $levels = $derived->levels->values();
        $this->assertCount(5, $levels);

        foreach ($levels as $index => $level) {
            $this->assertSame($source[$index]->code, $level->code);
            $this->assertSame($source[$index]->label, $level->label);
            $this->assertSame($source[$index]->inovar_code, $level->inovar_code);
            $this->assertSame($source[$index]->sequence, $level->sequence);
            $this->assertSame((string) $source[$index]->numeric_value, (string) $level->numeric_value);
            $this->assertSame($source[$index]->normalized_value, $level->normalized_value);
            $this->assertSame((string) $source[$index]->band_min_normalized, (string) $level->band_min_normalized);
            $this->assertSame((string) $source[$index]->band_max_normalized, (string) $level->band_max_normalized);
            $this->assertSame($source[$index]->is_negative, $level->is_negative);
        }

        // A origem não foi tocada.
        $this->assertSame('Fraco', $this->system()->levels->first()->label);
    }

    #[Test]
    public function a_scale_of_four_levels_is_created_with_its_own_name_range_and_sign(): void
    {
        $derived = $this->inTenant(fn () => app(DeriveCustomScale::class)->for($this->system(), $this->fourLevels(), 2, 'Matemática'));

        $this->assertSame('Escala 1 a 4 · Matemática', $derived->name);
        $this->assertSame('4.000', (string) $derived->max_value);
        $this->assertSame($this->system()->id, $derived->derived_from_scale_id);

        $levels = $derived->levels->values();
        $this->assertSame(['1', '2', '3', '4'], $levels->pluck('code')->all());
        $this->assertSame(['Insuficiente', 'Suficiente', 'Bom', 'Muito Bom'], $levels->pluck('label')->all());
        $this->assertSame([true, false, false, false], $levels->pluck('is_negative')->all());
        $this->assertSame(['0.000000', '49.500000', '69.500000', '89.500000'], $levels->map(fn ($level) => (string) $level->band_min_normalized)->all());
        $this->assertSame(['49.499999', '69.499999', '89.499999', '100.000000'], $levels->map(fn ($level) => (string) $level->band_max_normalized)->all());
        $this->assertSame([1, 2, 3, 4], $levels->pluck('sequence')->all());
        $this->assertSame(['1.000', '2.000', '3.000', '4.000'], $levels->map(fn ($level) => (string) $level->numeric_value)->all());
        $this->assertSame([null, null, null, null], $levels->pluck('normalized_value')->all());
    }

    #[Test]
    public function a_scale_of_seven_levels_keeps_repeated_and_missing_inovar_codes(): void
    {
        $derived = $this->inTenant(fn () => app(DeriveCustomScale::class)->for($this->system(), $this->sevenLevels(), 4, 'Inglês'));

        $this->assertSame('Escala 1 a 7 · Inglês', $derived->name);
        $this->assertSame(['F', 'F', 'I', 'S', 'B', 'B', null], $derived->levels->pluck('inovar_code')->all());
        $this->assertSame([true, true, true, false, false, false, false], $derived->levels->pluck('is_negative')->all());
    }

    #[Test]
    public function a_first_positive_level_of_one_means_no_negative_level_and_no_threshold(): void
    {
        $derived = $this->inTenant(fn () => app(DeriveCustomScale::class)->for($this->system(), $this->fourLevels(), 1, 'Sem negativa'));

        $this->assertSame([false, false, false, false], $derived->levels->pluck('is_negative')->all());
        $this->assertNull(ScaleThreshold::from($this->analysisBands($derived)));
    }

    #[Test]
    public function the_threshold_is_the_cut_the_teacher_chose(): void
    {
        $derived = $this->inTenant(fn () => app(DeriveCustomScale::class)->for($this->system(), $this->sevenLevels(), 5, 'Fronteira'));

        // Positiva a partir do nível 5 («a partir de 65 %»): a fronteira é 64,5.
        $this->assertSame('64.5', ScaleThreshold::from($this->analysisBands($derived)));
    }

    #[Test]
    public function a_proposal_falls_on_the_right_level_at_the_boundaries_of_a_four_level_scale(): void
    {
        $derived = $this->inTenant(fn () => app(DeriveCustomScale::class)->for($this->system(), $this->fourLevels(), 2, 'Fronteiras'));
        $resolver = app(ScaleProposalResolver::class);

        $this->assertSame('Insuficiente', $resolver->bandFor($derived, '49.4')->label);
        $this->assertSame('Insuficiente', $resolver->bandFor($derived, '49.499999')->label);
        $this->assertSame('Suficiente', $resolver->bandFor($derived, '49.5')->label);
        $this->assertSame('Muito Bom', $resolver->bandFor($derived, '89.5')->label);
        $this->assertSame('Muito Bom', $resolver->bandFor($derived, '100')->label);
        $this->assertSame('Insuficiente', $resolver->bandFor($derived, '0')->label);
    }

    #[Test]
    public function inovar_exports_with_repeated_codes_and_refuses_naming_the_level_that_has_none(): void
    {
        $complete = $this->sevenLevels();
        $complete[6]['inovar_code'] = 'MB';

        [$withAll, $withGap] = $this->inTenant(fn (): array => [
            app(DeriveCustomScale::class)->for($this->system(), $complete, 4, 'Completa'),
            app(DeriveCustomScale::class)->for($this->system(), $this->sevenLevels(), 4, 'Com falha'),
        ]);
        $resolver = app(InovarCodeResolver::class);

        $this->assertTrue($resolver->covers($withAll));
        $this->assertSame([], $resolver->missingFrom($withAll));
        $this->assertFalse($resolver->covers($withGap));
        $this->assertSame(['Sete'], $resolver->missingFrom($withGap));
    }

    #[Test]
    public function a_second_profile_with_the_same_name_gets_a_numbered_suffix(): void
    {
        $this->inTenant(function (): void {
            app(DeriveCustomScale::class)->for($this->system(), $this->defaultLevels(), 3, 'Português');
            $second = app(DeriveCustomScale::class)->for($this->system(), $this->defaultLevels(), 3, 'Português');

            $this->assertSame('Escala 1 a 5 · Português (2)', $second->name);
        });
    }

    #[Test]
    public function an_unfrozen_derived_scale_is_updated_in_place_and_can_shrink(): void
    {
        $this->inTenant(function (): void {
            $derived = app(DeriveCustomScale::class)->for($this->system(), $this->defaultLevels(), 3, 'Português');
            $again = app(DeriveCustomScale::class)->for($derived, $this->fourLevels(), 2, 'Português');

            $this->assertSame($derived->id, $again->id);
            $this->assertSame('Escala 1 a 4 · Português', $again->name);
            $this->assertSame('4.000', (string) $again->max_value);
            $this->assertSame(['Insuficiente', 'Suficiente', 'Bom', 'Muito Bom'], $again->levels->pluck('label')->all());
            $this->assertSame(['49.500000', '69.500000', '89.500000'], $again->levels->skip(1)->map(fn ($level) => (string) $level->band_min_normalized)->values()->all());
            $this->assertSame(4, $again->levels()->count());
            $this->assertSame(1, Scale::withoutGlobalScope('scaleVisibility')->whereNotNull('organization_id')->count());
        });
    }

    #[Test]
    public function an_unfrozen_derived_scale_can_grow(): void
    {
        $this->inTenant(function (): void {
            $derived = app(DeriveCustomScale::class)->for($this->system(), $this->fourLevels(), 2, 'Português');
            $again = app(DeriveCustomScale::class)->for($derived, $this->sevenLevels(), 4, 'Português');

            $this->assertSame($derived->id, $again->id);
            $this->assertSame('Escala 1 a 7 · Português', $again->name);
            $this->assertSame(7, $again->levels()->count());
            $this->assertSame('Sete', $again->levels->last()->label);
            $this->assertNull($again->levels->last()->inovar_code);
        });
    }

    #[Test]
    public function a_frozen_derived_scale_is_left_alone_and_a_new_one_is_derived_from_the_same_system_scale(): void
    {
        $this->inTenant(function (): void {
            $derived = app(DeriveCustomScale::class)->for($this->system(), $this->defaultLevels(), 3, 'Português');
            $derived->forceFill(['frozen_at' => Carbon::parse('2026-11-01 10:00:00')])->save();

            $fresh = app(DeriveCustomScale::class)->for($derived->refresh(), $this->fourLevels(), 2, 'Português');

            $this->assertNotSame($derived->id, $fresh->id);
            $this->assertSame($this->system()->id, $fresh->derived_from_scale_id);
            $this->assertSame('Escala 1 a 4 · Português', $fresh->name);
            $this->assertNull($fresh->frozen_at);
            $this->assertCount(4, $fresh->levels);
            // A congelada continua exatamente como estava.
            $this->assertSame('Escala 1 a 5 · Português', $derived->refresh()->name);
            $this->assertSame(['Fraco', 'Insuficiente', 'Suficiente', 'Bom', 'Muito Bom'], $derived->levels->pluck('label')->all());
            $this->assertSame('19.500000', (string) $derived->levels[1]->band_min_normalized);
        });
    }

    #[Test]
    public function refuses_an_interval_scale_and_a_level_scale_without_bands(): void
    {
        $this->inTenant(function (): void {
            $percentage = Scale::where('name', 'Percentagem (0 a 100)')->firstOrFail();

            try {
                app(DeriveCustomScale::class)->for($percentage, $this->defaultLevels(), 3, 'Português');
                $this->fail('Uma escala de intervalo não se personaliza.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('levels', $exception->errors());
            }

            $bare = Scale::create(['name' => 'Níveis sem bandas', 'kind' => 'level', 'min_value' => 1, 'max_value' => 3]);
            $bare->levels()->create(['code' => '1', 'label' => 'Um', 'sequence' => 1, 'is_negative' => true]);

            $this->expectException(ValidationException::class);
            app(DeriveCustomScale::class)->for($bare, $this->defaultLevels(), 3, 'Português');
        });
    }

    #[Test]
    public function the_service_refuses_what_would_leave_gaps_or_a_boundary_outside_the_scale(): void
    {
        $this->inTenant(function (): void {
            $service = app(DeriveCustomScale::class);
            $oneLevel = [['label' => 'Único', 'from_percent' => 0, 'inovar_code' => null]];
            $notFromZero = $this->fourLevels();
            $notFromZero[0]['from_percent'] = 10;
            $notIncreasing = $this->fourLevels();
            $notIncreasing[2]['from_percent'] = 50;

            foreach ([
                'levels' => [$oneLevel, 1],
                'levels (cortes sem 0)' => [$notFromZero, 2],
                'levels (cortes iguais)' => [$notIncreasing, 2],
                'first_positive_level (acima)' => [$this->fourLevels(), 5],
                'first_positive_level (abaixo)' => [$this->fourLevels(), 0],
            ] as $label => [$levels, $firstPositive]) {
                try {
                    $service->for($this->system(), $levels, $firstPositive, 'Inválida');
                    $this->fail("Devia recusar: {$label}");
                } catch (ValidationException) {
                    $this->addToAssertionCount(1);
                }
            }

            $this->assertSame(0, Scale::withoutGlobalScope('scaleVisibility')->whereNotNull('organization_id')->count());
        });
    }

    /**
     * @return list<AnalysisBand>
     */
    protected function analysisBands(Scale $scale): array
    {
        return $scale->levels->map(fn ($level): AnalysisBand => new AnalysisBand(
            $level->id,
            (string) $level->code,
            (string) $level->label,
            $level->sequence,
            $level->is_negative,
            (string) $level->band_min_normalized,
            (string) $level->band_max_normalized,
        ))->values()->all();
    }
}
