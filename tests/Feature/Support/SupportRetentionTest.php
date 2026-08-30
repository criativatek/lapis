<?php

namespace Tests\Feature\Support;

use App\Actions\Support\ManageRetentionHold;
use App\Actions\Support\OpenSupportRequest;
use App\Actions\Support\ReplyToSupportRequest;
use App\Mail\SupportNotificationMail;
use App\Models\AuditEvent;
use App\Models\RetentionHoldReason;
use App\Models\SupportCategory;
use App\Models\SupportMessage;
use App\Models\SupportNotificationDelivery;
use App\Models\SupportRequest;
use App\Models\SupportRequestStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Tests\TestCase;

/**
 * O RELÓGIO: 23 dias, 30 dias, 24 meses — e o que a suspensão trava.
 *
 * A linha condutora: **a suspensão só trava a anonimização**. Reter um pedido
 * para efeitos legais não é razão para deixar de responder a quem o escreveu, e
 * libertá-la não devolve dois anos de vida ao conteúdo.
 */
class SupportRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config([
            'retention.support_waiting_reminder_days' => 23,
            'retention.support_waiting_auto_resolve_days' => 30,
            'retention.support_resolved_months_retained' => 24,
        ]);
        $this->travelTo(Carbon::parse('2026-09-01 09:00'));
    }

    protected function waitingRequest(): SupportRequest
    {
        $user = User::factory()->create();
        $operator = User::factory()->create();

        $pedido = app(OpenSupportRequest::class)->open([
            'category' => SupportCategory::Imports->value,
            'subject' => 'A pauta não importa',
            'description' => 'O ficheiro da escola dá erro.',
        ], $user, $user->personalOrganization());

        app(ReplyToSupportRequest::class)->fromOperator($pedido, $operator, 'Pode enviar o ficheiro?');

        return $pedido->fresh();
    }

    #[Test]
    public function the_reminder_goes_out_at_twenty_three_days_and_only_once(): void
    {
        $pedido = $this->waitingRequest();

        $this->travel(22)->days();
        $this->artisan('support:retention')->assertSuccessful();
        $this->assertNull($pedido->fresh()->waiting_reminder_sent_at);

        $this->travel(2)->days();
        $this->artisan('support:retention')->assertSuccessful();
        $this->assertNotNull($pedido->fresh()->waiting_reminder_sent_at);

        // Correr outra vez no mesmo dia não manda um segundo lembrete: o
        // carimbo é o que torna o passo idempotente.
        $carimbo = $pedido->fresh()->waiting_reminder_sent_at;
        $this->artisan('support:retention')->assertSuccessful();
        $this->assertTrue($carimbo->equalTo($pedido->fresh()->waiting_reminder_sent_at));
    }

    #[Test]
    public function thirty_days_of_silence_resolves_it_and_says_why(): void
    {
        $pedido = $this->waitingRequest();

        $this->travel(31)->days();
        $this->artisan('support:retention')->assertSuccessful();

        $pedido->refresh();
        $this->assertSame(SupportRequestStatus::Resolved, $pedido->status);
        $this->assertNotNull($pedido->resolved_at);
        // Resolvido pelo TEMPO, não por uma pessoa — e a coluna di-lo em vez
        // de se deduzir do `resolved_by` nulo.
        $this->assertTrue($pedido->auto_resolved);
        $this->assertNull($pedido->resolved_by);

        // E deixa uma mensagem a explicar-se: um pedido que se fecha sozinho
        // sem dizer porquê é um fim sem explicação para quem o abrir depois.
        $ultima = $pedido->messages()->get()->last();
        $this->assertSame('system', $ultima->author_role->value);
        $this->assertStringContainsString('30 dias', $ultima->body);
    }

    #[Test]
    public function open_and_in_progress_never_expire(): void
    {
        $user = User::factory()->create();
        $pedido = app(OpenSupportRequest::class)->open([
            'category' => SupportCategory::Other->value,
            'subject' => 'Parado à nossa espera',
            'description' => 'Ninguém respondeu.',
        ], $user, $user->personalOrganization());

        // Um ano inteiro sem lhe tocarmos. Continua aberto: o nosso atraso não
        // pode ser um fim de conversa.
        $this->travel(365)->days();
        $this->artisan('support:retention')->assertSuccessful();

        $this->assertSame(SupportRequestStatus::Open, $pedido->fresh()->status);
        $this->assertNull($pedido->fresh()->resolved_at);
    }

    #[Test]
    public function twenty_four_months_after_resolution_it_is_truly_anonymised(): void
    {
        $pedido = $this->waitingRequest();
        $this->travel(31)->days();
        $this->artisan('support:retention')->assertSuccessful();

        $this->travel(24)->months();
        $this->artisan('support:retention')->assertSuccessful();

        $pedido->refresh();
        // NULL a sério — sem marcas de substituição.
        $this->assertNull($pedido->requester_name);
        $this->assertNull($pedido->requester_email);
        $this->assertNull($pedido->user_id);
        $this->assertNull($pedido->organization_id);
        $this->assertNull($pedido->subject);
        $this->assertNull($pedido->description);
        $this->assertNull($pedido->technical_reference);
        $this->assertNull($pedido->technical_route);
        $this->assertNotNull($pedido->anonymized_at);

        // As mensagens e as entregas desaparecem.
        $this->assertSame(0, SupportMessage::query()->where('support_request_id', $pedido->getKey())->count());
        $this->assertSame(0, SupportNotificationDelivery::query()->where('support_request_id', $pedido->getKey())->count());

        // Sobrevive o que serve estatística e não identifica ninguém.
        $this->assertNotNull($pedido->reference);
        $this->assertSame(SupportCategory::Imports, $pedido->category);
        $this->assertNotNull($pedido->app_version);
        $this->assertNotNull($pedido->resolved_at);
    }

    #[Test]
    public function nothing_is_replaced_by_a_placeholder(): void
    {
        $pedido = $this->waitingRequest();
        $this->travel(31)->days();
        $this->artisan('support:retention')->assertSuccessful();
        $this->travel(24)->months();
        $this->artisan('support:retention')->assertSuccessful();

        $linha = json_encode($pedido->fresh()->toArray(), JSON_UNESCAPED_UNICODE);

        foreach (['anonimizado', 'Anonimizado', 'invalido.local', 'removido', 'Removido'] as $marca) {
            $this->assertStringNotContainsString($marca, (string) $linha,
                "A anonimização deixou a marca «{$marca}» em vez de um NULL.");
        }
    }

    #[Test]
    public function a_hold_blocks_only_the_anonymisation(): void
    {
        $operator = User::factory()->create();
        $pedido = $this->waitingRequest();

        app(ManageRetentionHold::class)->apply(
            $pedido, $operator, RetentionHoldReason::LegalDispute, 'Nota interna do processo.',
        );

        // O lembrete e o auto-resolve continuam a correr sobre um pedido retido.
        $this->travel(24)->days();
        $this->artisan('support:retention')->assertSuccessful();
        $this->assertNotNull($pedido->fresh()->waiting_reminder_sent_at);

        $this->travel(10)->days();
        $this->artisan('support:retention')->assertSuccessful();
        $this->assertSame(SupportRequestStatus::Resolved, $pedido->fresh()->status);

        // A anonimização, não.
        $this->travel(25)->months();
        $this->artisan('support:retention')->assertSuccessful();
        $this->assertNull($pedido->fresh()->anonymized_at);
        $this->assertNotNull($pedido->fresh()->requester_email);
    }

    #[Test]
    public function releasing_a_hold_does_not_restart_the_clock(): void
    {
        $operator = User::factory()->create();
        $pedido = $this->waitingRequest();

        app(ManageRetentionHold::class)->apply($pedido, $operator, RetentionHoldReason::FormalProceeding);

        $this->travel(31)->days();
        $this->artisan('support:retention')->assertSuccessful();

        // Passam 25 meses com o pedido retido; nada é anonimizado.
        $this->travel(25)->months();
        $this->artisan('support:retention')->assertSuccessful();
        $this->assertNull($pedido->fresh()->anonymized_at);

        // Libertada a suspensão, a EXECUÇÃO SEGUINTE anonimiza: a janela conta
        // de `resolved_at` e não da data em que se levantou o hold.
        app(ManageRetentionHold::class)->release($pedido->fresh(), $operator);
        $this->artisan('support:retention')->assertSuccessful();

        $pedido->refresh();
        $this->assertNotNull($pedido->anonymized_at);
        // A prova da excepção sobrevive; a nota interna não.
        $this->assertNotNull($pedido->retention_hold_at);
        $this->assertSame(RetentionHoldReason::FormalProceeding, $pedido->retention_hold_reason_code);
        $this->assertNotNull($pedido->retention_hold_released_at);
        $this->assertNull($pedido->retention_hold_note);
    }

    #[Test]
    public function a_reopened_request_stops_the_retention_clock(): void
    {
        $user = User::factory()->create();
        $pedido = app(OpenSupportRequest::class)->open([
            'category' => SupportCategory::Reports->value,
            'subject' => 'Relatório',
            'description' => 'Não exporta.',
        ], $user, $user->personalOrganization());

        app(ReplyToSupportRequest::class)->fromOperator($pedido->fresh(), User::factory()->create(), 'Resolvido?');
        $this->travel(31)->days();
        $this->artisan('support:retention')->assertSuccessful();
        $this->assertSame(SupportRequestStatus::Resolved, $pedido->fresh()->status);

        // A pessoa volta a escrever: o relógio para.
        app(ReplyToSupportRequest::class)->fromRequester($pedido->fresh(), $user, 'Continua igual.');
        $this->assertNull($pedido->fresh()->resolved_at);

        $this->travel(25)->months();
        $this->artisan('support:retention')->assertSuccessful();
        $this->assertNull($pedido->fresh()->anonymized_at);
    }

    #[Test]
    public function the_dry_run_writes_nothing_and_shows_no_content(): void
    {
        $pedido = $this->waitingRequest();
        $this->travel(24)->days();

        $this->artisan('support:retention', ['--dry-run' => true])
            ->expectsOutputToContain($pedido->reference)
            ->doesntExpectOutputToContain('A pauta não importa')
            ->doesntExpectOutputToContain('O ficheiro da escola dá erro.')
            ->assertSuccessful();

        // Nada foi escrito.
        $this->assertNull($pedido->fresh()->waiting_reminder_sent_at);
    }

    #[Test]
    public function the_anonymisation_is_audited_without_a_causer_and_without_content(): void
    {
        $pedido = $this->waitingRequest();
        $this->travel(31)->days();
        $this->artisan('support:retention')->assertSuccessful();
        $this->travel(24)->months();
        $this->artisan('support:retention')->assertSuccessful();

        $evento = AuditEvent::withoutGlobalScope('organization')
            ->where('event', 'support.anonymised')
            ->sole();

        $this->assertNull($evento->causer_id);
        $this->assertNull($evento->organization_id);
        $this->assertSame($pedido->reference, $evento->properties['reference']);
    }

    #[Test]
    public function a_notification_that_fails_leaves_a_code_and_never_a_message(): void
    {
        // O transporte rebenta: o ticket sobrevive e a falha fica registada.
        Mail::shouldReceive('to->send')->andThrow(
            new UnexpectedResponseException('550 no such user here', 550)
        );

        $user = User::factory()->create();
        $pedido = app(OpenSupportRequest::class)->open([
            'category' => SupportCategory::Access->value,
            'subject' => 'Entrar',
            'description' => 'Não consigo.',
        ], $user, $user->personalOrganization());

        $this->assertNotNull($pedido->fresh());

        $entrega = SupportNotificationDelivery::query()
            ->where('support_request_id', $pedido->getKey())
            ->first();

        $this->assertNotNull($entrega);
        $this->assertNull($entrega->delivered_at);
        // O código SMTP diz o suficiente; a mensagem citava o destinatário.
        $this->assertSame('invalid_recipient', $entrega->failure_code->value);
        $this->assertStringNotContainsString(
            'no such user',
            json_encode($entrega->toArray(), JSON_UNESCAPED_UNICODE) ?: '',
        );
    }

    #[Test]
    public function the_notification_mail_never_carries_the_subject_or_the_description(): void
    {
        $user = User::factory()->create();
        $pedido = app(OpenSupportRequest::class)->open([
            'category' => SupportCategory::Billing->value,
            'subject' => 'ASSUNTO-SENTINELA',
            'description' => 'DESCRICAO-SENTINELA com o nome de um aluno.',
        ], $user, $user->personalOrganization());

        Mail::assertSent(SupportNotificationMail::class, function (SupportNotificationMail $mail) use ($pedido): bool {
            $rendered = $mail->build()->render();

            $this->assertStringNotContainsString('ASSUNTO-SENTINELA', $rendered);
            $this->assertStringNotContainsString('DESCRICAO-SENTINELA', $rendered);
            $this->assertStringContainsString($pedido->reference, $rendered);
            // O assunto do email é fixo e leva só a referência.
            $this->assertStringNotContainsString('ASSUNTO-SENTINELA', $mail->build()->subject ?? '');

            return true;
        });
    }
}
