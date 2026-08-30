<?php

namespace Tests\Feature\Support;

use App\Actions\Support\ChangeSupportStatus;
use App\Actions\Support\OpenSupportRequest;
use App\Actions\Support\ReplyToSupportRequest;
use App\Models\SupportAuthorRole;
use App\Models\SupportCategory;
use App\Models\SupportRequest;
use App\Models\SupportRequestStatus;
use App\Models\SupportSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O ciclo de vida de um pedido: quem responde move a bola, e cada estado arruma
 * os seus carimbos.
 *
 * A linha condutora: **o relógio nunca corre por acidente**. `waiting_since`
 * existe só em `waiting_for_user`; `resolved_at` só em `resolved`; e uma
 * resposta de quem perguntou apaga os dois.
 */
class SupportRequestLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->travelTo(Carbon::parse('2026-09-01 10:00'));
    }

    /** @return array<string, string> */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'category' => SupportCategory::Access->value,
            'subject' => 'Não consigo entrar',
            'description' => 'A password de recuperação não chega ao meu email.',
        ], $overrides);
    }

    protected function openFor(User $user): SupportRequest
    {
        return app(OpenSupportRequest::class)->open($this->payload(), $user, $user->personalOrganization());
    }

    #[Test]
    public function a_new_request_starts_open_with_the_description_as_the_first_message(): void
    {
        $user = User::factory()->create();

        $pedido = $this->openFor($user);

        $this->assertSame(SupportRequestStatus::Open, $pedido->status);
        $this->assertSame(SupportSource::Authenticated, $pedido->source);
        // A identidade vem da conta, nunca do corpo do pedido.
        $this->assertSame($user->name, $pedido->requester_name);
        $this->assertSame($user->email, $pedido->requester_email);
        // Nenhum relógio começou.
        $this->assertNull($pedido->waiting_since);
        $this->assertNull($pedido->resolved_at);
        // `technical_code` nasce NULL: nada o infere.
        $this->assertNull($pedido->technical_code);
        $this->assertMatchesRegularExpression('/^SUP-[A-HJ-NP-Z2-9]{6}$/', $pedido->reference);

        $mensagem = $pedido->messages()->sole();
        $this->assertSame(SupportAuthorRole::Requester, $mensagem->author_role);
        $this->assertSame($pedido->description, $mensagem->body);
    }

    #[Test]
    public function an_operator_reply_starts_the_waiting_clock_and_a_requester_reply_stops_it(): void
    {
        $user = User::factory()->create();
        $operator = User::factory()->create();
        $pedido = $this->openFor($user);

        app(ReplyToSupportRequest::class)->fromOperator($pedido, $operator, 'Já verificámos.');
        $pedido->refresh();

        $this->assertSame(SupportRequestStatus::WaitingForUser, $pedido->status);
        $this->assertNotNull($pedido->waiting_since);

        $this->travel(2)->days();
        app(ReplyToSupportRequest::class)->fromRequester($pedido, $user, 'Continua igual.');
        $pedido->refresh();

        // A espera acabou porque teve resposta — e um ciclo futuro terá o seu
        // próprio lembrete.
        $this->assertSame(SupportRequestStatus::Open, $pedido->status);
        $this->assertNull($pedido->waiting_since);
        $this->assertNull($pedido->waiting_reminder_sent_at);
    }

    #[Test]
    public function every_state_carries_exactly_its_own_stamps(): void
    {
        $user = User::factory()->create();
        $operator = User::factory()->create();
        $pedido = $this->openFor($user);
        $service = app(ChangeSupportStatus::class);

        $service->to($pedido, SupportRequestStatus::InProgress, $operator);
        $pedido->refresh();
        $this->assertNull($pedido->waiting_since);
        $this->assertNull($pedido->resolved_at);

        $service->to($pedido, SupportRequestStatus::WaitingForUser, $operator);
        $pedido->refresh();
        $this->assertNotNull($pedido->waiting_since);
        $this->assertNull($pedido->resolved_at);

        $service->to($pedido, SupportRequestStatus::Resolved, $operator);
        $pedido->refresh();
        $this->assertNotNull($pedido->resolved_at);
        $this->assertSame($operator->getKey(), $pedido->resolved_by);
        // Resolvido por uma pessoa nunca é «resolvido pelo tempo».
        $this->assertFalse($pedido->auto_resolved);
        $this->assertNull($pedido->waiting_since);
    }

    #[Test]
    public function there_is_no_closed_state(): void
    {
        // Quatro casos, e nenhum a mais: dois estados finais que ninguém sabe
        // distinguir é a armadilha que o ADR-0011 §2 recusa.
        $this->assertSame(
            ['open', 'in_progress', 'waiting_for_user', 'resolved'],
            array_map(fn (SupportRequestStatus $s): string => $s->value, SupportRequestStatus::cases()),
        );
    }

    #[Test]
    public function a_guest_request_never_carries_an_account(): void
    {
        $pedido = app(OpenSupportRequest::class)->open($this->payload([
            'requester_name' => 'Maria',
            'requester_email' => 'maria@exemplo.pt',
        ]));

        $this->assertSame(SupportSource::Guest, $pedido->source);
        $this->assertNull($pedido->user_id);
        $this->assertNull($pedido->organization_id);

        // A regra que o MySQL não deixou gravar no esquema vive no modelo — e
        // vale para toda a escrita, não só para a que passa pela acção.
        $this->expectException(LogicException::class);
        $pedido->forceFill(['user_id' => User::factory()->create()->getKey()])->save();
    }

    #[Test]
    public function an_operator_message_always_has_an_operator_behind_it(): void
    {
        $user = User::factory()->create();
        $pedido = $this->openFor($user);

        $this->expectException(LogicException::class);
        $pedido->messages()->create([
            'author_role' => SupportAuthorRole::Operator,
            'author_user_id' => null,
            'body' => 'Uma resposta que ninguém assinou.',
        ]);
    }
}
