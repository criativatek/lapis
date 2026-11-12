<?php

namespace App\Services\Assessment;

use App\Domain\Assessment\ScaleCutsToBands;
use App\Models\Scale;
use App\Models\ScaleLevel;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * A escala da escola, por perfil — sem tocar na escala do sistema.
 *
 * UMA ESCALA DERIVADA É UMA ESCALA DE NÍVEIS A SÉRIO. Tem de 2 a 10 níveis, cada
 * um com o seu nome, o corte «a partir de X %», o código do Inovar e o sinal
 * (negativo/positivo), e guarda-os com os mesmos campos da «Escala 1 a 5» do
 * sistema. Por isso a pauta, as fotografias, os relatórios, a autoavaliação e a
 * exportação Inovar continuam a ler `ScaleLevel` por identidade do nível, sem
 * uma linha alterada.
 *
 * Os níveis vêm do pedido; a escala do sistema só dá a origem (`kind` e o
 * rasto `derived_from_scale_id`). Dos cortes às bandas converte
 * `ScaleCutsToBands`, e mais ninguém.
 *
 * COPY-ON-WRITE, como o modelo de domínio decidiu (§2.3): enquanto nenhuma
 * versão ativa de perfil usar a escala derivada, os níveis sincronizam-se em
 * cima; depois de congelada (`frozen_at`, posto por ActivateProfileVersion),
 * editar cria uma escala nova a partir da MESMA origem do sistema. As versões
 * antigas, as classificações decididas e as fotografias continuam a apontar à
 * escala antiga, intacta.
 *
 * O ÚNICO SÍTIO que cria estas escalas.
 */
class DeriveCustomScale
{
    public const MIN_LEVELS = 2;

    public const MAX_LEVELS = 10;

    public function __construct(protected CurrentOrganization $tenant) {}

    /**
     * @param  list<array{label: string, from_percent: int, inovar_code: string|null}>  $levels  por ordem, do mais baixo ao mais alto
     * @param  int  $firstPositiveLevel  posição (1…N) do primeiro nível positivo
     * @param  string|null  $scaleName  o nome que o professor deu à escala; sem ele (ou se for o nome
     *                                  de origem, intacto) o nome é «Escala 1 a N · perfil» e, havendo
     *                                  outra com esse nome, ganha « (2)», « (3)»… Um nome escolhido
     *                                  pelo professor que já existe noutra escala é um erro do campo.
     */
    public function for(Scale $source, array $levels, int $firstPositiveLevel, string $profileName, ?string $scaleName = null): Scale
    {
        $origin = $this->originOf($source);
        $this->guardOrigin($origin);
        $this->guardLevels($levels, $firstPositiveLevel);

        $rows = $this->levelRows($levels, $firstPositiveLevel);

        return DB::transaction(function () use ($source, $origin, $rows, $profileName, $scaleName): Scale {
            // Derivada, da organização, e ainda livre: os níveis sincronizam-se em cima.
            if (! $source->isSystem() && $source->derived_from_scale_id !== null && ! $source->isFrozen()) {
                $name = $this->nameFor($source, count($rows), $profileName, $scaleName, $source->id);
                $synchronised = $this->synchronise($source, $rows, $name);

                if ($synchronised !== null) {
                    return $synchronised;
                }
            }

            return $this->create($origin, $rows, $this->nameFor($source, count($rows), $profileName, $scaleName, null));
        });
    }

    /** A escala do sistema de onde tudo deriva — a própria, ou a origem de uma derivada. */
    protected function originOf(Scale $scale): Scale
    {
        if ($scale->derived_from_scale_id === null) {
            return $scale->load('levels');
        }

        return Scale::withoutGlobalScope('scaleVisibility')
            ->with('levels')
            ->findOrFail($scale->derived_from_scale_id);
    }

    protected function guardOrigin(Scale $origin): void
    {
        $withBands = $origin->levels
            ->filter(fn (ScaleLevel $level): bool => $level->band_min_normalized !== null && $level->band_max_normalized !== null)
            ->count();

        if ($origin->kind !== 'level' || $origin->levels->isEmpty() || $withBands !== $origin->levels->count()) {
            throw ValidationException::withMessages([
                'levels' => 'Só uma escala de níveis com bandas de conversão se pode personalizar.',
            ]);
        }
    }

    /**
     * A rede de segurança de quem chama o serviço sem passar pelo pedido: o
     * pedido já valida isto campo a campo, mas a conversão em bandas não pode
     * receber cortes que deixem buracos ou sobreposições.
     *
     * @param  list<array{label: string, from_percent: int, inovar_code: string|null}>  $levels
     */
    protected function guardLevels(array $levels, int $firstPositiveLevel): void
    {
        $count = count($levels);

        if ($count < self::MIN_LEVELS || $count > self::MAX_LEVELS) {
            throw ValidationException::withMessages([
                'levels' => 'A escala tem de ter entre '.self::MIN_LEVELS.' e '.self::MAX_LEVELS.' níveis.',
            ]);
        }

        if ($firstPositiveLevel < 1 || $firstPositiveLevel > $count) {
            throw ValidationException::withMessages([
                'first_positive_level' => 'Escolha um dos níveis da escala.',
            ]);
        }

        try {
            ScaleCutsToBands::convert(array_map(fn (array $level): int => $level['from_percent'], $levels));
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'levels' => 'Os cortes começam em 0 e crescem de nível para nível, até 100.',
            ]);
        }
    }

    /**
     * Os campos de cada nível, prontos a gravar.
     *
     * @param  list<array{label: string, from_percent: int, inovar_code: string|null}>  $levels
     * @return list<array<string, mixed>>
     */
    protected function levelRows(array $levels, int $firstPositiveLevel): array
    {
        $bands = ScaleCutsToBands::convert(array_map(fn (array $level): int => $level['from_percent'], $levels));
        $rows = [];

        foreach ($levels as $index => $level) {
            $position = $index + 1;

            $rows[] = [
                'code' => (string) $position,
                'label' => $level['label'],
                'inovar_code' => $level['inovar_code'],
                'sequence' => $position,
                'numeric_value' => $position,
                'normalized_value' => null,
                'band_min_normalized' => $bands[$index]['min'],
                'band_max_normalized' => $bands[$index]['max'],
                'is_negative' => $position < $firstPositiveLevel,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    protected function create(Scale $origin, array $rows, string $name): Scale
    {
        $derived = Scale::create([
            'name' => $name,
            'kind' => $origin->kind,
            'min_value' => 1,
            'max_value' => count($rows),
        ]);
        $derived->forceFill(['derived_from_scale_id' => $origin->id])->save();

        foreach ($rows as $row) {
            $derived->levels()->create($row);
        }

        return $derived->refresh()->load('levels');
    }

    /**
     * Sincroniza os níveis existentes com os do pedido, por `sequence`: atualiza,
     * cria os que faltam, apaga os que sobram. Devolve null quando a base de dados
     * recusa apagar um nível (uma referência inesperada) — não se força, o chamador
     * cria uma escala nova.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    protected function synchronise(Scale $scale, array $rows, string $name): ?Scale
    {
        $count = count($rows);
        $existing = $scale->levels()->get()->keyBy('sequence');

        try {
            // Dentro da transação do chamador isto é um ponto de salvaguarda:
            // se apagar for recusado, tudo o que se fez aqui desfaz-se.
            DB::transaction(function () use ($scale, $rows, $existing, $count): void {
                foreach ($rows as $row) {
                    $level = $existing->get($row['sequence']);

                    if ($level instanceof ScaleLevel) {
                        $level->update($row);
                    } else {
                        $scale->levels()->create($row);
                    }
                }

                foreach ($existing as $sequence => $level) {
                    if ($sequence > $count) {
                        $level->delete();
                    }
                }
            });
        } catch (QueryException) {
            return null;
        }

        $scale->forceFill([
            'name' => $name,
            'max_value' => $count,
        ])->save();

        return $scale->refresh()->load('levels');
    }

    /**
     * O nome final da escala. O nome de origem — o que o diálogo pré-preenche — pede
     * sufixo se já existir; um nome que o professor escreveu e que já pertence a
     * OUTRA escala da organização é um erro do campo, não um sufixo em silêncio.
     *
     * @param  int|null  $ignoringScaleId  a própria escala, quando se edita em cima
     */
    protected function nameFor(Scale $source, int $levelCount, string $profileName, ?string $scaleName, ?int $ignoringScaleId): string
    {
        $default = "Escala 1 a {$levelCount} · ".trim($profileName);
        $typed = $scaleName === null ? '' : trim($scaleName);

        if ($typed === '' || $typed === $default || (! $source->isSystem() && $typed === $source->name && $ignoringScaleId === null)) {
            return $this->uniqueName($typed === '' ? $default : $typed, $ignoringScaleId);
        }

        if ($this->nameTaken($typed, $ignoringScaleId)) {
            throw ValidationException::withMessages([
                'scale_name' => 'Já existe uma escala com este nome. Escolha outro.',
            ]);
        }

        return $typed;
    }

    protected function nameTaken(string $name, ?int $ignoringScaleId): bool
    {
        return Scale::withoutGlobalScope('scaleVisibility')
            ->where('organization_id', $this->tenant->id())
            ->where('name', $name)
            ->when($ignoringScaleId !== null, fn ($query) => $query->where('id', '!=', $ignoringScaleId))
            ->exists();
    }

    /**
     * @param  int|null  $ignoringScaleId  a própria escala, ao renomeá-la
     */
    protected function uniqueName(string $base, ?int $ignoringScaleId = null): string
    {
        $name = $base;
        $suffix = 2;

        while ($this->nameTaken($name, $ignoringScaleId)) {
            $name = "{$base} ({$suffix})";
            $suffix++;
        }

        return $name;
    }
}
