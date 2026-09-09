<?php

namespace Tests\Feature\Support;

use App\Actions\Support\ChangeSupportStatus;
use App\Actions\Support\OpenSupportRequest;
use App\Models\SupportCategory;
use App\Models\SupportRequest;
use App\Models\SupportRequestStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A contagem que acende a faixa «o suporte respondeu-lhe».
 *
 * É partilhada em TODAS as páginas autenticadas, por isso o que aqui se fixa
 * é sobretudo o que ela NÃO pode fazer: contar pedidos de outra pessoa. Um
 * pedido de suporte é de quem o escreveu (ADR-0011) — nem colegas, nem a
 * organização — e uma contagem que somasse os dos outros seria a primeira
 * fuga entre titulares deste domínio, escondida num inteiro.
 */
class SupportReplyBannerTest extends TestCase
{
    use RefreshDatabase;

    private ?User $operator = null;

    private function requestFor(User $user, SupportRequestStatus $status): SupportRequest
    {
        $request = app(OpenSupportRequest::class)->open([
            'category' => SupportCategory::Other->value,
            'subject' => 'Pedido de teste',
            'description' => 'Conteúdo de teste.',
        ], $user, $user->personalOrganization());

        // O estado chega pela acção do domínio, e não por um `forceFill` que o
        // teste escrevesse à mão: `waiting_for_user` sem `waiting_since` é
        // recusado por uma CHECK constraint, e `resolved` sem `resolved_at`
        // também. Escrever só a coluna passava em SQLite, que ignora CHECKs, e
        // falhava em MySQL — que é onde a CI corre e onde está a produção.
        app(ChangeSupportStatus::class)->to($request, $status, $this->operator());

        return $request->refresh();
    }

    /** Quem muda o estado de um pedido é sempre um operador. Aqui basta que exista. */
    private function operator(): User
    {
        return $this->operator ??= User::factory()->create();
    }

    #[Test]
    public function counts_only_own_requests_waiting_for_the_user(): void
    {
        $user = User::factory()->create();
        $colleague = User::factory()->create();

        $this->requestFor($user, SupportRequestStatus::WaitingForUser);
        $this->requestFor($user, SupportRequestStatus::Open);
        $this->requestFor($user, SupportRequestStatus::Resolved);
        // A do colega NUNCA entra na soma — nem com o estado certo.
        $this->requestFor($colleague, SupportRequestStatus::WaitingForUser);

        $this->actingAs($user)->get('/dashboard')->assertInertia(
            fn (AssertableInertia $page) => $page->where('supportAwaitingReply', 1),
        );
    }

    #[Test]
    public function a_user_with_nothing_waiting_gets_zero(): void
    {
        $user = User::factory()->create();

        $this->requestFor($user, SupportRequestStatus::InProgress);

        $this->actingAs($user)->get('/dashboard')->assertInertia(
            fn (AssertableInertia $page) => $page->where('supportAwaitingReply', 0),
        );
    }
}
