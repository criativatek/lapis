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
     * Alguém que procure «política de privacidade Lapispro» tem de lá chegar sem
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
            'Quem responde pelo quê',
            'Dados do professor',
            'Dados dos alunos',
            'Dados técnicos',
            'Ficheiros',
            'Para que usamos os dados',
            'Com que fundamento tratamos os dados da sua conta',
            'Categorias especiais de dados',
            'Dados de menores',
            'Inteligência artificial',
            'Durante quanto tempo',
            'Os seus direitos',
            'Segurança',
            'Cookies e armazenamento no navegador',
            'Subcontratantes',
            'Transferências internacionais',
            // A Central de Suporte trouxe um canal que a Política descrevia
            // apenas como um endereço de email, e um titular — o visitante sem
            // conta — que o fundamento enunciado para «os dados da sua conta»
            // não cobria.
            'Contactos e suporte',
        ] as $required) {
            $this->assertTrue($headings->contains($required), "Falta a secção «{$required}».");
        }
    }

    #[Test]
    public function the_terms_cover_what_they_have_to_cover(): void
    {
        $headings = collect(LegalDocuments::terms()['sections'])->pluck('heading');

        foreach ([
            'O que é o Lapispro',
            'A sua conta',
            'Aceitação e versões destes Termos',
            'Utilização aceitável',
            'Dados pedagógicos e decisões',
            'Proteção de dados: quem responde pelo quê',
            'Disponibilidade e evolução',
            'Planos e condições comerciais',
            'Encerramento da conta',
            'Propriedade intelectual',
            'Limitação de responsabilidade',
            'Alterações a estes Termos',
            'Lei aplicável e resolução de litígios',
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
     * A REGRESSÃO QUE ESTE TESTE EXISTE PARA IMPEDIR JÁ ACONTECEU UMA VEZ.
     *
     * Até à 0.86.0 a Política afirmava que não eram enviados «os resultados, as
     * classificações, as autoavaliações» e que TODOS os números e datas saíam
     * substituídos por marcadores. Era uma descrição rigorosa de «Aperfeiçoar
     * redação» — onde continua verdadeira — enunciada como regra do módulo
     * inteiro, e era falsa para as três leituras que existem precisamente para
     * interpretar resultados: sem eles não teriam objeto nenhum.
     *
     * O teste é por FRASE NEGATIVA, não por bloco de prosa: falha se a Política
     * voltar a negar em absoluto o envio de resultados, classificações ou
     * autoavaliações, e não se importa com a redação à volta. Um teste que
     * fixasse parágrafos inteiros quebraria em cada revisão de estilo e seria
     * apagado à terceira.
     */
    #[Test]
    public function the_ai_section_does_not_deny_sending_data_the_features_actually_send(): void
    {
        $text = mb_strtolower($this->aiSectionText());

        // Cada padrão é uma negação categórica de algo que o produto envia.
        $forbidden = [
            '/n[ãa]o\s+s[ãa]o\s+enviad[oa]s[^.]*\bresultados\b/u' => 'a Política nega enviar resultados, e a análise da avaliação envia-os',
            '/n[ãa]o\s+s[ãa]o\s+enviad[oa]s[^.]*\bclassifica[çc][õo]es\b/u' => 'a Política nega enviar classificações, e a análise da avaliação envia-as',
            '/n[ãa]o\s+s[ãa]o\s+enviad[oa]s[^.]*\bautoavalia[çc][õo]es\b/u' => 'a Política nega enviar autoavaliações, e as leituras enviam-nas',
            // A formulação absoluta. A substituição total de números por
            // marcadores é uma propriedade SÓ de «Aperfeiçoar redação», e a
            // Política diz isso com o âmbito na própria frase — «os números e
            // as datas dessa secção». Sem o âmbito, é falsa para as restantes.
            '/todos\s+os\s+n[úu]meros/u' => 'a substituição total de números por marcadores é uma propriedade só de «Aperfeiçoar redação»; a frase tem de trazer o âmbito consigo',
        ];

        foreach ($forbidden as $pattern => $why) {
            $this->assertDoesNotMatchRegularExpression($pattern, $text, $why.'.');
        }
    }

    /**
     * A outra metade da mesma honestidade: a secção tem de DIZER que informação
     * pedagógica pode ser enviada, e tem de dizer o que nunca é. Uma secção que
     * apenas deixasse de mentir, calando-se, passaria o teste acima e continuaria
     * a ser uma descrição inútil.
     */
    #[Test]
    public function the_ai_section_states_what_may_be_sent_and_what_never_is(): void
    {
        $text = mb_strtolower($this->aiSectionText());

        // O que a IA pode receber, por ser o objeto das funcionalidades.
        foreach (['resultados', 'classifica', 'autoavalia', 'dom[íi]nios', 'evolu'] as $expected) {
            $this->assertMatchesRegularExpression(
                '/'.$expected.'/u',
                $text,
                "A secção de IA não diz que «{$expected}» pode ser enviado, e pode.",
            );
        }

        // O que nunca é enviado. Identificadores diretos, um a um.
        foreach (['nomes reais', 'correio eletr', 'n[úu]meros de processo', 'ulid'] as $expected) {
            $this->assertMatchesRegularExpression(
                '/'.$expected.'/u',
                $text,
                "A secção de IA não diz que «{$expected}» nunca é enviado.",
            );
        }

        // O texto livre potencialmente sensível, excluído nesta versão.
        $this->assertMatchesRegularExpression('/texto livre/u', $text);
        $this->assertMatchesRegularExpression('/(sa[úu]de|sens[íi]vel)/u', $text);

        // O pipeline, pelo nome.
        $this->assertMatchesRegularExpression('/pseudonimiza/u', $text);
        $this->assertMatchesRegularExpression('/minimiza/u', $text);

        // E quem decide.
        $this->assertMatchesRegularExpression('/revist[oa]/u', $text, 'A secção não diz que o professor revê o que a IA devolve.');
    }

    /**
     * AS DUAS SITUAÇÕES TÊM DE ESTAR SEPARADAS, porque o produto pode prometer
     * coisas diferentes sobre cada uma.
     *
     * No contexto que o Lapispro monta sozinho, a garantia é forte e é
     * estrutural: allowlist, pseudonimização, sanitização. No texto que o
     * professor escreve à mão, não é — não se controla o que lá é escrito, e
     * uma Política que juntasse as duas estaria a estender ao segundo caso uma
     * garantia que só existe no primeiro.
     */
    #[Test]
    public function the_ai_section_separates_the_automatic_context_from_typed_text(): void
    {
        $text = mb_strtolower($this->aiSectionText());

        $this->assertMatchesRegularExpression(
            '/contexto que (a aplica[çc][ãa]o|o lapispro) monta/u',
            $text,
            'A Política não distingue o contexto automático do texto escrito à mão.',
        );
        $this->assertMatchesRegularExpression(
            '/(escreve [àa] m[ãa]o|escrito [àa] m[ãa]o)/u',
            $text,
            'A Política não fala do texto que o professor escreve à mão.',
        );

        // O aviso, a confirmação, e o facto de o texto não ser alterado.
        $this->assertMatchesRegularExpression('/aviso/u', $text);
        $this->assertMatchesRegularExpression('/confirma[çc][ãa]o/u', $text);
        $this->assertMatchesRegularExpression('/o texto n[ãa]o [ée] alterado/u', $text);

        // A deteção, e o limite dela dito às claras.
        $this->assertMatchesRegularExpression('/reduz o risco e n[ãa]o o elimina/u', $text);
        $this->assertMatchesRegularExpression('/reconhece formatos, n[ãa]o pessoas/u', $text);
        $this->assertMatchesRegularExpression('/n[ãa]o [ée] detetado/u', $text);

        // E que a verificação não sai da máquina de quem escreve.
        $this->assertMatchesRegularExpression('/nenhum servi[çc]o externo/u', $text);
    }

    /**
     * §8 DO ADENDO: a Política pode afirmar o que o LAPISPRO faz com um pedido
     * de IA. Não pode afirmar o que um fornecedor faz, porque não existe
     * nenhum escolhido — e uma promessa sobre a retenção de um subcontratante
     * desconhecido é uma promessa que ninguém pode cumprir.
     */
    #[Test]
    public function the_ai_section_makes_no_promise_about_an_unknown_provider(): void
    {
        $text = mb_strtolower($this->aiSectionText());

        // O que o Lapispro faz, dito com o sujeito à frente.
        $this->assertMatchesRegularExpression('/o que o lapispro guarda/u', $text);
        $this->assertMatchesRegularExpression('/n[ãa]o h[áa] mem[óo]ria entre pedidos/u', $text);
        $this->assertMatchesRegularExpression('/n[ãa]o s[ãa]o usados pelo lapispro para treinar/u', $text);

        // E a ressalva explícita sobre o fornecedor.
        $this->assertMatchesRegularExpression(
            '/depende do fornecedor e do contrato/u',
            $text,
            'A Política não ressalva que a retenção pelo fornecedor depende do fornecedor escolhido.',
        );

        // A formulação universal que seria uma promessa por conta de terceiros.
        $this->assertDoesNotMatchRegularExpression(
            '/^(?!.*lapispro).*n[ãa]o [ée] guardado o texto das perguntas nem o das respostas\./mu',
            $text,
            'A Política afirma a não-retenção sem dizer de quem é a afirmação.',
        );
    }

    /**
     * «Pseudonimizado», nunca «anónimo». A distinção é jurídica e é real: o
     * professor, com a pauta à frente, reconhece cada linha. Prometer anonimato
     * seria prometer o que o produto não faz — e é uma promessa fácil de
     * escrever por descuido.
     */
    #[Test]
    public function no_legal_document_promises_anonymisation_of_ai_payloads(): void
    {
        foreach (['privacy', 'processing', 'terms'] as $document) {
            foreach (LegalDocuments::{$document}()['sections'] as $section) {
                foreach ($section['body'] as $paragraph) {
                    $this->assertDoesNotMatchRegularExpression(
                        '/(?<!não\s)\b(?:são|é|fica|ficam|serão)\s+anonimizad/iu',
                        $paragraph,
                        "«{$section['heading']}» promete anonimização.",
                    );
                    $this->assertDoesNotMatchRegularExpression(
                        '/dados\s+an[óo]nimos/iu',
                        $paragraph,
                        "«{$section['heading']}» promete dados anónimos.",
                    );
                }
            }
        }
    }

    /**
     * O Acordo descreve a natureza do tratamento. Enquanto essa enumeração não
     * previa transmitir nada a um subcontratante de IA, era mais estreita do que
     * a aplicação — e a natureza do tratamento é exatamente o que um acordo
     * destes existe para fixar.
     */
    #[Test]
    public function the_processing_agreement_accounts_for_an_ai_subprocessor(): void
    {
        $nature = collect(LegalDocuments::processing()['sections'])
            ->firstWhere('heading', 'Objeto, duração e natureza do tratamento');

        $text = mb_strtolower(implode(' ', $nature['body']));

        $this->assertMatchesRegularExpression('/intelig[êe]ncia artificial/u', $text);
        $this->assertMatchesRegularExpression('/pseudonimizad/u', $text);
        // E continua a dizer a verdade sobre esta data.
        $this->assertMatchesRegularExpression('/n[ãa]o est[áa] configurado/u', $text);

        $subprocessors = collect(LegalDocuments::processing()['sections'])
            ->firstWhere('heading', 'Outros subcontratantes');

        $this->assertMatchesRegularExpression(
            '/intelig[êe]ncia artificial/u',
            mb_strtolower(implode(' ', $subprocessors['body'])),
            'O Acordo não diz como se enquadraria um fornecedor de IA.',
        );
    }

    /**
     * Nenhum documento legal nomeia um fornecedor de IA enquanto nenhum estiver
     * configurado — a identificação do subcontratante precede o tratamento
     * real, e não o contrário.
     */
    #[Test]
    public function no_legal_document_names_an_ai_vendor(): void
    {
        foreach (['privacy', 'processing', 'terms'] as $document) {
            $text = collect(LegalDocuments::{$document}()['sections'])
                ->flatMap(fn (array $section): array => $section['body'])
                ->implode(' ');

            foreach (['OpenAI', 'Anthropic', 'Gemini', 'ChatGPT', 'Claude', 'Mistral', 'Vertex'] as $vendor) {
                $this->assertStringNotContainsString($vendor, $text, "«{$document}» nomeia {$vendor}.");
            }
        }
    }

    private function aiSectionText(): string
    {
        $ai = collect(LegalDocuments::privacy()['sections'])
            ->firstWhere('heading', 'Inteligência artificial');

        $this->assertNotNull($ai, 'A Política perdeu a secção «Inteligência artificial».');

        return implode(' ', $ai['body']);
    }

    /**
     * A retenção diz os prazos que uma rotina cumpre, e cala-se sobre os
     * outros.
     *
     * Quatro números são executados por código: o encerramento de conta, a
     * disponibilidade de uma exportação, a rotação dos registos técnicos e a
     * das cópias de segurança. A diferenciação Base +2 / Pro +5 da Matriz
     * continua por implementar, e a eliminação por antiguidade de dados
     * pedagógicos também — nenhuma das duas pode ser afirmada.
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

        // O que nenhuma rotina apaga, o texto diz que não apaga.
        $this->assertStringContainsString('não existe hoje eliminação automática por antiguidade', $text);
        $this->assertStringContainsString('Não está definido um prazo automático de eliminação', $text);
    }

    /**
     * OS PRAZOS PUBLICADOS SÃO OS QUE O CÓDIGO EXECUTA. O texto não escreve
     * «60 dias» à mão: lê `config('retention.*')`, que é o mesmo sítio de onde
     * `retention:execute` e `data-exports:prune` leem. Este teste falha no dia
     * em que alguém mudar um dos números e a página continuar a prometer o
     * antigo.
     */
    #[Test]
    public function the_published_retention_periods_are_the_ones_the_code_enforces(): void
    {
        config([
            'retention.personal_account_closure_days' => 45,
            'retention.data_export_availability_hours' => 12,
        ]);

        $retention = collect(LegalDocuments::privacy()['sections'])
            ->firstWhere('heading', 'Durante quanto tempo');

        $this->assertStringContainsString(
            'período de recuperação de 45 dias',
            implode(' ', $retention['body']),
        );
        $this->assertStringContainsString(
            'disponíveis 12 horas',
            implode(' ', $retention['body']),
        );

        // E o mesmo prazo nos Termos e no Acordo, que descrevem o mesmo
        // encerramento. Três documentos, um número.
        foreach ([LegalDocuments::terms(), LegalDocuments::processing()] as $document) {
            $this->assertStringContainsString(
                '45 dias',
                (string) json_encode($document, JSON_UNESCAPED_UNICODE),
            );
        }
    }

    /** Só cookies estritamente necessários — auditado, e por isso sem banner. */
    #[Test]
    public function the_cookie_section_matches_the_audit(): void
    {
        $cookies = collect(LegalDocuments::privacy()['sections'])
            ->firstWhere('heading', 'Cookies e armazenamento no navegador');

        $text = mb_strtolower(implode(' ', $cookies['body']));

        $this->assertStringContainsString('estritamente necessários', $text);
        $this->assertStringContainsString('não há cookies de publicidade', $text);
        $this->assertStringContainsString('não é apresentado pedido de consentimento', $text);

        // SEM CONCLUSÃO JURÍDICA ABSOLUTA. O que a página afirma é o que a
        // auditoria técnica encontrou; decidir que nenhum destes elementos
        // exige consentimento é uma qualificação jurídica, e a página diz isso
        // em vez de a fazer.
        $this->assertStringContainsString('sujeita a validação', $text);
        $this->assertStringContainsString('passará a ser pedido', $text);
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

    /**
     * A terceira página existe, é pública, e é encontrável.
     *
     * PÚBLICA APESAR DE SÓ SE APLICAR A QUEM TEM CONTA. Um professor que esteja
     * a decidir se põe ali os alunos da sua turma tem de poder ler o acordo de
     * subcontratação ANTES de criar conta. Um acordo que só se lê depois de
     * aceite é um acordo que ninguém leu.
     */
    #[Test]
    public function the_processing_agreement_is_public_indexable_and_listed(): void
    {
        config(['lapis.public_url' => 'https://lapispro.com']);

        $page = $this->get('/tratamento-de-dados')->assertOk();

        $page->assertInertia(fn (Assert $page) => $page->component('legal/Document'));
        $page->assertSee('<link rel="canonical" href="https://lapispro.com/tratamento-de-dados">', false);
        $page->assertSee('name="robots" content="index, follow', false);

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee('<loc>https://lapispro.com/tratamento-de-dados</loc>', false);

        $this->get('/robots.txt')
            ->assertOk()
            ->assertSee('Allow: /tratamento-de-dados', false);
    }

    /**
     * Três documentos que se remetem uns para os outros, e nenhum que se remeta
     * a si próprio. Um «ver também» que aponta para a página em que já se está
     * é ruído.
     */
    #[Test]
    public function the_three_documents_link_to_each_other(): void
    {
        $expected = [
            '/termos' => LegalDocuments::terms(),
            '/privacidade' => LegalDocuments::privacy(),
            '/tratamento-de-dados' => LegalDocuments::processing(),
        ];

        foreach ($expected as $self => $document) {
            $hrefs = array_column($document['related'], 'href');

            $this->assertCount(2, $hrefs);
            $this->assertNotContains($self, $hrefs);

            foreach (array_diff(array_keys($expected), [$self]) as $other) {
                $this->assertContains($other, $hrefs);
            }
        }
    }

    /**
     * A REPARTIÇÃO RESPONSÁVEL/SUBCONTRATANTE — E O QUE ELA NÃO PODE PRESSUPOR.
     *
     * A HORIZONLEVEL é responsável pelos dados da conta e subcontratante dos
     * dados dos alunos. Quem é o responsável destes últimos **depende do
     * professor**: um que exerça a atividade a título próprio determina ele
     * mesmo as finalidades; outro atua sob a autoridade de uma escola, e aí a
     * responsável é a instituição. Os textos comportam as duas situações e não
     * escolhem por ninguém.
     */
    #[Test]
    public function the_controller_processor_split_is_stated_in_both_directions(): void
    {
        $privacy = collect(LegalDocuments::privacy()['sections'])
            ->firstWhere('heading', 'Quem responde pelo quê');

        $text = implode(' ', $privacy['body']);

        $this->assertStringContainsString('responsável pelo tratamento', $text);
        $this->assertStringContainsString('subcontratante', $text);
        $this->assertStringContainsString(
            'deve fazê-lo enquanto responsável pelo tratamento ou devidamente autorizado pelo responsável pelo tratamento competente',
            $text,
        );

        // A formulação sóbria, factual, sobre o que a HORIZONLEVEL não faz —
        // que é uma afirmação verificável, ao contrário de uma sobre quem é o
        // responsável.
        $this->assertStringContainsString(
            'não determina as respetivas finalidades pedagógicas nem define a base jurídica aplicável',
            $text,
        );

        $processing = collect(LegalDocuments::processing()['sections'])
            ->firstWhere('heading', 'Quem é quem');

        $agreement = implode(' ', $processing['body']);

        $this->assertStringContainsString(
            'Quem introduz dados de alunos no Lapispro deve fazê-lo enquanto responsável pelo tratamento',
            $agreement,
        );

        // As duas situações, ditas por extenso.
        $this->assertStringContainsString('a título próprio', $agreement);
        $this->assertStringContainsString('sob a autoridade de uma escola ou agrupamento', $agreement);
    }

    /**
     * NENHUM DOCUMENTO AFIRMA QUE TODO O PROFESSOR É O RESPONSÁVEL.
     *
     * Um professor que trate dados de alunos sob a autoridade de uma escola não
     * é o responsável — a instituição é. Um texto que o afirmasse genericamente
     * atribuiria a um professor uma qualidade jurídica que ele pode não ter, e
     * com ela obrigações que não são suas. Este teste varre os três documentos
     * à procura dessa afirmação, em qualquer das formas em que ela já esteve
     * escrita.
     */
    #[Test]
    public function no_document_asserts_that_every_teacher_is_the_controller(): void
    {
        foreach ([
            'termos' => LegalDocuments::terms(),
            'privacidade' => LegalDocuments::privacy(),
            'tratamento de dados' => LegalDocuments::processing(),
        ] as $name => $document) {
            $text = (string) json_encode($document, JSON_UNESCAPED_UNICODE);

            foreach ([
                'o responsável é o professor',
                'o professor é o responsável',
                'O professor titular da conta é o responsável',
                'responsável por eles é o professor',
                'por conta do professor',
                'exercem-se perante o professor',
                'e é por isso o responsável pelo tratamento',
            ] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $text,
                    "«{$forbidden}» reapareceu em {$name}: pressupõe que todo o professor é o responsável.",
                );
            }
        }
    }

    /**
     * NENHUM DOCUMENTO ASSUME QUE TODO O PROFESSOR É CONSUMIDOR.
     *
     * Um professor pode contratar no exercício de uma atividade profissional e
     * não ser consumidor. A ressalva é condicional — «quando lhe seja aplicável
     * a legislação de proteção dos consumidores» —, o que é verdadeiro nos dois
     * casos e não classifica ninguém.
     */
    #[Test]
    public function no_document_assumes_the_teacher_is_a_consumer(): void
    {
        $terms = (string) json_encode(LegalDocuments::terms(), JSON_UNESCAPED_UNICODE);

        $this->assertSame(
            2,
            mb_substr_count($terms, 'Quando lhe seja aplicável a legislação de proteção dos consumidores'),
        );

        foreach ([
            'na qualidade de consumidor',
            'enquanto consumidor',
            'que a lei confere a quem contrata na qualidade de consumidor',
            'Se utilizar o Lapispro na qualidade de consumidor',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $terms);
        }
    }

    /**
     * Os fundamentos são os três que se aplicam à CONTA, e nenhum é invocado
     * para os dados dos alunos.
     */
    #[Test]
    public function the_legal_bases_cover_the_account_and_never_the_pedagogical_data(): void
    {
        $bases = collect(LegalDocuments::privacy()['sections'])
            ->firstWhere('heading', 'Com que fundamento tratamos os dados da sua conta');

        $text = implode(' ', $bases['body']);

        $this->assertStringContainsString('Execução do contrato', $text);
        $this->assertStringContainsString('Cumprimento de obrigações legais', $text);
        $this->assertStringContainsString('Interesse legítimo', $text);

        // O interesse legítimo tem de vir ponderado, não afirmado.
        $this->assertStringContainsString('Ponderámos este interesse', $text);

        $this->assertStringContainsString(
            'Não são invocados para os dados pedagógicos dos alunos: esses são tratados por conta do responsável pelo tratamento',
            $text,
        );
    }

    /**
     * Menores: descreve-se a posição, e NÃO se recolhe consentimento parental.
     *
     * O Lapispro não é oferecido a menores nem a encarregados de educação, e
     * não existe caminho na aplicação por onde um consentimento parental
     * entrasse. Dizer que se recolhe seria descrever um mecanismo que não
     * existe — que é a forma mais fácil de uma página legal passar a mentir.
     */
    #[Test]
    public function the_minors_section_states_the_position_without_claiming_parental_consent(): void
    {
        $minors = collect(LegalDocuments::privacy()['sections'])
            ->firstWhere('heading', 'Dados de menores');

        $text = implode(' ', $minors['body']);

        $this->assertStringContainsString('menores de idade', $text);
        $this->assertStringContainsString('não recolhemos nem verificamos consentimento parental', $text);
        $this->assertStringContainsString('não é oferecido a menores', mb_strtolower($text));

        // E não se promete um mecanismo que não existe em lado nenhum.
        $full = mb_strtolower((string) json_encode(LegalDocuments::privacy(), JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('autorização do encarregado de educação', $full);
        $this->assertStringNotContainsString('mediante consentimento dos pais', $full);
    }

    /**
     * Categorias especiais: o modelo de dados não tem campo nenhum para elas, e
     * é isso que a página diz — ver `docs/domain-model.md` §11.3, «sem dados de
     * saúde, NEE ou categorias especiais».
     */
    #[Test]
    public function the_special_categories_section_matches_the_data_model(): void
    {
        $special = collect(LegalDocuments::privacy()['sections'])
            ->firstWhere('heading', 'Categorias especiais de dados');

        $text = implode(' ', $special['body']);

        $this->assertStringContainsString('não foi concebido para tratar categorias especiais', $text);
        $this->assertStringContainsString('não tem campos de saúde nem de necessidades educativas especiais', $text);
        $this->assertStringContainsString('texto livre', $text);

        // As medidas de suporte descrevem a ação do professor, não o estatuto
        // formal do aluno — a distinção que `SupportMeasureLevel` documenta.
        $this->assertStringContainsString('Não afirmam nem inferem o estatuto formal do aluno', $text);
    }

    /**
     * A autoridade tem nome, e é o certo para Portugal.
     */
    #[Test]
    public function the_supervisory_authority_is_named(): void
    {
        $rights = collect(LegalDocuments::privacy()['sections'])
            ->firstWhere('heading', 'Os seus direitos');

        $text = implode(' ', $rights['body']);

        $this->assertStringContainsString('Comissão Nacional de Proteção de Dados (CNPD)', $text);
    }

    /**
     * A EXPORTAÇÃO NÃO É O DIREITO DE PORTABILIDADE, e a página não os
     * confunde. São coisas diferentes: uma é uma funcionalidade do produto, o
     * outro é um direito cujo âmbito a lei define. Apresentar a primeira como
     * cumprimento integral do segundo é a forma educada de o restringir.
     */
    #[Test]
    public function the_export_feature_is_not_presented_as_the_portability_right(): void
    {
        $rights = collect(LegalDocuments::privacy()['sections'])
            ->firstWhere('heading', 'Os seus direitos');

        $text = implode(' ', $rights['body']);

        $this->assertStringContainsString('é uma funcionalidade do produto', $text);
        $this->assertStringContainsString('não se confunde com eles nem esgota o direito de portabilidade', $text);

        // E os direitos sobre os dados dos alunos exercem-se perante o
        // responsável pelo tratamento, não perante nós — seja ele o professor
        // ou a escola.
        $this->assertStringContainsString(
            'exercem-se perante o responsável pelo tratamento — o professor ou a escola, consoante o caso',
            $text,
        );
    }

    /**
     * Lei portuguesa, e um foro prudente. NUNCA «foro exclusivo de Leiria»:
     * uma cláusula de foro exclusivo contra quem contrata como consumidor é
     * precisamente do género que um tribunal desconsidera.
     */
    #[Test]
    public function the_governing_law_is_portuguese_and_the_forum_is_not_exclusive(): void
    {
        $law = collect(LegalDocuments::terms()['sections'])
            ->firstWhere('heading', 'Lei aplicável e resolução de litígios');

        $text = implode(' ', $law['body']);

        $this->assertStringContainsString('lei portuguesa', $text);
        $this->assertStringContainsString('tribunais territorialmente competentes nos termos da lei', $text);

        $lower = mb_strtolower($text);
        $this->assertStringNotContainsString('foro exclusivo', $lower);
        $this->assertStringNotContainsString('comarca de leiria', $lower);
        $this->assertStringNotContainsString('com renúncia a qualquer outro', $lower);
    }

    /**
     * Só os fornecedores que se conseguem comprovar. Contabo e Cloudflare estão
     * documentados em `docs/deployment.md`; o servidor de correio é configurado
     * pelo operador no backoffice e não é comprovável a partir do repositório —
     * e por isso não é nomeado.
     */
    #[Test]
    public function only_verifiable_subprocessors_are_named(): void
    {
        $subprocessors = collect(LegalDocuments::privacy()['sections'])
            ->firstWhere('heading', 'Subcontratantes');

        $text = implode(' ', $subprocessors['body']);

        $this->assertStringContainsString('Contabo GmbH', $text);
        $this->assertStringContainsString('Cloudflare, Inc.', $text);
        $this->assertStringContainsString('Não nomeamos aqui o fornecedor', $text);

        // Nenhum fornecedor plausível mas não verificado.
        foreach (['Mailgun', 'Postmark', 'SendGrid', 'Resend', 'Amazon', 'Google Cloud', 'Azure'] as $unverified) {
            $this->assertStringNotContainsString($unverified, $text);
        }
    }

    /**
     * As transferências são ditas com prudência: o que se sabe, e o que ainda
     * não se pode afirmar.
     */
    #[Test]
    public function international_transfers_are_stated_prudently(): void
    {
        $transfers = collect(LegalDocuments::privacy()['sections'])
            ->firstWhere('heading', 'Transferências internacionais');

        $text = implode(' ', $transfers['body']);

        $this->assertStringContainsString('Alemanha', $text);
        $this->assertStringContainsString('Estados Unidos da América', $text);
        $this->assertStringContainsString('está em curso', $text);

        // Nenhuma garantia que ninguém verificou.
        $lower = mb_strtolower($text);
        $this->assertStringNotContainsString('todos os dados são tratados exclusivamente', $lower);
        $this->assertStringNotContainsString('cláusulas contratuais-tipo', $lower);
    }

    /**
     * As garantias sobre IA mantêm-se, e NÃO é feita nenhuma classificação de
     * risco ao abrigo do Regulamento da IA.
     *
     * Essa qualificação é jurídica, depende de análise, e uma página que a
     * afirme está a decidir uma questão que não decidiu. A nota interna vive em
     * `docs/legal.md`, que é onde pertence.
     */
    #[Test]
    public function the_ai_section_makes_no_regulatory_risk_classification(): void
    {
        $ai = collect(LegalDocuments::privacy()['sections'])
            ->firstWhere('heading', 'Inteligência artificial');

        $text = implode(' ', $ai['body']);

        // As garantias que já existiam continuam lá.
        $this->assertStringContainsString('sugere', $text);
        $this->assertStringContainsString('substituídos por designações genéricas', $text);
        // Scoped to the Lapispro since 0.86.0: a promise about what a provider
        // that does not yet exist does with content is a promise nobody can
        // keep — see `the_ai_section_makes_no_promise_about_an_unknown_provider`.
        $this->assertStringContainsString('não são usados pelo lapispro para treinar modelos', mb_strtolower($text));
        $this->assertStringContainsString('Não existem decisões automatizadas', $text);

        // E nenhuma classificação regulamentar.
        $lower = mb_strtolower($text);
        foreach ([
            'risco elevado',
            'alto risco',
            'risco limitado',
            'risco mínimo',
            'regulamento da ia',
            'ai act',
        ] as $classification) {
            $this->assertStringNotContainsString($classification, $lower);
        }
    }

    /**
     * O Institucional não está disponível, e nenhum documento promete um
     * contrato institucional que não existe.
     */
    #[Test]
    public function no_document_promises_an_institutional_contract_that_does_not_exist(): void
    {
        $terms = collect(LegalDocuments::terms()['sections'])
            ->firstWhere('heading', 'Planos e condições comerciais');

        $this->assertStringContainsString(
            'ainda não está disponível para adesão',
            implode(' ', $terms['body']),
        );

        $processing = collect(LegalDocuments::processing()['sections'])
            ->firstWhere('heading', 'Quem é quem');

        $this->assertStringContainsString(
            'ainda não está disponível para adesão, e será enquadrada em documento próprio quando estiver',
            implode(' ', $processing['body']),
        );
    }

    /** Os três documentos têm de dizer desde quando valem. */
    #[Test]
    public function every_document_carries_an_effective_date(): void
    {
        foreach ([
            LegalDocuments::terms(),
            LegalDocuments::privacy(),
            LegalDocuments::processing(),
        ] as $document) {
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
