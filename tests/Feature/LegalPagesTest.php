<?php

namespace Tests\Feature;

use App\Support\Legal\LegalDocuments;
use App\Support\Seo\LandingSeo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * As duas páginas que a auditoria de prontidão marcou como P0.
 *
 * O que estes testes protegem não é o layout — é a honestidade. Uma página de
 * privacidade que abre com uma morada inventada é pior do que não existir, e a
 * forma de garantir que isso não acontece é falhar a build quando acontecer.
 */
class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function both_pages_are_public_and_need_no_account(): void
    {
        $this->get('/termos')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('legal/Document'));

        $this->get('/privacidade')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('legal/Document'));
    }

    /**
     * Alguém que procure «política de privacidade LÁPIS» tem de lá chegar sem
     * passar pela landing. Cada uma canonicaliza-se a si própria, não à raiz —
     * um canonical para `/` diria ao motor de busca que a página não existe
     * como resultado.
     */
    #[Test]
    public function both_pages_are_indexable_and_canonical_to_themselves(): void
    {
        config(['lapis.public_url' => 'https://lapispro.com']);

        $terms = $this->get('/termos')->assertOk();
        $terms->assertSee('<link rel="canonical" href="https://lapispro.com/termos">', false);
        $terms->assertSee('name="robots" content="index, follow', false);

        $privacy = $this->get('/privacidade')->assertOk();
        $privacy->assertSee('<link rel="canonical" href="https://lapispro.com/privacidade">', false);
        $privacy->assertSee('name="robots" content="index, follow', false);
    }

    #[Test]
    public function the_sitemap_and_robots_list_them(): void
    {
        config(['lapis.public_url' => 'https://lapispro.com']);

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee('<loc>https://lapispro.com/termos</loc>', false)
            ->assertSee('<loc>https://lapispro.com/privacidade</loc>', false);

        $this->get('/robots.txt')
            ->assertOk()
            ->assertSee('Allow: /termos', false)
            ->assertSee('Allow: /privacidade', false);
    }

    /**
     * NADA FICTÍCIO APRESENTADO COMO FACTO. Nenhum destes valores existia no
     * projeto, e a página tem de dizer «por definir» em vez de inventar um.
     * Este teste falha no dia em que alguém encher um placeholder com um nome
     * plausível para «ficar bem» antes da revisão jurídica.
     */
    #[Test]
    public function no_invented_controller_identity_is_presented_as_fact(): void
    {
        config([
            'lapis.legal.controller_name' => null,
            'lapis.legal.controller_vat' => null,
            'lapis.legal.controller_address' => null,
            'lapis.legal.privacy_email' => null,
        ]);

        $controller = LegalDocuments::controller();

        $this->assertFalse($controller['complete']);
        $this->assertNull($controller['name']);
        $this->assertNull($controller['privacy_email']);

        $this->get('/privacidade')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('controller.complete', false)
                ->where('controller.name', null)
            );
    }

    /**
     * A identidade em vigor é a real, e chega à página.
     *
     * Estes valores foram confirmados a 2026-08-27 e são defaults do config —
     * não variáveis de ambiente. Se voltassem a depender só do `.env`,
     * produção mostraria «Por definir» até alguém definir quatro variáveis, que
     * é exatamente a falha que esta fatia existe para fechar.
     */
    #[Test]
    public function the_confirmed_controller_identity_is_in_force(): void
    {
        $controller = LegalDocuments::controller();

        $this->assertTrue($controller['complete']);
        $this->assertSame('HORIZONLEVEL, LDA', $controller['name']);
        $this->assertSame('513354166', $controller['vat']);
        $this->assertStringContainsString('Leiria', (string) $controller['address']);
        $this->assertSame('privacidade@lapispro.com', $controller['privacy_email']);

        $this->get('/privacidade')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('controller.complete', true)
                ->where('controller.name', 'HORIZONLEVEL, LDA')
            );
    }

    /**
     * Cada endereço no sítio onde tem motivo para estar, e não espalhado.
     * `contas@` aparece uma única vez, na secção da conta dos Termos.
     */
    #[Test]
    public function each_official_contact_is_used_where_it_belongs(): void
    {
        $privacy = json_encode(LegalDocuments::privacy(), JSON_UNESCAPED_UNICODE);
        $terms = json_encode(LegalDocuments::terms(), JSON_UNESCAPED_UNICODE);

        // Privacidade: o endereço de RGPD, e o de suporte na finalidade «suporte».
        $this->assertStringContainsString('privacidade@lapispro.com', (string) $privacy);
        $this->assertStringContainsString('suporte@lapispro.com', (string) $privacy);
        $this->assertStringNotContainsString('contas@lapispro.com', (string) $privacy);

        // Termos: só o de conta.
        $this->assertStringContainsString('contas@lapispro.com', (string) $terms);
        $this->assertSame(1, mb_substr_count((string) $terms, 'contas@lapispro.com'));

        // A entidade prestadora, dita por extenso nos dois documentos.
        $this->assertStringContainsString('HORIZONLEVEL, LDA', (string) $terms);
        $this->assertStringContainsString('HORIZONLEVEL, LDA', (string) $privacy);

        // A Criativatek não é a entidade jurídica e não aparece.
        $this->assertStringNotContainsString('Criativatek', (string) $terms);
        $this->assertStringNotContainsString('Criativatek', (string) $privacy);
    }

    /** Quando os valores existem, são apresentados — e `complete` diz que sim. */
    #[Test]
    public function a_configured_controller_identity_is_shown(): void
    {
        config([
            'lapis.legal.controller_name' => 'Entidade Exemplo, Lda.',
            'lapis.legal.controller_vat' => '999999990',
            'lapis.legal.controller_address' => 'Rua Exemplo 1, Leiria',
            'lapis.legal.privacy_email' => 'privacidade@exemplo.pt',
        ]);

        $controller = LegalDocuments::controller();

        $this->assertTrue($controller['complete']);
        $this->assertSame('privacidade@exemplo.pt', $controller['privacy_email']);
    }

    /**
     * O conteúdo mínimo que a auditoria exigiu. Assertado sobre o payload do
     * Inertia — que está no HTML — e não sobre o DOM: o SSR está desligado.
     */
    #[Test]
    public function the_privacy_policy_covers_what_it_has_to_cover(): void
    {
        $headings = collect(LegalDocuments::privacy()['sections'])->pluck('heading');

        foreach ([
            'Responsável pelo tratamento',
            'Dados do professor',
            'Dados dos alunos',
            'Dados técnicos',
            'Ficheiros',
            'Para que usamos os dados',
            'Fundamento do tratamento',
            'Inteligência artificial',
            'Durante quanto tempo',
            'Os seus direitos',
            'Dados de menores',
            'Segurança',
            'Cookies e armazenamento no navegador',
            'Subprocessadores e terceiros',
        ] as $required) {
            $this->assertTrue($headings->contains($required), "Falta a secção «{$required}».");
        }
    }

    #[Test]
    public function the_terms_cover_what_they_have_to_cover(): void
    {
        $headings = collect(LegalDocuments::terms()['sections'])->pluck('heading');

        foreach ([
            'O que é o LÁPIS',
            'A sua conta',
            'Utilização aceitável',
            'Dados pedagógicos e decisões',
            'Disponibilidade e evolução',
            'Planos e condições comerciais',
            'Encerramento da conta',
            'Propriedade intelectual',
            'Limitação de responsabilidade',
            'Alterações a estes Termos',
        ] as $required) {
            $this->assertTrue($headings->contains($required), "Falta a secção «{$required}».");
        }
    }

    /**
     * A descrição da IA tem de dizer a mesma coisa que o código faz: sugere,
     * não decide, e pode estar desligada. E não pode nomear um fornecedor —
     * não há nenhum configurado.
     */
    #[Test]
    public function the_ai_section_promises_only_what_the_product_does(): void
    {
        $ai = collect(LegalDocuments::privacy()['sections'])
            ->firstWhere('heading', 'Inteligência artificial');

        $text = implode(' ', $ai['body']);

        $this->assertStringContainsString('sugere', $text);
        $this->assertStringContainsString('professor decide', mb_strtolower($text));
        $this->assertStringContainsString('desativadas', $text);

        // Nenhum fornecedor nomeado: nenhum está configurado.
        foreach (['OpenAI', 'Anthropic', 'Google', 'Gemini', 'Azure', 'Mistral'] as $vendor) {
            $this->assertStringNotContainsString($vendor, $text);
        }
    }

    /**
     * A retenção não pode prometer o que a Fatia 3 deixou por fazer: a
     * diferenciação Base +2 / Pro +5 não está implementada, e a política não a
     * pode afirmar.
     */
    #[Test]
    public function the_retention_section_does_not_promise_unimplemented_rules(): void
    {
        $retention = collect(LegalDocuments::privacy()['sections'])
            ->firstWhere('heading', 'Durante quanto tempo');

        $text = implode(' ', $retention['body']);

        $this->assertStringNotContainsString('dois anos letivos', mb_strtolower($text));
        $this->assertStringNotContainsString('cinco anos letivos', mb_strtolower($text));
        $this->assertStringNotContainsString('instantânea', mb_strtolower($text));
        $this->assertStringContainsString('validação jurídica', $text);
    }

    /** Só cookies estritamente necessários — auditado, e por isso sem banner. */
    #[Test]
    public function the_cookie_section_matches_the_audit(): void
    {
        $cookies = collect(LegalDocuments::privacy()['sections'])
            ->firstWhere('heading', 'Cookies e armazenamento no navegador');

        $text = mb_strtolower(implode(' ', $cookies['body']));

        $this->assertStringContainsString('estritamente necessários', $text);
        $this->assertStringContainsString('não usa cookies de publicidade', $text);
        $this->assertStringContainsString('não é apresentado um pedido de consentimento', $text);

        // Sem conclusão jurídica absoluta: a auditoria técnica não encontrou
        // cookies não essenciais, o que não é o mesmo que decidir a questão
        // legal. E o armazenamento local é declarado, não só os cookies.
        $this->assertStringContainsString('sujeita a validação jurídica', $text);
        $this->assertStringContainsString('armazenamento local', $text);
    }

    /**
     * A palavra-passe não é «cifrada» — é reduzida a um hash irreversível. A
     * diferença importa: cifrado sugere que alguém, com a chave, a poderia ler.
     */
    #[Test]
    public function the_password_is_described_as_a_hash_and_never_as_encrypted(): void
    {
        $privacy = json_encode(LegalDocuments::privacy(), JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('representação criptográfica irreversível', (string) $privacy);
        $this->assertStringNotContainsString('versão cifrada da palavra-passe', (string) $privacy);
        $this->assertStringNotContainsString('palavras-passe são guardadas apenas em forma cifrada', (string) $privacy);
    }

    /**
     * Encerrar a conta não pode prometer apagar dados de uma escola. Só a
     * organização pessoal é do titular — ver `AnonymiseClosedAccount`, que
     * nunca toca numa organização institucional.
     */
    #[Test]
    public function closure_never_promises_to_delete_institutional_student_data(): void
    {
        $terms = json_encode(LegalDocuments::terms(), JSON_UNESCAPED_UNICODE);
        $privacy = json_encode(LegalDocuments::privacy(), JSON_UNESCAPED_UNICODE);

        foreach ([$terms, $privacy] as $document) {
            $this->assertStringContainsString('organização pessoal', (string) $document);
        }

        $this->assertStringContainsString(
            'não elimina dados de uma organização institucional',
            (string) $terms,
        );
        $this->assertStringContainsString('pertencem à instituição', (string) $privacy);
    }

    /** Ambos os documentos têm de dizer desde quando valem. */
    #[Test]
    public function both_documents_carry_an_effective_date(): void
    {
        foreach ([LegalDocuments::terms(), LegalDocuments::privacy()] as $document) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $document['effective_from']);
        }
    }

    /** A landing continua a responder e a canonicalizar-se à raiz. */
    #[Test]
    public function the_landing_page_is_unaffected(): void
    {
        config(['lapis.public_url' => 'https://lapispro.com']);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('<link rel="canonical" href="'.LandingSeo::canonical().'">', false)
            ->assertInertia(fn (Assert $page) => $page->component('Welcome'));
    }
}
