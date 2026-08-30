<?php

namespace Tests\Feature\Support;

use App\Actions\Support\OpenSupportRequest;
use App\Models\Organization;
use App\Models\SupportCategory;
use App\Models\SupportRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * QUEM VÊ UM PEDIDO — a regra mais restritiva da aplicação.
 *
 * Todas as outras funcionalidades assentam no global scope de organização:
 * quem está dentro do inquilino vê. Aqui não: um pedido de suporte é de uma
 * PESSOA. Estes testes existem para que a diferença não se perca no dia em que
 * alguém achar que `organization_id` é tenancy — é a coluna que mais se parece
 * com isso e a que não autoriza nada.
 */
class SupportVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    protected function openFor(User $user, ?Organization $organization = null): SupportRequest
    {
        return app(OpenSupportRequest::class)->open([
            'category' => SupportCategory::Assessment->value,
            'subject' => 'Uma dúvida',
            'description' => 'O cálculo do domínio não bate certo.',
        ], $user, $organization ?? $user->personalOrganization());
    }

    #[Test]
    public function the_author_sees_and_answers_their_own_request(): void
    {
        $user = User::factory()->create();
        $pedido = $this->openFor($user);

        $this->actingAs($user)->get("/support/{$pedido->ulid}")->assertOk();
        $this->actingAs($user)->post("/support/{$pedido->ulid}/mensagens", [
            'body' => 'Mais um pormenor.',
        ])->assertRedirect();
    }

    #[Test]
    public function another_teacher_never_sees_it_even_from_the_same_institution(): void
    {
        // Uma organização institucional com dois membros: o autor e um colega.
        $owner = User::factory()->create();
        $organization = $owner->personalOrganization();

        $colega = User::factory()->create();
        $organization->members()->attach($colega, ['joined_at' => now()]);

        $pedido = $this->openFor($owner, $organization);

        // Mesma organização, mesmo contexto no pedido — e mesmo assim não vê.
        $this->assertSame($organization->getKey(), $pedido->organization_id);
        $this->actingAs($colega)->get("/support/{$pedido->ulid}")->assertForbidden();
        $this->actingAs($colega)->post("/support/{$pedido->ulid}/mensagens", [
            'body' => 'Deixem-me ver isto.',
        ])->assertForbidden();
    }

    #[Test]
    public function the_listing_only_ever_shows_your_own(): void
    {
        $user = User::factory()->create();
        $outro = User::factory()->create();

        $meu = $this->openFor($user);
        $alheio = $this->openFor($outro);

        $this->actingAs($user)->get('/support')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('support/Index')
                ->has('requests', 1)
                ->where('requests.0.reference', $meu->reference));

        $this->actingAs($user)->get("/support/{$alheio->ulid}")->assertForbidden();
    }

    #[Test]
    public function a_guest_has_no_way_in_at_all(): void
    {
        $pedido = app(OpenSupportRequest::class)->open([
            'requester_name' => 'Maria',
            'requester_email' => 'maria@exemplo.pt',
            'category' => SupportCategory::Access->value,
            'subject' => 'Não entro',
            'description' => 'A password não chega.',
        ]);

        // Sem sessão, o ecrã do pedido nem existe para ele: a rota é `auth`.
        $this->get("/support/{$pedido->ulid}")->assertRedirect('/login');

        // E não há rota nenhuma que aceite a referência — nem pública, nem
        // autenticada. `SUP-XXXXXX` é um número de protocolo (ADR-0011 §3).
        $this->get('/contacto/'.$pedido->reference)->assertNotFound();
        $this->get('/support/'.$pedido->reference)->assertRedirect('/login');
    }

    #[Test]
    public function a_signed_in_user_cannot_claim_a_guest_request_by_owning_its_email(): void
    {
        $pedido = app(OpenSupportRequest::class)->open([
            'requester_name' => 'Maria',
            'requester_email' => 'maria@exemplo.pt',
            'category' => SupportCategory::Access->value,
            'subject' => 'Não entro',
            'description' => 'A password não chega.',
        ]);

        // A pessoa cria conta com o MESMO email. O pedido continua a não ser
        // dela: provar que se tem o endereço não é provar que se escreveu.
        $maria = User::factory()->create(['email' => 'maria@exemplo.pt']);

        $this->actingAs($maria)->get("/support/{$pedido->ulid}")->assertForbidden();
        $this->actingAs($maria)->get('/support')
            ->assertInertia(fn ($page) => $page->has('requests', 0));
    }
}
