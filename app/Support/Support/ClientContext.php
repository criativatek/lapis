<?php

namespace App\Support\Support;

use Illuminate\Validation\Rule;

/**
 * O que um reporte pode dizer sobre o ecrã de onde saiu — e mais nada.
 *
 * A LISTA É FECHADA, E É ESSA A DEFESA. Nenhuma destas chaves aceita texto
 * livre arbitrário: o browser vem de uma lista, a versão é um inteiro, o
 * viewport tem de ter a forma de um viewport. Uma carga que o esquema não
 * reconhece não é limpa depois — não entra. É a única forma de «negar por
 * omissão» que funciona sobre dados que um cliente compõe, e é o oposto do que
 * fazer regex sobre um despejo de consola conseguiria.
 *
 * PORQUÊ FAMÍLIA E VERSÃO MAIOR, E NÃO O USER-AGENT. Um User-Agent completo é
 * uma impressão digital; «chrome 151» responde à única pergunta que um
 * diagnóstico faz. Guardar o primeiro para responder à segunda seria recolher
 * mais do que o necessário para o fim declarado.
 *
 * O FUSO NÃO ENTRA. Toda a aplicação corre em `Europe/Lisbon` — o fuso do
 * cliente não distingue ninguém excepto quem está fora dele, e aí distingue
 * demais.
 */
class ClientContext
{
    /**
     * Famílias reconhecidas. O que não estiver aqui é `unknown` — nunca a
     * string que o cliente mandou.
     *
     * @var list<string>
     */
    public const BROWSERS = ['chrome', 'edge', 'firefox', 'safari', 'opera', 'samsung', 'unknown'];

    /** @var list<string> */
    public const PLATFORMS = ['windows', 'macos', 'linux', 'android', 'ios', 'unknown'];

    /**
     * As regras, prontas a espalhar dentro de uma FormRequest.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'client_context' => ['nullable', 'array'],

            // O nome do componente Inertia: um literal escrito no repositório
            // («classes/Show»), não uma rota e não uma URL. Diz em que ecrã a
            // pessoa estava sem dizer sobre quem.
            'client_context.page_component' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9\/_-]+$/'],

            'client_context.environment' => ['nullable', 'array'],
            'client_context.environment.browser' => ['nullable', Rule::in(self::BROWSERS)],
            'client_context.environment.browser_major' => ['nullable', 'integer', 'min:0', 'max:999'],
            'client_context.environment.platform' => ['nullable', Rule::in(self::PLATFORMS)],
            'client_context.environment.viewport' => ['nullable', 'string', 'regex:/^\d{2,5}x\d{2,5}$/'],
            'client_context.environment.language' => ['nullable', 'string', 'max:12', 'regex:/^[a-zA-Z-]+$/'],
        ];
    }

    /**
     * O contexto reduzido ao que a lista declara.
     *
     * Corre DEPOIS da validação e não em vez dela: a validação recusa um valor
     * mal formado, isto remove uma chave que ninguém pediu. Sem este passo, o
     * `array` validado traria consigo tudo o que viesse ao lado — a validação
     * do Laravel não poda o que não conhece.
     *
     * @param  array<string, mixed>|null  $context
     * @return array<string, mixed>|null
     */
    public static function only(?array $context): ?array
    {
        if ($context === null) {
            return null;
        }

        $environment = array_intersect_key(
            (array) ($context['environment'] ?? []),
            array_flip(['browser', 'browser_major', 'platform', 'viewport', 'language']),
        );

        $kept = array_filter([
            'page_component' => $context['page_component'] ?? null,
            'environment' => $environment === [] ? null : $environment,
        ], static fn (mixed $value): bool => $value !== null);

        return $kept === [] ? null : $kept;
    }
}
