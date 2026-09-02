<?php

namespace App\Support\Support;

use App\Models\SupportRequest;

/**
 * O que um reporte leva quando sai para um rastreador externo.
 *
 * LISTA DE PERMISSÕES, E NUNCA DE EXCLUSÕES. `fields()` devolve exactamente as
 * chaves que podem sair, e `IssueGithubPayloadTest` compara-as com uma lista
 * literal escrita à mão. Uma lista de exclusões — «tudo menos o `subject`» —
 * vaza no dia em que alguém acrescenta uma coluna e não se lembra dela; esta
 * quebra o teste nesse mesmo dia, que é o comportamento que se quer.
 *
 * O CORPO É COMPOSTO, NÃO COPIADO. Nada aqui lê `subject`, `description` ou uma
 * `SupportMessage` — o que a pessoa escreveu fica no Lapispro, que é a conversa
 * canónica (ADR-0011 §4). O que sai é o contexto técnico, que é de vocabulário
 * fechado de ponta a ponta, mais o texto que o OPERADOR escrever no momento de
 * exportar.
 *
 * O TÍTULO NÃO LEVA O RESUMO. `subject` é o primeiro sítio onde alguém escreve
 * «o aluno João não aparece na turma 5.ºB» sem pensar duas vezes — é o mesmo
 * argumento que a ADR-0011 §14 já usou para o manter fora dos emails, e por
 * maioria de razão para o manter fora de um sistema que não é nosso.
 */
class IssueGithubPayload
{
    /**
     * As chaves que podem sair. Escrita à mão, e é esse o ponto.
     *
     * @var list<string>
     */
    public const ALLOWED = [
        'reference',
        'category',
        'severity',
        'technical_code',
        'app_version',
        'technical_route',
        'page_component',
        'browser',
        'platform',
        'viewport',
        'errors',
        'network',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function fields(SupportRequest $request): array
    {
        $context = $request->client_context ?? [];
        $environment = $context['environment'] ?? [];

        return [
            'reference' => $request->reference,
            'category' => $request->category->value,
            'severity' => $request->severity?->value,
            'technical_code' => $request->technical_code?->value,
            'app_version' => $request->app_version,
            'technical_route' => $request->technical_route,
            'page_component' => $context['page_component'] ?? null,
            'browser' => trim(implode(' ', array_filter([
                $environment['browser'] ?? null,
                $environment['browser_major'] ?? null,
            ]))) ?: null,
            'platform' => $environment['platform'] ?? null,
            'viewport' => $environment['viewport'] ?? null,
            // Só a forma dos erros e dos pedidos falhados. A consola NÃO sai:
            // é a única parte do contexto que leva texto que a aplicação não
            // compôs, e por isso é a única que não pode atravessar a fronteira.
            'errors' => array_map(
                static fn (array $error): string => trim(($error['name'] ?? '?').' '.($error['where'] ?? '')),
                array_slice($context['errors'] ?? [], 0, 5),
            ),
            'network' => array_map(
                static fn (array $row): string => ($row['status'] ?? '?').' '.($row['method'] ?? '?').' '.($row['route'] ?? '?'),
                array_values(array_filter(
                    $context['network'] ?? [],
                    static fn (array $row): bool => (int) ($row['status'] ?? 0) === 0 || (int) ($row['status'] ?? 0) >= 400,
                )),
            ),
        ];
    }

    /** O título: a referência e a rota, e nada que alguém tenha escrito. */
    public static function title(SupportRequest $request): string
    {
        return sprintf(
            '[%s] %s',
            $request->reference,
            $request->technical_route ?? $request->category->value,
        );
    }

    /**
     * O corpo, em Markdown.
     *
     * @param  string  $operatorNotes  o que o operador escreveu. É a única prosa
     *                                 que sai, e saiu porque alguém a escreveu
     *                                 de propósito para sair.
     */
    public static function body(SupportRequest $request, string $operatorNotes): string
    {
        $fields = self::fields($request);

        $lines = [
            $operatorNotes,
            '',
            '---',
            '',
            '| | |',
            '|---|---|',
        ];

        foreach (['reference', 'category', 'severity', 'technical_code', 'app_version', 'technical_route', 'page_component', 'browser', 'platform', 'viewport'] as $key) {
            if ($fields[$key] !== null && $fields[$key] !== '') {
                $lines[] = "| {$key} | `{$fields[$key]}` |";
            }
        }

        if ($fields['errors'] !== []) {
            $lines[] = '';
            $lines[] = '**Erros**';
            foreach ($fields['errors'] as $error) {
                $lines[] = "- `{$error}`";
            }
        }

        if ($fields['network'] !== []) {
            $lines[] = '';
            $lines[] = '**Pedidos que falharam**';
            foreach ($fields['network'] as $row) {
                $lines[] = "- `{$row}`";
            }
        }

        $lines[] = '';
        $lines[] = '_O que a pessoa escreveu, a conversa e as imagens ficam no Lapispro._';

        return implode("\n", $lines);
    }
}
