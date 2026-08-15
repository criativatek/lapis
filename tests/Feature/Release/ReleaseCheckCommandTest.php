<?php

namespace Tests\Feature\Release;

use App\Support\Release\BuildStamp;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The check exists to fail. These tests are mostly about the failing.
 *
 * A post-deploy check that passes when it should not is worse than no check,
 * because it converts "nobody looked" into "somebody looked and it was fine".
 * So each way the deploy can go wrong gets its own case: the wrong version, the
 * wrong commit, and — the one that caused this whole mechanism — no evidence at
 * all, which must be reported as a failure rather than waved through.
 */
class ReleaseCheckCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        @unlink(BuildStamp::path());

        parent::tearDown();
    }

    protected function stamp(string $version, string $commit): void
    {
        (new BuildStamp($version, $commit, '2026-08-15T02:25:00+01:00'))->write();
    }

    #[Test]
    public function it_reports_the_running_version_and_commit(): void
    {
        config(['app.version' => '0.31.0']);
        $this->stamp('0.31.0', 'f39c084abcdef1234567890abcdef1234567890a');

        $this->artisan('lapis:release-check')
            ->expectsOutputToContain('0.31.0')
            ->expectsOutputToContain('f39c084')
            ->assertSuccessful();
    }

    #[Test]
    public function it_confirms_a_deploy_that_matches_what_was_sent(): void
    {
        config(['app.version' => '0.31.0']);
        $this->stamp('0.31.0', 'f39c084abcdef1234567890abcdef1234567890a');

        $this->artisan('lapis:release-check --expect-version=0.31.0 --expect-commit=f39c084')
            ->assertSuccessful();
    }

    #[Test]
    public function it_fails_when_the_running_version_is_not_the_expected_one(): void
    {
        // The 0.29.1 shape: git moved on, production did not.
        config(['app.version' => '0.29.0']);
        $this->stamp('0.29.0', 'f9f6491abcdef1234567890abcdef1234567890a');

        $this->artisan('lapis:release-check --expect-version=0.31.0')
            ->expectsOutputToContain('esperada 0.31.0')
            ->assertFailed();
    }

    #[Test]
    public function it_fails_when_the_running_commit_is_not_the_expected_one(): void
    {
        // The version can match while the code does not: a package built from an
        // older commit that happens to carry the same version number.
        config(['app.version' => '0.31.0']);
        $this->stamp('0.31.0', '213168fabcdef1234567890abcdef1234567890a');

        $this->artisan('lapis:release-check --expect-version=0.31.0 --expect-commit=f39c084')
            ->expectsOutputToContain('esperado f39c084')
            ->assertFailed();
    }

    #[Test]
    public function a_missing_stamp_is_a_failure_not_a_shrug(): void
    {
        config(['app.version' => '0.31.0']);
        @unlink(BuildStamp::path());

        $this->artisan('lapis:release-check --expect-commit=f39c084')
            ->expectsOutputToContain('não há carimbo')
            ->assertFailed();
    }

    #[Test]
    public function it_reports_a_stamp_that_disagrees_with_the_running_configuration(): void
    {
        // The package was assembled from one state and is running as another —
        // a stale bootstrap/cache/config.php looks exactly like this (armadilha 1).
        config(['app.version' => '0.30.0']);
        $this->stamp('0.31.0', 'f39c084abcdef1234567890abcdef1234567890a');

        $this->artisan('lapis:release-check')
            ->expectsOutputToContain('carimbo diz 0.31.0')
            ->assertFailed();
    }

    #[Test]
    public function without_expectations_it_only_reports_and_does_not_judge(): void
    {
        // Run by hand on the server to answer "what is running here?", with
        // nothing to compare against. That is not a failure.
        config(['app.version' => '0.31.0']);
        @unlink(BuildStamp::path());

        $this->artisan('lapis:release-check')
            ->expectsOutputToContain('sem carimbo')
            ->assertSuccessful();
    }

    #[Test]
    public function a_corrupt_or_incomplete_stamp_is_treated_as_absent(): void
    {
        config(['app.version' => '0.31.0']);
        file_put_contents(BuildStamp::path(), '{"version":"0.31.0"}');

        // Half a stamp proves nothing. Better to say there is none than to
        // report a commit that was never written.
        $this->assertNull(BuildStamp::read());

        $this->artisan('lapis:release-check --expect-commit=f39c084')->assertFailed();
    }
}
