<?php

namespace Tests\Unit\Retention;

use App\Support\Retention\RetentionPolicy;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards against config/retention.php drifting from RetentionPolicy without
 * anyone noticing — cheap to assert even though RetentionPolicy reads live
 * config and so cannot realistically go stale on its own.
 */
class RetentionPolicyTest extends TestCase
{
    protected bool $seed = false;

    private function policy(): RetentionPolicy
    {
        return new RetentionPolicy;
    }

    #[Test]
    public function it_reads_every_value_straight_from_config(): void
    {
        $policy = $this->policy();

        $this->assertSame(config('retention.pedagogical_previous_years_retained'), $policy->pedagogicalPreviousYearsRetained());
        $this->assertSame(config('retention.personal_account_closure_days'), $policy->personalAccountClosureDays());
        $this->assertSame(config('retention.institutional_closure_days'), $policy->institutionalClosureDays());
        $this->assertSame(config('retention.technical_log_days'), $policy->technicalLogDays());
        $this->assertSame(config('retention.security_audit_years'), $policy->securityAuditYears());
        $this->assertSame(config('retention.data_export_availability_hours'), $policy->dataExportAvailabilityHours());
        $this->assertSame(
            [
                config('retention.technical_backup_rotation_days_min'),
                config('retention.technical_backup_rotation_days_max'),
            ],
            $policy->technicalBackupRotationDaysRange(),
        );
    }

    #[Test]
    public function it_reflects_a_changed_config_value_immediately(): void
    {
        config()->set('retention.pedagogical_previous_years_retained', 7);

        $this->assertSame(7, $this->policy()->pedagogicalPreviousYearsRetained());
    }
}
