<?php

namespace App\Services\Assessment;

use App\Models\Scale;
use App\Models\ScaleLevel;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * As menções da escola, por perfil — sem tocar na escala do sistema.
 *
 * UMA ESCALA DERIVADA É UM NÍVEL DE ESCALA A SÉRIO. Copia os cinco níveis da
 * «Escala 1 a 5» campo a campo (código, Inovar, bandas, valor numérico, sinal)
 * e só muda os nomes. Por isso a pauta, as fotografias, os relatórios, a
 * autoavaliação e a exportação Inovar continuam a ler `ScaleLevel.label` por
 * identidade do nível, sem uma linha alterada — a alternativa (nomes
 * alternativos guardados no perfil) obrigaria cada um desses sítios a saber
 * aplicar a substituição, e qualquer um esquecido mostraria «Fraco» à mesma.
 *
 * COPY-ON-WRITE, como o modelo de domínio decidiu (§2.3): enquanto nenhuma
 * versão ativa de perfil usar a escala derivada, os nomes editam-se em cima;
 * depois de congelada (`frozen_at`, posto por ActivateProfileVersion), editar
 * cria uma escala nova a partir da MESMA origem do sistema. As versões
 * antigas, as classificações decididas e as fotografias continuam a apontar à
 * escala antiga, intacta.
 *
 * O ÚNICO SÍTIO que cria estas escalas. O nome, o rasto (`derived_from_scale_id`)
 * e a lista de campos copiados vivem aqui e em mais lado nenhum.
 */
class DeriveScaleWithLabels
{
    /** Os campos de um nível que viajam intactos da origem para a cópia. */
    protected const COPIED_LEVEL_FIELDS = [
        'code', 'inovar_code', 'sequence', 'numeric_value', 'normalized_value',
        'band_min_normalized', 'band_max_normalized', 'is_negative',
    ];

    public function __construct(protected CurrentOrganization $tenant) {}

    /**
     * @param  list<string>  $labels  cinco nomes, por ordem de `sequence`
     */
    public function for(Scale $source, array $labels, string $profileName): Scale
    {
        $origin = $this->originOf($source);
        $this->guardOrigin($origin, $labels);

        return DB::transaction(function () use ($source, $origin, $labels, $profileName): Scale {
            // Derivada, da organização, e ainda livre: os nomes mudam em cima.
            if (! $source->isSystem() && $source->derived_from_scale_id !== null && ! $source->isFrozen()) {
                $this->applyLabels($source, $labels);

                return $source->refresh()->load('levels');
            }

            $derived = Scale::create([
                'name' => $this->uniqueName($profileName),
                'kind' => $origin->kind,
                'min_value' => $origin->min_value,
                'max_value' => $origin->max_value,
            ]);
            $derived->forceFill(['derived_from_scale_id' => $origin->id])->save();

            foreach ($origin->levels->sortBy('sequence')->values() as $index => $level) {
                $derived->levels()->create([
                    ...collect($level->getAttributes())->only(self::COPIED_LEVEL_FIELDS)->all(),
                    'label' => $labels[$index],
                ]);
            }

            return $derived->refresh()->load('levels');
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

    /**
     * @param  list<string>  $labels
     */
    protected function guardOrigin(Scale $origin, array $labels): void
    {
        $withBands = $origin->levels
            ->filter(fn (ScaleLevel $level): bool => $level->band_min_normalized !== null && $level->band_max_normalized !== null)
            ->count();

        if ($origin->kind !== 'level' || $origin->levels->isEmpty() || $withBands !== $origin->levels->count()) {
            throw ValidationException::withMessages([
                'labels' => 'Só uma escala de níveis com bandas de conversão tem menções para personalizar.',
            ]);
        }

        if (count($labels) !== $origin->levels->count()) {
            throw ValidationException::withMessages([
                'labels' => "A escala tem {$origin->levels->count()} níveis; recebeu ".count($labels).' nomes.',
            ]);
        }
    }

    /**
     * @param  list<string>  $labels
     */
    protected function applyLabels(Scale $scale, array $labels): void
    {
        foreach ($scale->levels()->orderBy('sequence')->get()->values() as $index => $level) {
            $level->update(['label' => $labels[$index]]);
        }
    }

    protected function uniqueName(string $profileName): string
    {
        $base = 'Escala 1 a 5 · '.trim($profileName);
        $name = $base;
        $suffix = 2;

        while (Scale::withoutGlobalScope('scaleVisibility')
            ->where('organization_id', $this->tenant->id())
            ->where('name', $name)
            ->exists()) {
            $name = "{$base} ({$suffix})";
            $suffix++;
        }

        return $name;
    }
}
