<?php

namespace App\Support\Import;

use Closure;
use Illuminate\Database\QueryException;

/**
 * Runs a database write that carries a correction grid, and refuses to let the
 * grid escape into a log if it fails.
 *
 * Laravel builds a QueryException's message with
 * `Str::replaceArray('?', $bindings, $sql)` — the bindings are interpolated into
 * the message. For most tables that is a gift when something breaks. Here the
 * binding is `canonical_snapshot`: every student's name, every answer they gave,
 * the whole file. Measured on a three-student fixture the message was 5 147
 * characters and contained all three names and every response.
 *
 * `APP_DEBUG=false` does not help. It governs what the browser is shown; the
 * exception is still reported, and `storage/logs/laravel.log` is a plaintext
 * file that nobody prunes. These are children's names.
 *
 * So the SQLSTATE and the table survive, because that is what a diagnosis
 * actually needs, and the payload does not. The original exception is
 * deliberately NOT chained: a `previous` would put the message straight back
 * into the same log entry.
 */
class WithoutLeakingTheGrid
{
    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $write
     * @return TReturn
     */
    public static function run(Closure $write, string $context)
    {
        try {
            return $write();
        } catch (QueryException $exception) {
            throw new CorrectionImportException(
                __('Não foi possível gravar a importação (:contexto, :codigo). O conteúdo do ficheiro não foi registado.', [
                    'contexto' => $context,
                    // The SQLSTATE class alone: enough to tell a missing table
                    // from a constraint violation, and it names nobody.
                    'codigo' => self::sqlState($exception),
                ]),
            );
        }
    }

    /**
     * The five-character SQLSTATE and nothing else.
     *
     * `errorInfo` is the direct route but is not always populated — it depends
     * on the driver and on how the exception was wrapped. The fallback matches
     * a fixed-shape token and keeps only the match, never the surrounding
     * message: reading that message back into the output is precisely the leak
     * this class exists to prevent.
     */
    protected static function sqlState(QueryException $exception): string
    {
        $fromDriver = $exception->errorInfo[0] ?? null;

        if (is_string($fromDriver) && $fromDriver !== '') {
            return $fromDriver;
        }

        $previous = $exception->getPrevious()?->getMessage() ?? '';

        return preg_match('/SQLSTATE\[([0-9A-Za-z]{5})\]/', $previous, $matches) === 1
            ? $matches[1]
            : 'desconhecido';
    }
}
