<?php

namespace Tests\Feature\Legal;

use App\Models\SupportRequest;
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

    /**
     * A Política tem de descrever o que o botão de dentro da aplicação recolhe.
     *
     * O QUE ESTE CASO FECHA. Entre as 0.106.0 e as 0.109.0, o reporte passou a
     * levar contexto do browser, mensagens de consola, pedidos falhados e uma
     * imagem do ecrã — e a Política continuou a descrever apenas o formulário
     * público, dizendo que dele não se recolhe informação do navegador nem se
     * aceitam anexos. Nada disso era falso; era **incompleto**, que numa página
     * de privacidade dá no mesmo para quem a lê.
     *
     * O código foi à frente do texto porque nada os prendia um ao outro. Isto é
     * a corda.
     */
    #[Test]
    public function the_policy_describes_what_a_report_from_inside_the_app_collects(): void
    {
        $corpo = implode(' ', $this->sectionBody('Contactos e suporte'));

        // O contexto técnico, pelos nomes por que o utilizador o reconhece.
        $this->assertStringContainsString('navegador', $corpo);
        $this->assertStringContainsString('tamanho da janela', $corpo);
        $this->assertStringContainsString('mensagens técnicas', $corpo);
        $this->assertStringContainsString('pedidos ao servidor que tenham falhado', $corpo);

        // E o que o distingue de tudo o resto: a imagem, e a decisão dela.
        $this->assertStringContainsString('imagem do seu ecrã', $corpo);
        $this->assertStringContainsString('Nenhuma imagem é recolhida sem a pedir', $corpo);
        $this->assertStringContainsString('nomes de alunos e classificações', $corpo);
    }

    /**
     * A Política tem de dizer quem transcreve a voz de quem dita, e para onde
     * vai o áudio.
     *
     * O DITADO É O ÚNICO CAMINHO EM QUE ALGO DO UTILIZADOR SAI SEM PASSAR POR
     * NÓS. O áudio vai do navegador para o serviço de reconhecimento do
     * fornecedor dele — hoje, no Chrome, a Google — e o Lapispro nunca o vê. É
     * precisamente por não passar por nós que é fácil não o declarar: nada nos
     * nossos registos o mostraria. Daí este caso.
     *
     * O ecrã já avisa (`lib/dictation.ts`), e o aviso no ecrã não substitui o
     * documento: quem lê a Política para saber o que acontece aos dados não abre
     * o diálogo de reporte para descobrir.
     */
    #[Test]
    public function the_policy_says_who_transcribes_dictated_speech(): void
    {
        $corpo = implode(' ', $this->sectionBody('Contactos e suporte'));

        $this->assertStringContainsString('Pode ditar a descrição', $corpo);
        // Quem transcreve, e que não somos nós.
        $this->assertStringContainsString('o seu próprio navegador, e não o Lapispro', $corpo);
        // O destinatário, pelo nome. Sem isto o parágrafo diz «sai» e não diz para onde.
        $this->assertStringContainsString('o fornecedor dele — hoje, no Chrome e no Edge, é este segundo caso, e o fornecedor é a Google', $corpo);
        // E o que continua a ser verdade: nós não guardamos som.
        $this->assertStringContainsString('o som passa por nós nem é gravado', $corpo);
    }

    /**
     * A Política tem de nomear o GitHub antes de a exportação ligar.
     *
     * O ADR-0013 fez desta nomeação uma condição para configurar o token — um
     * reporte exportado é a única parte deste domínio que sai para uma
     * plataforma de terceiros. Três afirmações, e cada uma prende uma promessa
     * diferente: quem recebe (subcontratante nomeado, com jurisdição), o que
     * NUNCA segue (texto, identidade, imagens — é a lista `ALLOWED` do
     * `IssueGithubPayload` dita por palavras), e o que acontece no fim do prazo
     * — incluindo o que o GitHub não deixa apagar.
     */
    #[Test]
    public function the_policy_names_github_and_what_never_leaves(): void
    {
        $texto = $this->privacyText();
        $suporte = implode(' ', $this->sectionBody('Contactos e suporte'));
        $subcontratantes = implode(' ', $this->sectionBody('Subcontratantes'));
        $prazos = implode(' ', $this->sectionBody('Durante quanto tempo'));
        $transferencias = implode(' ', $this->sectionBody('Transferências internacionais'));

        // Quem recebe, e onde.
        $this->assertStringContainsString('GitHub, Inc.', $subcontratantes);
        $this->assertStringContainsString('Estados Unidos da América', $subcontratantes);
        $this->assertStringContainsString('GitHub', $transferencias);

        // O que nunca segue — a frase que transforma a lista ALLOWED em promessa.
        $this->assertStringContainsString('Não seguem o texto que escreveu, o seu nome, o seu email, nem as imagens', $suporte);

        // Caso a caso, não automático — a decisão que o ADR-0013 regista.
        $this->assertStringContainsString('caso a caso', $suporte);

        // E a exceção à eliminação, dita em vez de implícita: reescrever e
        // fechar é o máximo que a API permite, e o histórico de edições fica.
        $this->assertStringContainsString('histórico de edições', $prazos);
        $this->assertStringContainsString('reescrito', $prazos);

        // Sem markdown a fingir ênfase: a página renderiza texto plano.
        $this->assertStringNotContainsString('**', $texto);
    }

    /**
     * O parágrafo do formulário público tem de dizer que é do público.
     *
     * «Não guardamos informação sobre o seu navegador» continua verdadeiro para
     * `/contacto` e deixou de o ser para o botão de dentro da aplicação. Uma
     * afirmação verdadeira sobre metade de um canal, escrita como se fosse sobre
     * o canal inteiro, é lida como uma promessa que não se cumpre.
     */
    #[Test]
    public function the_public_form_paragraph_says_it_is_about_the_public_form(): void
    {
        $corpo = implode(' ', $this->sectionBody('Contactos e suporte'));

        $this->assertStringContainsString('o público, que pode usar sem conta', $corpo);
        $this->assertStringContainsString('Esse formulário não aceita ficheiros anexos', $corpo);
    }

    /**
     * E o que a Política diz sobre o formulário público tem de continuar a ser
     * verdade NO CÓDIGO — não basta escrevê-lo.
     */
    #[Test]
    public function the_public_form_really_refuses_the_technical_context(): void
    {
        $this->post('/contacto', [
            'requester_name' => 'Ana',
            'requester_email' => 'ana@exemplo.pt',
            'category' => 'access',
            'subject' => 'Não entro',
            'description' => 'A palavra-passe não é aceite.',
            'client_context' => ['environment' => ['browser' => 'chrome', 'browser_major' => 151]],
        ])->assertRedirect();

        $this->assertNull(SupportRequest::query()->sole()->client_context);
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
    public function the_policy_discloses_authorized_technical_access_and_its_audit_record(): void
    {
        $corpo = implode(' ', $this->sectionBody('Acesso técnico da nossa equipa'));

        $this->assertStringContainsString('pessoal técnico', mb_strtolower($corpo));
        $this->assertStringContainsString('registo de atividade', $corpo);
    }

    #[Test]
    public function the_effective_dates_moved_for_all_three_legal_documents(): void
    {
        $this->assertSame(
            config('lapis.legal.privacy_effective_from'),
            LegalDocuments::privacy()['effective_from'],
        );

        $this->assertSame('2026-09-03', config('lapis.legal.terms_effective_from'));
        $this->assertSame('2026-09-03', config('lapis.legal.privacy_effective_from'));
        $this->assertSame('2026-09-03', config('lapis.legal.processing_effective_from'));
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
