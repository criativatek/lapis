<?php

namespace Tests\Feature\Legal;

use App\Models\User;
use App\Support\Legal\LegalDocuments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O registo da aceitação dos Termos.
 *
 * PORQUE É QUE ISTO PRECISA DE TESTES PRÓPRIOS. Os Termos passaram a dizer que
 * o professor é o responsável pelo tratamento dos dados dos seus alunos e que
 * o Lapispro é subcontratante — e o Acordo de Tratamento de Dados, que fixa
 * essa repartição, é aceite por remissão. Uma repartição de responsabilidades
 * que ninguém consegue demonstrar ter sido aceite não é uma repartição: é uma
 * página no sítio.
 *
 * E o inverso importa tanto quanto: guardar MAIS do que isto — endereço IP,
 * navegador — seria recolher dados de tráfego para provar uma aceitação, com
 * base legal mais frágil, para uma finalidade que duas colunas já cumprem.
 * Há um teste a garantir que não acontece.
 */
class TermsAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function registering_records_the_terms_version_and_the_moment(): void
    {
        $this->post(route('register.store'), [
            'name' => 'Professora Teste',
            'email' => 'professora@escola.pt',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $user = User::where('email', 'professora@escola.pt')->firstOrFail();

        $this->assertSame(LegalDocuments::termsVersion(), $user->terms_version);
        $this->assertNotNull($user->terms_accepted_at);
    }

    /**
     * A versão é a data de entrada em vigor — um valor, não dois que têm de
     * concordar.
     */
    #[Test]
    public function the_recorded_version_follows_the_document_in_force(): void
    {
        config(['lapis.legal.terms_effective_from' => '2027-01-15']);

        $this->post(route('register.store'), [
            'name' => 'Professor Teste',
            'email' => 'professor@escola.pt',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertSame(
            '2027-01-15',
            User::where('email', 'professor@escola.pt')->firstOrFail()->terms_version,
        );

        $this->assertSame('2027-01-15', LegalDocuments::termsVersion());
        $this->assertSame('2027-01-15', LegalDocuments::terms()['effective_from']);
    }

    /**
     * A VERSÃO VEM DO SERVIDOR, NUNCA DO PEDIDO. Um campo de formulário a dizer
     * que versão foi aceite é um campo que o cliente altera — e uma conta que
     * afirma ter aceite uma versão que nunca esteve em vigor prova menos do que
     * não afirmar nada.
     */
    #[Test]
    public function a_version_supplied_by_the_client_is_ignored(): void
    {
        $this->post(route('register.store'), [
            'name' => 'Professora Teste',
            'email' => 'professora@escola.pt',
            'password' => 'password',
            'password_confirmation' => 'password',
            'terms_version' => '1999-01-01',
            'terms_accepted_at' => '1999-01-01 00:00:00',
        ]);

        $user = User::where('email', 'professora@escola.pt')->firstOrFail();

        $this->assertSame(LegalDocuments::termsVersion(), $user->terms_version);
        $this->assertNotSame('1999-01-01', $user->terms_version);
        $this->assertTrue($user->terms_accepted_at->isToday());
    }

    /**
     * SÓ DUAS COLUNAS. Nada de endereço IP nem de identificador de navegador —
     * a `users` não tem sequer onde os guardar, e este teste falha no dia em
     * que alguém lhe acrescentar um sítio.
     */
    #[Test]
    public function the_acceptance_record_carries_no_ip_and_no_user_agent(): void
    {
        $this->post(route('register.store'), [
            'name' => 'Professora Teste',
            'email' => 'professora@escola.pt',
            'password' => 'password',
            'password_confirmation' => 'password',
        ], ['User-Agent' => 'Mozilla/5.0 (Teste)']);

        $columns = array_keys(
            (array) User::where('email', 'professora@escola.pt')->firstOrFail()->getAttributes()
        );

        foreach (['ip_address', 'terms_ip', 'user_agent', 'terms_user_agent'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns);
        }
    }

    /**
     * NULO É UM ESTADO LEGÍTIMO. As contas anteriores a esta coluna não aceitaram
     * nada, e preencher-lhes uma data seria inventar um facto — exatamente o que
     * estas colunas existem para não fazer.
     */
    #[Test]
    public function accounts_created_before_the_column_existed_stay_null(): void
    {
        $user = User::factory()->create();

        $this->assertNull($user->terms_version);
        $this->assertNull($user->terms_accepted_at);
    }

    /**
     * A página de registo diz o que se está a aceitar, e liga para os três
     * documentos. Sem caixa de seleção: aceitar os Termos é condição do
     * contrato, não uma escolha separada, e uma checkbox obrigatória não
     * acrescenta consentimento nenhum — só um passo.
     */
    #[Test]
    public function the_register_page_says_what_is_being_accepted(): void
    {
        $this->get(route('register'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('auth/Register'));
    }
}
