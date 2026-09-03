<?php

namespace Tests\Feature\Support;

use App\Actions\Support\AnonymiseSupportRequest;
use App\Models\SupportAttachment;
use App\Models\SupportRequest;
use App\Models\User;
use App\Support\Support\IssueConsent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A certificação da captura de ecrã é uma CONDIÇÃO, não um campo.
 *
 * Uma captura de ecrã do Lapispro é, por construção, uma imagem dos dados sobre
 * que o defeito é — uma tabela de nomes de crianças contra classificações. Quem
 * a envia tem de a ter visto e de o dizer.
 *
 * O CASO QUE IMPORTA É O DO MEIO: sem a marca, a imagem não segue **e o reporte
 * segue na mesma**. Bloquear o envio inteiro ensinaria a marcar sem olhar, que é
 * exactamente o contrário do que esta caixa existe para fazer — e um reporte
 * perdido por causa de uma imagem é um defeito que ninguém chega a saber.
 */
class IssueConsentTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'category' => 'classes_students',
            'subject' => 'A grelha não abre',
            'description' => 'Fica em branco.',
            'technical_route' => '/classes/:id',
        ], $overrides);
    }

    /**
     * Um reporte sem assunto nem resumo entra — e o servidor deriva os dois.
     *
     * Pedido no SUP-2B5T3J: o widget deixou de perguntar o que a descrição já
     * diz. O resumo é a primeira linha da descrição encolhida a 70 caracteres
     * — texto ditado chega sem pontuação, e os espaços em série colapsam — e
     * a categoria nasce «other», que a triagem do backoffice reclassifica.
     */
    #[Test]
    public function a_report_without_category_or_subject_gets_both_derived(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/issues', [
            'description' => "A captura   ficou\nescura quando cliquei no botão de reportar e depois "
                .'o resto da página desapareceu por completo do ecrã',
            'technical_route' => '/dashboard',
            'consent_terms_version' => IssueConsent::currentVersion(),
        ])->assertRedirect();

        $request = SupportRequest::query()->sole();

        $this->assertSame('other', $request->category->value);
        // Espaços e quebras colapsados, corte a 70 mais a reticencia.
        $this->assertLessThanOrEqual(71, mb_strlen($request->subject));
        $this->assertStringStartsWith('A captura ficou escura', $request->subject);
        $this->assertStringEndsWith('…', $request->subject);
    }

    #[Test]
    public function a_certified_screenshot_is_stored_on_the_private_disk(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());

        $this->post('/issues', $this->payload([
            'screenshot' => UploadedFile::fake()->image('ecra.jpg'),
            'screenshot_certified' => true,
            'screenshot_warning' => 'Esta página mostra nomes de alunos.',
        ]))->assertRedirect();

        $attachment = SupportAttachment::query()->sole();

        $this->assertSame(SupportAttachment::KIND_SCREENSHOT, $attachment->kind);
        Storage::disk('local')->assertExists($attachment->disk_path);

        // O nome original nunca toca no disco: seria ele próprio um dado no
        // caminho, e um caminho lê-se sem abrir nada.
        $this->assertStringNotContainsString('ecra', $attachment->disk_path);
    }

    #[Test]
    public function an_uncertified_screenshot_is_dropped_and_the_report_still_arrives(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());

        $this->post('/issues', $this->payload([
            'screenshot' => UploadedFile::fake()->image('ecra.jpg'),
            'screenshot_certified' => false,
        ]))->assertRedirect();

        // O reporte entrou.
        $this->assertDatabaseCount('support_requests', 1);
        // A imagem não.
        $this->assertDatabaseCount('support_attachments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    #[Test]
    public function the_scope_records_what_that_acceptance_covered(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());

        $this->post('/issues', $this->payload([
            'screenshot' => UploadedFile::fake()->image('ecra.jpg'),
            'screenshot_certified' => true,
            'screenshot_warning' => 'Esta página mostra nomes de alunos.',
            'images' => [UploadedFile::fake()->image('outra.png')],
        ]))->assertRedirect();

        $support = SupportRequest::query()->sole();

        $this->assertNotNull($support->consent_accepted_at);
        $this->assertNotNull($support->consent_terms_version);
        $this->assertTrue($support->consent_scope['screenshot_certified']);
        $this->assertSame(1, $support->consent_scope['screenshots']);
        $this->assertSame(1, $support->consent_scope['uploads']);
        // O aviso que lhe foi mostrado. Sem ele não se sabe se a certificação
        // foi informada ou às cegas.
        $this->assertSame('Esta página mostra nomes de alunos.', $support->consent_scope['screenshot_warning']);
    }

    #[Test]
    public function more_images_than_the_ceiling_are_refused(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());

        $this->post('/issues', $this->payload([
            'images' => [
                UploadedFile::fake()->image('a.png'),
                UploadedFile::fake()->image('b.png'),
                UploadedFile::fake()->image('c.png'),
                UploadedFile::fake()->image('d.png'),
            ],
        ]))->assertSessionHasErrors('images');

        $this->assertDatabaseCount('support_requests', 0);
    }

    #[Test]
    public function a_file_that_is_not_an_image_is_refused(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());

        $this->post('/issues', $this->payload([
            'images' => [UploadedFile::fake()->create('pauta.csv', 10, 'text/csv')],
        ]))->assertSessionHasErrors('images.0');
    }

    #[Test]
    public function only_the_person_who_reported_can_see_the_image(): void
    {
        Storage::fake('local');
        $author = User::factory()->create();
        $stranger = User::factory()->create();

        $this->actingAs($author)->post('/issues', $this->payload([
            'screenshot' => UploadedFile::fake()->image('ecra.jpg'),
            'screenshot_certified' => true,
        ]))->assertRedirect();

        $support = SupportRequest::query()->sole();
        $attachment = SupportAttachment::query()->sole();
        $url = "/support/{$support->ulid}/imagens/{$attachment->ulid}";

        $this->actingAs($author)->get($url)->assertOk();
        // Um colega da mesma organização também não vê o pedido, e por isso
        // também não vê a imagem: a autorização é a do pedido.
        $this->actingAs($stranger)->get($url)->assertForbidden();
    }

    #[Test]
    public function anonymisation_deletes_the_files_and_not_only_the_rows(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $this->actingAs($user)->post('/issues', $this->payload([
            'screenshot' => UploadedFile::fake()->image('ecra.jpg'),
            'screenshot_certified' => true,
        ]))->assertRedirect();

        $support = SupportRequest::query()->sole();
        $path = SupportAttachment::query()->sole()->disk_path;
        Storage::disk('local')->assertExists($path);

        app(AnonymiseSupportRequest::class)->execute($support->fresh());

        // Apagar a linha e deixar o ficheiro seria a pior das duas metades.
        $this->assertDatabaseCount('support_attachments', 0);
        Storage::disk('local')->assertMissing($path);

        // O ÂMBITO do aceite vai; o FACTO de ter havido aceite fica — é o
        // registo de um acto da pessoa, como `resolved_by`.
        $support->refresh();
        $this->assertNull($support->consent_scope);
        $this->assertNotNull($support->consent_accepted_at);
    }
}
