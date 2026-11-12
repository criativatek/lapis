<?php

namespace Tests\Feature\Assessment;

use App\Models\AuditEvent;
use App\Models\Scale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O endereço de «Personalizar escala». O que se prova: cria a escala derivada
 * da organização com os níveis do pedido, diz qual, regista o rasto, recusa o
 * que não é uma lista válida de 2 a 10 níveis e não deixa uma organização tocar
 * na escala de outra.
 */
class ScaleMentionsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    protected function systemScale(): Scale
    {
        return Scale::withoutGlobalScope('scaleVisibility')->where('name', 'Escala 1 a 5')->firstOrFail();
    }

    protected function url(?Scale $scale = null): string
    {
        return '/scales/'.($scale ?? $this->systemScale())->ulid.'/mencoes';
    }

    /**
     * @return list<array{label: string, from_percent: int, inovar_code: string|null}>
     */
    protected function fiveLevels(): array
    {
        return [
            ['label' => 'Muito insuficiente', 'from_percent' => 0, 'inovar_code' => 'F'],
            ['label' => 'Insuficiente', 'from_percent' => 20, 'inovar_code' => 'I'],
            ['label' => 'Suficiente', 'from_percent' => 50, 'inovar_code' => 'S'],
            ['label' => 'Bom', 'from_percent' => 70, 'inovar_code' => 'B'],
            ['label' => 'Muito bom', 'from_percent' => 90, 'inovar_code' => 'MB'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'levels' => $this->fiveLevels(),
            'first_positive_level' => 3,
            'profile_name' => 'Português',
            'scale_name' => 'Escala 1 a 5 · Português',
        ], $overrides);
    }

    #[Test]
    public function creates_a_derived_scale_for_the_organization_and_says_which(): void
    {
        $response = $this->actingAs($this->user)->post($this->url(), $this->payload());

        $response->assertRedirect();

        $derived = Scale::withoutGlobalScope('scaleVisibility')->where('name', 'Escala 1 a 5 · Português')->firstOrFail();
        $this->assertSame($this->user->personalOrganization()->id, $derived->organization_id);
        $this->assertSame($this->systemScale()->id, $derived->derived_from_scale_id);
        $this->assertSame('Muito insuficiente', $derived->levels()->orderBy('sequence')->first()->label);
        $response->assertSessionHas('derivedScaleId', $derived->id);
    }

    #[Test]
    public function creates_a_scale_of_four_and_of_seven_levels(): void
    {
        $four = [
            ['label' => 'Insuficiente', 'from_percent' => 0, 'inovar_code' => 'I'],
            ['label' => 'Suficiente', 'from_percent' => 50, 'inovar_code' => 'S'],
            ['label' => 'Bom', 'from_percent' => 70, 'inovar_code' => 'B'],
            ['label' => 'Muito Bom', 'from_percent' => 90, 'inovar_code' => 'MB'],
        ];
        $seven = [
            ['label' => 'A', 'from_percent' => 0, 'inovar_code' => null],
            ['label' => 'B', 'from_percent' => 10, 'inovar_code' => null],
            ['label' => 'C', 'from_percent' => 25, 'inovar_code' => null],
            ['label' => 'D', 'from_percent' => 50, 'inovar_code' => 'S'],
            ['label' => 'E', 'from_percent' => 60, 'inovar_code' => 'B'],
            ['label' => 'F', 'from_percent' => 80, 'inovar_code' => 'B'],
            ['label' => 'G', 'from_percent' => 100, 'inovar_code' => 'MB'],
        ];

        $this->actingAs($this->user)->post($this->url(), $this->payload(['levels' => $four, 'first_positive_level' => 2, 'profile_name' => 'Quatro', 'scale_name' => 'Escala 1 a 4 · Quatro']))->assertSessionHasNoErrors();
        $this->actingAs($this->user)->post($this->url(), $this->payload(['levels' => $seven, 'first_positive_level' => 4, 'profile_name' => 'Sete', 'scale_name' => 'Escala 1 a 7 · Sete']))->assertSessionHasNoErrors();

        $fourScale = Scale::withoutGlobalScope('scaleVisibility')->where('name', 'Escala 1 a 4 · Quatro')->firstOrFail();
        $sevenScale = Scale::withoutGlobalScope('scaleVisibility')->where('name', 'Escala 1 a 7 · Sete')->firstOrFail();
        $this->assertCount(4, $fourScale->levels);
        $this->assertCount(7, $sevenScale->levels);
        $this->assertSame('99.500000', (string) $sevenScale->levels->last()->band_min_normalized);
        $this->assertSame('100.000000', (string) $sevenScale->levels->last()->band_max_normalized);
    }

    #[Test]
    public function records_an_audit_event_with_the_levels_and_the_boundary_and_no_student_data(): void
    {
        $this->actingAs($this->user)->post($this->url(), $this->payload());

        $event = AuditEvent::withoutGlobalScopes()->where('event', 'scale.mentions_customised')->latest('id')->first();
        $this->assertNotNull($event);
        $this->assertSame($this->fiveLevels(), $event->properties['levels']);
        $this->assertSame(3, $event->properties['first_positive_level']);
        $this->assertSame($this->systemScale()->id, $event->properties['derived_from_scale_id']);
    }

    #[Test]
    public function rejects_fewer_than_two_and_more_than_ten_levels(): void
    {
        $oneLevel = [['label' => 'Único', 'from_percent' => 0, 'inovar_code' => null]];
        $eleven = array_map(
            fn (int $index): array => ['label' => "Nível {$index}", 'from_percent' => $index * 9, 'inovar_code' => null],
            range(0, 10),
        );

        $this->actingAs($this->user)->post($this->url(), $this->payload(['levels' => $oneLevel, 'first_positive_level' => 1]))->assertSessionHasErrors('levels');
        $this->actingAs($this->user)->post($this->url(), $this->payload(['levels' => $eleven, 'first_positive_level' => 5]))->assertSessionHasErrors('levels');
        $this->actingAs($this->user)->post($this->url(), $this->payload(['levels' => []]))->assertSessionHasErrors('levels');
    }

    #[Test]
    public function rejects_repeated_names_ignoring_case_and_the_edges_and_names_that_are_empty_or_too_long(): void
    {
        $levels = $this->fiveLevels();
        $levels[3]['label'] = ' MUITO bom ';
        $this->actingAs($this->user)->post($this->url(), $this->payload(['levels' => $levels]))->assertSessionHasErrors('levels.4.label');

        $levels = $this->fiveLevels();
        $levels[0]['label'] = str_repeat('x', 65);
        $this->actingAs($this->user)->post($this->url(), $this->payload(['levels' => $levels]))->assertSessionHasErrors('levels.0.label');

        $levels = $this->fiveLevels();
        $levels[0]['label'] = '';
        $this->actingAs($this->user)->post($this->url(), $this->payload(['levels' => $levels]))->assertSessionHasErrors('levels.0.label');
    }

    #[Test]
    public function rejects_cuts_that_do_not_start_at_zero_do_not_grow_or_are_not_whole_numbers_within_range(): void
    {
        $levels = $this->fiveLevels();
        $levels[0]['from_percent'] = 5;
        $this->actingAs($this->user)->post($this->url(), $this->payload(['levels' => $levels]))->assertSessionHasErrors('levels.0.from_percent');

        $levels = $this->fiveLevels();
        $levels[3]['from_percent'] = 50;
        $this->actingAs($this->user)->post($this->url(), $this->payload(['levels' => $levels]))->assertSessionHasErrors('levels.3.from_percent');

        $levels = $this->fiveLevels();
        $levels[2]['from_percent'] = 10;
        $this->actingAs($this->user)->post($this->url(), $this->payload(['levels' => $levels]))->assertSessionHasErrors('levels.2.from_percent');

        $levels = $this->fiveLevels();
        $levels[2]['from_percent'] = 50.5;
        $this->actingAs($this->user)->post($this->url(), $this->payload(['levels' => $levels]))->assertSessionHasErrors('levels.2.from_percent');

        $levels = $this->fiveLevels();
        $levels[4]['from_percent'] = 101;
        $this->actingAs($this->user)->post($this->url(), $this->payload(['levels' => $levels]))->assertSessionHasErrors('levels.4.from_percent');

        $levels = $this->fiveLevels();
        $levels[4]['from_percent'] = 100;
        $this->actingAs($this->user)->post($this->url(), $this->payload(['levels' => $levels]))->assertSessionHasNoErrors();
    }

    #[Test]
    public function accepts_the_codes_the_school_defined_and_rejects_a_long_code_or_one_with_a_space(): void
    {
        $levels = $this->fiveLevels();
        $levels[0]['inovar_code'] = 'NS';
        $levels[1]['inovar_code'] = 'SMB';
        $levels[2]['inovar_code'] = null;
        $this->actingAs($this->user)->post($this->url(), $this->payload(['levels' => $levels]))->assertSessionHasNoErrors();

        $levels = $this->fiveLevels();
        $levels[1]['inovar_code'] = 'ABCDEFGHI';
        $this->actingAs($this->user)->post($this->url(), $this->payload(['levels' => $levels]))->assertSessionHasErrors('levels.1.inovar_code');

        $levels = $this->fiveLevels();
        $levels[1]['inovar_code'] = 'A B';
        $this->actingAs($this->user)->post($this->url(), $this->payload(['levels' => $levels]))->assertSessionHasErrors('levels.1.inovar_code');

        $levels = $this->fiveLevels();
        $levels[1]['inovar_code'] = 'ABCDEFGH';
        $this->actingAs($this->user)->post($this->url(), $this->payload(['levels' => $levels, 'scale_name' => 'Outra']))->assertSessionHasNoErrors();
    }

    #[Test]
    public function rejects_a_first_positive_level_outside_the_scale(): void
    {
        $this->actingAs($this->user)->post($this->url(), $this->payload(['first_positive_level' => 6]))->assertSessionHasErrors('first_positive_level');
        $this->actingAs($this->user)->post($this->url(), $this->payload(['first_positive_level' => 0]))->assertSessionHasErrors('first_positive_level');
        $this->actingAs($this->user)->post($this->url(), $this->payload(['first_positive_level' => null]))->assertSessionHasErrors('first_positive_level');
        $this->actingAs($this->user)->post($this->url(), $this->payload(['first_positive_level' => 1]))->assertSessionHasNoErrors();
    }

    #[Test]
    public function the_old_labels_body_is_no_longer_accepted(): void
    {
        $this->actingAs($this->user)
            ->post($this->url(), ['labels' => ['A', 'B', 'C', 'D', 'E'], 'profile_name' => 'Português', 'scale_name' => 'X'])
            ->assertSessionHasErrors('levels');
    }

    #[Test]
    public function rejects_an_interval_scale(): void
    {
        $percentage = Scale::withoutGlobalScope('scaleVisibility')->where('name', 'Percentagem (0 a 100)')->firstOrFail();

        $this->actingAs($this->user)->post($this->url($percentage), $this->payload())->assertSessionHasErrors('levels');
    }

    #[Test]
    public function an_organization_cannot_customise_the_scale_of_another(): void
    {
        $this->actingAs($this->user)->post($this->url(), $this->payload())->assertSessionHasNoErrors();
        $derived = Scale::withoutGlobalScope('scaleVisibility')->where('name', 'Escala 1 a 5 · Português')->firstOrFail();

        $stranger = User::factory()->create();

        $this->actingAs($stranger)->post($this->url($derived), $this->payload(['profile_name' => 'Intruso']))->assertNotFound();
        $this->assertSame('Muito insuficiente', $derived->levels()->orderBy('sequence')->first()->label);
        $this->assertSame(1, Scale::withoutGlobalScope('scaleVisibility')->whereNotNull('organization_id')->count());
    }

    #[Test]
    public function a_guest_is_sent_to_login(): void
    {
        $this->post($this->url(), $this->payload())->assertRedirect('/login');
    }
}
