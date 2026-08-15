<?php

namespace Tests\Feature\Import;

use App\Services\Import\Correction\PlickersCsvParser;
use App\Support\Import\CorrectionImportException;
use App\Support\Import\WithoutLeakingTheGrid;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What a failed write is allowed to say.
 *
 * Laravel composes a QueryException's message by interpolating the bindings
 * into the SQL. For most tables that is a gift; here the binding is the whole
 * correction grid — every student's name and every answer they gave. Measured
 * on the three-student fixture, that message ran to over five thousand
 * characters and contained all three names.
 *
 * `APP_DEBUG=false` does not help: it governs what the browser is shown, not
 * what is written to storage/logs/laravel.log, which is plaintext and which
 * nobody prunes. So the payload is stopped at the point of failure rather than
 * relied upon to be invisible later.
 */
class CorrectionImportPrivacyTest extends TestCase
{
    protected function queryFailureCarrying(string $payload): QueryException
    {
        // The same shape Laravel produces: the binding interpolated into the SQL.
        return new QueryException(
            'mysql',
            'insert into `correction_imports` (`canonical_snapshot`) values (?)',
            [$payload],
            new PDOException('SQLSTATE[42S02]: Base table or view not found'),
        );
    }

    #[Test]
    public function a_failed_write_never_repeats_the_grid_in_its_message(): void
    {
        $grid = (new PlickersCsvParser)->parse(
            base_path('tests/Fixtures/Import/plickers-basico.csv'),
            'plickers-basico.csv',
        );

        $payload = (string) json_encode($grid->toArray());

        // Sanity: the payload really does contain what we are protecting.
        $this->assertStringContainsString('Ana Exemplo', $payload);
        $this->assertStringContainsString('raw_response', $payload);

        try {
            WithoutLeakingTheGrid::run(function () use ($payload): void {
                throw $this->queryFailureCarrying($payload);
            }, 'ao guardar a análise do ficheiro');

            $this->fail('A falha tinha de ser convertida.');
        } catch (CorrectionImportException $exception) {
            $mensagem = $exception->getMessage();

            foreach (['Ana Exemplo', 'Bruno Exemplo', 'Carla Exemplo', 'raw_response', 'answer_key'] as $sensivel) {
                $this->assertStringNotContainsString($sensivel, $mensagem);
            }

            // Not chained: a `previous` would put the original message — payload
            // and all — straight back into the same log entry.
            $this->assertNull($exception->getPrevious());

            // And still useful: the SQLSTATE survives, which is what tells a
            // missing table from a constraint violation.
            $this->assertStringContainsString('42S02', $mensagem);
            $this->assertStringContainsString('ao guardar a análise do ficheiro', $mensagem);
        }
    }

    #[Test]
    public function a_successful_write_is_untouched(): void
    {
        // The guard is only about failure. Nothing about the happy path changes.
        $resultado = WithoutLeakingTheGrid::run(fn (): string => 'gravado', 'contexto');

        $this->assertSame('gravado', $resultado);
    }

    #[Test]
    public function production_never_shows_the_browser_a_query(): void
    {
        // The other half of the audit, pinned so a stray APP_DEBUG=true in a
        // future .env.example is noticed here rather than in production.
        $this->assertFalse(
            (bool) config('app.debug', false) && app()->isProduction(),
            'APP_DEBUG nunca pode estar ligado em produção.',
        );
    }
}
