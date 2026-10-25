<?php

namespace Tests\Feature\Database;

use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\DataImportStatus;
use App\Models\Enrollment;
use App\Models\Organization;
use App\Models\Student;
use App\Models\User;
use App\Services\Import\Backup\BuildImportPlan;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\BuildsRectificationChainBackups;
use Tests\Concerns\ConnectsToMysqlServer;
use Tests\Concerns\SubscribesOrganizations;
use Tests\TestCase;

/**
 * THE BACKUP AND THE RESTORE OF A RECTIFIED CLASSIFICATION, ON A REAL MYSQL.
 *
 * A rectified cell is more than one row, and the table that holds them refuses
 * every order in which they could be written except one (the same one
 * `RectifyClassification` follows): a `superseded` row needs its successor's id,
 * the successor needs its predecessor to exist, and only one row of a cell may be
 * alive. SQLite enforces none of that, so a restore that wrote the rows in the
 * natural order passed the whole suite and would have failed on the first backup
 * of a school that had ever rectified a grade.
 *
 * Nothing here is mocked: the world is built, exported, uploaded and confirmed
 * through the same routes a teacher uses, over the real schema with its CHECK
 * constraints and its unique index switched on. The first test asserts that they
 * ARE on, because every other test here passes vacuously without them.
 *
 * OPT-IN, like the other MySQL guarantees (the first run migrates the whole
 * application). Run it with:
 *
 *     RECTIFICATION_MYSQL_SCRATCH=1 DB_HOST=127.0.0.1 DB_PORT=3341 DB_USERNAME=root \
 *         php artisan test --filter=BackupRectificationChainMysqlTest
 *
 * The database `lapis_backup_rectification_scratch` is created and migrated once
 * and reused; every test works on accounts of its own.
 */
class BackupRectificationChainMysqlTest extends TestCase
{
    use BuildsRectificationChainBackups;
    use ConnectsToMysqlServer;
    use SubscribesOrganizations;

    private const DATABASE = 'lapis_backup_rectification_scratch';

    private const CONNECTION = 'backup_rectification_scratch';

    protected function setUp(): void
    {
        parent::setUp();

        if (env('RECTIFICATION_MYSQL_SCRATCH') !== '1') {
            $this->markTestSkipped('Defina RECTIFICATION_MYSQL_SCRATCH=1 para provar a cadeia de retificações em MySQL.');
        }

        if (! $this->mysqlIsListening()) {
            $this->markTestSkipped('Não há MySQL à escuta em '.$this->host().':'.$this->port().'.');
        }

        $this->useScratchAsTheDefaultConnection();
        $this->ensureSchema();

        Storage::fake('local');
        $this->teacher = User::factory()->create();
    }

    // ------------------------------------------------------ the guarantees

    #[Test]
    public function the_checks_and_the_index_this_test_depends_on_are_really_there(): void
    {
        $names = $this->scratch()->table('information_schema.CHECK_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', self::DATABASE)->pluck('CONSTRAINT_NAME')->all();

        $this->assertContains('classifications_superseded_pointer_check', $names);
        $this->assertContains('classifications_rectification_complete_check', $names);
        $this->assertTrue($this->scratch()->table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', self::DATABASE)->where('INDEX_NAME', 'classifications_one_live_unique')->where('NON_UNIQUE', 0)->exists());
    }

    #[Test]
    public function the_natural_order_is_refused_so_the_restore_cannot_use_it(): void
    {
        ['enrollments' => $enrollments] = $this->chainWorld($this->teacher);
        $row = $this->inTenant($this->teacher->personalOrganization(), fn (): array => (array) $this->scratch()->table('classifications')
            ->where('enrollment_id', $enrollments['Carla']->id)->first());

        // The old importer inserted every row as the backup said and wired the
        // pointer afterwards: a `superseded` row with no pointer…
        $attributes = collect($row)->except(['id', 'ulid', 'status_active_flag'])->all();
        $attributes['ulid'] = (string) Str::ulid();
        $attributes['status'] = 'superseded';
        $attributes['enrollment_id'] = $enrollments['Bruno']->id;

        $this->assertRejected(fn () => $this->scratch()->table('classifications')->insert($attributes));
    }

    // ------------------------------------------------------- the round trip

    #[Test]
    public function a_chain_of_three_and_a_chain_of_two_and_a_plain_one_round_trip_into_another_account(): void
    {
        $this->chainWorld($this->teacher);
        $origin = $this->chainsOf($this->teacher->personalOrganization());
        $file = $this->exportBackup($this->teacher, $this->teacher->personalOrganization());

        $other = User::factory()->create();
        $destination = $other->personalOrganization();
        $import = $this->uploadInto($file, $other, $destination);
        $this->confirmImport($import, $other, $destination);

        $restored = $this->chainsOf($destination);

        $this->assertSame(DataImportStatus::Imported, $import->fresh()->status);
        $this->assertSame(['Ana', 'Bruno', 'Carla'], array_keys($restored));
        $this->assertSame(['superseded', 'superseded', 'published'], array_column($restored['Ana'], 'status'));
        $this->assertSame(['superseded', 'published'], array_column($restored['Bruno'], 'status'));
        $this->assertSame(['published'], array_column($restored['Carla'], 'status'));

        // Exactly one live row per student, chained both ways.
        $this->assertSame([null, '3', '4'], array_column($restored['Ana'], 'rectifies'));
        $this->assertSame(['4', '5', null], array_column($restored['Ana'], 'superseded_by'));
        $this->assertSame([null, '2'], array_column($restored['Bruno'], 'rectifies'));
        $this->assertSame(['entry_error', 'other'], array_slice(array_column($restored['Ana'], 'reason'), 1));
        $this->assertSame([null, null, 'Reapreciação em conselho de turma.'], array_column($restored['Ana'], 'note'));

        // Nothing points at itself — in the destination, nor anywhere in the table.
        $this->assertSame(0, $this->selfPointers($destination));
        $this->assertSame(0, (int) $this->scratch()->selectOne('select count(*) as total from classifications where superseded_by_id = id')->total);

        // The same history, the only difference being who rectified it.
        $strip = fn (array $chains): array => collect($chains)->map(fn (array $rows) => collect($rows)->map(
            fn (array $row) => collect($row)->except(['rectified_by', 'confirmed_by'])->all()
        )->all())->all();
        $this->assertSame($strip($origin), $strip($restored));
        $this->assertSame([null, $other->email, $other->email], array_column($restored['Ana'], 'rectified_by'));
        $this->assertSame(3, $import->fresh()->summary['rectifications_in_restorer_name']);
    }

    #[Test]
    public function restoring_into_another_organization_of_the_same_person_keeps_every_author(): void
    {
        $this->chainWorld($this->teacher);
        $origin = $this->chainsOf($this->teacher->personalOrganization());
        $file = $this->exportBackup($this->teacher, $this->teacher->personalOrganization());

        $destination = Organization::factory()->withMember($this->teacher)->create(['owner_id' => $this->teacher->id, 'name' => 'Outra']);
        $import = $this->uploadInto($file, $this->teacher, $destination);
        $this->confirmImport($import, $this->teacher, $destination);

        $this->assertSame($origin, $this->chainsOf($destination));
        $this->assertSame(0, $this->selfPointers($destination));
        $this->assertSame(0, $import->fresh()->summary['rectifications_in_restorer_name']);
    }

    #[Test]
    public function the_order_of_the_rows_in_the_file_does_not_matter(): void
    {
        $this->chainWorld($this->teacher);
        $origin = $this->chainsOf($this->teacher->personalOrganization());
        $file = $this->rewriteBackup(
            $this->exportBackup($this->teacher, $this->teacher->personalOrganization()),
            function (array $json): array {
                $json['classifications'] = array_reverse($json['classifications']);

                return $json;
            },
        );

        $destination = Organization::factory()->withMember($this->teacher)->create(['owner_id' => $this->teacher->id, 'name' => 'Ao contrário']);
        $this->confirmImport($this->uploadInto($file, $this->teacher, $destination), $this->teacher, $destination);

        $this->assertSame($origin, $this->chainsOf($destination));
        $this->assertSame(0, $this->selfPointers($destination));
    }

    #[Test]
    public function restoring_the_same_backup_again_adds_nothing(): void
    {
        $this->chainWorld($this->teacher);
        $file = $this->exportBackup($this->teacher, $this->teacher->personalOrganization());

        $other = User::factory()->create();
        $destination = $other->personalOrganization();
        $this->confirmImport($this->uploadInto($file, $other, $destination), $other, $destination);
        $before = $this->chainsOf($destination);
        $rows = $this->inTenant($destination, fn (): int => Classification::query()->count());

        $second = $this->uploadInto($file, $other, $destination);
        $plan = $this->inTenant($destination, fn (): array => app(BuildImportPlan::class)->build($second->canonical_snapshot ?? [], $destination, $other, []));

        $this->assertSame(0, collect($plan['rows']['classifications'])->where('classification', 'new')->count());
        $this->assertSame($before, $this->chainsOf($destination));
        $this->assertSame($rows, $this->inTenant($destination, fn (): int => Classification::query()->count()));
    }

    // ----------------------------------------------------------- atomicity

    #[Test]
    public function a_failure_in_the_middle_of_a_chain_leaves_the_destination_as_it_was(): void
    {
        $this->chainWorld($this->teacher);
        $origin = $this->chainsOf($this->teacher->personalOrganization());
        $file = $this->exportBackup($this->teacher, $this->teacher->personalOrganization());

        $other = User::factory()->create();
        $destination = $other->personalOrganization();
        $import = $this->uploadInto($file, $other, $destination);
        $classifications = (int) $this->scratch()->table('classifications')->count();

        // Ana's third decision cannot be written: her first two are already in,
        // the first of them already superseded and pointing at the second.
        $dispatcher = Classification::getEventDispatcher();
        $dispatcher->listen('eloquent.created: '.Classification::class, function (Classification $classification): void {
            if ($classification->rectification_reason?->value === 'other') {
                throw new RuntimeException('Falha injetada a meio da cadeia.');
            }
        });

        try {
            $this->actingAs($other)->withSession(['organization_id' => $destination->id])
                ->post("/data-imports/{$import->ulid}/confirm");
        } finally {
            $dispatcher->forget('eloquent.created: '.Classification::class);
        }

        $this->assertSame(DataImportStatus::Failed, $import->fresh()->status);
        $this->assertSame([], $this->chainsOf($destination));
        $this->assertSame(0, $this->inTenant($destination, fn (): int => Student::query()->count()));
        $this->assertSame(0, $this->inTenant($destination, fn (): int => Enrollment::query()->count()));
        $this->assertSame($classifications, (int) $this->scratch()->table('classifications')->count(), 'a row of the failed restore survived');
        $this->assertSame($origin, $this->chainsOf($this->teacher->personalOrganization()), 'the origin was touched');

        // And the very same backup restores once the cause is gone.
        $retry = $this->uploadInto($file, $other, $destination);
        $this->confirmImport($retry, $other, $destination);
        $this->assertCount(3, $this->chainsOf($destination)['Ana']);
        $this->assertSame(0, $this->selfPointers($destination));
    }

    #[Test]
    public function the_live_row_of_a_restored_cell_is_the_only_one_the_index_lets_through(): void
    {
        $this->chainWorld($this->teacher);
        $file = $this->exportBackup($this->teacher, $this->teacher->personalOrganization());
        $other = User::factory()->create();
        $destination = $other->personalOrganization();
        $this->confirmImport($this->uploadInto($file, $other, $destination), $other, $destination);

        $live = $this->inTenant($destination, fn (): int => Classification::query()
            ->where('scope', ClassificationScope::Period)->where('status', '!=', 'superseded')->count());
        $cells = $this->inTenant($destination, fn (): int => Classification::query()
            ->where('scope', ClassificationScope::Period)->distinct()->count('enrollment_id'));

        $this->assertSame(3, $live);
        $this->assertSame(3, $cells);

        // A second live row in a restored cell is refused by the table itself.
        $carla = $this->inTenant($destination, fn (): Classification => Classification::query()->where('status', 'published')->whereNull('rectifies_id')->whereNull('superseded_by_id')
            ->get()->first(fn (Classification $row) => $row->enrollment->student->identity->display_name === 'Carla'));
        $twin = collect($this->scratch()->table('classifications')->where('id', $carla->id)->first())->except(['id', 'ulid', 'status_active_flag'])->all();
        $twin['ulid'] = (string) Str::ulid();

        $this->assertRejected(fn () => $this->scratch()->table('classifications')->insert($twin));
    }

    // -------------------------------------------------------------- helpers

    private function assertRejected(callable $statement): void
    {
        try {
            $statement();
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('A tabela devia ter recusado esta escrita.');
    }

    private function scratch(): Connection
    {
        return DB::connection(self::CONNECTION);
    }

    private function useScratchAsTheDefaultConnection(): void
    {
        $this->serverPdo()->exec('CREATE DATABASE IF NOT EXISTS `'.self::DATABASE.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        Config::set('database.connections.'.self::CONNECTION, [
            'driver' => 'mysql',
            'host' => $this->host(),
            'port' => $this->port(),
            'database' => self::DATABASE,
            'username' => (string) env('DB_USERNAME', 'root'),
            'password' => (string) env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => null,
        ]);
        // The application's own models and services use the DEFAULT connection:
        // pointing it here is what makes this the real restore on the real schema.
        Config::set('database.default', self::CONNECTION);

        DB::purge(self::CONNECTION);
    }

    /** Migrated once and reused; a run that died halfway starts the schema again. */
    private function ensureSchema(): void
    {
        $schema = $this->scratch()->getSchemaBuilder();

        $ready = $schema->hasTable('classifications')
            && $schema->hasColumn('classifications', 'rectified_at')
            && $this->scratch()->table('scales')->exists();

        if ($ready) {
            return;
        }

        $this->assertSame(0, Artisan::call('migrate:fresh', ['--database' => self::CONNECTION, '--force' => true]), Artisan::output());
        $this->seed(ReferenceDataSeeder::class);
    }
}
