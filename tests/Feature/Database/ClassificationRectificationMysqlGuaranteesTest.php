<?php

namespace Tests\Feature\Database;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\AssertsMysqlRejection;
use Tests\Concerns\ConnectsToMysqlServer;
use Tests\TestCase;
use Throwable;

/**
 * AS GARANTIAS DA RETIFICAÇÃO QUE O SQLITE NÃO SABE DAR.
 *
 * A retificação substitui uma classificação publicada por outra, e o que a torna
 * segura vive em três sítios que a suíte normal (SQLite em memória) não exerce:
 *
 *  1. **As CHECK constraints** — «substituída» ⇔ «tem sucessora», e os quatro
 *     campos da retificação todos ou nenhum. O SQLite não as recebe numa tabela
 *     existente.
 *  2. **O índice «uma só viva»** (`classifications_one_live_unique`, sobre uma
 *     coluna gerada). É ele que obriga a libertar a antiga ANTES de inserir a
 *     nova, e é a sequência (a)(b)(c) que se prova aqui, sobre a tabela real.
 *  3. **O bloqueio de linha.** `lockForUpdate()` é ignorado pelo SQLite; é ele
 *     que faz a segunda sessão esperar e, depois, ver a linha já substituída.
 *
 * Reproduz, sobre a tabela migrada de verdade, os casos A–J da sonda que validou
 * o desenho em MySQL 8.0.43 e 9.7.1. Nada é simulado: são as mesmas migrações,
 * as mesmas constraints e duas ligações reais.
 *
 * OPT-IN, pelo mesmo motivo das outras garantias de MySQL (migrar tudo leva
 * ~40 s na primeira vez). Corre com:
 *
 *     RECTIFICATION_MYSQL_SCRATCH=1 php artisan test --filter=ClassificationRectificationMysqlGuarantees
 *
 * A base `lapis_rectification_scratch` é criada e migrada uma vez e reaproveitada.
 * Sem a variável, ou sem MySQL à escuta, o teste marca-se `skipped`.
 */
class ClassificationRectificationMysqlGuaranteesTest extends TestCase
{
    use AssertsMysqlRejection, ConnectsToMysqlServer;

    private const DATABASE = 'lapis_rectification_scratch';

    private const MIGRATION = 'database/migrations/2026_11_25_000100_add_rectification_to_classifications_table.php';

    protected function setUp(): void
    {
        parent::setUp();

        if (env('RECTIFICATION_MYSQL_SCRATCH') !== '1') {
            $this->markTestSkipped('Defina RECTIFICATION_MYSQL_SCRATCH=1 para correr as garantias de MySQL.');
        }

        if (! $this->mysqlIsListening()) {
            $this->markTestSkipped('Não há MySQL à escuta em '.$this->host().':'.$this->port().'.');
        }

        $this->configureScratchConnections();
        $this->ensureSchema();
        $this->resetFixtures();
    }

    // ------------------------------------------------- 1. CHECK constraints

    #[Test]
    public function a_superseded_row_without_a_successor_is_refused(): void
    {
        // Caso A. É a premissa de toda a sequência: não se pode simplesmente
        // marcar a antiga como substituída e só depois dizer por quem.
        $old = $this->seedPublished(1);

        $this->assertRejected(
            fn () => $this->scratch()->table('classifications')->where('id', $old)->update(['status' => 'superseded']),
            'uma substituída sem sucessora',
        );

        $this->assertSame('published', $this->row($old)->status);
    }

    #[Test]
    public function a_successor_pointer_on_a_row_that_is_not_superseded_is_refused(): void
    {
        $old = $this->seedPublished(1);
        $other = $this->seedPublished(2);

        $this->assertRejected(
            fn () => $this->scratch()->table('classifications')->where('id', $old)->update(['superseded_by_id' => $other]),
            'um ponteiro de sucessora numa linha que não está substituída',
        );

        $this->assertNull($this->row($old)->superseded_by_id);
    }

    #[Test]
    public function the_rectification_facts_come_together_or_not_at_all_but_the_author_may_be_unknown(): void
    {
        $old = $this->seedPublished(1);
        $complete = [
            'rectifies_id' => $old,
            'rectification_reason' => 'entry_error',
            'rectified_at' => '2026-11-25 10:00:00',
        ];

        // Cada um dos três em falta, um de cada vez, e cada um sozinho — com ou
        // sem autor: o autor não entra no grupo.
        foreach ([null, 1] as $author) {
            foreach (array_keys($complete) as $missing) {
                $partial = $complete;
                unset($partial[$missing]);

                $this->assertRejected(
                    fn () => $this->insertClassification(['enrollment_id' => 9, 'rectified_by' => $author] + $partial),
                    "uma retificação sem {$missing} (autor ".($author === null ? 'nulo' : 'presente').')',
                );
            }
        }

        foreach ($complete as $column => $value) {
            $this->assertRejected(
                fn () => $this->insertClassification(['enrollment_id' => 9, $column => $value]),
                "uma retificação só com {$column}",
            );
        }

        $this->assertSame(0, $this->scratch()->table('classifications')->where('enrollment_id', 9)->count());

        // Com os três e SEM autor (a autoria histórica não resolvida de um
        // restauro) a CHECK aceita; a linha antiga sai do índice «uma só viva»
        // primeiro, como no serviço.
        $this->stepA($old);
        $id = $this->insertClassification(['enrollment_id' => 1, 'rectified_by' => null] + $complete);
        $this->assertNull($this->row($id)->rectified_by);
        $this->assertSame($old, (int) $this->row($id)->rectifies_id);
    }

    // --------------------------------------- 2. a sequência (a)(b)(c) por inteiro

    #[Test]
    public function inserting_the_new_row_while_the_old_one_is_live_violates_the_one_live_index(): void
    {
        // Caso B: a razão de a antiga ter de ser libertada primeiro.
        $old = $this->seedPublished(1);

        $this->assertRejected(
            fn () => $this->insertRectification(1, $old, 80),
            'a nova com a antiga ainda viva',
        );

        $this->assertCount(1, $this->liveRows(1));
    }

    #[Test]
    public function the_rectification_sequence_leaves_one_live_row_and_the_right_pointer(): void
    {
        // Caso C: (a) a antiga passa a substituída com um auto-ponteiro
        // temporário (a CHECK exige ponteiro e a nova ainda não existe);
        // (b) insere-se a nova; (c) o ponteiro aponta, enfim, para a nova.
        $old = $this->seedPublished(1);

        $new = $this->rectify($old, 1, 80);

        $live = $this->liveRows(1);
        $this->assertCount(1, $live);
        $this->assertSame($new, (int) $live[0]->id);

        $this->assertSame('superseded', $this->row($old)->status);
        $this->assertSame($new, (int) $this->row($old)->superseded_by_id);
        $this->assertSame($old, (int) $this->row($new)->rectifies_id);

        $this->assertSame(0, $this->selfPointers(), 'sobrou um auto-ponteiro temporário');
    }

    #[Test]
    public function a_second_rectification_chains_three_rows_with_a_single_live_one(): void
    {
        // Caso D.
        $first = $this->seedPublished(1);
        $second = $this->rectify($first, 1, 80);
        $third = $this->rectify($second, 1, 90);

        $chain = $this->scratch()->table('classifications')->where('enrollment_id', 1)->orderBy('id')->get();
        $this->assertCount(3, $chain);

        $this->assertSame($second, (int) $chain[0]->superseded_by_id);
        $this->assertSame($third, (int) $chain[1]->superseded_by_id);
        $this->assertNull($chain[2]->superseded_by_id);
        $this->assertSame($first, (int) $chain[1]->rectifies_id);
        $this->assertSame($second, (int) $chain[2]->rectifies_id);

        $this->assertSame([$third], array_map(fn ($r) => (int) $r->id, $this->liveRows(1)));
        $this->assertSame(0, $this->selfPointers());
    }

    // ------------------------------------------------------ 3. as falhas desfazem tudo

    #[Test]
    public function a_failure_right_after_step_a_rolls_everything_back(): void
    {
        // Caso E.
        $old = $this->seedPublished(2);

        try {
            $this->scratch()->transaction(function () use ($old) {
                $this->stepA($old);

                throw new RuntimeException('falha simulada depois do passo (a)');
            });
            $this->fail('a transação devia ter lançado');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('passo (a)', $exception->getMessage());
        }

        $this->assertOriginalStateIntact($old, 2);
    }

    #[Test]
    public function an_invalid_insert_after_step_a_rolls_everything_back(): void
    {
        // Caso F: a CHECK dos quatro campos recusa a nova (falta o motivo) e a
        // transação inteira, passo (a) incluído, desfaz-se.
        $old = $this->seedPublished(3);

        $this->assertRejected(function () use ($old) {
            $this->scratch()->transaction(function () use ($old) {
                $this->stepA($old);

                $this->insertClassification([
                    'enrollment_id' => 3,
                    'final_value' => 80,
                    'override_reason' => 'r',
                    'rectifies_id' => $old,
                    'rectified_by' => 1,
                    'rectified_at' => '2026-11-25 10:00:00',
                ]);
            });
        }, 'uma retificação sem motivo');

        $this->assertOriginalStateIntact($old, 3);
    }

    #[Test]
    public function a_php_failure_after_step_b_rolls_back_the_old_and_the_new_row(): void
    {
        // Caso G: nenhum erro de SQL, só uma exceção da aplicação depois de a
        // nova já ter entrado.
        $old = $this->seedPublished(4);

        try {
            $this->scratch()->transaction(function () use ($old) {
                $this->stepA($old);
                $this->insertRectification(4, $old, 80);

                throw new RuntimeException('falha simulada depois do passo (b)');
            });
            $this->fail('a transação devia ter lançado');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('passo (b)', $exception->getMessage());
        }

        $this->assertOriginalStateIntact($old, 4);
    }

    #[Test]
    public function the_pointer_foreign_key_restricts_deleting_a_row_that_is_pointed_at(): void
    {
        // Caso H: a antiga é apontada pela nova (`superseded_by_id`) e a nova
        // aponta para a antiga (`rectifies_id`); nenhuma se apaga.
        $old = $this->seedPublished(1);
        $new = $this->rectify($old, 1, 80);

        $this->assertRejected(
            fn () => $this->scratch()->table('classifications')->where('id', $old)->delete(),
            'apagar a antiga apontada pela nova',
        );

        $this->assertSame(2, $this->scratch()->table('classifications')->where('enrollment_id', 1)->count());
        $this->assertSame($new, (int) $this->row($old)->superseded_by_id);
    }

    // ------------------------------------------------------------ 4. concorrência

    #[Test]
    public function a_second_session_waits_on_the_lock_and_then_sees_the_row_superseded(): void
    {
        // Caso I. Duas ligações reais: enquanto a primeira retifica, a segunda
        // não passa do `lockForUpdate`; depois do commit, vê a linha já
        // substituída — e é isso que lhe permite recusar em vez de retificar
        // duas vezes a mesma decisão.
        $old = $this->seedPublished(5);

        $a = $this->scratch();
        $b = $this->scratchB();
        $b->statement('SET SESSION innodb_lock_wait_timeout = 2');

        $a->beginTransaction();
        $a->table('classifications')->where('id', $old)->lockForUpdate()->first();

        $b->beginTransaction();

        try {
            $b->table('classifications')->where('id', $old)->lockForUpdate()->first();
            $b->rollBack();
            $this->fail('a segunda sessão passou pelo lock: nada impede duas retificações em simultâneo');
        } catch (Throwable $exception) {
            $b->rollBack();
            $this->assertStringContainsString('Lock wait timeout', $exception->getMessage());
        }

        $this->stepA($old, $a);
        $new = $this->insertRectification(5, $old, 80, $a);
        $a->table('classifications')->where('id', $old)->update(['superseded_by_id' => $new]);
        $a->commit();

        $b->beginTransaction();
        $seen = $b->table('classifications')->where('id', $old)->lockForUpdate()->first();
        $b->rollBack();

        $this->assertSame('superseded', $seen->status);
        $this->assertSame($new, (int) $seen->superseded_by_id);
        $this->assertCount(1, $this->liveRows(5));
    }

    #[Test]
    public function a_forgotten_temporary_self_pointer_is_detectable(): void
    {
        // Caso J: a base do comando que verifica a base de dados. Um auto-ponteiro
        // que sobrevivesse a um defeito do serviço tem de se poder encontrar.
        $row = $this->seedPublished(6);
        $this->stepA($row);

        $this->assertSame(1, $this->selfPointers());
    }

    // --------------------------------------------------------------- 5. a migração

    #[Test]
    public function the_migration_rolls_back_and_forward_without_leaving_orphans(): void
    {
        $columns = ['rectifies_id', 'rectification_reason', 'rectification_note', 'rectified_by', 'rectified_at'];
        $checks = ['classifications_superseded_pointer_check', 'classifications_rectification_complete_check'];

        $this->assertSame($columns, $this->presentColumns($columns));
        $this->assertSame($checks, $this->presentChecks($checks));

        try {
            $this->migrate('migrate:rollback');

            $this->assertSame([], $this->presentColumns($columns), 'ficaram colunas órfãs');
            $this->assertSame([], $this->presentChecks($checks), 'ficaram CHECKs órfãs');
            $this->assertSame([], $this->orphanForeignKeys(), 'ficaram chaves estrangeiras órfãs');

            // Uma linha incoerente tem de travar a migração ANTES de tocar em
            // nada, com a mensagem do preflight e não com a do motor.
            $bad = $this->seedPublished(7);
            $this->scratch()->table('classifications')->where('id', $bad)->update(['status' => 'superseded']);

            try {
                $this->migrate('migrate');
                $this->fail('a migração devia ter parado perante uma classificação incoerente');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('classificações incoerentes', $exception->getMessage());
            }

            $this->assertSame([], $this->presentColumns($columns), 'o preflight devia ter parado antes de criar colunas');

            $this->scratch()->table('classifications')->where('id', $bad)->delete();
        } finally {
            if ($this->presentColumns($columns) === []) {
                $this->scratch()->table('classifications')->where('status', 'superseded')->delete();
                $this->migrate('migrate');
            }
        }

        $this->assertSame($columns, $this->presentColumns($columns));
        $this->assertSame($checks, $this->presentChecks($checks));

        // Depois de «up/down/up» a CHECK continua sem o autor.
        $clause = (string) $this->scratch()->selectOne(
            'select check_clause as clause from information_schema.check_constraints where constraint_schema = database() and constraint_name = ?',
            ['classifications_rectification_complete_check'],
        )->clause;
        $this->assertStringNotContainsString('rectified_by', $clause);
        $this->assertStringContainsString('rectified_at', $clause);
    }

    // --------------------------------------------------------------- a sequência

    /** (a) A antiga sai do índice «uma só viva», com um auto-ponteiro temporário. */
    private function stepA(int $oldId, ?Connection $db = null): void
    {
        ($db ?? $this->scratch())->table('classifications')->where('id', $oldId)->update([
            'status' => 'superseded',
            'superseded_by_id' => $oldId,
            'lock_version' => DB::raw('lock_version + 1'),
        ]);
    }

    /** A sequência completa, como o serviço a vai correr: lock, (a), (b), (c). */
    private function rectify(int $oldId, int $enrollment, int $finalValue): int
    {
        return $this->scratch()->transaction(function (Connection $db) use ($oldId, $enrollment, $finalValue) {
            $db->table('classifications')->where('id', $oldId)->lockForUpdate()->first();

            $this->stepA($oldId, $db);
            $new = $this->insertRectification($enrollment, $oldId, $finalValue, $db);
            $db->table('classifications')->where('id', $oldId)->update(['superseded_by_id' => $new]);

            return $new;
        });
    }

    private function insertRectification(int $enrollment, int $rectifies, int $finalValue, ?Connection $db = null): int
    {
        return $this->insertClassification([
            'enrollment_id' => $enrollment,
            'final_value' => $finalValue,
            'rectifies_id' => $rectifies,
            'rectification_reason' => 'entry_error',
            'rectified_by' => 1,
            'rectified_at' => '2026-11-25 10:00:00',
        ], $db);
    }

    private function assertOriginalStateIntact(int $old, int $enrollment): void
    {
        $row = $this->row($old);

        $this->assertSame('published', $row->status);
        $this->assertNull($row->superseded_by_id);
        $this->assertSame(1, (int) $row->status_active_flag);
        $this->assertSame(0, (int) $row->lock_version);
        $this->assertSame(1, $this->scratch()->table('classifications')->where('enrollment_id', $enrollment)->count());
        $this->assertSame(0, $this->selfPointers());
    }

    // ------------------------------------------------------------- leituras

    private function row(int $id): object
    {
        return $this->scratch()->table('classifications')->where('id', $id)->first();
    }

    /** @return list<object> */
    private function liveRows(int $enrollment): array
    {
        return $this->scratch()->table('classifications')
            ->where('enrollment_id', $enrollment)->where('status', '<>', 'superseded')
            ->orderBy('id')->get()->all();
    }

    private function selfPointers(): int
    {
        return (int) $this->scratch()->table('classifications')->whereColumn('superseded_by_id', 'id')->count();
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private function presentColumns(array $names): array
    {
        $found = $this->scratch()->table('information_schema.columns')
            ->where('table_schema', self::DATABASE)->where('table_name', 'classifications')
            ->whereIn('column_name', $names)->selectRaw('column_name as n')->pluck('n')->map(fn ($n) => strtolower($n))->all();

        return array_values(array_filter($names, fn ($name) => in_array($name, $found, true)));
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private function presentChecks(array $names): array
    {
        $found = $this->scratch()->table('information_schema.table_constraints')
            ->where('table_schema', self::DATABASE)->where('table_name', 'classifications')
            ->where('constraint_type', 'CHECK')->whereIn('constraint_name', $names)
            ->selectRaw('constraint_name as n')->pluck('n')->map(fn ($n) => strtolower($n))->all();

        return array_values(array_filter($names, fn ($name) => in_array($name, $found, true)));
    }

    /** @return list<string> */
    private function orphanForeignKeys(): array
    {
        return $this->scratch()->table('information_schema.key_column_usage')
            ->where('table_schema', self::DATABASE)->where('table_name', 'classifications')
            ->whereIn('column_name', ['rectifies_id', 'rectified_by'])
            ->selectRaw('constraint_name as n')->pluck('n')->all();
    }

    private function migrate(string $command): void
    {
        // Só esta migração (`--path`): a prova é sobre ela e não deve depender
        // de quantas vierem depois.
        $code = Artisan::call($command, [
            '--database' => 'rectification_scratch',
            '--path' => self::MIGRATION,
            '--force' => true,
        ]);

        $this->assertSame(0, $code, Artisan::output());
    }

    // ------------------------------------------------------------- fixtures

    private function configureScratchConnections(): void
    {
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

        // DUAS ligações à MESMA base: duas sessões MySQL distintas são a única
        // forma de um teste num só processo observar um lock de linha a trabalhar.
        Config::set('database.connections.rectification_scratch', $base);
        Config::set('database.connections.rectification_scratch_b', $base);
        DB::purge('rectification_scratch');
        DB::purge('rectification_scratch_b');
    }

    private function ensureSchema(): void
    {
        $this->serverPdo()->exec('CREATE DATABASE IF NOT EXISTS `'.self::DATABASE.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        // Só na primeira vez. Se uma corrida anterior morreu a meio da migração
        // da retificação, a migração completa-se aqui.
        $schema = $this->scratch()->getSchemaBuilder();

        if (! $schema->hasTable('classifications') || ! $schema->hasColumn('classifications', 'rectified_at')) {
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'rectification_scratch', '--force' => true]), Artisan::output());
        }
    }

    /**
     * Os pais das classificações, sem montar o mundo à volta deles.
     *
     * A tabela de baixo é a REAL, com as suas FKs ligadas; o que se dispensa são
     * os ascendentes dos pais (escola, aluno, turma…), que nada aqui exerce. Por
     * isso cada pai é uma linha mínima inserida com as verificações de FK
     * desligadas, e só enquanto se monta — durante o teste ficam ligadas.
     */
    private function resetFixtures(): void
    {
        $db = $this->scratch();

        $db->statement('SET FOREIGN_KEY_CHECKS = 0');

        foreach (['classifications', 'enrollments', 'academic_periods', 'assessment_profile_versions', 'organizations', 'users'] as $table) {
            $db->table($table)->delete();
        }

        $this->stub('users', 1);
        $this->stub('organizations', 1);
        $this->stub('academic_periods', 1);
        $this->stub('assessment_profile_versions', 1);

        // Uma matrícula por caso, para que a unicidade «uma só viva» de um caso
        // não toque nos outros.
        foreach (range(1, 9) as $enrollment) {
            $this->stub('enrollments', $enrollment);
        }

        $db->statement('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * Insere a linha mínima de uma tabela, preenchendo só as colunas NOT NULL
     * sem valor por omissão, a partir do que o próprio esquema declara.
     */
    private function stub(string $table, int $id): void
    {
        $columns = $this->scratch()->select(
            'select column_name as name, data_type as type, column_type as full_type, character_maximum_length as len, extra as extra '
            .'from information_schema.columns where table_schema = ? and table_name = ? '
            ."and is_nullable = 'NO' and column_default is null",
            [self::DATABASE, $table],
        );

        $row = [];

        foreach ($columns as $column) {
            $extra = strtolower($column->extra);

            if (str_contains($extra, 'auto_increment') || str_contains($extra, 'generated')) {
                continue;
            }

            $row[$column->name] = match (true) {
                in_array($column->type, ['bigint', 'int', 'mediumint', 'smallint', 'tinyint']) => $id,
                in_array($column->type, ['decimal', 'float', 'double']) => 0,
                in_array($column->type, ['datetime', 'timestamp']) => '2026-11-25 10:00:00',
                $column->type === 'date' => '2026-11-25',
                $column->type === 'json' => '{}',
                $column->type === 'enum' => $this->firstEnumValue($column->full_type),
                // Único por (tabela, coluna, id); as ulids têm 26 caracteres.
                default => substr(md5("{$table}.{$column->name}.{$id}"), 0, min(26, (int) ($column->len ?: 26))),
            };
        }

        // Onde o esquema tem CHECKs próprias, o valor genérico não as cumpre.
        $known = [
            'academic_periods' => ['starts_on' => '2026-09-01', 'ends_on' => '2026-12-15', 'kind' => 'term', 'status' => 'open'],
            'assessment_profile_versions' => ['status' => 'active', 'domain_weight_mode' => 'free'],
            'enrollments' => ['status' => 'active'],
            'organizations' => ['type' => 'personal'],
        ][$table] ?? [];

        $this->scratch()->table($table)->insert(['id' => $id] + $known + $row);
    }

    private function firstEnumValue(string $columnType): string
    {
        preg_match("/^enum\('([^']*)'/", $columnType, $m);

        return $m[1] ?? '';
    }

    private function seedPublished(int $enrollment): int
    {
        return $this->insertClassification(['enrollment_id' => $enrollment]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function insertClassification(array $overrides, ?Connection $db = null): int
    {
        static $counter = 0;
        $counter++;

        return (int) ($db ?? $this->scratch())->table('classifications')->insertGetId($overrides + [
            'ulid' => str_pad((string) $counter, 26, '0', STR_PAD_LEFT),
            'organization_id' => 1,
            'enrollment_id' => 1,
            'academic_period_id' => 1,
            'scope' => 'period',
            'assessment_profile_version_id' => 1,
            'status' => 'published',
            'proposed_value' => 60,
            'final_value' => 70,
            'override_reason' => 'Decisão do professor.',
            'confirmed_by' => 1,
            'confirmed_at' => '2026-11-20 10:00:00',
            'published_at' => '2026-11-21 10:00:00',
        ]);
    }

    private function scratch(): Connection
    {
        return DB::connection('rectification_scratch');
    }

    private function scratchB(): Connection
    {
        return DB::connection('rectification_scratch_b');
    }
}
