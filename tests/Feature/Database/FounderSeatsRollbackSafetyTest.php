<?php

namespace Tests\Feature\Database;

use App\Models\FounderSeat;
use App\Models\Organization;
use App\Models\User;
use App\Support\Commercial\FounderSeats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * A MIGRAÇÃO DOS LUGARES SÓ RECUA ENQUANTO NINGUÉM FOR FUNDADOR.
 *
 * Um lugar ocupado é a prova de uma condição comercial acordada com uma pessoa
 * — o ordinal que lhe foi prometido e o preço que lhe foi dado — e nada a
 * reconstrói depois de a tabela desaparecer. É a mesma razão pela qual a
 * migração do snapshot comercial se recusa a recuar depois de haver preços
 * gravados, e o `PlanVersionRollbackSafetyTest` existe para a versão dela desta
 * garantia. Esta é a deste lote.
 *
 * E A SEGUNDA METADE DE CADA ASSERÇÃO É QUE NADA FOI DESTRUÍDO NO CAMINHO ATÉ
 * RECUSAR — um guarda que rebenta depois de já ter largado a tabela seria pior
 * do que guarda nenhum.
 */
class FounderSeatsRollbackSafetyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A migração deste lote. Contar a partir do nome — e não escrever
     * `--step => 1` — é o que impede que este ficheiro comece a recuar a
     * migração errada no dia em que outra aterrar por cima.
     */
    private const MIGRATION = '2026_09_12_000100_create_founder_seats_table';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.founder.price_cents' => 2990,
            'billing.founder.seats' => 250,
            'billing.founder.deadline' => '2026-12-31',
            'billing.bank_transfer.window_days' => 14,
        ]);

        $this->travelTo(Carbon::parse('2026-06-01 10:00'));
    }

    // ------------------------------------------- o estado que É reversível

    #[Test]
    public function rolling_back_before_anybody_is_a_founder_is_supported(): void
    {
        $this->assertTrue(Schema::hasTable('founder_seats'));
        $this->assertSame(0, FounderSeat::count());

        $this->rollback();

        $this->assertFalse(Schema::hasTable('founder_seats'), 'sem lugares atribuídos, recuar é uma mudança de esquema e mais nada');

        // E é reaplicável, que é o que faz disto um rollback e não uma perda.
        $this->artisan('migrate')->run();
        $this->assertTrue(Schema::hasTable('founder_seats'));
    }

    // ------------------------------------------ o estado que NÃO é reversível

    #[Test]
    public function rolling_back_with_a_claimed_seat_is_refused(): void
    {
        app(FounderSeats::class)->claim($this->organization());

        $this->assertSame(1, FounderSeat::count());

        $message = $this->refusedRollback();

        $this->assertStringContainsString('Refusing to roll back', $message);
        $this->assertStringContainsString('Membro Fundador seat', $message);

        $this->assertNothingWasDestroyed();
    }

    #[Test]
    public function a_confirmed_seat_is_refused_just_the_same(): void
    {
        // Reservado e confirmado são o mesmo tipo de prova para este efeito: os
        // dois descrevem alguém a quem foi prometido um ordinal.
        $organization = $this->organization();
        app(FounderSeats::class)->claim($organization);
        app(FounderSeats::class)->confirm($organization);

        $this->assertStringContainsString('Refusing to roll back', $this->refusedRollback());

        $this->assertNothingWasDestroyed();
    }

    #[Test]
    public function a_refused_rollback_leaves_a_database_that_still_works(): void
    {
        $organization = $this->organization();
        app(FounderSeats::class)->claim($organization);

        $this->refusedRollback();

        // Recusar não é meio-aplicar: a migração continua registada, a tabela
        // continua lá com o que tinha, e atribuir outro lugar continua a
        // funcionar como se nada tivesse acontecido.
        $this->assertSame(
            1,
            DB::table('migrations')->where('migration', self::MIGRATION)->count(),
            'a migração recusou e manteve-se aplicada',
        );

        $novo = app(FounderSeats::class)->claim($this->organization());

        $this->assertSame(2, $novo?->seat_number);
        $this->assertSame(2, FounderSeat::count());
    }

    // ------------------------------------------------------------- helpers

    private function rollback(): void
    {
        $this->artisan('migrate:rollback', ['--step' => $this->stepsBackToThisLot()])->run();
    }

    /**
     * Corre o rollback esperando que seja recusado, e devolve a mensagem.
     */
    private function refusedRollback(): string
    {
        try {
            $this->rollback();
        } catch (RuntimeException $exception) {
            return $exception->getMessage();
        } catch (Throwable $exception) {
            $this->fail('o rollback falhou por outra razão que não o guarda: '.$exception->getMessage());
        }

        $this->fail('o rollback foi aceite com lugares atribuídos');
    }

    private function assertNothingWasDestroyed(): void
    {
        $this->assertTrue(Schema::hasTable('founder_seats'), 'o guarda largou a tabela antes de recusar');
        $this->assertGreaterThan(0, FounderSeat::count(), 'o guarda apagou os lugares que existia para proteger');
    }

    private function stepsBackToThisLot(): int
    {
        return DB::table('migrations')->where('migration', '>=', self::MIGRATION)->count();
    }

    private function organization(): Organization
    {
        return User::factory()->create()->personalOrganization()->fresh();
    }
}
