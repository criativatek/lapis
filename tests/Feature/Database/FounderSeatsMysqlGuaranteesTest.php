<?php

namespace Tests\Feature\Database;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

/**
 * AS GARANTIAS QUE O SQLITE NÃO SABE DAR.
 *
 * A suíte corre em SQLite em memória, e há duas propriedades de `founder_seats`
 * que ela por construção não consegue provar:
 *
 *  1. **As CHECK constraints.** A migração só as adiciona em MySQL/MariaDB e
 *     diz-o: «o SQLite não as adiciona a uma tabela existente». Ou seja, o
 *     limite `1 <= seat_number <= 1000` — o travão de sanidade contra uma
 *     configuração que pedisse dez mil fundadores — não é exercido em lado
 *     nenhum da suíte normal.
 *  2. **O bloqueio de linha.** `lockForUpdate()` é ignorado pelo SQLite, que
 *     tem um escritor de cada vez. A âncora de `FounderSeats::lockAllocation()`
 *     — a linha do plano `pro`, escolhida por existir SEMPRE, mesmo com a
 *     tabela de lugares vazia — é exactamente a peça que fecha a corrida da
 *     primeira venda, e em SQLite ela é um `no-op` que passa despercebido.
 *
 * OPT-IN, E POR ISSO REPORTADO À MÃO. Migrar tudo num MySQL de raiz leva ~40 s,
 * que é caro de mais para pagar em cada `composer ci:check` por causa de um
 * ficheiro. Corre com:
 *
 *     FOUNDER_MYSQL_SCRATCH=1 php artisan test --filter=FounderSeatsMysqlGuarantees
 *
 * O esquema é criado uma vez na base `lapis_founder_scratch` e reaproveitado; a
 * segunda corrida é rápida. Sem a variável, ou sem MySQL à escuta, o teste
 * marca-se `skipped` em vez de falhar numa máquina que não tem servidor.
 */
class FounderSeatsMysqlGuaranteesTest extends TestCase
{
    private const DATABASE = 'lapis_founder_scratch';

    private const HARD_CAP = 1000;

    protected function setUp(): void
    {
        parent::setUp();

        if (env('FOUNDER_MYSQL_SCRATCH') !== '1') {
            $this->markTestSkipped('Defina FOUNDER_MYSQL_SCRATCH=1 para correr as garantias de MySQL.');
        }

        if (! $this->mysqlIsListening()) {
            $this->markTestSkipped('Não há MySQL à escuta em 127.0.0.1:3308.');
        }

        $this->configureScratchConnections();
        $this->ensureSchema();
        $this->resetFixtures();
    }

    // ------------------------------------------------- 1. CHECK constraints

    #[Test]
    public function mysql_refuses_a_seat_number_outside_the_hard_cap(): void
    {
        // O travão que só existe em MySQL. `billing.founder.seats` move o teto
        // COMERCIAL; este é o de sanidade, e mudá-lo exige uma migração — que é
        // o peso certo para uma decisão dessas.
        foreach ([0, -1, self::HARD_CAP + 1] as $forbidden) {
            $this->assertRejected(
                fn () => $this->insertSeat($forbidden, $this->organizationId(1)),
                "MySQL aceitou seat_number = {$forbidden}",
            );
        }

        // E aceita as pontas válidas do intervalo.
        $this->insertSeat(1, $this->organizationId(1));
        $this->insertSeat(self::HARD_CAP, $this->organizationId(2));

        $this->assertSame(2, (int) $this->scratch()->table('founder_seats')->count());
    }

    #[Test]
    public function mysql_refuses_a_duplicate_ordinal_and_a_second_seat_for_one_organization(): void
    {
        $this->insertSeat(1, $this->organizationId(1));

        $this->assertRejected(
            fn () => $this->insertSeat(1, $this->organizationId(2)),
            'dois lugares com o mesmo ordinal',
        );

        $this->assertRejected(
            fn () => $this->insertSeat(2, $this->organizationId(1)),
            'duas vezes o mesmo ocupante',
        );

        $this->assertSame(1, (int) $this->scratch()->table('founder_seats')->count());
    }

    // ------------------------------------------------ 2. o lock é mesmo lock

    #[Test]
    public function the_allocation_anchor_really_serialises_two_connections(): void
    {
        // A PROPRIEDADE QUE FECHA A CORRIDA DA PRIMEIRA VENDA, medida em vez de
        // afirmada — e medida com `founder_seats` VAZIA, que é precisamente o
        // estado em que a versão anterior não bloqueava nada.
        $this->assertSame(0, (int) $this->scratch()->table('founder_seats')->count());

        $a = $this->scratch();
        $b = $this->scratchB();

        // A segunda ligação desiste ao fim de 1 s em vez de esperar 50: é essa
        // desistência que PROVA que o lock existe e é exclusivo.
        $b->statement('SET SESSION innodb_lock_wait_timeout = 1');

        $a->beginTransaction();

        try {
            $a->table('plans')->where('key', 'pro')->lockForUpdate()->first();

            $b->beginTransaction();

            try {
                $b->table('plans')->where('key', 'pro')->lockForUpdate()->first();
                $b->rollBack();
                $this->fail('a segunda ligação passou pelo lock: a âncora não serializa nada');
            } catch (Throwable $exception) {
                $b->rollBack();
                $this->assertStringContainsString(
                    'Lock wait timeout',
                    $exception->getMessage(),
                    'a segunda ligação falhou por outra razão que não o lock',
                );
            }
        } finally {
            $a->rollBack();
        }

        // Largada a primeira, a segunda passa de imediato — é uma fila, não um
        // impasse.
        $b->beginTransaction();
        $this->assertNotNull($b->table('plans')->where('key', 'pro')->lockForUpdate()->first());
        $b->rollBack();
    }

    // ------------------------------------------------------------ fixtures

    private function mysqlIsListening(): bool
    {
        try {
            new PDO('mysql:host=127.0.0.1;port=3308', 'root', '');

            return true;
        } catch (PDOException) {
            return false;
        }
    }

    private function configureScratchConnections(): void
    {
        $base = [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3308',
            'database' => self::DATABASE,
            'username' => 'root',
            'password' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => null,
        ];

        // DUAS ligações à MESMA base, e é esse o ponto: duas sessões MySQL
        // distintas são a única forma de um teste num só processo observar um
        // lock de linha a fazer o seu trabalho.
        Config::set('database.connections.founder_scratch', $base);
        Config::set('database.connections.founder_scratch_b', $base);
    }

    private function ensureSchema(): void
    {
        (new PDO('mysql:host=127.0.0.1;port=3308', 'root', ''))
            ->exec('CREATE DATABASE IF NOT EXISTS `'.self::DATABASE.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        DB::purge('founder_scratch');

        if ($this->scratch()->getSchemaBuilder()->hasTable('founder_seats')) {
            return;
        }

        // Só na primeira vez. Depois disto a base fica migrada e as corridas
        // seguintes arrancam de imediato.
        $this->artisan('migrate', ['--database' => 'founder_scratch', '--force' => true])->run();
    }

    private function resetFixtures(): void
    {
        $scratch = $this->scratch();

        $scratch->statement('SET FOREIGN_KEY_CHECKS = 0');
        $scratch->table('founder_seats')->delete();
        $scratch->table('organizations')->delete();
        $scratch->table('users')->delete();
        $scratch->table('plans')->delete();
        $scratch->statement('SET FOREIGN_KEY_CHECKS = 1');

        $scratch->table('plans')->insert(['id' => 1, 'key' => 'pro', 'name' => 'Pro']);

        foreach ([1, 2] as $n) {
            $scratch->table('users')->insert([
                'id' => $n,
                'name' => "Utilizador {$n}",
                'email' => "utilizador{$n}@exemplo.pt",
                'password' => 'x',
            ]);

            $scratch->table('organizations')->insert([
                'id' => $n,
                'ulid' => str_pad((string) $n, 26, '0', STR_PAD_LEFT),
                'name' => "Organização {$n}",
                'type' => 'personal',
                'owner_id' => $n,
            ]);
        }
    }

    private function organizationId(int $n): int
    {
        return $n;
    }

    private function insertSeat(int $seatNumber, int $organizationId): void
    {
        $this->scratch()->table('founder_seats')->insert([
            'seat_number' => $seatNumber,
            'organization_id' => $organizationId,
            'price_cents' => 2990,
            'currency' => 'EUR',
            'claimed_at' => '2026-06-01 10:00:00',
        ]);
    }

    private function assertRejected(callable $write, string $what): void
    {
        try {
            $write();
            $this->fail("MySQL aceitou {$what}");
        } catch (Throwable) {
            // É esta a garantia.
        }
    }

    private function scratch(): Connection
    {
        return DB::connection('founder_scratch');
    }

    private function scratchB(): Connection
    {
        return DB::connection('founder_scratch_b');
    }
}
