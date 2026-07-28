<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PruneRosterImportTempStorageTest extends TestCase
{
    #[Test]
    public function it_deletes_temp_folders_older_than_the_threshold_and_keeps_recent_ones(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');

        $oldToken = 'old-token-abandoned';
        $recentToken = 'recent-token-still-in-progress';

        $disk->put("roster-imports/{$oldToken}/0.jpg", 'old-abandoned-bytes');
        $disk->put("roster-imports/{$recentToken}/0.jpg", 'recent-bytes');

        // Storage::fake('local') backs the disk with a real local filesystem
        // (under storage/framework/testing/disks/local), so the file's real
        // mtime can be backdated directly — no need to fake the clock. Past
        // the command's default 360-minute (6 hour) threshold.
        touch($disk->path("roster-imports/{$oldToken}/0.jpg"), now()->subHours(7)->getTimestamp());

        $this->artisan('roster-imports:prune')->assertExitCode(0);

        $disk->assertMissing("roster-imports/{$oldToken}/0.jpg");
        $disk->assertExists("roster-imports/{$recentToken}/0.jpg");
    }

    #[Test]
    public function the_older_than_option_lets_a_shorter_threshold_be_used(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');

        $token = 'just-created';
        $disk->put("roster-imports/{$token}/0.jpg", 'bytes');

        // Backdate by a few seconds so an --older-than in whole minutes (the
        // option's unit) reliably classifies it as "older than 0 minutes"
        // without relying on real wall-clock sleep in the test.
        touch($disk->path("roster-imports/{$token}/0.jpg"), now()->subSeconds(5)->getTimestamp());

        $this->artisan('roster-imports:prune', ['--older-than' => 0])->assertExitCode(0);

        $disk->assertDirectoryEmpty('roster-imports');
    }

    #[Test]
    public function it_does_nothing_when_no_roster_imports_have_ever_run(): void
    {
        Storage::fake('local');

        $this->artisan('roster-imports:prune')->assertExitCode(0);
    }
}
