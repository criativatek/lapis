<?php

namespace Tests\Feature\Usage;

use App\Actions\DataImports\ExecuteDataImport;
use App\Models\DataImport;
use App\Models\DataImportStatus;
use App\Models\Organization;
use App\Models\UsageEvent;
use App\Models\User;
use App\Support\Usage\UsageEventType;
use App\Support\Usage\UsageFailureCode;
use App\Support\Usage\UsageFeature;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\SubscribesOrganizations;
use Tests\Feature\Usage\Concerns\SetsUsageCoverage;
use Tests\TestCase;

/**
 * Os ganchos de importação e exportação: o restauro de uma cópia de segurança
 * (sucesso e falha) e o calendário. O comportamento visível da rota é o de antes.
 */
class UsageHooksImportExportTest extends TestCase
{
    use RefreshDatabase, SetsUsageCoverage, SubscribesOrganizations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-12 10:00:00', 'Europe/Lisbon'));
        $this->startUsageCollectionAt('2026-10-01 00:00:00');
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

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
    public function a_restore_that_fails_records_one_failure_and_the_visible_behaviour_is_unchanged(): void
    {
        Storage::disk('local')->put('data-imports/doomed.zip', 'bytes');
        [$organization, $owner] = $this->organizationWithRestore();
        $import = $this->validatedImport($organization, $owner, 'data-imports/doomed.zip');

        $this->mock(ExecuteDataImport::class)
            ->shouldReceive('execute')
            ->andThrow(new RuntimeException('a escrita rebentou'));

        // O que o `catch` sempre fez: volta com o erro «import», marca a linha
        // como falhada e apaga o ficheiro carregado.
        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->post("/data-imports/{$import->ulid}/confirm")
            ->assertRedirect()
            ->assertSessionHasErrors('import');

        $this->assertSame('failed', $import->fresh()->status->value);
        Storage::disk('local')->assertMissing('data-imports/doomed.zip');

        $failure = UsageEvent::query()->sole();
        $this->assertSame(UsageEventType::OperationFailed, $failure->event);
        $this->assertSame(UsageFeature::ImportExport, $failure->feature);
        $this->assertSame(UsageFailureCode::Unexpected, $failure->failure_code);
        $this->assertNull($failure->item_count);
    }

    #[Test]
    public function a_database_error_in_a_restore_is_recorded_with_the_database_code_and_no_message(): void
    {
        Storage::disk('local')->put('data-imports/doomed.zip', 'bytes');
        [$organization, $owner] = $this->organizationWithRestore();
        $import = $this->validatedImport($organization, $owner, 'data-imports/doomed.zip');

        $this->mock(ExecuteDataImport::class)
            ->shouldReceive('execute')
            ->andThrow(new QueryException('sqlite', 'insert into secret (x) values (?)', ['SENTINELA-PRIVADA-7f3a'], new \Exception('x')));

        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->post("/data-imports/{$import->ulid}/confirm")
            ->assertSessionHasErrors('import');

        $failure = UsageEvent::query()->sole();
        $this->assertSame(UsageFailureCode::Database, $failure->failure_code);
        $this->assertStringNotContainsString('SENTINELA', json_encode($failure->getAttributes(), JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function a_restore_that_succeeds_records_one_action_with_the_rows_restored(): void
    {
        [$organization, $owner] = $this->organizationWithRestore();
        $import = $this->validatedImport($organization, $owner, 'data-imports/ok.zip');

        $restored = $this->validatedImport($organization, $owner, 'data-imports/ok.zip');
        $restored->forceFill([
            'status' => DataImportStatus::Imported->value,
            'summary' => [
                'classes' => ['created' => 2, 'skipped' => 1],
                'students' => ['created' => 30, 'skipped' => 0],
                'enrollments' => ['created' => 30, 'skipped' => 0],
                'instruments_created' => 4,
                'classes_needing_reassignment' => 2,
                'records_without_original_author' => 9,
            ],
        ])->save();

        $this->mock(ExecuteDataImport::class)
            ->shouldReceive('execute')
            ->andReturn($restored);

        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->post("/data-imports/{$import->ulid}/confirm")
            ->assertRedirect();

        $event = UsageEvent::query()->sole();
        $this->assertSame(UsageEventType::BackupRestored, $event->event);
        // 2 + 30 + 30 + 4: só o que foi criado, nunca contadores de outra natureza.
        $this->assertSame(66, $event->item_count);
        $this->assertSame(UsageFeature::ImportExport, $event->feature);
    }

    #[Test]
    public function a_calendar_event_is_recorded_as_one_action(): void
    {
        $teacher = User::factory()->create();
        $organization = $teacher->personalOrganization();
        $this->subscribeOrganizationTo($organization, 'pro');

        $this->actingAs($teacher)->withSession(['organization_id' => $organization->id])
            ->post('/calendar/acontecimentos', [
                'type' => 'meeting',
                'title' => 'Conselho de turma',
                'starts_on' => '2026-10-20',
                'ends_on' => null,
                'starts_at' => null,
                'ends_at' => null,
                'description' => null,
                'school_class_ulids' => [],
            ])->assertRedirect();

        $event = UsageEvent::query()->sole();
        $this->assertSame(UsageEventType::CalendarEventCreated, $event->event);
        $this->assertSame(UsageFeature::Calendar, $event->feature);
        $this->assertSame(1, $event->item_count);
    }
}
