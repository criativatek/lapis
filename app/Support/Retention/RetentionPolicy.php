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

    public function dataExportAvailabilityHours(): int
    {
        return (int) config('retention.data_export_availability_hours');
    }
}
