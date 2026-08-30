<?php

namespace Tests\Feature\Support;

use App\Actions\Support\ChangeSupportStatus;
use App\Actions\Support\ManageRetentionHold;
use App\Actions\Support\OpenSupportRequest;
use App\Actions\Support\ReplyToSupportRequest;
use App\Models\AuditEvent;
use App\Models\RetentionHoldReason;
use App\Models\SupportCategory;
use App\Models\SupportRequestStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A SENTINELA: nada do que uma pessoa escreveu chega ao rasto de auditoria.
 *
 * `audit_events` é imutável por construção — não há caminho de código que o
 * reescreva, e a anonimização dos 24 meses não lhe toca. Tudo o que lá entrar
 * fica lá para sempre. Por isso este teste injecta uma cadeia única em cada
 * campo livre e verifica que **nenhuma** delas aparece em campo nenhum de
 * evento nenhum.
 *
 * É um teste que se mantém verdadeiro sozinho: um evento novo que traga
 * conteúdo falha aqui sem ninguém se lembrar de o vir actualizar.
 */
class SupportAuditSentinelTest extends TestCase
{
    use RefreshDatabase;

    /** As cadeias que nunca podem existir em `audit_events`. */
    private const SENTINELAS = [
        'SENTINELA-ASSUNTO',
        'SENTINELA-DESCRICAO',
        'SENTINELA-RESPOSTA-OPERADOR',
        'SENTINELA-RESPOSTA-PESSOA',
        'SENTINELA-NOTA-HOLD',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    #[Test]
    public function no_free_text_from_anybody_ever_reaches_the_audit_trail(): void
    {
        $user = User::factory()->create();
        $operator = User::factory()->create();

        // Um pedido com sentinelas em todos os campos que uma pessoa escreve,
        // percorrendo o ciclo de vida inteiro.
        $pedido = app(OpenSupportRequest::class)->open([
            'category' => SupportCategory::Assessment->value,
            'subject' => 'SENTINELA-ASSUNTO',
            'description' => 'SENTINELA-DESCRICAO',
        ], $user, $user->personalOrganization());

        app(ReplyToSupportRequest::class)->fromOperator($pedido->fresh(), $operator, 'SENTINELA-RESPOSTA-OPERADOR');
        app(ReplyToSupportRequest::class)->fromRequester($pedido->fresh(), $user, 'SENTINELA-RESPOSTA-PESSOA');
        app(ChangeSupportStatus::class)->to($pedido->fresh(), SupportRequestStatus::Resolved, $operator);
        app(ManageRetentionHold::class)->apply(
            $pedido->fresh(), $operator, RetentionHoldReason::LegalDispute, 'SENTINELA-NOTA-HOLD',
        );
        app(ManageRetentionHold::class)->release($pedido->fresh(), $operator);

        // Reabrir, para cobrir também esse evento.
        app(ReplyToSupportRequest::class)->fromRequester($pedido->fresh(), $user, 'SENTINELA-RESPOSTA-PESSOA');

        $eventos = AuditEvent::withoutGlobalScope('organization')
            ->where('event', 'like', 'support.%')
            ->get();

        $this->assertGreaterThanOrEqual(6, $eventos->count(), 'O ciclo de vida devia ter deixado rasto.');

        $tudo = json_encode($eventos->map->toArray()->all(), JSON_UNESCAPED_UNICODE) ?: '';

        foreach (self::SENTINELAS as $sentinela) {
            $this->assertStringNotContainsString($sentinela, $tudo,
                "A auditoria guardou «{$sentinela}» — conteúdo livre num registo imutável.");
        }
    }

    #[Test]
    public function opening_is_recorded_without_a_causer_for_guest_and_for_signed_in_alike(): void
    {
        // Com sessão iniciada — e mesmo assim sem autor no rasto: um evento
        // imutável que aponte para o utilizador é um identificador que a
        // anonimização dos 24 meses não conseguiria apagar (ADR-0011 §10).
        $user = User::factory()->create();
        $this->actingAs($user);

        app(OpenSupportRequest::class)->open([
            'category' => SupportCategory::Access->value,
            'subject' => 'Com conta',
            'description' => 'Texto.',
        ], $user, $user->personalOrganization());

        // E sem sessão.
        auth()->logout();
        app(OpenSupportRequest::class)->open([
            'requester_name' => 'Maria',
            'requester_email' => 'maria@exemplo.pt',
            'category' => SupportCategory::Access->value,
            'subject' => 'Sem conta',
            'description' => 'Texto.',
        ]);

        $aberturas = AuditEvent::withoutGlobalScope('organization')
            ->where('event', 'support.request_opened')
            ->get();

        $this->assertCount(2, $aberturas);

        foreach ($aberturas as $evento) {
            $this->assertNull($evento->causer_id, 'A criação de um pedido não pode ficar ligada a ninguém.');
            $this->assertNull($evento->organization_id);
        }
    }

    #[Test]
    public function operator_actions_keep_their_causer(): void
    {
        $user = User::factory()->create();
        $operator = User::factory()->create();

        $pedido = app(OpenSupportRequest::class)->open([
            'category' => SupportCategory::Other->value,
            'subject' => 'Um pedido',
            'description' => 'Texto.',
        ], $user, $user->personalOrganization());

        app(ReplyToSupportRequest::class)->fromOperator($pedido->fresh(), $operator, 'Resposta.');
        app(ChangeSupportStatus::class)->to($pedido->fresh(), SupportRequestStatus::Resolved, $operator);

        foreach (['support.replied', 'support.resolved'] as $evento) {
            $linha = AuditEvent::withoutGlobalScope('organization')->where('event', $evento)->sole();

            $this->assertSame($operator->getKey(), $linha->causer_id,
                "«{$evento}» é responsabilidade de quem o praticou e tem de o dizer.");
        }
    }

    #[Test]
    public function the_auto_resolve_records_a_closed_metadata_and_no_causer(): void
    {
        config(['retention.support_waiting_auto_resolve_days' => 30]);

        $user = User::factory()->create();
        $pedido = app(OpenSupportRequest::class)->open([
            'category' => SupportCategory::Other->value,
            'subject' => 'Silêncio',
            'description' => 'Texto.',
        ], $user, $user->personalOrganization());

        app(ReplyToSupportRequest::class)->fromOperator($pedido->fresh(), User::factory()->create(), 'Pergunta.');

        $this->travel(31)->days();
        $this->artisan('support:retention')->assertSuccessful();

        $evento = AuditEvent::withoutGlobalScope('organization')
            ->where('event', 'support.auto_resolved')
            ->sole();

        $this->assertNull($evento->causer_id);
        // Metadata fechada: chaves conhecidas, valores de vocabulário.
        $this->assertSame('system', $evento->properties['by']);
        $this->assertSame('waiting_for_user', $evento->properties['from']);
        $this->assertSame('resolved', $evento->properties['to']);
    }
}
