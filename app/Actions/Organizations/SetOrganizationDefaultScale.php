<?php

namespace App\Actions\Organizations;

use App\Models\Organization;
use App\Models\Scale;
use App\Support\Tenancy\CurrentOrganization;
use InvalidArgumentException;

/**
 * A escala da organização: o apontador `organizations.default_scale_id`.
 *
 * Só aponta para uma escala DESTA organização — nunca para a de outra, nunca
 * para uma do sistema (essa já é o ponto de partida quando não há apontador).
 * Move-se com a escala: se guardar criou uma escala nova (copy-on-write), o
 * apontador passa para ela. Desmarcar só limpa o apontador que apontava para
 * a escala editada ou para o resultado do guardar — nunca toca num apontador
 * que aponta para outro lado.
 */
class SetOrganizationDefaultScale
{
    public function __construct(protected CurrentOrganization $tenant) {}

    /**
     * @param  Scale  $edited  a escala de onde se partiu no diálogo
     * @param  Scale  $result  a escala que resultou de guardar (a mesma, ou a nova)
     * @return bool se a escala resultante ficou a ser a da organização
     */
    public function apply(Scale $edited, Scale $result, bool $useAsDefault): bool
    {
        $organization = Organization::query()->findOrFail($this->tenant->id());
        $current = $organization->default_scale_id;

        if ($useAsDefault) {
            if ($result->organization_id !== $organization->id) {
                throw new InvalidArgumentException('A escala da organização tem de ser uma escala desta organização.');
            }

            $target = $result->id;
        } elseif ($current !== null && in_array($current, [$edited->id, $result->id], true)) {
            $target = null;
        } else {
            return $current !== null && $current === $result->id;
        }

        if ($target !== $current) {
            $organization->forceFill(['default_scale_id' => $target])->save();
        }

        return $target === $result->id;
    }
}
