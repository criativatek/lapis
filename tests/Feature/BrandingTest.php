<?php

namespace Tests\Feature;

use App\Support\Legal\LegalDocuments;
use App\Support\Seo\LandingSeo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A marca é Lapispro, e o nome antigo não volta.
 *
 * PORQUE É QUE ISTO PRECISA DE TESTES. Um rebranding é uma substituição em 185
 * ficheiros, e o que falha numa substituição dessas não rebenta: uma página
 * fica com o nome antigo, mais ninguém repara, e passa a haver duas marcas em
 * produção. A regressão silenciosa é o modo de falha, e a única defesa é uma
 * afirmação que corre a cada build.
 *
 * DUAS CAMADAS, PORQUE O SSR ESTÁ DESLIGADO. O que o servidor renderiza —
 * título, `og:site_name`, o payload do Inertia com os documentos legais —
 * testa-se sobre a resposta. O que vive só num `.vue` nunca chega à resposta, e
 * por isso testa-se sobre o código-fonte: é feio, e é a única forma de o
 * apanhar sem montar um browser.
 *
 * O QUE NÃO É MARCA E NÃO É TESTADO AQUI. `lapis:build-package`,
 * `config/lapis.php`, as variáveis `LAPIS_*`, `DB_DATABASE=lapis`,
 * `LapisGridContract` e os nomes definidos `LAPIS_GRID` / `LAPIS_ITEM_*` dentro
 * dos ficheiros Excel. O último é um contrato de compatibilidade: renomeá-lo
 * partia todas as grelhas que um professor já descarregou.
 */
class BrandingTest extends TestCase
{
    use RefreshDatabase;

    /** As páginas públicas que um visitante vê antes de ter conta. */
    public static function publicPages(): array
    {
        return [
            'landing' => ['/'],
            'login' => ['/login'],
            'registo' => ['/register'],
            'termos' => ['/termos'],
            'privacidade' => ['/privacidade'],
            'tratamento de dados' => ['/tratamento-de-dados'],
        ];
    }

    #[DataProvider('publicPages')]
    #[Test]
    public function no_public_page_still_carries_the_old_brand(string $path): void
    {
        $html = $this->get($path)->assertOk()->getContent();

        $this->assertStringNotContainsString('LÁPIS', (string) $html);
        $this->assertStringNotContainsString('Lápis', (string) $html);

        // `LAPIS` sem acento apanharia `LAPIS_GRID` e os nomes de variáveis de
        // ambiente, que não são marca. Procura-se a palavra isolada.
        $this->assertDoesNotMatchRegularExpression('/\bLAPIS\b(?!_)/', (string) $html);
    }

    #[DataProvider('publicPages')]
    #[Test]
    public function every_public_page_names_the_product(string $path): void
    {
        $this->get($path)
            ->assertOk()
            ->assertSee('Lapispro', false);
    }

    /**
     * O título e o `og:site_name` são renderizados pelo servidor e são o que um
     * motor de busca lê. Foram os primeiros a divergir da última vez.
     */
    #[Test]
    public function the_server_rendered_title_and_social_tags_carry_the_new_brand(): void
    {
        $landing = $this->get('/')->assertOk();

        $landing->assertSee('<title>'.LandingSeo::TITLE.'</title>', false);
        $landing->assertSee('<meta property="og:site_name" content="Lapispro">', false);

        $this->assertStringStartsWith('Lapispro', LandingSeo::TITLE);
        $this->assertStringContainsString('Lapispro', LandingSeo::SOCIAL_TITLE);

        // O título continua a caber num resultado de pesquisa depois de a marca
        // ter ficado três caracteres mais longa.
        $this->assertLessThanOrEqual(60, mb_strlen(LandingSeo::TITLE));
    }

    /**
     * O acrónimo desapareceu. As iniciais deixaram de coincidir com o nome, e
     * um acrónimo que não soletra o produto é pior do que nenhum.
     */
    #[Test]
    public function the_old_acronym_is_gone_from_everything_the_user_reads(): void
    {
        foreach (array_keys(self::publicPages()) as $name) {
            $path = self::publicPages()[$name][0];

            $this->assertStringNotContainsString(
                'Laboratório de Apoio ao Professor',
                (string) $this->get($path)->getContent(),
                "O acrónimo ainda aparece em {$name}.",
            );
        }

        $this->assertArrayNotHasKey('alternateName', LandingSeo::structuredData());
    }

    /**
     * O payload do Inertia — que é onde os documentos legais vivem, porque o
     * SSR está desligado — nomeia o produto pelo nome novo.
     */
    #[Test]
    public function the_legal_documents_name_the_product_correctly(): void
    {
        foreach ([
            LegalDocuments::terms(),
            LegalDocuments::privacy(),
            LegalDocuments::processing(),
        ] as $document) {
            $json = (string) json_encode($document, JSON_UNESCAPED_UNICODE);

            $this->assertStringContainsString('Lapispro', $json);
            $this->assertStringNotContainsString('LÁPIS', $json);
            $this->assertDoesNotMatchRegularExpression('/\bLAPIS\b/', $json);
        }
    }

    /**
     * O que só vive num `.vue` — rodapé, cabeçalho, cartões de plano, FAQ.
     *
     * Sem SSR, nada disto chega a uma resposta HTTP, e a única forma de o
     * afirmar sem montar um browser é ler o código-fonte. `CHANGELOG.md` está
     * deliberadamente fora: é registo histórico e mantém o nome com que foi
     * escrito.
     */
    #[Test]
    public function no_component_source_still_carries_the_old_brand(): void
    {
        $offenders = [];

        foreach ([
            base_path('resources/js'),
            base_path('resources/views'),
        ] as $root) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                if (! in_array($file->getExtension(), ['vue', 'ts', 'php'], true)) {
                    continue;
                }

                $contents = (string) file_get_contents($file->getPathname());

                // `LAPIS_GRID`, `LAPIS_ITEM_*` e os nomes de variáveis de
                // ambiente não são marca — daí o `(?!_)`.
                if (preg_match('/LÁPIS|Lápis|\bLAPIS\b(?!_)/u', $contents)) {
                    $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
                }
            }
        }

        $this->assertSame([], $offenders, "Marca antiga em:\n".implode("\n", $offenders));
    }

    /**
     * O `<Head>` da landing tem de dizer exatamente o mesmo que o servidor.
     *
     * SÃO DOIS LITERAIS QUE TÊM DE CONCORDAR, e o comentário no `Welcome.vue`
     * já dizia «MUST MATCH» — sem nada a impor. Divergiram nesta própria fatia:
     * encurtar a constante para caber nos 60 caracteres deixou o componente com
     * o título antigo, e o sintoma era invisível (o separador continua a dizer
     * uma coisa, o crawler lê outra). Enquanto o SSR estiver desligado, a única
     * forma de o afirmar é ler o ficheiro.
     */
    #[Test]
    public function the_landing_tab_title_matches_the_server_rendered_one(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/pages/Welcome.vue'));

        $this->assertMatchesRegularExpression(
            '/<Head\s+title="'.preg_quote(LandingSeo::TITLE, '/').'"\s*\/>/',
            $source,
            'O <Head> do Welcome.vue divergiu de LandingSeo::TITLE.',
        );
    }

    /**
     * Os nomes dos ficheiros que o professor descarrega são marca, e passaram
     * despercebidos na primeira passagem: um ZIP chamado `LAPIS-exportacao`
     * mostra o nome antigo a quem o abre meses depois.
     */
    #[Test]
    public function downloadable_file_names_carry_the_new_brand(): void
    {
        $sources = [
            base_path('app/Http/Controllers/DataExportController.php'),
            base_path('app/Actions/DataExports/GenerateDataExport.php'),
        ];

        foreach ($sources as $source) {
            $contents = (string) file_get_contents($source);

            $this->assertStringNotContainsString('LAPIS-exportacao', $contents);
            $this->assertStringNotContainsString('Exportacao-LAPIS', $contents);
        }
    }
}
