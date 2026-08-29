<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Services\Ai\Gateway\AiCapability;
use App\Services\Ai\Gateway\AiQuota;
use App\Services\Ai\Gateway\AiUsageSummary;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administração institucional → Inteligência Artificial.
 *
 * GOVERNANCE IS CONSUMPTION AND CONFIGURATION. IT IS NOT SURVEILLANCE (§20).
 * That sentence is the entire specification of this screen, and it is worth
 * saying what it rules out, because the ruled-out version is the one that is
 * easy to build:
 *
 *   NOT SHOWN, EVER      what any teacher asked, what any engine answered, any
 *                        pedagogical content, any student, any per-member
 *                        breakdown of who used what.
 *
 *   SHOWN                which AI capabilities this organization holds, what
 *                        ceilings are in force, how much has been spent this
 *                        month in total and by capability, and why calls were
 *                        refused.
 *
 * THE FIRST LIST IS NOT ENFORCED BY THIS CONTROLLER'S RESTRAINT. It is enforced
 * by `ai_usage_events` having no column that could hold any of it — see that
 * migration's docblock. An administrator with a debugger and this route cannot
 * reach a prompt, because there is no prompt to reach.
 *
 * AND NO RANKING OF COLLEAGUES. `AiUsageSummary` deliberately has no per-user
 * aggregation; the Matriz Mestre rules out simplistic teacher rankings, and an
 * organization that has run out of pool needs a bigger pool or a per-member
 * ceiling — both settings, neither a name.
 *
 * IT CONFIGURES NOTHING YET, AND SAYS SO. The pool's numbers live in the
 * plan's `limits['ai_pool']` (a contract figure) or in the platform's own
 * settings; there is no form here that writes either, because letting an
 * institutional administrator raise their own contractual plafond is not a
 * thing a SaaS does. What this screen adds is that they can SEE it, which is
 * what makes «o mês acabou» explicable without a support ticket.
 *
 * GATED BY `ai_governance`, WHICH ONLY INSTITUCIONAL HOLDS. The route carries
 * the module middleware; the policy underneath still runs, because a module
 * gate knows the organization's plan and not who is asking.
 */
class InstitutionAiController extends Controller
{
    public function __construct(
        protected CurrentOrganization $currentOrganization,
        protected Entitlements $entitlements,
        protected AiQuota $quota,
        protected AiUsageSummary $usage,
    ) {}

    public function index(): Response
    {
        $organization = $this->currentOrganization->get();

        // The same policy Administração Institucional already uses: the module
        // gate says the plan allows it, and this says the person may.
        Gate::authorize('viewAny', [OrganizationInvitation::class, $organization]);

        return Inertia::render('institution/Ai', [
            'organization' => [
                'name' => $organization->name,
            ],
            'capabilities' => $this->capabilities($organization),
            'pool' => $this->pool($organization),
            // Since the start of the month — the same window the organization
            // quota and the pool are counted in, so a figure here and a ceiling
            // beside it are comparable.
            'usage' => $this->usage->forOrganization($organization, now()->startOfMonth()),
        ]);
    }

    /**
     * What this organization holds, and what ceiling each capability has.
     *
     * EVERY METERED CAPABILITY IS LISTED, held or not. «Não está incluída no
     * vosso plano» is an answer; a capability missing from the list is a
     * mystery, and the mystery is what generates the email.
     *
     * THE CEILING SHOWN IS THE EFFECTIVE ONE — `AiQuota::limit()` reads the
     * plan first and the platform default second, exactly as the request path
     * does. A screen that showed the platform default while a plan overrode it
     * would be worse than showing nothing.
     *
     * @return list<array<string, mixed>>
     */
    protected function capabilities(Organization $organization): array
    {
        $rows = [];

        foreach (AiCapability::metered() as $capability) {
            $allowed = false;

            foreach ($capability->moduleKeys() as $moduleKey) {
                if ($this->entitlements->allowsFor($organization, $moduleKey)) {
                    $allowed = true;

                    break;
                }
            }

            $rows[] = [
                'key' => $capability->value,
                'label' => $capability->label(),
                'where' => $capability->whereItLives(),
                'allowed' => $allowed,
                'limits' => [
                    'user_daily' => $this->quota->limit($organization, $capability, 'user_daily'),
                    'organization_monthly' => $this->quota->limit($organization, $capability, 'organization_monthly'),
                ],
            ];
        }

        return $rows;
    }

    /**
     * The organizational pool, and how much of it is gone.
     *
     * `applies` IS FALSE FOR ALMOST EVERY ORGANIZATION AND THAT IS CORRECT. The
     * pool is an Institucional instrument; a Base or Pro organization has none,
     * and this screen is not reachable for them anyway. An Institucional
     * organization whose contract has not set a figure sees `applies: true`
     * with null ceilings — «o plafond está preparado e não está limitado», which
     * is the honest state and not an error.
     *
     * @return array<string, mixed>
     */
    protected function pool(Organization $organization): array
    {
        return [
            'applies' => $this->quota->poolApplies($organization),
            'organization_monthly' => $this->quota->poolLimit($organization, 'organization_monthly'),
            'user_monthly' => $this->quota->poolLimit($organization, 'user_monthly'),
            'used_this_month' => $this->quota->organizationPoolUsageThisMonth($organization),
        ];
    }
}
