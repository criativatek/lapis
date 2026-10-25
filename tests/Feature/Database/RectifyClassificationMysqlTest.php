<?php

namespace Tests\Feature\Database;

use App\Models\AcademicPeriod;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\RectificationReason;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Assessment\ConfirmClassification;
use App\Services\Assessment\ProposeClassifications;
use App\Services\Assessment\PublishClassifications;
use App\Services\Assessment\RectifyClassification;
use App\Support\Assessment\ClassificationDecisionException;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\ConnectsToMysqlServer;
use Tests\TestCase;

/**
 * THE SERVICE ITSELF, ON THE REAL SCHEMA, IN MYSQL.
 *
 * `ClassificationRectificationMysqlGuaranteesTest` proves that the table lets
 * the sequence (a)(b)(c) through and refuses everything else, with hand-written
 * SQL. This proves that `RectifyClassification` really runs that sequence: the
 * real service, over the demo world migrated on a real MySQL, with the CHECK
 * constraints, the «one live row» unique index and the row locks all switched on
 * — none of which the SQLite suite exercises.
 *
 * The concurrency case uses two genuine sessions: the test holds the row locked
 * through an uncommitted rectification, and a SECOND PHP PROCESS runs the service
 * against it. It has to be a process, because a single one cannot wait on its own
 * lock; and the test only commits once the server itself reports a transaction in
 * LOCK WAIT, so «it was blocked» is observed rather than assumed.
 *
 * OPT-IN, as the other MySQL guarantees (the first run migrates and seeds the
 * whole application, ~1 min). Run it with:
 *
 *     RECTIFICATION_MYSQL_SCRATCH=1 DB_HOST=127.0.0.1 DB_PORT=3341 DB_USERNAME=root \
 *         php artisan test --filter=RectifyClassificationMysqlTest
 *
 * The database `lapis_rectification_service_scratch` is created, migrated and
 * seeded once, and reused.
 */
class RectifyClassificationMysqlTest extends TestCase
{
    use ConnectsToMysqlServer;

    private const DATABASE = 'lapis_rectification_service_scratch';

    private const CONNECTION = 'rectification_service_scratch';

    private User $ana;

    private SchoolClass $class;

    private AcademicPeriod $period;

    private Classification $carolina;

    protected function setUp(): void
    {
        parent::setUp();

        if (env('RECTIFICATION_MYSQL_SCRATCH') !== '1') {
            $this->markTestSkipped('Defina RECTIFICATION_MYSQL_SCRATCH=1 para correr a retificação em MySQL.');
        }

        if (! $this->mysqlIsListening()) {
            $this->markTestSkipped('Não há MySQL à escuta em '.$this->host().':'.$this->port().'.');
        }

        $this->useScratchAsTheDefaultConnection();
        $this->ensureWorld();
        $this->resetClassifications();

        $this->travelTo(Carbon::parse('2026-12-15 10:00:00'));
        $this->ana = User::where('email', 'ana.martins@lapis.test')->firstOrFail();

        $this->carolina = $this->asTenant(function (): Classification {
            $this->class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $this->period = $this->class->academicYear->periods()->where('sequence', 1)->firstOrFail();

            app(ProposeClassifications::class)->forPeriod($this->class, $this->period);
            $carolina = $this->carolinaRow();

            app(ConfirmClassification::class)->confirm($carolina, $this->ana, $this->levelId('4'));
            app(PublishClassifications::class)->forPeriod($this->class, $this->period, ClassificationScope::Period);

            return $carolina->fresh();
        });
    }

    // ------------------------------------------------------------------ (i)

    #[Test]
    public function real_rectification_commits_with_one_live_row_and_no_self_pointer(): void
    {
        $old = $this->carolina;

        $new = $this->rectify($old, '3');

        $this->assertSame('superseded', $this->row($old->id)->status);
        $this->assertSame($new->id, (int) $this->row($old->id)->superseded_by_id);
        $this->assertSame('published', $this->row($new->id)->status);
        $this->assertSame($old->id, (int) $this->row($new->id)->rectifies_id);
        $this->assertSame([$new->id], $this->liveIds($old));
        $this->assertSame(0, $this->selfPointers());
        $this->assertSame(1, $this->scratch()->table('audit_events')->where('event', 'classification.rectified')->count());
    }

    // ----------------------------------------------------------------- (ii)

    #[Test]
    public function a_chain_of_two_rectifications_keeps_one_live_row(): void
    {
        $a = $this->carolina;
        $b = $this->rectify($a, '3');
        $c = $this->rectify($b, '2');

        $this->assertSame($b->id, (int) $this->row($a->id)->superseded_by_id);
        $this->assertSame($c->id, (int) $this->row($b->id)->superseded_by_id);
        $this->assertNull($this->row($c->id)->superseded_by_id);
        $this->assertSame($a->id, (int) $this->row($b->id)->rectifies_id);
        $this->assertSame($b->id, (int) $this->row($c->id)->rectifies_id);
        $this->assertSame([$c->id], $this->liveIds($a));
        $this->assertSame(0, $this->selfPointers());
    }

    // ---------------------------------------------------------------- (iii)

    #[Test]
    public function failure_after_step_a_rolls_everything_back(): void
    {
        // Thrown as the new row is about to be inserted: the old one has already
        // been released into «superseded» with its temporary pointer.
        Classification::creating(function (Classification $row): void {
            if ($row->rectifies_id !== null) {
                throw new RuntimeException('falha depois do passo (a)');
            }
        });

        $this->assertRolledBack('falha depois do passo (a)');
    }

    #[Test]
    public function failure_after_insert_rolls_everything_back(): void
    {
        // Thrown once the INSERT has gone through, before the pointer is moved.
        Classification::created(function (Classification $row): void {
            if ($row->rectifies_id !== null) {
                throw new RuntimeException('falha depois do INSERT');
            }
        });

        $this->assertRolledBack('falha depois do INSERT');
    }

    private function assertRolledBack(string $message): void
    {
        $old = $this->carolina;
        $before = $this->scratch()->table('classifications')->count();

        try {
            $this->rectify($old, '3');
            $this->fail('a retificação devia ter lançado');
        } catch (RuntimeException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }

        $row = $this->row($old->id);
        $this->assertSame('published', $row->status);
        $this->assertNull($row->superseded_by_id);
        $this->assertSame(1, (int) $row->status_active_flag);
        $this->assertSame($old->lock_version, (int) $row->lock_version);
        $this->assertSame($before, $this->scratch()->table('classifications')->count());
        $this->assertSame(0, $this->scratch()->table('classifications')->whereNotNull('rectifies_id')->count());
        $this->assertSame(0, $this->selfPointers());
        $this->assertSame(0, $this->scratch()->table('audit_events')->where('event', 'classification.rectified')->count());
        $this->assertSame([$old->id], $this->liveIds($old));
    }

    // ----------------------------------------------------------------- (iv)

    #[Test]
    public function a_second_session_waits_for_the_lock_and_is_then_refused(): void
    {
        $old = $this->carolina;
        $levelTwo = $this->levelId('2');

        // The first rectifier: the REAL service, inside a transaction that stays
        // open, so the row lock it takes is still held when the second arrives.
        DB::beginTransaction();

        try {
            $new = $this->rectify($old, '3');

            $child = $this->startSecondSession($old, $levelTwo);
            $this->waitUntilTheServerShowsALockWait();

            DB::commit();
        } catch (\Throwable $exception) {
            DB::rollBack();
            isset($child) && proc_terminate($child['process']);

            throw $exception;
        }

        $result = $this->finishSecondSession($child);

        $this->assertSame(
            ['outcome' => 'refused', 'exception' => ClassificationDecisionException::class, 'message' => ClassificationDecisionException::alreadyRectified()->getMessage()],
            $result,
        );

        // Only the first rectification happened.
        $this->assertSame([$new->id], $this->liveIds($old));
        $this->assertSame(2, $this->scratch()->table('classifications')
            ->where('enrollment_id', $old->enrollment_id)->where('academic_period_id', $old->academic_period_id)->where('scope', 'period')->count());
        $this->assertSame('3.000', $this->row($new->id)->final_value);
        $this->assertSame(0, $this->selfPointers());
    }

    /** @return array{process: resource, pipes: array<int, resource>, script: string} */
    private function startSecondSession(Classification $old, int $levelId): array
    {
        $script = tempnam(sys_get_temp_dir(), 'rectify-child-').'.php';

        file_put_contents($script, <<<'PHP'
<?php
// A second process: the same service, the same database, a different session.
[$self, $root, $classificationId, $userId, $version, $levelId] = $argv;

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

Illuminate\Support\Facades\DB::statement('SET SESSION innodb_lock_wait_timeout = 60');

try {
    app(App\Services\Assessment\RectifyClassification::class)->rectify(
        App\Models\Classification::withoutGlobalScopes()->findOrFail((int) $classificationId),
        App\Models\User::findOrFail((int) $userId),
        App\Models\RectificationReason::EntryError,
        null,
        (int) $levelId,
        null,
        (int) $version,
        false,
    );
    echo json_encode(['outcome' => 'rectified']);
} catch (Throwable $e) {
    echo json_encode(['outcome' => 'refused', 'exception' => $e::class, 'message' => $e->getMessage()]);
}
PHP);

        $env = array_merge(array_filter(getenv(), 'is_string'), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_URL' => '',
            'DB_HOST' => $this->host(),
            'DB_PORT' => $this->port(),
            'DB_DATABASE' => self::DATABASE,
            'DB_USERNAME' => (string) env('DB_USERNAME', 'root'),
            'DB_PASSWORD' => (string) env('DB_PASSWORD', ''),
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
        ]);

        $process = proc_open(
            [PHP_BINARY, $script, base_path(), (string) $old->id, (string) $this->ana->id, (string) $old->lock_version, (string) $levelId],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
            $env,
        );

        $this->assertIsResource($process, 'não foi possível arrancar o segundo processo');

        return ['process' => $process, 'pipes' => $pipes, 'script' => $script];
    }

    /**
     * The server, not a sleep, says when the second session is stuck on the lock.
     */
    private function waitUntilTheServerShowsALockWait(): void
    {
        $deadline = microtime(true) + 60;

        while (microtime(true) < $deadline) {
            $waiting = (int) $this->scratchB()->table('information_schema.innodb_trx')->where('trx_state', 'LOCK WAIT')->count();

            if ($waiting > 0) {
                return;
            }

            usleep(100_000);
        }

        $this->fail('a segunda sessão nunca ficou à espera do lock: nada a impedia de retificar em simultâneo');
    }

    /**
     * @param  array{process: resource, pipes: array<int, resource>, script: string}  $child
     * @return array<string, mixed>
     */
    private function finishSecondSession(array $child): array
    {
        $out = stream_get_contents($child['pipes'][1]);
        $err = stream_get_contents($child['pipes'][2]);
        fclose($child['pipes'][1]);
        fclose($child['pipes'][2]);
        proc_close($child['process']);
        @unlink(substr($child['script'], 0, -4));
        @unlink($child['script']);

        $decoded = json_decode((string) $out, true);
        $this->assertIsArray($decoded, "o segundo processo não devolveu JSON. stdout: {$out} stderr: {$err}");

        return $decoded;
    }

    // ------------------------------------------------------------------ (v)

    #[Test]
    public function a_leaked_self_pointer_is_detectable(): void
    {
        $this->assertSame(0, $this->selfPointers());

        // What a defect in the service would leave behind: released, never repointed.
        $this->scratch()->table('classifications')->where('id', $this->carolina->id)
            ->update(['status' => 'superseded', 'superseded_by_id' => $this->carolina->id]);

        $this->assertSame(1, $this->selfPointers());
    }

    // ------------------------------------------------------------- helpers

    private function rectify(Classification $classification, string $levelCode): Classification
    {
        return $this->asTenant(fn () => app(RectifyClassification::class)->rectify(
            $classification, $this->ana, RectificationReason::EntryError, null, $this->levelId($levelCode), null, $this->row($classification->id)->lock_version, false,
        ));
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function asTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->ana->personalOrganization(), $callback);
    }

    private function carolinaRow(): Classification
    {
        return Classification::query()
            ->where('academic_period_id', $this->period->id)
            ->where('scope', ClassificationScope::Period)
            ->get()
            ->first(fn (Classification $c) => $c->enrollment->student->identity->display_name === 'Carolina Nunes');
    }

    private function levelId(string $code): int
    {
        return $this->asTenant(fn () => $this->class->profileVersion->scale->levels()->where('code', $code)->firstOrFail()->id);
    }

    private function row(int $id): object
    {
        return $this->scratch()->table('classifications')->where('id', $id)->first();
    }

    /** @return list<int> */
    private function liveIds(Classification $of): array
    {
        return $this->scratch()->table('classifications')
            ->where('enrollment_id', $of->enrollment_id)->where('academic_period_id', $of->academic_period_id)
            ->where('scope', $of->scope->value)->where('status', '<>', 'superseded')
            ->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function selfPointers(): int
    {
        return (int) $this->scratch()->table('classifications')->whereColumn('superseded_by_id', 'id')->count();
    }

    private function scratch(): Connection
    {
        return DB::connection(self::CONNECTION);
    }

    /** A second, independent session on the same database, used to look at the server. */
    private function scratchB(): Connection
    {
        return DB::connection(self::CONNECTION.'_b');
    }

    // ------------------------------------------------------------- fixtures

    private function useScratchAsTheDefaultConnection(): void
    {
        $this->serverPdo()->exec('CREATE DATABASE IF NOT EXISTS `'.self::DATABASE.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        $base = [
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
        ];

        Config::set('database.connections.'.self::CONNECTION, $base);
        Config::set('database.connections.'.self::CONNECTION.'_b', $base);
        // The application's own models and services use the DEFAULT connection:
        // pointing it here is what makes this the real service on the real schema.
        Config::set('database.default', self::CONNECTION);

        DB::purge(self::CONNECTION);
        DB::purge(self::CONNECTION.'_b');
    }

    /**
     * The whole application migrated and the demo scenario seeded — once, then
     * reused. A run that died halfway leaves no demo class behind, and the next
     * one starts the world again from an empty database rather than patching it.
     */
    private function ensureWorld(): void
    {
        $schema = $this->scratch()->getSchemaBuilder();

        $seeded = $schema->hasTable('classes')
            && $schema->hasColumn('classifications', 'rectified_at')
            && $this->scratch()->table('classes')->where('label', '7.º A')->exists();

        if ($seeded) {
            return;
        }

        $this->assertSame(0, Artisan::call('migrate:fresh', ['--database' => self::CONNECTION, '--force' => true]), Artisan::output());

        // What `RefreshDatabase` + `$seed` give every other test: the reference
        // data (plans, system scales), then the teacher and her demo class.
        $this->seed(ReferenceDataSeeder::class);
        User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);
    }

    /** Every test starts from «nothing classified», and builds Carolina's published row itself. */
    private function resetClassifications(): void
    {
        $db = $this->scratch();

        $db->statement('SET FOREIGN_KEY_CHECKS = 0');
        $db->table('classifications')->delete();
        $db->table('audit_events')->delete();
        $db->statement('SET FOREIGN_KEY_CHECKS = 1');
    }
}
