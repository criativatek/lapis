<?php

namespace App\Services\Assessment;

use App\Models\Scale;
use App\Models\ScaleLevel;

/**
 * COMO SE LÊ UM MOMENTO, seja ele a pauta de hoje ou uma fotografia de
 * novembro.
 *
 * A DESCOBERTA QUE ESTA CLASSE APROVEITA: o que `BuildEvaluationSheet` produz
 * para o ecrã e o que `CaptureEvaluationSheet` guarda numa fotografia têm
 * EXATAMENTE a mesma forma — a fotografia é literalmente o modelo de leitura,
 * congelado. Por isso o Quadro Síntese não precisa de dois leitores: precisa de
 * um, e é este. Um segundo leitor seria a maneira mais rápida de fazer o
 * histórico discordar do presente sobre o que estava no ecrã.
 *
 * A APRECIAÇÃO VIGENTE, DEFINIDA UMA VEZ (§17):
 *
 *   sem decisão do professor  →  vigora a PROPOSTA do Lapispro
 *   com decisão do professor  →  vigora a DECISÃO
 *
 * As duas viajam sempre, e nenhuma apaga a outra: quem lê escolhe o que mostra,
 * mas a proposta continua a poder ser consultada depois de o professor a ter
 * substituído, e o quantitativo não muda por causa de nenhuma delas.
 *
 * AS FOTOGRAFIAS ANTIGAS NÃO TRAZEM A POSIÇÃO DO NÍVEL NA ESCALA, e por isso é
 * preciso reencontrá-la para pintar uma cor ou ler uma tendência. A procura é
 * feita pelo CÓDIGO primeiro e pelo RÓTULO depois — a mesma ordem de preferência
 * que a tabela do ecrã já usa —, e quando a escala mudou ao ponto de nenhum dos
 * dois existir hoje, a resposta é «não sei»: o texto guardado continua a ser
 * mostrado tal e qual, sem cor e sem seta. Reescrever a história para lhe dar
 * uma posição que ela não tinha seria pior do que não ter cor nenhuma (§15).
 */
final class SynopticReading
{
    public const ORIGIN_DECIDED = 'decided';

    public const ORIGIN_PROPOSED = 'proposed';

    public const ORIGIN_NONE = 'none';

    /**
     * A apreciação vigente de um domínio, a partir de uma linha de domínio da
     * pauta (viva ou guardada).
     *
     * @param  array<string, mixed>  $domain
     * @return array<string, mixed>
     */
    public static function domainAppreciation(array $domain, ?Scale $scale): array
    {
        $decidedCode = $domain['decided_scale_level_code'] ?? null;
        $decidedLabel = $domain['decided_scale_level_label'] ?? null;

        if ($decidedCode !== null || $decidedLabel !== null) {
            return self::appreciation(
                self::ORIGIN_DECIDED,
                $decidedCode,
                $decidedLabel,
                $domain['decided_scale_level_id'] ?? null,
                $scale,
            );
        }

        return self::appreciation(
            ($domain['scale_level_code'] ?? null) === null && ($domain['scale_level_label'] ?? null) === null
                ? self::ORIGIN_NONE
                : self::ORIGIN_PROPOSED,
            $domain['scale_level_code'] ?? null,
            $domain['scale_level_label'] ?? null,
            $domain['scale_level_id'] ?? null,
            $scale,
        );
    }

    /**
     * A apreciação global vigente — a decisão do professor quando existe, a
     * proposta do Lapispro quando não.
     *
     * A DECISÃO GLOBAL TEM UM SÍTIO PRÓPRIO e não é a mesma coisa que a decisão
     * por domínio: vive em `classification`, com o seu estado, o seu rasto e a
     * sua publicação. A semântica canónica não muda aqui — apenas se lê (§17).
     *
     * @param  array<string, mixed>  $student
     * @return array<string, mixed>
     */
    public static function overallAppreciation(array $student, ?Scale $scale): array
    {
        $classification = $student['classification'] ?? null;

        if (is_array($classification)
            && (($classification['final_scale_level_code'] ?? null) !== null
                || ($classification['final_scale_level_label'] ?? null) !== null)) {
            return self::appreciation(
                self::ORIGIN_DECIDED,
                $classification['final_scale_level_code'] ?? null,
                $classification['final_scale_level_label'] ?? null,
                $classification['final_scale_level_id'] ?? null,
                $scale,
            );
        }

        $overall = $student['overall'] ?? [];
        $code = $overall['scale_level_code'] ?? null;
        $label = $overall['scale_level_label'] ?? null;

        // Numa escala de intervalo não há banda nenhuma a nomear, e o que a
        // pauta mostra é o próprio valor. A proposta guardada é então a da
        // classificação, quando ela existe.
        if ($code === null && $label === null && is_array($classification)) {
            $code = $classification['proposed_scale_level_code'] ?? null;
            $label = $classification['proposed_scale_level_label'] ?? null;
        }

        return self::appreciation(
            $code === null && $label === null ? self::ORIGIN_NONE : self::ORIGIN_PROPOSED,
            $code,
            $label,
            $overall['scale_level_id'] ?? null,
            $scale,
        );
    }

    /**
     * A tendência entre duas apreciações vigentes de momentos estruturais
     * consecutivos (§26).
     *
     * COMPARA POSIÇÕES NA ESCALA, NUNCA NÚMEROS LITERAIS. Numa escala em que
     * «Insuficiente» é 1 e «Muito Bom» é 5, subir de posição é evoluir; numa
     * escala invertida — que existe, e nada aqui a proíbe — seria o contrário, e
     * é por isso que o que se compara é `sequence`, que é o que a escala diz
     * sobre a ordem dos seus próprios níveis.
     *
     * SEM COMPARÁVEL, SEM TENDÊNCIA. Um momento sem apreciação, ou uma
     * apreciação cuja posição na escala não se conseguiu reencontrar, não gera
     * seta nenhuma — não se inventa movimento a partir de uma ausência (§29).
     *
     * @param  array<string, mixed>|null  $previous
     * @param  array<string, mixed>|null  $current
     * @return array<string, mixed>|null
     */
    public static function trend(?array $previous, ?array $current, ?string $previousMomentLabel = null): ?array
    {
        if ($previous === null || $current === null) {
            return null;
        }

        $before = $previous['sequence'] ?? null;
        $after = $current['sequence'] ?? null;

        if ($before === null || $after === null) {
            return null;
        }

        $direction = match (true) {
            $after > $before => 'up',
            $after < $before => 'down',
            default => 'flat',
        };

        return [
            'direction' => $direction,
            'label' => match ($direction) {
                'up' => 'Evolução',
                'down' => 'Regressão',
                default => 'Manutenção',
            },
            'from' => ['code' => $previous['code'] ?? null, 'label' => $previous['label'] ?? null],
            'to' => ['code' => $current['code'] ?? null, 'label' => $current['label'] ?? null],
            'from_moment' => $previousMomentLabel,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function appreciation(
        string $origin,
        ?string $code,
        ?string $label,
        ?int $scaleLevelId,
        ?Scale $scale,
    ): array {
        $level = self::locate($scale, $scaleLevelId, $code, $label);

        return [
            'origin' => $origin,
            // O CÓDIGO É O QUE SE ESCREVE numa pauta de 2.º/3.º ciclo, e o
            // rótulo é o que se escreve quando os quantitativos estão desligados
            // (§60). Ambos viajam sempre; a apresentação escolhe.
            'code' => $code,
            'label' => $label,
            'text' => $code ?? $label,
            'sequence' => $level?->sequence === null ? null : (int) $level->sequence,
            'is_negative' => $level === null ? null : (bool) $level->is_negative,
        ];
    }

    /**
     * O nível da escala de hoje que corresponde ao que a fotografia guardou.
     *
     * Pelo id quando ele lá está e ainda existe, pelo código depois, pelo rótulo
     * por último. Null quando nada corresponde — ver o cabeçalho da classe.
     */
    protected static function locate(?Scale $scale, ?int $scaleLevelId, ?string $code, ?string $label): ?ScaleLevel
    {
        if ($scale === null) {
            return null;
        }

        $levels = $scale->levels;

        if ($scaleLevelId !== null) {
            $found = $levels->firstWhere('id', $scaleLevelId);

            if ($found instanceof ScaleLevel) {
                return $found;
            }
        }

        if ($code !== null) {
            $found = $levels->first(fn (ScaleLevel $level): bool => (string) $level->code === $code);

            if ($found instanceof ScaleLevel) {
                return $found;
            }
        }

        if ($label !== null) {
            $found = $levels->first(fn (ScaleLevel $level): bool => (string) $level->label === $label);

            if ($found instanceof ScaleLevel) {
                return $found;
            }
        }

        return null;
    }
}
