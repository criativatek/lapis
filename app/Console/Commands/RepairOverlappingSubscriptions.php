<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\SubscriptionStatus;
use App\Support\Entitlements\Entitlements;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Closes subscriptions that overlap, for organizations that acquired more than
 * one in force before ChangeOrganizationPlan existed to prevent it.
 *
 * Deliberately a command and not a migration. A migration would have to name the
 * organizations it repairs, would run once on each database with whatever
 * happened to be true that day, and would be unrunnable afterwards — while what
 * this is for is a data condition that has to be inspected before it is touched
 * and verified after. It is also idempotent: run it twice and the second run
 * finds nothing.
 *
 * The rule is chosen to be invisible to the teacher: the subscription that
 * `Entitlements` ALREADY resolves to is the one that survives, so repairing
 * changes nobody's plan. It only removes the ones underneath that were never
 * effective and that would have resurfaced the moment the winner was suspended.
 *
 * Anything that does not match that shape is reported and left alone. A pattern
 * nobody has looked at is not something a batch job should decide about.
 */
class RepairOverlappingSubscriptions extends Command
{
    protected $signature = 'lapis:repair-overlapping-subscriptions
                            {--apply : Write the repair. Without it the command only reports.}';

    protected $description = 'Report — and with --apply, close — subscriptions that are in force at the same time as another';

    public function handle(Entitlements $entitlements): int
    {
        $apply = (bool) $this->option('apply');

        $this->line($apply
            ? 'A APLICAR a reparação.'
            : 'Simulação (--dry-run implícito). Nada é escrito. Use --apply para reparar.');
        $this->newLine();

        $reparaveis = 0;
        $ambiguos = 0;
        $encerradas = 0;

        foreach (Organization::query()->withoutGlobalScope('organization')->orderBy('id')->get() as $organization) {
            $subscriptions = $this->subscriptionsOf($organization);
            $inForce = $subscriptions->filter(fn (OrganizationSubscription $s): bool => $s->isInForce());

            if ($inForce->count() <= 1) {
                continue;
            }

            // The one Entitlements resolves to: newest starts_at, id as the
            // tiebreaker. Identical to the resolver, on purpose — the repair must
            // not change which plan an organization is on.
            $winner = $inForce
                ->sortBy([['starts_at', 'asc'], ['id', 'asc']])
                ->last();

            $superseded = $inForce->reject(fn (OrganizationSubscription $s): bool => $s->is($winner));

            $this->line("org{$organization->getKey()} — {$inForce->count()} subscrições em vigor");

            foreach ($subscriptions as $subscription) {
                $papel = match (true) {
                    $subscription->is($winner) => 'MANTÉM  ',
                    $superseded->contains(fn (OrganizationSubscription $s): bool => $s->is($subscription)) => 'ENCERRA ',
                    default => 'histórico',
                };

                $this->line(sprintf(
                    '    [%s] id=%-5d plano=%-14s estado=%-10s starts_at=%s ends_at=%s',
                    $papel,
                    $subscription->getKey(),
                    $subscription->plan->key,
                    $subscription->status->value,
                    $subscription->starts_at->toDateTimeString(),
                    $subscription->ends_at?->toDateTimeString() ?? '(aberta)',
                ));
            }

            $problema = $this->refuseToGuess($superseded, $winner);

            if ($problema !== null) {
                $this->error("    NÃO REPARADO: {$problema}");
                $this->error('    Precisa de decisão humana. Nada foi alterado nesta organização.');
                $this->newLine();
                $ambiguos++;

                continue;
            }

            $reparaveis++;

            if ($apply) {
                $encerradas += $this->close($superseded, $winner);
                $entitlements->flush();
                $this->info('    reparado.');
            }

            $this->newLine();
        }

        $this->line(str_repeat('-', 70));
        $this->line("organizações com sobreposição reparável: {$reparaveis}");
        $this->line("organizações ambíguas, deixadas intactas:  {$ambiguos}");

        if ($apply) {
            $this->line("subscrições encerradas:                   {$encerradas}");
        } elseif ($reparaveis > 0) {
            $this->newLine();
            $this->comment('Nada foi escrito. Volte a correr com --apply para reparar.');
        }

        if ($reparaveis === 0 && $ambiguos === 0) {
            $this->info('Nenhuma sobreposição. Nada a fazer.');
        }

        // A non-zero exit for the ambiguous case, so a deploy script notices.
        return $ambiguos > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Why this organization must not be repaired automatically, or null.
     *
     * One rule, and it is narrow on purpose. A superseded subscription that
     * declares its own `ends_at` is one somebody deliberately gave a window to,
     * and overwriting that date would destroy a decision this command has not
     * been told about. Everything else about the overlap — a superseded
     * subscription that ran for a while before the next one started, or one that
     * started in the very same instant and was never effective at all — closes
     * correctly at the moment the surviving subscription began.
     *
     * @param  Collection<int, OrganizationSubscription>  $superseded
     */
    protected function refuseToGuess(Collection $superseded, OrganizationSubscription $winner): ?string
    {
        foreach ($superseded as $subscription) {
            if ($subscription->ends_at !== null) {
                return "a subscrição id={$subscription->getKey()} já declara um fim em "
                    .$subscription->ends_at->toDateTimeString().', que não vou reescrever';
            }
        }

        return null;
    }

    /**
     * Closes the superseded ones at the instant the winner started.
     *
     * `ends_at = winner.starts_at` is the honest reading: this subscription
     * stopped applying the moment the other one took over. Where both started in
     * the same instant — the provisioning defect — that window is empty, which is
     * exactly right: it never was in force. The row, its plan and its dates stay.
     *
     * @param  Collection<int, OrganizationSubscription>  $superseded
     */
    protected function close(Collection $superseded, OrganizationSubscription $winner): int
    {
        return DB::transaction(function () use ($superseded, $winner): int {
            $closed = 0;

            foreach ($superseded as $subscription) {
                $subscription->forceFill([
                    'status' => SubscriptionStatus::Expired,
                    'ends_at' => $winner->starts_at,
                ])->save();

                $closed++;
            }

            return $closed;
        });
    }

    /**
     * @return Collection<int, OrganizationSubscription>
     */
    protected function subscriptionsOf(Organization $organization): Collection
    {
        return OrganizationSubscription::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->with('plan')
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get()
            ->collect();
    }
}
