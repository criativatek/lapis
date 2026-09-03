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
 * AS GARANTIAS DO MOTOR DE VOUCHERS QUE O SQLITE NÃO SABE DAR.
 *
 * A mesma divisão do `FounderSeatsMysqlGuaranteesTest`, pela mesma razão: a
 * suíte corre em SQLite, onde as CHECK constraints da migração não existem e o
 * `lockForUpdate()` é um no-op. O que aqui se prova, contra um MySQL de raiz:
 *
 *  1. as CHECKs — a lista fechada de `benefit_type`, a forma de cada família,
 *     o intervalo da percentagem, o teto mínimo de utilizações, a janela, e o
 *     anti-limbo do resgate (ou reserva com prazo, ou confirmação);
 *  2. os UNIQUEs — o código normalizado, e o par (voucher, organização);
 *  3. o lock de linha na tabela `vouchers` serializa mesmo duas ligações — a
 *     peça que fecha a corrida do último lugar;
 *  4. o `down()` recusa destruir estado comercial: com um voucher emitido OU um
 *     resgate gravado, não recua; vazio, recua e volta a migrar.
 *
 * OPT-IN, como o dos lugares:
 *
 *     VOUCHER_MYSQL_SCRATCH=1 php artisan test --filter=VouchersMysqlGuarantees
 *
 * Reutiliza a base `lapis_founder_scratch`, que já está migrada de corridas
 * anteriores — as tabelas de vouchers entram pela migração pendente.
 */
class VouchersMysqlGuaranteesTest extends TestCase
{
    private const DATABASE = 'lapis_founder_scratch';

    protected function setUp(): void
    {
        parent::setUp();

        if (env('VOUCHER_MYSQL_SCRATCH') !== '1') {
            $this->markTestSkipped('Defina VOUCHER_MYSQL_SCRATCH=1 para correr as garantias de MySQL.');
        }

        if (! $this->mysqlIsListening()) {
            $this->markTestSkipped('Não há MySQL à escuta em 127.0.0.1:'.env('DB_PORT', '3306').'.');
        }

        $this->configureScratchConnections();
        $this->ensureSchema();
        $this->resetFixtures();
    }

    // ------------------------------------------------- 1. CHECK constraints

    #[Test]
    public function mysql_refuses_every_shape_the_three_families_forbid(): void
    {
        // A lista de famílias está FECHADA nos três tipos V1.
        $this->assertRejected(fn () => $this->insertVoucher(['benefit_type' => 'ai_credits', 'benefit_percent' => 10]), 'uma família desconhecida');

        // fixed_price sem moeda; percent fora de 1..100; free_until sem data;
        // campos de família alheia; teto zero; janela invertida.
        $this->assertRejected(fn () => $this->insertVoucher(['benefit_type' => 'fixed_price', 'benefit_amount_cents' => 1990]), 'um preço sem moeda');
        $this->assertRejected(fn () => $this->insertVoucher(['benefit_percent' => 0]), 'percentagem 0');
        $this->assertRejected(fn () => $this->insertVoucher(['benefit_percent' => 101]), 'percentagem 101');
        $this->assertRejected(fn () => $this->insertVoucher(['benefit_type' => 'free_until']), 'free_until sem data');
        $this->assertRejected(fn () => $this->insertVoucher(['benefit_percent' => 30, 'benefit_free_until' => '2027-08-31 23:59:59']), 'percent com campo de free_until');
        $this->assertRejected(fn () => $this->insertVoucher(['max_redemptions' => 0]), 'teto zero em vez de NULL');
        $this->assertRejected(fn () => $this->insertVoucher(['valid_from' => '2026-09-01 00:00:00', 'valid_until' => '2026-08-01 00:00:00']), 'janela invertida');

        // E aceita as três famílias bem formadas.
        $this->insertVoucher(['normalized_code' => 'PC30', 'code' => 'PC-30']);
        $this->insertVoucher(['normalized_code' => 'FIXO', 'code' => 'FIXO', 'benefit_type' => 'fixed_price', 'benefit_percent' => null, 'benefit_amount_cents' => 1990, 'benefit_currency' => 'EUR']);
        $this->insertVoucher(['normalized_code' => 'GRAT', 'code' => 'GRAT', 'benefit_type' => 'free_until', 'benefit_percent' => null, 'benefit_free_until' => '2027-08-31 23:59:59']);

        $this->assertSame(3, (int) $this->scratch()->table('vouchers')->count());
    }

    #[Test]
    public function mysql_refuses_a_redemption_in_limbo_and_a_duplicate_pair(): void
    {
        $this->insertVoucher(['normalized_code' => 'PC30', 'code' => 'PC-30']);
        $voucherId = (int) $this->scratch()->table('vouchers')->value('id');

        // Nem reserva com prazo, nem confirmação: o limbo é recusado.
        $this->assertRejected(
            fn () => $this->insertRedemption($voucherId, 1, ['reserved_until' => null, 'confirmed_at' => null]),
            'um resgate sem reserva nem confirmação',
        );

        $this->insertRedemption($voucherId, 1, []);

        // O par (voucher, organização) é único — a chave da idempotência.
        $this->assertRejected(
            fn () => $this->insertRedemption($voucherId, 1, ['ulid' => '01JD00000000000000000000X2']),
            'a mesma organização duas vezes no mesmo código',
        );

        $this->assertSame(1, (int) $this->scratch()->table('voucher_redemptions')->count());
    }

    #[Test]
    public function the_normalized_code_is_unique_no_matter_how_the_display_form_differs(): void
    {
        $this->insertVoucher(['normalized_code' => 'ANPRI2026', 'code' => 'ANPRI-2026']);

        $this->assertRejected(
            fn () => $this->insertVoucher(['normalized_code' => 'ANPRI2026', 'code' => 'anpri 2026']),
            'o mesmo código normalizado com outra apresentação',
        );

        // A recusa tem de ter deixado a tabela como estava. `assertRejected`
        // só sabe que houve uma exceção — e uma exceção qualquer (uma coluna
        // mal escrita, a ligação em baixo) também é uma exceção. É a contagem
        // que prova que foi o índice único a recusar, e não outra coisa.
        $this->assertSame(1, (int) $this->scratch()->table('vouchers')->count());
    }

    // ------------------------------------------------ 2. o lock é mesmo lock

    #[Test]
    public function the_voucher_row_lock_really_serialises_two_connections(): void
    {
        $this->insertVoucher(['normalized_code' => 'PC30', 'code' => 'PC-30', 'max_redemptions' => 1]);

        $a = $this->scratch();
        $b = $this->scratchB();

        $b->statement('SET SESSION innodb_lock_wait_timeout = 1');

        $a->beginTransaction();

        try {
            $a->table('vouchers')->where('normalized_code', 'PC30')->lockForUpdate()->first();

            $b->beginTransaction();

            try {
                $b->table('vouchers')->where('normalized_code', 'PC30')->lockForUpdate()->first();
                $b->rollBack();
                $this->fail('a segunda ligação passou pelo lock: a decisão de capacidade não está serializada');
            } catch (Throwable $exception) {
                $b->rollBack();
                $this->assertStringContainsString('Lock wait timeout', $exception->getMessage());
            }
        } finally {
            $a->rollBack();
        }

        // Largada a primeira, a segunda passa: fila, não impasse.
        $b->beginTransaction();
        $this->assertNotNull($b->table('vouchers')->where('normalized_code', 'PC30')->lockForUpdate()->first());
        $b->rollBack();
    }

    #[Test]
    public function the_capability_voucher_last_slot_is_serialised_by_its_row_lock(): void
    {
        $this->scratch()->table('capability_vouchers')->insert([
            'ulid' => '01JD00000000000000000000C1', 'code' => 'CAP-ONE', 'normalized_code' => 'CAPONE', 'label' => 'Capacidade',
            'duration_days' => 7, 'max_redemptions' => 1, 'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $a = $this->scratch();
        $b = $this->scratchB();
        $b->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $a->beginTransaction();
        try {
            $a->table('capability_vouchers')->where('normalized_code', 'CAPONE')->lockForUpdate()->first();
            $b->beginTransaction();
            try {
                $b->table('capability_vouchers')->where('normalized_code', 'CAPONE')->lockForUpdate()->first();
                $b->rollBack();
                $this->fail('A segunda decisão passou pelo lock do último resgate.');
            } catch (Throwable $exception) {
                $b->rollBack();
                $this->assertStringContainsString('Lock wait timeout', $exception->getMessage());
            }
        } finally {
            $a->rollBack();
        }
    }

    // ------------------------------------------- 3. rollback conservador

    #[Test]
    public function rollback_refuses_while_commercial_state_exists_and_proceeds_when_empty(): void
    {
        // Com um voucher EMITIDO (sem resgate nenhum): recusa.
        $this->insertVoucher(['normalized_code' => 'PC30', 'code' => 'PC-30']);

        try {
            $this->rollbackVouchers();
            $this->assertTrue(
                $this->scratch()->getSchemaBuilder()->hasTable('vouchers'),
                'o rollback apagou tabelas com um voucher emitido',
            );
        } catch (Throwable) {
            // A recusa pode vir como exceção: também serve, desde que a tabela fique.
            $this->assertTrue($this->scratch()->getSchemaBuilder()->hasTable('vouchers'));
        }

        // Vazio: recua sem drama — e volta a migrar para as corridas seguintes.
        $this->scratch()->table('vouchers')->delete();
        $this->rollbackVouchers();
        $this->assertFalse($this->scratch()->getSchemaBuilder()->hasTable('vouchers'));

        $this->artisan('migrate', ['--database' => 'founder_scratch', '--force' => true])->run();
        $this->assertTrue($this->scratch()->getSchemaBuilder()->hasTable('vouchers'));
        $this->assertTrue($this->scratch()->getSchemaBuilder()->hasTable('voucher_redemptions'));
    }

    /**
     * Recuar ATÉ AOS VOUCHERS, e não «um passo».
     *
     * `--step 1` funcionou enquanto os vouchers foram a migração mais recente.
     * A Central de Suporte passou a estar depois deles, e um passo único
     * recuava a tabela errada — este teste falhava por uma razão que nada tem
     * que ver com vouchers. `--path` também não serve: o `migrate:rollback` só
     * considera o ÚLTIMO LOTE, e os dois estão em lotes diferentes.
     *
     * Recua-se então lote a lote até a tabela desaparecer, com um limite para
     * não girar em vazio. Continua a valer quando houver uma décima migração a
     * seguir, que é a propriedade que faltava — e pára sozinho quando uma
     * migração pelo caminho se recusar a recuar, que é o que este teste
     * verifica na primeira metade.
     */
    private function rollbackVouchers(): void
    {
        // Headroom, not a tight count: this test calls rollbackVouchers() TWICE
        // in the same run (refusal, then success), so the cap has to cover BOTH
        // passes' worth of migrations sitting after vouchers — not just how many
        // exist today. A tight number here is exactly what silently broke the
        // first time a single migration (temporary capability grants) landed
        // after vouchers with zero margin to spare.
        for ($passo = 0; $passo < 40; $passo++) {
            if (! $this->scratch()->getSchemaBuilder()->hasTable('vouchers')) {
                return;
            }

            $this->artisan('migrate:rollback', [
                '--database' => 'founder_scratch',
                '--step' => 1,
                '--force' => true,
            ])->run();
        }
    }

    // ------------------------------------------------------------ fixtures

    private function mysqlIsListening(): bool
    {
        try {
            new PDO('mysql:host=127.0.0.1;port='.env('DB_PORT', '3306'), 'root', '');

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
            'port' => env('DB_PORT', '3306'),
            'database' => self::DATABASE,
            'username' => 'root',
            'password' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => null,
        ];

        Config::set('database.connections.founder_scratch', $base);
        Config::set('database.connections.founder_scratch_b', $base);
    }

    private function ensureSchema(): void
    {
        (new PDO('mysql:host=127.0.0.1;port='.env('DB_PORT', '3306'), 'root', ''))
            ->exec('CREATE DATABASE IF NOT EXISTS `'.self::DATABASE.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        DB::purge('founder_scratch');

        // Corre SEMPRE: numa base já migrada só entra o que estiver pendente —
        // as tabelas de vouchers, na primeira corrida depois desta fatia.
        $this->artisan('migrate', ['--database' => 'founder_scratch', '--force' => true])->run();
    }

    private function resetFixtures(): void
    {
        $scratch = $this->scratch();

        $scratch->statement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['capability_voucher_redemptions', 'capability_grant_module', 'capability_grants', 'capability_voucher_module', 'capability_vouchers', 'capability_grant_preset_module', 'capability_grant_presets'] as $table) {
            if ($scratch->getSchemaBuilder()->hasTable($table)) {
                $scratch->table($table)->delete();
            }
        }
        // Partilhamos a base com o scratch da Central de Suporte. O que é dela
        // não é deste teste, e um pedido deixado para trás bloquearia o
        // rollback abaixo por uma razão alheia aos vouchers.
        foreach (['support_notification_deliveries', 'support_messages', 'support_requests'] as $tabela) {
            if ($scratch->getSchemaBuilder()->hasTable($tabela)) {
                $scratch->table($tabela)->delete();
            }
        }
        $scratch->table('voucher_redemptions')->delete();
        $scratch->table('vouchers')->delete();
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

    /** @param array<string, mixed> $overrides */
    private function insertVoucher(array $overrides = []): void
    {
        $this->scratch()->table('vouchers')->insert(array_merge([
            'code' => 'PC-30',
            'normalized_code' => $overrides['normalized_code'] ?? 'PC30'.random_int(1000, 9999),
            'label' => 'Teste',
            'benefit_type' => 'percent_discount',
            'benefit_percent' => 30,
            'created_at' => '2026-06-01 10:00:00',
            'updated_at' => '2026-06-01 10:00:00',
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function insertRedemption(int $voucherId, int $organizationId, array $overrides = []): void
    {
        $this->scratch()->table('voucher_redemptions')->insert(array_merge([
            'ulid' => '01JD00000000000000000000X1',
            'voucher_id' => $voucherId,
            'organization_id' => $organizationId,
            'reserved_until' => '2026-06-11 10:00:00',
            'confirmed_at' => null,
            'redeemed_at' => '2026-06-01 10:00:00',
            'benefit_type' => 'percent_discount',
            'benefit_percent' => 30,
            'result_price_cents' => 3143,
            'result_currency' => 'EUR',
            'created_at' => '2026-06-01 10:00:00',
            'updated_at' => '2026-06-01 10:00:00',
        ], $overrides));
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
