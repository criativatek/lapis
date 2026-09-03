<?php

namespace Tests;

use App\Support\Release\BuildsSsrBundle;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use Tests\Support\PassthroughSsrBundleBuilder;

abstract class TestCase extends BaseTestCase
{
    /**
     * Reference data the app cannot function without — plans/modules and the
     * system scales — seeded for every test that refreshes the database, the
     * same baseline production has, minus the demo accounts.
     */
    protected bool $seed = true;

    protected string $seeder = ReferenceDataSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();

        // lapis:build-package rebuilds bootstrap/ssr for real before reading it
        // (BuildsSsrBundle). Bound here, globally, so the ordinary suite never
        // shells out to npm — only SsrBundleFreshnessTest replaces this
        // per-test to exercise the rebuild-succeeds/fails/replaces-stale paths.
        $this->app->bind(BuildsSsrBundle::class, PassthroughSsrBundleBuilder::class);
    }

    /**
     * Compara um valor lido de uma coluna JSON com o que se esperava, sem
     * depender da ORDEM DAS CHAVES.
     *
     * O MySQL guarda JSON num formato binário próprio e devolve o objecto com
     * as chaves reordenadas (as mais curtas primeiro); o SQLite guarda o texto
     * tal e qual. Um `assertSame` sobre um array associativo compara a ordem,
     * pelo que a mesma asserção passava em local e falhava em CI — seis testes
     * assim, e nenhum deles sobre um comportamento que a ordem afecte. Ordenar
     * os dois lados antes de comparar mantém o rigor de tipos que o
     * `assertEqualsCanonicalizing` deitaria fora.
     *
     * @param  array<array-key, mixed>  $expected
     * @param  array<array-key, mixed>  $actual
     */
    protected function assertSameJsonPayload(array $expected, array $actual, string $message = ''): void
    {
        $sortByKey = function (array $value) use (&$sortByKey): array {
            foreach ($value as $key => $item) {
                if (is_array($item)) {
                    $value[$key] = $sortByKey($item);
                }
            }

            ksort($value);

            return $value;
        };

        $this->assertSame($sortByKey($expected), $sortByKey($actual), $message);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
