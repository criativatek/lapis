<?php

namespace Tests\Feature\Console;

use App\Models\DataImport;
use App\Models\DataImportStatus;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PruneDataImportsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * `organization_id` is deliberately not fillable on DataImport — it is
     * always stamped from the resolved tenant, never accepted from a
     * request. A scheduler test has no tenant in the container at all, so
     * it has to set it the same way the model itself would: forceFill.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function createDataImport(array $attributes): DataImport
    {
        $import = new DataImport;
        $import->forceFill($attributes);
        $import->save();

        return $import;
    }

    #[Test]
    public function it_cancels_abandoned_imports_past_their_expiry_and_removes_the_stored_file(): void
    {
        Storage::fake('local');
        $organization = Organization::factory()->create();
        $user = User::factory()->withoutOrganization()->create();
        Storage::disk('local')->put('data-imports/abandoned.zip', 'bytes');

        $import = $this->createDataImport([
            'organization_id' => $organization->id,
            'status' => DataImportStatus::Validated->value,
            'stored_path' => 'data-imports/abandoned.zip',
            'requested_by' => $user->id,
            'expires_at' => now()->subHour(),
        ]);

        $this->artisan('data-imports:prune')->assertExitCode(0);

        Storage::disk('local')->assertMissing('data-imports/abandoned.zip');
        $fresh = $import->fresh();
        $this->assertSame('cancelled', $fresh->status->value);
        $this->assertNull($fresh->stored_path);
    }

    #[Test]
    public function it_keeps_imports_still_within_their_expiry_window(): void
    {
        Storage::fake('local');
        $organization = Organization::factory()->create();
        $user = User::factory()->withoutOrganization()->create();
        Storage::disk('local')->put('data-imports/fresh.zip', 'bytes');

        $import = $this->createDataImport([
            'organization_id' => $organization->id,
            'status' => DataImportStatus::Validated->value,
            'stored_path' => 'data-imports/fresh.zip',
            'requested_by' => $user->id,
            'expires_at' => now()->addHour(),
        ]);

        $this->artisan('data-imports:prune')->assertExitCode(0);

        Storage::disk('local')->assertExists('data-imports/fresh.zip');
        $this->assertSame('validated', $import->fresh()->status->value);
    }

    #[Test]
    public function it_removes_orphan_files_older_than_a_day_with_no_import_row(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $disk->put('data-imports/orphan.zip', 'bytes');
        touch($disk->path('data-imports/orphan.zip'), now()->subDays(2)->getTimestamp());

        $this->artisan('data-imports:prune')->assertExitCode(0);

        $disk->assertMissing('data-imports/orphan.zip');
    }

    #[Test]
    public function it_keeps_a_recent_orphan_file_in_case_a_wizard_is_still_using_it(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $disk->put('data-imports/recent-orphan.zip', 'bytes');

        $this->artisan('data-imports:prune')->assertExitCode(0);

        $disk->assertExists('data-imports/recent-orphan.zip');
    }

    #[Test]
    public function it_does_nothing_when_there_is_nothing_to_prune(): void
    {
        Storage::fake('local');

        $this->artisan('data-imports:prune')->assertExitCode(0);
    }
}
