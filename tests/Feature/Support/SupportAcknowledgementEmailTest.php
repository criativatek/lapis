<?php

namespace Tests\Feature\Support;

use App\Actions\Support\OpenSupportRequest;
use App\Mail\SupportNotificationMail;
use App\Models\SupportCategory;
use App\Models\SupportMessage;
use App\Models\SupportNotificationDelivery;
use App\Models\SupportNotificationType;
use App\Models\SupportRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Tests\TestCase;

/**
 * A CONFIRMAÇÃO AUTOMÁTICA DE RECEÇÃO — o que ela promete, e o que nunca leva.
 *
 * Um pedido de suporte é escrito por alguém que já está com um problema. O
 * silêncio a seguir a carregar no botão é a segunda avaria do dia, e é essa que
 * esta confirmação existe para não acontecer.
 *
 * As três linhas que estes testes seguram:
 *
 *  1. **O pedido é a fonte canónica, o email é aviso.** O ticket é gravado
 *     primeiro; se o SMTP falhar, o pedido continua lá e a falha fica visível
 *     no backoffice para alguém a reenviar (ADR-0011 §5 e §6).
 *  2. **Nada do que a pessoa escreveu sai por email.** Nem o resumo, nem a
 *     descrição, nem uma mensagem do fio. Só a referência, a categoria — que é
 *     vocabulário fechado — e o primeiro nome de quem vai receber a mensagem.
 *  3. **O que se promete depende de haver conta.** A quem a tem, acompanhar o
 *     pedido na aplicação; a um visitante, resposta por email e mais nada,
 *     porque é tudo o que existe para ele (ADR-0011 §3).
 *
 * E uma quarta, que é sobre o ecrã e não sobre o email: **a interface não diz
 * que enviou uma confirmação que não saiu.**
 */
class SupportAcknowledgementEmailTest extends TestCase
{
    use RefreshDatabase;

    /** O transporte que funcionava, guardado antes de ser partido de propósito. */
    protected mixed $workingMailer = null;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->withoutVite();
    }

    /** @return array<string, string> */
    protected function guestPayload(array $overrides = []): array
    {
        return array_merge([
            'requester_name' => 'Maria Antunes Ferreira',
            'requester_email' => 'maria@exemplo.pt',
            'category' => SupportCategory::Access->value,
            'subject' => 'RESUMO-SENTINELA',
            'description' => 'DESCRICAO-SENTINELA, com o nome de um aluno.',
        ], $overrides);
    }

    protected function openAuthenticated(User $user): SupportRequest
    {
        return app(OpenSupportRequest::class)->open([
            'category' => SupportCategory::Imports->value,
            'subject' => 'RESUMO-SENTINELA',
            'description' => 'DESCRICAO-SENTINELA, com o nome de um aluno.',
        ], $user, $user->personalOrganization());
    }

    /**
     * O aviso de receção que saiu para quem pediu — nunca o da equipa.
     */
    protected function acknowledgementMail(): SupportNotificationMail
    {
        $encontrado = null;

        Mail::assertSent(SupportNotificationMail::class, function (SupportNotificationMail $mail) use (&$encontrado): bool {
            if ($mail->type !== SupportNotificationType::RequestReceived) {
                return false;
            }

            $encontrado = $mail;

            return true;
        });

        $this->assertNotNull($encontrado, 'Nenhuma confirmação de receção foi enviada a quem abriu o pedido.');

        return $encontrado;
    }

    /**
     * Faz o transporte recusar tudo o que lhe passa pela frente.
     *
     * Um 550 é uma recusa de destinatário: classificável a partir do CÓDIGO,
     * sem ler a mensagem — que é a regra de `SupportDeliveryFailureCode`. A
     * mensagem da excepção cita de propósito o endereço e o servidor, para que
     * os testes de privacidade tenham o que procurar e não encontrar.
     */
    protected function failTheMailer(): void
    {
        $this->workingMailer = Mail::getFacadeRoot();

        Mail::shouldReceive('to')->andReturnSelf();
        Mail::shouldReceive('send')->andThrow(
            new UnexpectedResponseException('550 5.1.1 <maria@exemplo.pt>: recipient rejected by mx.exemplo.pt', 550)
        );
    }

    /** Devolve o transporte que funcionava, para o reenvio poder ser reenvio. */
    protected function repairTheMailer(): void
    {
        $this->assertNotNull($this->workingMailer, 'Nada foi partido, logo não há nada para reparar.');

        Mail::swap($this->workingMailer);
    }

    /**
     * O objeto de página que o cliente Inertia vai receber — flash incluída.
     *
     * A flash NÃO É UM PROP: viaja ao lado deles, e por isso `assertInertia`,
     * que só sabe de props, não lhe chega. Lê-se do `data-page`, que é
     * exactamente o que o browser lê.
     *
     * @return array<string, mixed>
     */
    protected function pageObject(TestResponse $response): array
    {
        $response->assertOk();

        /** @var array<string, mixed> $page */
        $page = $response->viewData('page');

        return $page;
    }

    // ————————————————————————————————————————————————————————————————
    // O email sai, e diz o que tem a dizer
    // ————————————————————————————————————————————————————————————————

    #[Test]
    public function an_authenticated_request_gets_an_acknowledgement_with_the_reference(): void
    {
        $user = User::factory()->create(['name' => 'Ana Sofia Pereira']);

        $pedido = $this->openAuthenticated($user);

        $mail = $this->acknowledgementMail();
        $built = $mail->build();
        $assunto = (string) $built->subject;
        $corpo = $built->render();

        // O assunto diz o que é ANTES da referência: é o que aparece na
        // notificação do telemóvel, e é aí que a pessoa fica descansada.
        $this->assertSame("Recebemos o seu pedido de suporte — {$pedido->reference}", $assunto);
        $this->assertStringContainsString($pedido->reference, $corpo);

        // Cumprimenta pelo PRIMEIRO nome, e só por ele.
        $this->assertStringContainsString('Olá, Ana,', $corpo);
        $this->assertStringNotContainsString('Ana Sofia Pereira', $corpo);

        // O tom pedido: lamentar, analisar, celeridade.
        $this->assertStringContainsString('Lamentamos o incómodo', $corpo);
        $this->assertStringContainsString('com a maior brevidade possível', $corpo);
        $this->assertStringContainsString('Equipa de Suporte Lapispro', $corpo);
    }

    #[Test]
    public function a_guest_request_gets_an_acknowledgement_with_the_reference(): void
    {
        $this->post('/contacto', $this->guestPayload())->assertRedirect();

        $pedido = SupportRequest::query()->sole();
        $built = $this->acknowledgementMail()->build();

        $this->assertSame("Recebemos o seu pedido de suporte — {$pedido->reference}", (string) $built->subject);
        $this->assertStringContainsString($pedido->reference, $built->render());
        $this->assertStringContainsString('Olá, Maria,', $built->render());
    }

    /**
     * A promessa muda com a conta — e é a única coisa que muda.
     */
    #[Test]
    public function only_an_account_holder_is_invited_back_into_the_application(): void
    {
        $user = User::factory()->create();
        $pedido = $this->openAuthenticated($user);

        $corpo = $this->acknowledgementMail()->build()->render();

        $this->assertStringContainsString('Ver pedido', $corpo);
        $this->assertStringContainsString("/support/{$pedido->ulid}", $corpo);
        $this->assertStringContainsString('Pode acompanhar este pedido', $corpo);
    }

    #[Test]
    public function a_visitor_is_promised_email_and_never_a_portal_that_does_not_exist(): void
    {
        $this->post('/contacto', $this->guestPayload())->assertRedirect();

        $corpo = $this->acknowledgementMail()->build()->render();

        // Sem CTA e sem caminho: um visitante não tem para onde ir, e mandá-lo
        // para um login que não lhe serve seria pior do que não o mandar a lado
        // nenhum (ADR-0011 §3).
        $this->assertStringNotContainsString('Ver pedido', $corpo);
        $this->assertStringNotContainsString('/support/', $corpo);
        $this->assertStringNotContainsString('Pode acompanhar este pedido', $corpo);

        // O que ele recebe em vez disso: a equipa escreve para este endereço.
        $this->assertStringContainsString('entrará em contacto através deste endereço de email', $corpo);
    }

    /**
     * SEM SLA. «Com a maior brevidade possível» é uma intenção; «em 24 horas» é
     * um contrato — e um contrato que ninguém assinou é o que se lê de volta no
     * dia em que a resposta demora 26.
     */
    #[Test]
    public function the_acknowledgement_promises_care_and_never_a_deadline(): void
    {
        $user = User::factory()->create();
        $this->openAuthenticated($user);

        $corpo = $this->acknowledgementMail()->build()->render();

        foreach (['24 horas', '48 horas', 'prazo máximo', 'garantimos resposta', 'até amanhã'] as $promessa) {
            $this->assertStringNotContainsStringIgnoringCase($promessa, $corpo);
        }
    }

    #[Test]
    public function the_footer_says_that_replying_by_email_never_reaches_the_request(): void
    {
        $this->post('/contacto', $this->guestPayload())->assertRedirect();

        $corpo = $this->acknowledgementMail()->build()->render();

        $this->assertStringContainsString('confirmação automática da receção', $corpo);
        $this->assertStringContainsString('não são adicionadas ao pedido no Lapispro', $corpo);
    }

    // ————————————————————————————————————————————————————————————————
    // Privacidade: o que a pessoa escreveu fica na aplicação
    // ————————————————————————————————————————————————————————————————

    /**
     * NEM O RESUMO, NEM A DESCRIÇÃO, NEM UMA MENSAGEM DO FIO.
     *
     * O campo que o formulário chama «Resumo» é texto livre, e é exactamente
     * onde é mais fácil escrever «o aluno João não aparece na turma 5.ºB» sem
     * pensar. O que identifica o pedido no email é a CATEGORIA, que é
     * vocabulário fechado e não pode conter o nome de ninguém.
     */
    #[Test]
    public function nothing_the_person_wrote_leaves_in_the_acknowledgement(): void
    {
        $user = User::factory()->create();
        $pedido = $this->openAuthenticated($user);

        SupportMessage::query()->where('support_request_id', $pedido->getKey())->update([
            'body' => 'MENSAGEM-SENTINELA sobre a Beatriz, do 7.ºC.',
        ]);

        $built = $this->acknowledgementMail()->build();
        $corpo = $built->render();
        $assunto = (string) $built->subject;

        foreach ([$corpo, $assunto] as $texto) {
            $this->assertStringNotContainsString('RESUMO-SENTINELA', $texto);
            $this->assertStringNotContainsString('DESCRICAO-SENTINELA', $texto);
            $this->assertStringNotContainsString('MENSAGEM-SENTINELA', $texto);
            $this->assertStringNotContainsString('Beatriz', $texto);
        }

        // O que fica no lugar: a categoria, fechada.
        $this->assertStringContainsString('Pedido: '.$pedido->category->label(), $corpo);
    }

    // ————————————————————————————————————————————————————————————————
    // O SMTP falha — e o pedido não se mexe
    // ————————————————————————————————————————————————————————————————

    #[Test]
    public function an_smtp_failure_never_rolls_the_request_back(): void
    {
        $this->failTheMailer();

        $user = User::factory()->create();
        $pedido = $this->openAuthenticated($user);

        // O pedido existe, com o fio começado. O que falhou foi o aviso.
        $this->assertDatabaseHas('support_requests', ['id' => $pedido->getKey()]);
        $this->assertSame(1, SupportRequest::query()->count());
        $this->assertSame(1, SupportMessage::query()->where('support_request_id', $pedido->getKey())->count());
    }

    #[Test]
    public function an_smtp_failure_leaves_a_technical_delivery_row_for_a_person_to_act_on(): void
    {
        $this->failTheMailer();

        $user = User::factory()->create();
        $pedido = $this->openAuthenticated($user);

        $entrega = SupportNotificationDelivery::query()
            ->where('support_request_id', $pedido->getKey())
            ->where('notification_type', SupportNotificationType::RequestReceived)
            ->sole();

        $this->assertNull($entrega->delivered_at);
        $this->assertSame(1, $entrega->attempts);
        $this->assertNotNull($entrega->last_failed_at);
        // Classificado do CÓDIGO SMTP, não do texto.
        $this->assertSame('invalid_recipient', $entrega->failure_code?->value);
        $this->assertTrue($entrega->hasFailed());

        // E aparece na lista do backoffice, que é o ponto de a guardar. O aviso
        // à equipa falhou pela mesma razão e está lá ao lado — são dois avisos
        // distintos, e cada um tem a sua linha e o seu botão.
        $this->assertSame(2, SupportNotificationDelivery::query()->pending()->count());
        $this->assertSame(1, SupportNotificationDelivery::query()
            ->pending()
            ->where('notification_type', SupportNotificationType::RequestReceived)
            ->count());
    }

    /**
     * A TABELA TÉCNICA NÃO É UMA SEGUNDA CÓPIA DO PEDIDO.
     *
     * Nem corpo, nem assunto, nem endereço, nem a mensagem da excepção — que
     * neste teste cita o endereço e o servidor —, nem stack trace. Se isto
     * cedesse, a anonimização dos 24 meses deixaria de conseguir cumprir-se
     * apagando estas linhas.
     */
    #[Test]
    public function the_delivery_row_stores_state_and_never_content(): void
    {
        $this->failTheMailer();

        $user = User::factory()->create();
        $pedido = $this->openAuthenticated($user);

        $linha = json_encode(
            SupportNotificationDelivery::query()
                ->where('support_request_id', $pedido->getKey())
                ->get()
                ->toArray(),
            JSON_UNESCAPED_UNICODE
        ) ?: '';

        foreach ([
            'RESUMO-SENTINELA',
            'DESCRICAO-SENTINELA',
            'Recebemos o seu pedido',
            'Lamentamos o incómodo',
            $user->email,
            'recipient rejected',
            'mx.exemplo.pt',
            '#0 ',
            'vendor',
            '.php',
        ] as $proibido) {
            $this->assertStringNotContainsString($proibido, $linha);
        }

        // As colunas que a tabela tem são as que não identificam ninguém.
        $this->assertStringContainsString('request_received', $linha);
        $this->assertStringContainsString('requester', $linha);
        $this->assertStringContainsString('invalid_recipient', $linha);
    }

    // ————————————————————————————————————————————————————————————————
    // O reenvio, durável e manual
    // ————————————————————————————————————————————————————————————————

    #[Test]
    public function the_backoffice_can_resend_a_failed_acknowledgement_without_touching_the_request(): void
    {
        $this->failTheMailer();

        $user = User::factory()->create();
        $pedido = $this->openAuthenticated($user);

        // O transporte volta a funcionar; nada mais muda.
        $this->repairTheMailer();

        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        $this->actingAs($admin)
            ->post("/admin/support/{$pedido->ulid}/resend", [
                'notification_type' => SupportNotificationType::RequestReceived->value,
            ])
            ->assertRedirect();

        $entrega = SupportNotificationDelivery::query()
            ->where('support_request_id', $pedido->getKey())
            ->where('notification_type', SupportNotificationType::RequestReceived)
            ->sole();

        $this->assertNotNull($entrega->delivered_at);
        $this->assertNull($entrega->failure_code);
        $this->assertSame(2, $entrega->attempts);

        // O REENVIO NÃO DUPLICA NADA: nem o pedido, nem o fio, nem a linha de
        // entrega. Reconstrói o email a partir do pedido e volta a tentar.
        $this->assertSame(1, SupportRequest::query()->count());
        $this->assertSame(1, SupportMessage::query()->where('support_request_id', $pedido->getKey())->count());
        $this->assertSame(1, SupportNotificationDelivery::query()
            ->where('support_request_id', $pedido->getKey())
            ->where('notification_type', SupportNotificationType::RequestReceived)
            ->count());

        // E o email que sai é o mesmo email, reconstruído.
        $this->assertSame(
            "Recebemos o seu pedido de suporte — {$pedido->reference}",
            (string) $this->acknowledgementMail()->build()->subject
        );
    }

    // ————————————————————————————————————————————————————————————————
    // O ecrã não mente
    // ————————————————————————————————————————————————————————————————

    #[Test]
    public function the_screen_confirms_the_email_only_when_it_actually_went_out(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/support', [
            'category' => SupportCategory::Imports->value,
            'subject' => 'A pauta não importa',
            'description' => 'O ficheiro dá erro.',
        ])->assertRedirect();

        $pedido = SupportRequest::query()->sole();

        $flash = $this->pageObject($this->actingAs($user)->get("/support/{$pedido->ulid}"))['flash'] ?? [];

        $this->assertSame($pedido->reference, $flash['supportAcknowledgement']['reference'] ?? null);
        $this->assertTrue($flash['supportAcknowledgement']['emailDelivered'] ?? null);
    }

    #[Test]
    public function the_screen_admits_the_email_did_not_go_out_instead_of_claiming_it_did(): void
    {
        $this->failTheMailer();

        $user = User::factory()->create();

        $this->actingAs($user)->post('/support', [
            'category' => SupportCategory::Imports->value,
            'subject' => 'A pauta não importa',
            'description' => 'O ficheiro dá erro.',
        ])->assertRedirect();

        $pedido = SupportRequest::query()->sole();

        // O pedido ficou registado — e o ecrã di-lo. O que ele NÃO faz é
        // mandar a pessoa esperar por um email que não saiu.
        $flash = $this->pageObject($this->actingAs($user)->get("/support/{$pedido->ulid}"))['flash'] ?? [];

        $this->assertSame($pedido->reference, $flash['supportAcknowledgement']['reference'] ?? null);
        $this->assertFalse($flash['supportAcknowledgement']['emailDelivered'] ?? null);
    }

    #[Test]
    public function the_visitor_screen_is_honest_about_the_email_too(): void
    {
        $this->post('/contacto', $this->guestPayload())->assertRedirect();

        $pedido = SupportRequest::query()->sole();
        $flash = $this->pageObject($this->get('/contacto'))['flash'] ?? [];

        $this->assertSame($pedido->reference, $flash['supportReference'] ?? null);
        $this->assertTrue($flash['supportEmailDelivered'] ?? null);
    }

    #[Test]
    public function the_visitor_screen_never_claims_an_email_that_failed(): void
    {
        $this->failTheMailer();

        $this->post('/contacto', $this->guestPayload())->assertRedirect();

        $pedido = SupportRequest::query()->sole();
        $flash = $this->pageObject($this->get('/contacto'))['flash'] ?? [];

        $this->assertSame($pedido->reference, $flash['supportReference'] ?? null);
        $this->assertFalse($flash['supportEmailDelivered'] ?? null);
    }
}
