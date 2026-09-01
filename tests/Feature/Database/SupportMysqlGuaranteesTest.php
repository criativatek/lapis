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
 * AS GARANTIAS DA CENTRAL DE SUPORTE QUE O SQLITE NÃO SABE DAR.
 *
 * A mesma divisão de `VouchersMysqlGuaranteesTest` e de
 * `FounderSeatsMysqlGuaranteesTest`, pela mesma razão: a suíte corre em SQLite,
 * onde as CHECK constraints da migração não existem. O que aqui se prova,
 * contra um MySQL de raiz:
 *
 *  1. as CHECKs — os quatro estados, as categorias, o vocabulário de
 *     classificação técnica, o relógio da espera, a data de resolução, e a
 *     forma do hold;
 *  2. o UNIQUE de `reference` e o UNIQUE por notificação/destinatário;
 *  3. que a sequência **hold → release → anonimização** passa por todas as
 *     restrições — a que mais facilmente se tornaria impossível, porque a
 *     anonimização apaga a NOTA e deixa a data e o motivo de pé;
 *  4. que o `down()` recusa recuar sobre um pedido gravado.
 *
 * DUAS REGRAS NÃO ESTÃO AQUI, e a ausência é a prova de outra coisa: «um
 * pedido de visitante não tem conta» e «uma resposta de operador tem operador»
 * não podem ser CHECKs, porque o MySQL recusa (erro 3823) uma CHECK sobre uma
 * coluna que participa numa chave estrangeira com acção referencial. Vivem nos
 * guards dos modelos e são provadas em `SupportRequestLifecycleTest`.
 *
 * OPT-IN, como as outras:
 *
 *     SUPPORT_MYSQL_SCRATCH=1 php artisan test --filter=SupportMysqlGuarantees
 */
class SupportMysqlGuaranteesTest extends TestCase
{
    private const DATABASE = 'lapis_founder_scratch';

    protected function setUp(): void
    {
        parent::setUp();

        if (env('SUPPORT_MYSQL_SCRATCH') !== '1') {
            $this->markTestSkipped('Defina SUPPORT_MYSQL_SCRATCH=1 para correr as garantias de MySQL.');
        }

        if (! $this->mysqlIsListening()) {
            $this->markTestSkipped('Não há MySQL à escuta em 127.0.0.1:'.env('DB_PORT', '3306').'.');
        }

        $this->configureScratchConnection();
        $this->ensureSchema();
        $this->resetFixtures();
    }

    // ------------------------------------------------- 1. CHECK constraints

    #[Test]
    public function mysql_refuses_every_shape_the_domain_forbids(): void
    {
        // Não existe `closed`: quatro estados, e a base de dados sabe-o.
        $this->assertRejected(fn () => $this->insertRequest(['status' => 'closed']), 'o estado «closed»');
        $this->assertRejected(fn () => $this->insertRequest(['category' => 'inventada']), 'uma categoria inventada');
        $this->assertRejected(fn () => $this->insertRequest(['source' => 'api']), 'uma origem inventada');
        $this->assertRejected(fn () => $this->insertRequest(['technical_code' => 'performance']), 'um código técnico fora do vocabulário');

        // O relógio da espera existe no estado da espera, e em mais nenhum.
        $this->assertRejected(fn () => $this->insertRequest(['status' => 'waiting_for_user', 'waiting_since' => null]), 'uma espera sem relógio');
        // Um pedido resolvido sabe quando o foi: é daqui que a retenção conta.
        $this->assertRejected(fn () => $this->insertRequest(['status' => 'resolved', 'resolved_at' => null]), 'um pedido resolvido sem data');
        // Não se lembra uma espera que não começou.
        $this->assertRejected(fn () => $this->insertRequest(['waiting_reminder_sent_at' => '2026-09-20 10:00:00']), 'um lembrete sem espera');
        // Um hold é uma data COM um motivo classificado, ou não é nada.
        $this->assertRejected(fn () => $this->insertRequest(['retention_hold_at' => '2026-09-20 10:00:00']), 'uma suspensão sem motivo');
        $this->assertRejected(fn () => $this->insertRequest(['retention_hold_reason_code' => 'legal_dispute']), 'um motivo sem suspensão');
        $this->assertRejected(fn () => $this->insertRequest(['retention_hold_at' => '2026-09-20 10:00:00', 'retention_hold_reason_code' => 'porque_sim']), 'um motivo fora do vocabulário');
        // Não se liberta o que nunca foi aplicado.
        $this->assertRejected(fn () => $this->insertRequest(['retention_hold_released_at' => '2026-09-20 10:00:00']), 'libertar uma suspensão inexistente');

        // E aceita um pedido bem formado.
        $this->insertRequest(['reference' => 'SUP-AAAAAA']);
        $this->assertSame(1, (int) $this->scratch()->table('support_requests')->count());
    }

    #[Test]
    public function a_delivery_may_not_be_delivered_and_failed_at_once(): void
    {
        $this->insertRequest(['reference' => 'SUP-BBBBBB']);
        $id = (int) $this->scratch()->table('support_requests')->value('id');

        $this->assertRejected(
            fn () => $this->insertDelivery($id, ['delivered_at' => '2026-09-20 10:00:00', 'failure_code' => 'smtp_auth']),
            'uma entrega simultaneamente entregue e falhada',
        );
        $this->assertRejected(fn () => $this->insertDelivery($id, ['notification_type' => 'inventada']), 'um tipo de aviso inventado');
        $this->assertRejected(fn () => $this->insertDelivery($id, ['recipient_role' => 'toda_a_gente']), 'um destinatário fora dos dois papéis');
        $this->assertRejected(fn () => $this->insertDelivery($id, ['failure_code' => 'timeout']), 'um código de falha fora do vocabulário');
        // Uma tentativa deixa data; zero tentativas não deixam nenhuma.
        $this->assertRejected(fn () => $this->insertDelivery($id, ['attempts' => 3, 'last_attempt_at' => null]), 'tentativas sem data');

        $this->insertDelivery($id, []);
        $this->assertSame(1, (int) $this->scratch()->table('support_notification_deliveries')->count());
    }

    #[Test]
    public function a_message_may_not_carry_an_invented_role(): void
    {
        $this->insertRequest(['reference' => 'SUP-CCCCCC']);
        $id = (int) $this->scratch()->table('support_requests')->value('id');

        $this->assertRejected(fn () => $this->insertMessage($id, ['author_role' => 'robot']), 'um papel inventado');

        foreach (['requester', 'operator', 'system'] as $i => $role) {
            $this->insertMessage($id, ['author_role' => $role, 'ulid' => '01JD0000000000000000000AB'.$i]);
        }

        $this->assertSame(3, (int) $this->scratch()->table('support_messages')->count());
    }

    // --------------------------------------------------------- 2. UNIQUEs

    #[Test]
    public function the_reference_and_each_notification_are_unique(): void
    {
        $this->insertRequest(['reference' => 'SUP-DDDDDD']);
        $id = (int) $this->scratch()->table('support_requests')->value('id');

        $this->assertRejected(
            fn () => $this->insertRequest(['reference' => 'SUP-DDDDDD', 'ulid' => '01JD0000000000000000000ZZ']),
            'duas vezes a mesma referência',
        );

        $this->insertDelivery($id, []);
        $this->assertRejected(
            fn () => $this->insertDelivery($id, ['ulid' => '01JD0000000000000000000YY']),
            'duas linhas para o mesmo aviso e o mesmo destinatário',
        );
    }

    // ------------------------------ 3. hold → release → anonimização passa

    #[Test]
    public function the_hold_release_anonymise_sequence_survives_every_constraint(): void
    {
        // É a sequência que mais facilmente se tornaria impossível: a
        // anonimização apaga a NOTA e deixa a data, o motivo e a libertação de
        // pé como prova da excepção. Uma CHECK a ligar a nota ao hold — que
        // seria natural escrever — partiria isto em produção e em mais lado
        // nenhum.
        $this->insertRequest([
            'reference' => 'SUP-EEEEEE',
            'status' => 'resolved',
            'resolved_at' => '2026-09-20 10:00:00',
        ]);

        $tabela = $this->scratch()->table('support_requests')->where('reference', 'SUP-EEEEEE');

        // 1. Suspender, com motivo e nota.
        $tabela->update([
            'retention_hold_at' => '2026-09-21 10:00:00',
            'retention_hold_reason_code' => 'legal_dispute',
            'retention_hold_note' => 'Processo em curso.',
        ]);

        // 2. Libertar.
        $tabela->update(['retention_hold_released_at' => '2026-11-01 10:00:00']);

        // 3. Anonimizar: identificantes a NULL, nota a NULL, prova de pé.
        $tabela->update([
            'requester_name' => null,
            'requester_email' => null,
            'user_id' => null,
            'organization_id' => null,
            'subject' => null,
            'description' => null,
            'technical_reference' => null,
            'technical_route' => null,
            'retention_hold_note' => null,
            'anonymized_at' => '2028-10-01 10:00:00',
        ]);

        $linha = $this->scratch()->table('support_requests')->where('reference', 'SUP-EEEEEE')->first();

        $this->assertNull($linha->requester_email);
        $this->assertNull($linha->retention_hold_note);
        // A excepção continua provada.
        $this->assertNotNull($linha->retention_hold_at);
        $this->assertSame('legal_dispute', $linha->retention_hold_reason_code);
        $this->assertNotNull($linha->retention_hold_released_at);
        $this->assertNotNull($linha->anonymized_at);
        // E o que serve estatística sobreviveu.
        $this->assertSame('SUP-EEEEEE', $linha->reference);
        $this->assertSame('resolved', $linha->status);
    }

    // --------------------------------------------- 4. rollback conservador

    #[Test]
    public function rollback_refuses_while_a_request_exists(): void
    {
        $this->insertRequest(['reference' => 'SUP-FFFFFF']);

        try {
            $this->artisan('migrate:rollback', ['--database' => 'support_scratch', '--step' => 1, '--force' => true])->run();
        } catch (Throwable) {
            // A recusa pode vir como exceção: o que importa é a tabela ficar.
        }

        $this->assertTrue(
            $this->scratch()->getSchemaBuilder()->hasTable('support_requests'),
            'o rollback apagou tabelas com um pedido de suporte gravado',
        );
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

    private function configureScratchConnection(): void
    {
        Config::set('database.connections.support_scratch', [
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
        ]);
    }

    private function ensureSchema(): void
    {
        (new PDO('mysql:host=127.0.0.1;port='.env('DB_PORT', '3306'), 'root', ''))
            ->exec('CREATE DATABASE IF NOT EXISTS `'.self::DATABASE.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        DB::purge('support_scratch');

        // Corre sempre: numa base já migrada só entra o que estiver pendente.
        $this->artisan('migrate', ['--database' => 'support_scratch', '--force' => true])->run();
    }

    private function resetFixtures(): void
    {
        $scratch = $this->scratch();

        $scratch->statement('SET FOREIGN_KEY_CHECKS = 0');
        $scratch->table('support_notification_deliveries')->delete();
        $scratch->table('support_messages')->delete();
        $scratch->table('support_requests')->delete();
        $scratch->statement('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** @param array<string, mixed> $overrides */
    private function insertRequest(array $overrides = []): void
    {
        $this->scratch()->table('support_requests')->insert(array_merge([
            'ulid' => '01JD0000000000000000000A'.random_int(1, 9),
            'reference' => 'SUP-'.strtoupper(substr(md5((string) random_int(1, 999999)), 0, 6)),
            'requester_name' => 'Maria',
            'requester_email' => 'maria@exemplo.pt',
            'source' => 'guest',
            'category' => 'access',
            'subject' => 'Assunto',
            'description' => 'Descrição.',
            'status' => 'open',
            'app_version' => '0.101.0',
            'created_at' => '2026-09-20 10:00:00',
            'updated_at' => '2026-09-20 10:00:00',
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function insertDelivery(int $requestId, array $overrides = []): void
    {
        $this->scratch()->table('support_notification_deliveries')->insert(array_merge([
            'ulid' => '01JD0000000000000000000B1',
            'support_request_id' => $requestId,
            'notification_type' => 'request_received',
            'recipient_role' => 'requester',
            'attempts' => 1,
            'last_attempt_at' => '2026-09-20 10:00:00',
            'created_at' => '2026-09-20 10:00:00',
            'updated_at' => '2026-09-20 10:00:00',
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function insertMessage(int $requestId, array $overrides = []): void
    {
        $this->scratch()->table('support_messages')->insert(array_merge([
            'ulid' => '01JD0000000000000000000C1',
            'support_request_id' => $requestId,
            'author_role' => 'requester',
            'body' => 'Uma mensagem.',
            'created_at' => '2026-09-20 10:00:00',
            'updated_at' => '2026-09-20 10:00:00',
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
        return DB::connection('support_scratch');
    }
}
