<?php

namespace Tests\Feature\Support;

use App\Actions\Support\OpenSupportRequest;
use App\Models\SupportCategory;
use App\Models\SupportNotificationDelivery;
use App\Models\SupportRequest;
use App\Models\SupportRequestStatus;
use App\Models\SupportTechnicalCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O backoffice: a fila, as decisões e o reenvio.
 *
 * A fronteira de acesso é o middleware `platform-admin` — e é a única. A
 * primeira coisa que estes testes fixam é isso: um professor com sessão
 * iniciada não chega aqui por caminho nenhum, nem sequer ao seu próprio pedido.
 */
class AdminSupportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->withoutVite();
    }

    protected function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        return $admin;
    }

    protected function request(?User $user = null): SupportRequest
    {
        $user ??= User::factory()->create();

        return app(OpenSupportRequest::class)->open([
            'category' => SupportCategory::Imports->value,
            'subject' => 'A pauta não importa',
            'description' => 'O ficheiro dá erro.',
        ], $user, $user->personalOrganization());
    }

    #[Test]
    public function only_platform_admins_get_in(): void
    {
        $teacher = User::factory()->create();
        $pedido = $this->request($teacher);

        // Nem sequer ao pedido que é dele: aqui a porta é outra.
        $this->actingAs($teacher)->get('/admin/support')->assertForbidden();
        $this->actingAs($teacher)->get("/admin/support/{$pedido->ulid}")->assertForbidden();
        $this->actingAs($teacher)->post("/admin/support/{$pedido->ulid}/reply", ['body' => 'x'])->assertForbidden();
        $this->actingAs($teacher)->post("/admin/support/{$pedido->ulid}/hold", [
            'reason_code' => 'legal_dispute',
        ])->assertForbidden();
    }

    #[Test]
    public function the_queue_puts_the_work_first(): void
    {
        $admin = $this->admin();
        $this->request();

        $this->actingAs($admin)->get('/admin/support')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/Support')
                ->has('requests.data', 1)
                ->has('options.statuses', 4)
                ->has('options.categories', 7)
                ->has('options.technicalCodes', 9));
    }

    #[Test]
    public function an_operator_answers_classifies_and_resolves(): void
    {
        $admin = $this->admin();
        $pedido = $this->request();

        $this->actingAs($admin)->post("/admin/support/{$pedido->ulid}/reply", [
            'body' => 'Pode reenviar o ficheiro?',
        ])->assertRedirect();
        $this->assertSame(SupportRequestStatus::WaitingForUser, $pedido->fresh()->status);
        $this->assertNotNull($pedido->fresh()->waiting_since);

        $this->actingAs($admin)->post("/admin/support/{$pedido->ulid}/classify", [
            'technical_code' => SupportTechnicalCode::ImportParse->value,
        ])->assertRedirect();
        $this->assertSame(SupportTechnicalCode::ImportParse, $pedido->fresh()->technical_code);

        $this->actingAs($admin)->post("/admin/support/{$pedido->ulid}/status", [
            'status' => SupportRequestStatus::Resolved->value,
        ])->assertRedirect();
        $this->assertNotNull($pedido->fresh()->resolved_at);
        $this->assertSame($admin->getKey(), $pedido->fresh()->resolved_by);
    }

    #[Test]
    public function a_hold_demands_a_classified_reason(): void
    {
        $admin = $this->admin();
        $pedido = $this->request();

        $this->actingAs($admin)->post("/admin/support/{$pedido->ulid}/hold", [])
            ->assertSessionHasErrors('reason_code');

        $this->actingAs($admin)->post("/admin/support/{$pedido->ulid}/hold", [
            'reason_code' => 'inventada',
        ])->assertSessionHasErrors('reason_code');

        $this->actingAs($admin)->post("/admin/support/{$pedido->ulid}/hold", [
            'reason_code' => 'statutory_obligation',
            'note' => 'Processo 123.',
        ])->assertRedirect();

        $this->assertTrue($pedido->fresh()->hasActiveHold());

        $this->actingAs($admin)->delete("/admin/support/{$pedido->ulid}/hold")->assertRedirect();
        $this->assertFalse($pedido->fresh()->hasActiveHold());
    }

    #[Test]
    public function resending_rebuilds_the_email_and_clears_the_failure(): void
    {
        $admin = $this->admin();
        $pedido = $this->request();

        // Uma entrega que falhou, à espera de uma pessoa.
        $entrega = SupportNotificationDelivery::query()
            ->where('support_request_id', $pedido->getKey())
            ->where('notification_type', 'request_received')
            ->sole();

        $entrega->forceFill([
            'delivered_at' => null,
            'failure_code' => 'smtp_connection',
            'last_failed_at' => now(),
        ])->save();

        $this->actingAs($admin)->get('/admin/support')
            ->assertInertia(fn ($page) => $page->has('pendingDeliveries', 1));

        $this->actingAs($admin)->post("/admin/support/{$pedido->ulid}/resend", [
            'notification_type' => 'request_received',
        ])->assertRedirect();

        $entrega->refresh();
        $this->assertNotNull($entrega->delivered_at);
        $this->assertNull($entrega->failure_code);
        // O reenvio actualiza a linha em vez de acrescentar outra.
        $this->assertSame(1, SupportNotificationDelivery::query()
            ->where('support_request_id', $pedido->getKey())
            ->where('notification_type', 'request_received')
            ->count());
    }

    #[Test]
    public function the_search_never_looks_inside_what_the_person_wrote(): void
    {
        $admin = $this->admin();
        $pedido = $this->request();

        // Pela referência, encontra.
        $this->actingAs($admin)->get('/admin/support?search='.$pedido->reference)
            ->assertInertia(fn ($page) => $page->has('requests.data', 1));

        // Pelo corpo do pedido, não — abrir a descrição sem razão é ler o que
        // só interessa a quem vai responder.
        $this->actingAs($admin)->get('/admin/support?search=ficheiro')
            ->assertInertia(fn ($page) => $page->has('requests.data', 0));
    }
}
