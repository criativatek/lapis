<?php

namespace Tests\Unit\Retention;

use App\Support\Retention\ClosureRetention;
use App\Support\Retention\RetentionPolicy;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pure boundary logic — no schema, no closure-request flow exists yet. The
 * entire point of this class is getting the boundary exactly right: strictly
 * less-than the configured number of days, never less-than-or-equal.
 */
class ClosureRetentionTest extends TestCase
{
    protected bool $seed = false;

    private function closureRetention(): ClosureRetention
    {
        return new ClosureRetention(new RetentionPolicy);
    }

    /**
     * @return array<string, array{int, bool}>
     */
    public static function personalAccountDays(): array
    {
        return [
            'day 0 is recoverable' => [0, true],
            'day 59 is recoverable' => [59, true],
            'day 60 is not recoverable (boundary)' => [60, false],
            'day 61 is not recoverable' => [61, false],
            'day 1000 is not recoverable' => [1000, false],
        ];
    }

    #[Test]
    #[DataProvider('personalAccountDays')]
    public function personal_account_recoverability_follows_the_configured_boundary(int $daysElapsed, bool $expected): void
    {
        $closureRequestedAt = Carbon::parse('2026-01-01 00:00:00');
        $now = $closureRequestedAt->copy()->addDays($daysElapsed);

        $this->assertSame($expected, $this->closureRetention()->isPersonalAccountRecoverable($closureRequestedAt, $now));
    }

    /**
     * @return array<string, array{int, bool}>
     */
    public static function institutionalDays(): array
    {
        return [
            'day 0 is recoverable' => [0, true],
            'day 89 is recoverable' => [89, true],
            'day 90 is not recoverable (boundary)' => [90, false],
            'day 91 is not recoverable' => [91, false],
        ];
    }

    #[Test]
    #[DataProvider('institutionalDays')]
    public function institutional_organization_recoverability_follows_the_configured_boundary(int $daysElapsed, bool $expected): void
    {
        $closureRequestedAt = Carbon::parse('2026-01-01 00:00:00');
        $now = $closureRequestedAt->copy()->addDays($daysElapsed);

        $this->assertSame($expected, $this->closureRetention()->isInstitutionalOrganizationRecoverable($closureRequestedAt, $now));
    }
}
