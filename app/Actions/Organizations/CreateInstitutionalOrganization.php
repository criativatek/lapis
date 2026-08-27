<?php

namespace App\Actions\Organizations;

use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Gives a school its workspace, with a named owner from the first instant.
 *
 * THE INVARIANT THIS EXISTS TO PROTECT (Fatia 2, found while auditing Fatia 1):
 * ResolveOrganization resolves a tenant from `organization_memberships`, never
 * from `owner_id`. An owner who is not also a member can never have this
 * organization resolved as their own — so owner_id and the membership are
 * written together, in one transaction, and neither is optional. This mirrors
 * CreatePersonalOrganization exactly, for exactly the same reason.
 *
 * The subscription goes through SubscribeOrganization, the same collaborator
 * CreatePersonalOrganization uses — the FIRST subscription of a brand-new
 * organization is written in exactly one place, whichever kind of organization
 * it is.
 *
 * ⚠️ ANTES DA PRIMEIRA ORGANIZAÇÃO INSTITUCIONAL REAL. O Lapispro lança para
 * professores individuais. Nesse caso o enquadramento está em
 * `/tratamento-de-dados`: quem introduz dados de alunos fá-lo enquanto
 * responsável pelo tratamento ou autorizado pelo responsável competente, e o
 * Lapispro é subcontratante. Uma escola a utilizar a plataforma com vários
 * professores sob administração comum exige um contrato e um acordo de
 * subcontratação celebrados com a instituição, papéis internos definidos e
 * prazos de conservação acordados — nada disso existe hoje.
 *
 * Não há caminho self-service para aqui: esta ação só é alcançável por um
 * administrador da plataforma (`AdminAccountController`), e é esse o portão.
 * A lista do que tem de estar fechado antes de o atravessar está em
 * `docs/legal.md`, secção «Portão institucional».
 */
class CreateInstitutionalOrganization
{
    public function __construct(protected SubscribeOrganization $subscribe) {}

    public function create(string $name, User $owner, ?Plan $initialPlan = null): Organization
    {
        return DB::transaction(function () use ($name, $owner, $initialPlan): Organization {
            $organization = Organization::create([
                'name' => $name,
                'type' => OrganizationType::Institutional,
                'owner_id' => $owner->getKey(),
            ]);

            $organization->members()->attach($owner, ['joined_at' => Carbon::now()]);

            $this->subscribe->subscribe($organization, $initialPlan ?? Plan::where('key', 'institutional')->first());

            return $organization;
        });
    }
}
