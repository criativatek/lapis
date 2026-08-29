<?php

namespace Tests\Feature\Export;

use App\Models\Plan;
use App\Models\Scale;
use App\Models\User;
use App\Services\Export\InovarCodeResolver;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\EntitlementsSeeder;
use Database\Seeders\SystemScalesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What INOVAR calls a band of a scale.
 *
 * Stated on the band, never inferred from it. The scale in use here names its
 * bands «1»…«5» and labels them in Portuguese, and neither of those is an
 * INOVAR code — reading either as one would be deciding, in silence, something
 * nobody configured.
 */
class InovarCodeResolverTest extends TestCase
{
    use RefreshDatabase;

    private function resolver(): InovarCodeResolver
    {
        return app(InovarCodeResolver::class);
    }

    private function systemScale(string $name): Scale
    {
        $this->seed(SystemScalesSeeder::class);

        return Scale::withoutGlobalScopes()->where('name', $name)->with('levels')->firstOrFail();
    }

    // -------------------------------------------------- 1. as cinco menções

    #[Test]
    public function the_standard_portuguese_scale_writes_the_five_codes_inovar_expects(): void
    {
        $scale = $this->systemScale('Escala 1 a 5');
        $resolver = $this->resolver();

        $byLabel = [];

        foreach ($scale->levels as $level) {
            $byLabel[$level->label] = $resolver->forLevel($level);
        }

        $this->assertSame([
            'Fraco' => 'F',
            'Insuficiente' => 'I',
            'Suficiente' => 'S',
            'Bom' => 'B',
            'Muito Bom' => 'MB',
        ], $byLabel);

        $this->assertTrue($resolver->covers($scale));
        $this->assertSame([], $resolver->missingFrom($scale));
    }

    #[Test]
    public function the_code_is_read_from_the_band_and_never_from_its_name_or_its_label(): void
    {
        $scale = $this->systemScale('Escala 1 a 5');
        $level = $scale->levels->firstWhere('label', 'Bom');

        // The band is called «4» on this scale and «Bom» to a teacher. Neither
        // is what INOVAR is told.
        $this->assertSame('4', $level->code);
        $this->assertSame('B', $this->resolver()->forLevel($level));

        // Rename it and the code does not move: the correspondence is stated,
        // not read out of the words.
        $level->update(['label' => 'Satisfatório']);
        $this->assertSame('B', $this->resolver()->forLevel($level->fresh()));

        $source = (string) file_get_contents(app_path('Services/Export/InovarCodeResolver.php'));
        $this->assertStringNotContainsString("=== 'Bom'", $source);
        $this->assertStringNotContainsString('$level->label', explode('missingFrom', $source)[0]);
        $this->assertStringNotContainsString('$level->code', $source);
    }

    // ------------------------------------------ 2. o que não é exportável

    #[Test]
    public function a_scale_without_the_correspondence_is_refused_rather_than_guessed(): void
    {
        $scale = $this->systemScale('Escala 1 a 5');

        // A scale a school built for itself has no INOVAR correspondence until
        // somebody states one, and inventing it is worse than refusing.
        $scale->levels->firstWhere('label', 'Suficiente')->update(['inovar_code' => null]);
        $scale->unsetRelation('levels');

        $this->assertFalse($this->resolver()->covers($scale));
        $this->assertSame(['Suficiente'], $this->resolver()->missingFrom($scale));
    }

    #[Test]
    public function a_code_outside_the_five_this_integration_writes_is_not_passed_through(): void
    {
        $scale = $this->systemScale('Escala 1 a 5');
        $level = $scale->levels->firstWhere('label', 'Bom');

        // INOVAR would refuse the file and the teacher would find out there
        // instead of here.
        $level->update(['inovar_code' => 'X']);

        $this->assertNull($this->resolver()->forLevel($level->fresh()));
    }

    #[Test]
    public function a_scale_with_no_bands_at_all_exports_nothing(): void
    {
        // The 0–20 has no qualitative bands of its own, so there is nothing to
        // write a mention from in the first place.
        $this->assertFalse($this->resolver()->covers($this->systemScale('Escala 0 a 20')));
        $this->assertFalse($this->resolver()->covers(null));
        $this->assertNull($this->resolver()->forLevel(null));
    }

    // ---------------------------------------------------- 3. quem pode usar

    #[Test]
    public function the_export_is_a_pro_capability_and_the_base_plan_does_not_have_it(): void
    {
        $this->seed(EntitlementsSeeder::class);

        $modulesOf = fn (string $planKey): array => Plan::where('key', $planKey)
            ->firstOrFail()->currentVersionOrFail()->modules()->pluck('key')->all();

        $this->assertNotContains('inovar_export', $modulesOf('base'));
        $this->assertContains('inovar_export', $modulesOf('pro'));
        $this->assertContains('inovar_export', $modulesOf('institutional'));
    }

    #[Test]
    public function the_capability_is_persisted_reference_data_and_not_a_constant_in_a_controller(): void
    {
        $this->seed(EntitlementsSeeder::class);

        $teacher = User::factory()->create();

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function (): void {
            // Named in the modules table like every other gateable capability,
            // so a plan change is data and not a deployment (§8.2).
            $this->assertDatabaseHas('modules', ['key' => 'inovar_export']);
        });
    }
}
