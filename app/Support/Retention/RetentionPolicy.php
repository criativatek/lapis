<?php

namespace App\Support\Retention;

/**
 * A thin, stateless reader over config/retention.php. No logic beyond reading
 * config, so it is trivially fakeable in tests via config()->set(...) in a
 * test's setUp(). See docs/data-lifecycle.md for what each figure means and
 * whether it is actually enforced anywhere yet.
 */
final class RetentionPolicy
{
    public function pedagogicalPreviousYearsRetained(): int
    {
        return (int) config('retention.pedagogical_previous_years_retained');
    }

    public function personalAccountClosureDays(): int
    {
        return (int) config('retention.personal_account_closure_days');
    }

    public function institutionalClosureDays(): int
    {
        return (int) config('retention.institutional_closure_days');
    }

    public function technicalLogDays(): int
    {
        return (int) config('retention.technical_log_days');
    }

    public function securityAuditYears(): int
    {
        return (int) config('retention.security_audit_years');
    }

    /**
     * @return array{0: int, 1: int} [min, max] days
     */
    public function technicalBackupRotationDaysRange(): array
    {
        return [
            (int) config('retention.technical_backup_rotation_days_min'),
            (int) config('retention.technical_backup_rotation_days_max'),
        ];
    }

    /**
     * Quantos dias se guardam os eventos de utilização detalhados
     * (`usage_events`). NUNCA mais do que os agregados: um agregado apagado não
     * pode ser reconstituído a partir de eventos ainda guardados. Executado
     * por `usage:prune`.
     */
    public function usageEventsDays(): int
    {
        return max(1, min((int) config('retention.usage_events_days'), $this->usageRollupsDays()));
    }

    /**
     * Quantos dias se guardam os agregados diários por pessoa
     * (`usage_daily_rollups`). É também o período mais antigo que o painel
     * aceita mostrar. Executado por `usage:prune`.
     */
    public function usageRollupsDays(): int
    {
        return max(1, (int) config('retention.usage_rollups_days'));
    }

    public function dataExportAvailabilityHours(): int
    {
        return (int) config('retention.data_export_availability_hours');
    }
}
