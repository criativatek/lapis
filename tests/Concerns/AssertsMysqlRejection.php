<?php

namespace Tests\Concerns;

use Illuminate\Database\QueryException;

/**
 * «O MySQL recusa isto» — provado, não presumido.
 *
 * Até à 0.156.1 este helper vivia copiado nos três `*MysqlGuaranteesTest`, e
 * as três cópias tinham o `$this->fail()` DENTRO do `try` cuja recusa queriam
 * apanhar, com um `catch (Throwable)`. O `fail()` lança uma
 * `AssertionFailedError`, que é um Throwable como outro qualquer, e o próprio
 * catch engolia-a: uma escrita que o MySQL ACEITASSE passava por recusada. O
 * teste não podia falhar. O PHPUnit só o denunciou num sítio — marcou como
 * «risky», por zero asserções, o teste de Suporte que não tinha mais nada —;
 * nos outros, as asserções vizinhas escondiam o mesmo vazio.
 *
 * Agora conta só uma recusa do MOTOR, e por uma restrição: uma violação de
 * integridade (SQLSTATE da classe 23 — UNIQUE, chave estrangeira, NOT NULL),
 * de uma CHECK (erro 3819), ou um valor fora do domínio da própria coluna em
 * modo estrito (classe 22 — por exemplo, -1 numa coluna UNSIGNED, que o
 * motor recusa antes de a CHECK chegar a ser avaliada). Uma coluna que
 * deixou de existir (42S22), um erro de sintaxe (42000) ou a ligação em baixo
 * também lançam uma exceção, e nenhuma delas prova garantia alguma: falham o
 * teste com a mensagem verdadeira. E o `fail()` vive fora do `try`, onde nada
 * o pode apanhar.
 */
trait AssertsMysqlRejection
{
    private const int MYSQL_CHECK_CONSTRAINT_VIOLATED = 3819;

    protected function assertRejected(callable $write, string $what): void
    {
        try {
            $write();
        } catch (QueryException $exception) {
            $this->assertTrue(
                $this->isConstraintViolation($exception),
                "O MySQL recusou {$what}, mas não por uma restrição: {$exception->getMessage()}",
            );

            return;
        }

        $this->fail("O MySQL aceitou {$what}");
    }

    private function isConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return str_starts_with($sqlState, '23')
            || str_starts_with($sqlState, '22')
            || $driverCode === self::MYSQL_CHECK_CONSTRAINT_VIOLATED;
    }
}
