<?php

namespace Tests\Feature\Legal;

use App\Models\User;
use App\Support\Legal\LegalDocuments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A POLÍTICA TEM DE DIZER O QUE O CÓDIGO FAZ — e só o que ele faz.
 *
 * Estes testes existem porque o desalinhamento anterior não foi um erro de
 * escrita: foi uma funcionalidade nova a criar um canal e um titular que os
 * textos não previam, sem que nada falhasse. Aqui os prazos vêm de
 * `config/retention.php`, que é a mesma fonte que a rotina executa, por isso
 * mudar o prazo sem mudar o texto — ou o contrário — parte um teste.
 */
class SupportPrivacyAlignmentTest extends TestCase
{
    use RefreshDatabase;

    /** Todo o texto da Política, achatado, para procurar dentro. */
    protected function privacyText(): string
    {
        return json_encode(LegalDocuments::privacy(), JSON_UNESCAPED_UNICODE) ?: '';
    }

    /** @return list<string> */
    protected function sectionBody(string $heading): array
    {
        return collect(LegalDocuments::privacy()['sections'])
            ->firstWhere('heading', $heading)['body'] ?? [];
    }

    #[Test]
    public function the_policy_names_the_web_form_and_not_only_the_email(): void
    {
        $texto = $this->privacyText();

        $this->assertStringContainsString('formulário de contacto', $texto,
            'A Política continua a descrever o suporte apenas como um endereço de email.');
        $this->assertStringContainsString('suporte@lapispro.com', $texto);
    }

    #[Test]
    public function the_support_section_states_a_basis_for_each_kind_of_contact(): void
    {
        $corpo = implode(' ', $this->sectionBody('Contactos e suporte'));

        // As três situações que o enquadramento distingue, cada uma com a sua
        // alínea — e nenhuma delas apoiada em consentimento.
        $this->assertStringContainsString('diligências pré-contratuais', $corpo);
        $this->assertStringContainsString('execução do contrato', $corpo);
        $this->assertStringContainsString('interesse legítimo', $corpo);
        $this->assertStringContainsString('alínea b)', $corpo);
        $this->assertStringContainsString('alínea f)', $corpo);

        // O simples contacto não autoriza marketing, e não se pede aceitação.
        $this->assertStringContainsString('não nos autoriza a enviar-lhe comunicações comerciais', $corpo);
        $this->assertStringContainsString('Não lhe pedimos consentimento', $corpo);

        // Quando a base é a alínea f), o direito de oposição tem de constar.
        $this->assertStringContainsString('pode opor-se', $corpo);
    }

    #[Test]
    public function the_policy_states_the_three_retention_windows_from_the_same_config_the_routine_reads(): void
    {
        $prazos = implode(' ', $this->sectionBody('Durante quanto tempo'));

        foreach ([
            'support_waiting_reminder_days',
            'support_waiting_auto_resolve_days',
            'support_resolved_months_retained',
        ] as $chave) {
            $valor = (string) config('retention.'.$chave);

            $this->assertStringContainsString($valor, $prazos,
                "A Política não indica o prazo de `{$chave}` ({$valor}) que a rotina cumpre.");
        }

        // E descreve o que acontece no fim, incluindo a reabertura.
        $this->assertStringContainsString('reabre', $prazos);
    }

    #[Test]
    public function the_policy_describes_the_hold_without_promising_a_review_nobody_runs(): void
    {
        $prazos = implode(' ', $this->sectionBody('Durante quanto tempo'));

        $this->assertStringContainsString('suspensa', $prazos);
        $this->assertStringContainsString('obrigação legal', $prazos);
        $this->assertStringContainsString('defesa de direitos', $prazos);
        // Levantar a suspensão não devolve tempo ao conteúdo.
        $this->assertStringContainsString('a partir do encerramento e não da data em que a suspensão terminou', $prazos);

        // NÃO prometemos uma revisão periódica: não há rotina que a faça.
        $this->assertStringNotContainsString('revisão periódica', $prazos);
        $this->assertStringNotContainsString('revista periodicamente', $prazos);
    }

    #[Test]
    public function the_policy_never_claims_something_the_code_does_not_do(): void
    {
        $texto = $this->privacyText();

        // O formulário não aceita anexos, e não guarda IP nem User-Agent. A
        // Política afirma as ausências — o que falharia aqui é afirmá-las ao
        // contrário.
        $this->assertStringContainsString('Não guardamos o seu endereço IP', $texto);
        $this->assertStringContainsString('não aceita ficheiros anexos', $texto);
    }

    #[Test]
    public function the_effective_date_moved_for_the_privacy_policy_only(): void
    {
        $this->assertSame(
            config('lapis.legal.privacy_effective_from'),
            LegalDocuments::privacy()['effective_from'],
        );

        // Os outros dois documentos não foram alterados por esta release.
        $this->assertSame('2026-08-27', config('lapis.legal.terms_effective_from'));
        $this->assertSame('2026-08-27', config('lapis.legal.processing_effective_from'));
        $this->assertNotSame(
            config('lapis.legal.terms_effective_from'),
            config('lapis.legal.privacy_effective_from'),
        );
    }

    // ------------------------------------------------- o aviso a quem já usa

    #[Test]
    public function a_teacher_who_never_dismissed_it_sees_the_notice(): void
    {
        $user = User::factory()->create();
        $user->personalOrganization();

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('auth.should_see_privacy_notice', true));
    }

    #[Test]
    public function dismissing_hides_it_without_accepting_anything(): void
    {
        $user = User::factory()->create();
        $user->personalOrganization();

        $this->actingAs($user)->post('/avisos/privacidade')->assertRedirect();

        $user->refresh();
        $this->assertNotNull($user->privacy_notice_dismissed_at);
        // Fechar um aviso não é aceitar: a aceitação dos Termos é outra coluna
        // e continua intocada.
        $this->assertNull($user->terms_accepted_at);
        $this->assertNull($user->terms_version);

        $this->actingAs($user)->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('auth.should_see_privacy_notice', false));
    }

    #[Test]
    public function a_later_policy_brings_the_notice_back_on_its_own(): void
    {
        $user = User::factory()->create();
        $user->personalOrganization();
        $this->actingAs($user)->post('/avisos/privacidade');

        // A Política é atualizada outra vez. Ninguém limpa coluna nenhuma.
        config(['lapis.legal.privacy_effective_from' => Carbon::now()->addDay()->toDateString()]);

        $this->assertTrue($user->fresh()->shouldSeePrivacyNotice());
    }

    #[Test]
    public function the_notice_never_blocks_the_application(): void
    {
        $user = User::factory()->create();
        $user->personalOrganization();

        // Sem fechar o aviso, tudo continua acessível — não é um portão.
        foreach (['/dashboard', '/settings/profile', '/help'] as $rota) {
            $this->actingAs($user)->get($rota)->assertOk();
        }
    }

    // ------------------------------------------------------------ /contacto

    #[Test]
    public function the_public_form_shows_the_short_notice_and_links_the_policy(): void
    {
        Mail::fake();
        $this->withoutVite();

        // O SSR está desligado nos testes, por isso o texto do componente não
        // está no HTML. O que se afirma aqui é o que o servidor garante: a
        // página existe, é a certa, e a Política que ela referencia responde.
        $this->get('/contacto')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('marketing/Contacto'));

        $this->get('/privacidade')->assertOk();

        // O texto vive no componente; um teste de unidade do Vue cobre-o.
        $componente = file_get_contents(resource_path('js/pages/marketing/Contacto.vue')) ?: '';

        $this->assertStringContainsString('Política de Privacidade', $componente);
        $this->assertStringContainsString('/privacidade', $componente);
        $this->assertStringContainsString('diligências pré-contratuais', $componente);
        $this->assertStringContainsString('Não inclua nomes de alunos', $componente);

        // Sem caixa de aceitação: o tratamento não assenta em consentimento.
        $this->assertStringNotContainsString('type="checkbox"', $componente);
        $this->assertStringNotContainsString('Aceito a Política', $componente);
    }

    #[Test]
    public function the_guest_form_still_works_after_the_copy_change(): void
    {
        Mail::fake();
        $this->withoutVite();

        $this->post('/contacto', [
            'requester_name' => 'Maria Antunes',
            'requester_email' => 'maria@exemplo.pt',
            'category' => 'access',
            'subject' => 'Não consigo entrar',
            'description' => 'O email de recuperação não chega.',
        ])->assertRedirect()->assertSessionHas(
            'inertia.flash_data',
            fn (array $flash): bool => isset($flash['supportReference']),
        );
    }
}
