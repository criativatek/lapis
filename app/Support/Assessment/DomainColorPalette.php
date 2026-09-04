<?php

namespace App\Support\Assessment;

use App\Models\Domain;

/**
 * A deterministic, stable colour for a domain that has none of its own.
 *
 * IDENTITY, NEVER PERFORMANCE. The Pauta de Avaliação groups columns by domain
 * and needs a visual anchor for each group; the colour must never be read as
 * "bom/mau" (§7 — the qualitative-tone resolver already owns that vocabulary,
 * and this one must not collide with it). The palette therefore avoids vivid
 * red/green and stays in soft pastels.
 *
 * STABLE BECAUSE INDEXED BY `sequence`, not by array position in a fetched
 * list — the same domain gets the same colour on every request, and a domain
 * added or removed elsewhere in the profile does not reshuffle everyone
 * else's colour.
 */
class DomainColorPalette
{
    /**
     * @var list<string>
     */
    protected const PALETTE = [
        '#DCEAFB', // azul suave
        '#E1F0E1', // verde suave
        '#EDE1F7', // lilás suave
        '#FBE6D9', // pêssego suave
        '#FBF3D0', // amarelo suave
        '#DCF3F0', // verde-água suave
        '#F5E1EC', // rosa suave
        '#E6E6F2', // violeta acinzentado suave
    ];

    public static function for(?string $color, int $sequence): string
    {
        // Uma coluna vazia não é uma cor escolhida. `domains.color` é anulável e
        // ainda não tem UI que a escreva; uma string vazia vinda de uma edição
        // manual pintaria a coluna de «nada» em vez de cair na paleta.
        if ($color !== null && trim($color) !== '') {
            return trim($color);
        }

        $index = $sequence % count(self::PALETTE);

        return self::PALETTE[$index];
    }

    /**
     * Attaches the presentation colour to the read model's domain rows.
     *
     * THE ONE PLACE THIS HAPPENS. The live sheet on screen and the snapshot
     * frozen from it must show the same colours, or the photograph stops being
     * a photograph. Two call sites resolving colour independently would be two
     * places to drift; this is one.
     *
     * `BuildEvaluationSheet` still carries no colour of its own — it is
     * export-neutral by design, and colour is presentation (§7).
     *
     * @param  list<array<string, mixed>>  $domains
     * @return list<array<string, mixed>>
     */
    public static function decorate(array $domains): array
    {
        if ($domains === []) {
            return [];
        }

        // Fetched once, keyed by id, so a class with many domains costs one
        // extra query rather than one per row.
        $configured = Domain::query()
            ->whereIn('id', array_column($domains, 'domain_id'))
            ->pluck('color', 'id');

        return array_map(
            fn (array $domain): array => [
                ...$domain,
                'color' => self::for($configured[$domain['domain_id']] ?? null, (int) $domain['sequence']),
            ],
            $domains,
        );
    }
}
