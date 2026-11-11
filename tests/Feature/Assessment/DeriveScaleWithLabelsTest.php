<?php

namespace Tests\Feature\Assessment;

use App\Models\Scale;
use App\Models\User;
use App\Services\Assessment\DeriveScaleWithLabels;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A escala derivada É um nível de escala a sério: copia tudo da origem e só
 * muda os nomes. É isso que faz a pauta, as fotografias e o Inovar funcionarem
 * sem uma linha alterada.
 */
class DeriveScaleWithLabelsTest extends TestCase
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

    /** @return list<string> */
    protected function schoolLabels(): array
    {
        return ['Muito insuficiente', 'Insuficiente', 'Suficiente', 'Bom', 'Muito bom'];
    }

    #[Test]
    public function copies_the_five_levels_field_by_field_and_changes_only_the_label(): void
    {
        $derived = $this->inTenant(fn () => app(DeriveScaleWithLabels::class)->for($this->system(), $this->schoolLabels(), 'Português'));

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
            $this->assertSame($source[$index]->inovar_code, $level->inovar_code);
            $this->assertSame($source[$index]->sequence, $level->sequence);
            $this->assertSame((string) $source[$index]->numeric_value, (string) $level->numeric_value);
            $this->assertSame((string) $source[$index]->band_min_normalized, (string) $level->band_min_normalized);
            $this->assertSame((string) $source[$index]->band_max_normalized, (string) $level->band_max_normalized);
            $this->assertSame($source[$index]->is_negative, $level->is_negative);
            $this->assertSame($this->schoolLabels()[$index], $level->label);
        }

        // A origem não foi tocada.
        $this->assertSame('Fraco', $this->system()->levels->first()->label);
    }

    #[Test]
    public function a_second_profile_with_the_same_name_gets_a_numbered_suffix(): void
    {
        $this->inTenant(function (): void {
            app(DeriveScaleWithLabels::class)->for($this->system(), $this->schoolLabels(), 'Português');
            $second = app(DeriveScaleWithLabels::class)->for($this->system(), ['F', 'I', 'S', 'B', 'MB'], 'Português');

            $this->assertSame('Escala 1 a 5 · Português (2)', $second->name);
        });
    }

    #[Test]
    public function an_unfrozen_derived_scale_is_updated_in_place(): void
    {
        $this->inTenant(function (): void {
            $derived = app(DeriveScaleWithLabels::class)->for($this->system(), $this->schoolLabels(), 'Português');
            $again = app(DeriveScaleWithLabels::class)->for($derived, ['Fraco', 'Insuficiente', 'Suficiente', 'Bom', 'Excelente'], 'Português');

            $this->assertSame($derived->id, $again->id);
            $this->assertSame('Excelente', $again->levels->last()->label);
            $this->assertSame(1, Scale::withoutGlobalScope('scaleVisibility')->whereNotNull('organization_id')->count());
        });
    }

    #[Test]
    public function a_frozen_derived_scale_is_left_alone_and_a_new_one_is_derived_from_the_same_system_scale(): void
    {
        $this->inTenant(function (): void {
            $derived = app(DeriveScaleWithLabels::class)->for($this->system(), $this->schoolLabels(), 'Português');
            $derived->forceFill(['frozen_at' => Carbon::parse('2026-11-01 10:00:00')])->save();

            $fresh = app(DeriveScaleWithLabels::class)->for($derived->refresh(), ['Fraco', 'Insuficiente', 'Suficiente', 'Bom', 'Excelente'], 'Português');

            $this->assertNotSame($derived->id, $fresh->id);
            $this->assertSame($this->system()->id, $fresh->derived_from_scale_id);
            $this->assertSame('Escala 1 a 5 · Português (2)', $fresh->name);
            $this->assertNull($fresh->frozen_at);
            $this->assertSame('Excelente', $fresh->levels->last()->label);
            // A congelada continua exatamente como estava.
            $this->assertSame('Muito bom', $derived->refresh()->levels->last()->label);
        });
    }

    #[Test]
    public function refuses_an_interval_scale_and_a_level_scale_without_bands(): void
    {
        $this->inTenant(function (): void {
            $percentage = Scale::where('name', 'Percentagem (0 a 100)')->firstOrFail();

            try {
                app(DeriveScaleWithLabels::class)->for($percentage, $this->schoolLabels(), 'Português');
                $this->fail('Uma escala de intervalo não tem menções para personalizar.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('labels', $exception->errors());
            }

            $bare = Scale::create(['name' => 'Níveis sem bandas', 'kind' => 'level', 'min_value' => 1, 'max_value' => 3]);
            $bare->levels()->create(['code' => '1', 'label' => 'Um', 'sequence' => 1, 'is_negative' => true]);

            $this->expectException(ValidationException::class);
            app(DeriveScaleWithLabels::class)->for($bare, $this->schoolLabels(), 'Português');
        });
    }
}
