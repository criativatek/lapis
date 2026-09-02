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

    /** @var list<string> */
    public const LEVELS = ['log', 'info', 'warn', 'error', 'debug'];

    /** @var list<string> */
    public const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

    /**
     * Os tectos. São generosos para o diagnóstico e fechados para o resto: um
     * anel que crescesse com a sessão passaria a ser um registo de tudo o que a
     * pessoa fez, e isso é outra coisa — com outra base legal.
     */
    public const MAX_CONSOLE = 100;

    public const MAX_NETWORK = 50;

    public const MAX_ERRORS = 10;

    /** Cada linha de consola. Acima disto é um despejo, não uma mensagem. */
    public const MAX_LINE = 500;

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

            // A consola, tal como o browser a viu. É a única parte disto que
            // leva texto que a aplicação não compôs, e está aqui por decisão
            // explícita: sem ela, metade dos defeitos de front-end não se
            // reproduzem. O tecto por linha e por anel é o que a mantém uma ajuda
            // ao diagnóstico e não um registo da sessão.
            'client_context.console' => ['nullable', 'array', 'max:'.self::MAX_CONSOLE],
            'client_context.console.*.level' => ['required', Rule::in(self::LEVELS)],
            'client_context.console.*.text' => ['required', 'string', 'max:'.self::MAX_LINE],
            'client_context.console.*.at' => ['nullable', 'date_format:Y-m-d\\TH:i:s.v\\Z'],

            // A rede: método, ROTA MASCARADA e estado. Nunca o URL — é onde vivem
            // os identificadores e os tokens, e um estado 500 numa rota diz o
            // mesmo sem os levar.
            'client_context.network' => ['nullable', 'array', 'max:'.self::MAX_NETWORK],
            'client_context.network.*.method' => ['required', Rule::in(self::METHODS)],
            'client_context.network.*.route' => ['required', 'string', 'max:200'],
            'client_context.network.*.status' => ['required', 'integer', 'min:0', 'max:599'],
            'client_context.network.*.at' => ['nullable', 'date_format:Y-m-d\\TH:i:s.v\\Z'],

            // Erros por apanhar. O `where` é o ficheiro do bundle e a linha — um
            // caminho de artefacto compilado, não um caminho da aplicação.
            'client_context.errors' => ['nullable', 'array', 'max:'.self::MAX_ERRORS],
            'client_context.errors.*.name' => ['required', 'string', 'max:100'],
            'client_context.errors.*.message' => ['nullable', 'string', 'max:'.self::MAX_LINE],
            'client_context.errors.*.where' => ['nullable', 'string', 'max:200'],
            'client_context.errors.*.at' => ['nullable', 'date_format:Y-m-d\\TH:i:s.v\\Z'],
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
            'console' => self::rows($context['console'] ?? null, ['level', 'text', 'at'], self::MAX_CONSOLE),
            'network' => self::rows($context['network'] ?? null, ['method', 'route', 'status', 'at'], self::MAX_NETWORK),
            'errors' => self::rows($context['errors'] ?? null, ['name', 'message', 'where', 'at'], self::MAX_ERRORS),
        ], static fn (mixed $value): bool => $value !== null);

        return $kept === [] ? null : $kept;
    }

    /**
     * Uma lista de linhas reduzida às colunas declaradas, e cortada ao tecto.
     *
     * O corte repete-se aqui de propósito. A validação já recusa uma lista
     * grande de mais — mas esta classe também é chamada de onde não houve
     * FormRequest nenhuma (uma acção, um teste, um comando), e um tecto que só
     * existe numa das duas portas não é um tecto.
     *
     * @param  list<string>  $columns
     * @return list<array<string, mixed>>|null
     */
    protected static function rows(mixed $rows, array $columns, int $limit): ?array
    {
        if (! is_array($rows) || $rows === []) {
            return null;
        }

        $kept = [];

        foreach (array_slice(array_values($rows), 0, $limit) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $only = array_intersect_key($row, array_flip($columns));

            if ($only !== []) {
                $kept[] = $only;
            }
        }

        return $kept === [] ? null : $kept;
    }
}
