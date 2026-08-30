<?php

namespace Tests\Feature\Support;

use App\Mail\SupportNotificationMail;
use App\Models\SupportCategory;
use App\Models\SupportRequest;
use App\Models\SupportSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «Fale connosco» — quem não tem conta, ou não consegue entrar nela.
 *
 * A linha condutora: **cria e mais nada**. Depois de enviar, a pessoa fica com
 * uma referência e um email — e nenhuma porta para voltar a entrar.
 */
class GuestSupportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->withoutVite();
    }

    /** @return array<string, string> */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'requester_name' => 'Maria Antunes',
            'requester_email' => 'maria@exemplo.pt',
            'category' => SupportCategory::Access->value,
            'subject' => 'Não consigo entrar',
            'description' => 'O email de recuperação não chega.',
        ], $overrides);
    }

    #[Test]
    public function the_page_is_public_and_warns_about_personal_data(): void
    {
        $this->get('/contacto')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('marketing/Contacto')
                ->has('categories', 7));
    }

    #[Test]
    public function a_visitor_opens_a_request_and_gets_a_reference(): void
    {
        $this->post('/contacto', $this->payload())
            ->assertRedirect()
            ->assertSessionHas('supportReference');

        $pedido = SupportRequest::query()->sole();

        $this->assertSame(SupportSource::Guest, $pedido->source);
        $this->assertNull($pedido->user_id);
        $this->assertNull($pedido->organization_id);
        $this->assertSame('maria@exemplo.pt', $pedido->requester_email);
        $this->assertMatchesRegularExpression('/^SUP-[A-HJ-NP-Z2-9]{6}$/', $pedido->reference);
    }

    #[Test]
    public function neither_the_ip_nor_the_user_agent_is_ever_stored(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->post('/contacto', $this->payload(), ['User-Agent' => 'SentinelaUA/1.0'])
            ->assertRedirect();

        $linha = json_encode(SupportRequest::query()->sole()->toArray(), JSON_UNESCAPED_UNICODE) ?: '';

        // O IP serve ao limitador da rota e morre aí. Guardá-lo faria deste
        // formulário um registo de visitas.
        $this->assertStringNotContainsString('198.51.100.7', $linha);
        $this->assertStringNotContainsString('SentinelaUA', $linha);
    }

    #[Test]
    public function the_form_refuses_what_it_needs_to_answer(): void
    {
        $this->post('/contacto', $this->payload(['requester_email' => '']))
            ->assertSessionHasErrors('requester_email');

        $this->post('/contacto', $this->payload(['category' => 'inventada']))
            ->assertSessionHasErrors('category');

        $this->assertSame(0, SupportRequest::query()->count());
    }

    #[Test]
    public function the_guest_email_carries_no_link_to_any_portal(): void
    {
        $this->post('/contacto', $this->payload())->assertRedirect();

        Mail::assertSent(SupportNotificationMail::class, function (SupportNotificationMail $mail): bool {
            if ($mail->type->recipientRole()->value !== 'requester') {
                return false;
            }

            $rendered = $mail->build()->render();

            // Sem CTA: um visitante não tem para onde ir, e mandá-lo para um
            // login que não lhe serve seria pior do que não o mandar a lado
            // nenhum (ADR-0011 §3).
            $this->assertStringNotContainsString('Ver pedido', $rendered);
            $this->assertStringNotContainsString('/support/', $rendered);
            // E diz a verdade sobre responder por email.
            $this->assertStringContainsString('não fica guardada no histórico', $rendered);

            return true;
        });
    }

    #[Test]
    public function the_route_is_throttled_against_flooding(): void
    {
        RateLimiter::clear('');

        foreach (range(1, 5) as $i) {
            $this->post('/contacto', $this->payload(['subject' => 'Pedido '.$i]))->assertRedirect();
        }

        $this->post('/contacto', $this->payload(['subject' => 'Pedido 6']))->assertStatus(429);
    }
}
