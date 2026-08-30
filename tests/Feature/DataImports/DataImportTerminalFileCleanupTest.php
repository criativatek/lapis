<?php

namespace Tests\Feature\DataImports;

use App\Actions\DataImports\ExecuteDataImport;
use App\Models\DataImport;
use App\Models\DataImportStatus;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\FailsToDeleteFiles;
use Tests\Concerns\SubscribesOrganizations;
use Tests\TestCase;

/**
 * What happens to the uploaded backup when a restore session ends.
 *
 * `DataImportStatus::isFinal()` says it plainly — "Nothing more will happen to
 * this import. The uploaded file has no reason to exist past this point" — and
 * `Failed` is one of those states. Until 0.101.3 nothing acted on that
 * sentence: confirm()'s catch marked the row and left the backup on the
 * private disk, and neither prune pass would ever pick it up (pass 1 only
 * looked at open imports; the orphan pass skips anything a row still points
 * at). Production had exactly one such file when this was found.
 *
 * These tests pin the promise at the moment it is made, rather than trusting
 * the nightly net to make up for it.
 */
class DataImportTerminalFileCleanupTest extends TestCase
{
    use FailsToDeleteFiles;
    use RefreshDatabase;
    use SubscribesOrganizations;

    /** @return array{Organization, User} */
    private function organizationWithRestore(): array
    {
        $owner = User::factory()->withoutOrganization()->create();
        $organization = Organization::factory()->institutional()->create(['owner_id' => $owner->id]);
        $organization->members()->attach($owner, ['joined_at' => now()]);
        $this->subscribeOrganizationTo($organization, 'pro');

        return [$organization, $owner];
    }

    private function validatedImport(Organization $organization, User $requester, string $storedPath): DataImport
    {
        $import = new DataImport;
        $import->forceFill([
            'organization_id' => $organization->id,
            'status' => DataImportStatus::Validated->value,
            'stored_path' => $storedPath,
            'requested_by' => $requester->id,
            'canonical_snapshot' => [
                'schema_version' => 3,
                'app_version' => '0.101.2',
                'generated_at' => now()->toIso8601String(),
                'organization' => ['ulid' => (string) Str::ulid(), 'name' => 'Origem', 'type' => 'personal'],
                'classes' => [],
                'students' => [],
                'enrollments' => [],
                'instruments' => [],
                'classifications' => [],
            ],
            'expires_at' => now()->addDay(),
        ]);
        $import->save();

        return $import;
    }

    #[Test]
    public function a_failed_import_does_not_leave_its_backup_on_the_private_disk(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('data-imports/doomed.zip', 'bytes');

        [$organization, $owner] = $this->organizationWithRestore();
        $import = $this->validatedImport($organization, $owner, 'data-imports/doomed.zip');

        $this->mock(ExecuteDataImport::class)
            ->shouldReceive('execute')
            ->andThrow(new RuntimeException('a escrita rebentou'));

        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->post("/data-imports/{$import->ulid}/confirm")
            ->assertRedirect();

        $fresh = $import->fresh();
        $this->assertSame('failed', $fresh->status->value);
        Storage::disk('local')->assertMissing('data-imports/doomed.zip');
        $this->assertNull($fresh->stored_path, 'A confirmed delete must take the pointer with it.');
    }

    #[Test]
    public function a_failed_import_whose_file_will_not_delete_keeps_the_pointer_for_a_retry(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('data-imports/stuck.zip', 'bytes');
        $this->failDeleteOf('data-imports/stuck.zip');

        [$organization, $owner] = $this->organizationWithRestore();
        $import = $this->validatedImport($organization, $owner, 'data-imports/stuck.zip');

        $this->mock(ExecuteDataImport::class)
            ->shouldReceive('execute')
            ->andThrow(new RuntimeException('a escrita rebentou'));

        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->post("/data-imports/{$import->ulid}/confirm")
            ->assertRedirect();

        $fresh = $import->fresh();
        $this->assertSame('failed', $fresh->status->value);
        $this->assertSame('data-imports/stuck.zip', $fresh->stored_path, 'An unproven delete must never cost us the pointer.');
    }

    #[Test]
    public function cancelling_keeps_the_pointer_when_the_file_could_not_be_removed(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('data-imports/stubborn.zip', 'bytes');
        $this->failDeleteOf('data-imports/stubborn.zip');

        [$organization, $owner] = $this->organizationWithRestore();
        $import = $this->validatedImport($organization, $owner, 'data-imports/stubborn.zip');

        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->delete("/data-imports/{$import->ulid}")
            ->assertRedirect();

        $fresh = $import->fresh();
        $this->assertSame('cancelled', $fresh->status->value, 'The teacher did cancel — that part is true and is recorded.');
        $this->assertSame('data-imports/stubborn.zip', $fresh->stored_path);
    }

    #[Test]
    public function cancelling_clears_the_pointer_when_the_file_really_went(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('data-imports/bye.zip', 'bytes');

        [$organization, $owner] = $this->organizationWithRestore();
        $import = $this->validatedImport($organization, $owner, 'data-imports/bye.zip');

        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->delete("/data-imports/{$import->ulid}")
            ->assertRedirect();

        Storage::disk('local')->assertMissing('data-imports/bye.zip');
        $this->assertNull($import->fresh()->stored_path);
    }
}
