<?php

namespace App\Console\Commands;

use App\Models\DataExport;
use App\Models\Organization;
use App\Models\User;
use App\Support\Retention\DeletionEligibility;
use Illuminate\Console\Command;

/**
 * Read-only preview of everything the retention/closure policy currently
 * considers eligible — personal accounts, institutional organizations,
 * expired export ZIPs, academic years outside the pedagogical window.
 *
 * Deliberately a dry-run only (§18 of the lifecycle brief): nothing here
 * deletes, purges or mutates. It exists so an operator can answer "what
 * WOULD be affected" before any future purge tooling is built.
 */
class RetentionStatus extends Command
{
    protected $signature = 'retention:status {--json : Emit machine-readable JSON instead of tables}';

    protected $description = 'Preview accounts, organizations, exports and academic years the retention policy considers eligible (read-only)';

    public function handle(DeletionEligibility $eligibility): int
    {
        $personalAccounts = $eligibility->personalAccounts();
        $institutionalOrganizations = $eligibility->institutionalOrganizations();
        $expiredDataExports = $eligibility->expiredDataExports();
        $academicYears = $eligibility->academicYears();
        $auditEventsOutsideRetention = $eligibility->auditEventsOutsideRetention();

        if ($this->option('json')) {
            $this->line(json_encode([
                'personal_accounts' => $personalAccounts->map(fn (array $row): array => [
                    'id' => $row['user']->getKey(),
                    'email' => $row['user']->email,
                    'days_remaining' => $row['days_remaining'],
                    'eligible' => $row['eligible'],
                ])->all(),
                'institutional_organizations' => $institutionalOrganizations->map(fn (array $row): array => [
                    'ulid' => $row['organization']->ulid,
                    'name' => $row['organization']->name,
                    'days_remaining' => $row['days_remaining'],
                    'eligible' => $row['eligible'],
                ])->all(),
                'expired_data_exports' => $expiredDataExports->map(fn (DataExport $export): array => [
                    'ulid' => $export->ulid,
                    'expires_at' => $export->expires_at?->toIso8601String(),
                ])->all(),
                'academic_years' => collect($academicYears)->flatMap(fn (array $entry): array => collect($entry['years'])->map(fn (array $classified): array => [
                    'organization' => $entry['organization']->name,
                    'year' => $classified['year']->label,
                    'within_retention' => $classified['within_retention'],
                ])->all())->all(),
                'audit_events_outside_retention' => $auditEventsOutsideRetention,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('<fg=default;options=bold>Contas pessoais em encerramento</>', (string) $personalAccounts->count());
        foreach ($personalAccounts as $row) {
            /** @var User $user */
            $user = $row['user'];
            $this->components->twoColumnDetail(
                $user->email,
                $row['eligible'] ? '<fg=red>elegível para eliminação</>' : "{$row['days_remaining']} dia(s) restantes",
            );
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=default;options=bold>Organizações institucionais em encerramento</>', (string) $institutionalOrganizations->count());
        foreach ($institutionalOrganizations as $row) {
            /** @var Organization $organization */
            $organization = $row['organization'];
            $this->components->twoColumnDetail(
                $organization->name,
                $row['eligible'] ? '<fg=red>elegível para eliminação</>' : "{$row['days_remaining']} dia(s) restantes",
            );
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=default;options=bold>Exports expirados por limpar</>', (string) $expiredDataExports->count());

        $this->newLine();
        $yearsOutsideRetention = collect($academicYears)->flatMap(fn (array $entry): array => $entry['years'])
            ->filter(fn (array $classified): bool => ! $classified['within_retention']);
        $this->components->twoColumnDetail('<fg=default;options=bold>Anos letivos fora da retenção pedagógica</>', (string) $yearsOutsideRetention->count());
        foreach ($academicYears as $entry) {
            /** @var Organization $organization */
            $organization = $entry['organization'];
            foreach ($entry['years'] as $classified) {
                if (! $classified['within_retention']) {
                    $this->components->twoColumnDetail("{$organization->name} — {$classified['year']->label}", 'fora da retenção');
                }
            }
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=default;options=bold>Eventos de auditoria fora da retenção de segurança</>', (string) $auditEventsOutsideRetention);

        $this->newLine();
        $this->components->info('Nada foi apagado. Este comando é só de leitura.');

        return self::SUCCESS;
    }
}
