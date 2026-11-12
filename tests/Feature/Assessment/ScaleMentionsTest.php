<?php

namespace Tests\Feature\Assessment;

use App\Models\AuditEvent;
use App\Models\Scale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O endereço de «Personalizar menções». O que se prova: cria a escala derivada
 * da organização, diz qual, regista o rasto, e recusa o que não é uma lista
 * de cinco menções distintas sobre uma escala de níveis.
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

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'labels' => ['Muito insuficiente', 'Insuficiente', 'Suficiente', 'Bom', 'Muito bom'],
            'profile_name' => 'Português',
        ], $overrides);
    }

    #[Test]
    public function creates_a_derived_scale_for_the_organization_and_says_which(): void
    {
        $response = $this->actingAs($this->user)
            ->post("/scales/{$this->systemScale()->ulid}/mencoes", $this->payload());

        $response->assertRedirect();

        $derived = Scale::withoutGlobalScope('scaleVisibility')->where('name', 'Escala 1 a 5 · Português')->firstOrFail();
        $this->assertSame($this->user->personalOrganization()->id, $derived->organization_id);
        $this->assertSame($this->systemScale()->id, $derived->derived_from_scale_id);
        $this->assertSame('Muito insuficiente', $derived->levels()->orderBy('sequence')->first()->label);
        $response->assertSessionHas('derivedScaleId', $derived->id);
    }

    #[Test]
    public function records_an_audit_event_with_the_names_and_no_student_data(): void
    {
        $this->actingAs($this->user)->post("/scales/{$this->systemScale()->ulid}/mencoes", $this->payload());

        $event = AuditEvent::withoutGlobalScopes()->where('event', 'scale.mentions_customised')->latest('id')->first();
        $this->assertNotNull($event);
        $this->assertSame(['Muito insuficiente', 'Insuficiente', 'Suficiente', 'Bom', 'Muito bom'], $event->properties['labels']);
        $this->assertSame($this->systemScale()->id, $event->properties['derived_from_scale_id']);
    }

    #[Test]
    public function rejects_fewer_than_five_names_repeated_names_and_names_too_long(): void
    {
        $url = "/scales/{$this->systemScale()->ulid}/mencoes";

        $this->actingAs($this->user)->post($url, $this->payload(['labels' => ['A', 'B', 'C', 'D']]))->assertSessionHasErrors('labels');
        $this->actingAs($this->user)->post($url, $this->payload(['labels' => ['Bom', 'Insuficiente', 'Suficiente', ' bom ', 'Muito bom']]))->assertSessionHasErrors('labels');
        $this->actingAs($this->user)->post($url, $this->payload(['labels' => [str_repeat('x', 65), 'I', 'S', 'B', 'MB']]))->assertSessionHasErrors('labels.0');
        $this->actingAs($this->user)->post($url, $this->payload(['labels' => ['', 'I', 'S', 'B', 'MB']]))->assertSessionHasErrors('labels.0');
    }

    #[Test]
    public function rejects_an_interval_scale(): void
    {
        $percentage = Scale::withoutGlobalScope('scaleVisibility')->where('name', 'Percentagem (0 a 100)')->firstOrFail();

        $this->actingAs($this->user)->post("/scales/{$percentage->ulid}/mencoes", $this->payload())->assertSessionHasErrors('labels');
    }

    #[Test]
    public function a_guest_is_sent_to_login(): void
    {
        $this->post("/scales/{$this->systemScale()->ulid}/mencoes", $this->payload())->assertRedirect('/login');
    }
}
