<?php

namespace App\Services\Ai\Providers;

use RuntimeException;

/**
 * «Este modelo não te serve» — dito depressa, e apenas sobre o modelo.
 *
 * Interna ao driver do Gemini e nunca sai dele: o que chega ao chamador é
 * sempre um `AiRequestFailed`, com o estado do modelo que o operador
 * configurou. Existe para o `complete()` distinguir a recusa de um modelo
 * daquilo que uma segunda tentativa não resolveria.
 *
 * Só a levantam os três estados de `GeminiProvider::TRY_ANOTHER_MODEL`, e a
 * razão de serem esses está lá escrita.
 */
class ModelCannotServe extends RuntimeException
{
    public function __construct(public readonly int $status)
    {
        parent::__construct("The model answered {$status}.");
    }
}
