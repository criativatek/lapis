<?php

namespace Tests\Feature\Assessment;

use App\Models\InterimAssessment;
use App\Models\Report;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Assessment\CaptureInterimAssessment;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A reparação dos hashes escritos antes de o hashing ser canónico (0.117.0).
 *
 * O QUE ESTES CASOS FIXAM. A 0.117.0 corrigiu o ALGORITMO — mas uma linha
 * antiga guarda o hash do `json_encode` ingénuo, e no MySQL, que reordena as
 * chaves de uma coluna JSON, essa linha declara-se «adulterada» para sempre,
 * mesmo intacta. Um alarme de integridade falso ensina a ignorar o verdadeiro;
 * a migração de re-hash declara o conteúdo guardado como nova base — a única
 * opção honesta, porque o hash antigo não distinguia uma chave reordenada de
 * adulteração real: falhava para as duas.
 *
 * Cada caso FABRICA uma linha no estado antigo (hash sobre uma ordem de
 * chaves diferente da canónica), afirma que ela se declara adulterada — que é
 * o defeito a reparar, visto vermelho aqui dentro — e afirma que depois da
 * migração volta a estar intacta.
 */
class RehashNaiveSnapshotsTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        // O ano demo é 2026/2027; estar no fim dele torna reais as datas todas.
        $this->travelTo(Carbon::parse('2027-06-30 12:00:00'));
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function asTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->teacher->personalOrganization(), $callback);
    }

    /**
     * O hash como a versão antiga o teria escrito: sobre as chaves POR OUTRA
     * ORDEM. É exactamente o que o MySQL faz a uma coluna JSON — e o que fazia
     * cada linha antiga declarar-se adulterada sob o algoritmo canónico.
     *
     * @param  array<string, mixed>  $payload
     */
    private function naiveHashOverReorderedKeys(array $payload): string
    {
        krsort($payload);

        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function repair(): void
    {
        $migration = require database_path(
            'migrations/2026_09_30_000100_rehash_snapshots_stored_with_naive_hashes.php'
        );

        $migration->up();
    }

    #[Test]
    public function an_interim_assessment_stored_with_a_naive_hash_is_intact_again_after_the_repair(): void
    {
        $interim = $this->asTenant(function (): InterimAssessment {
            $schoolClass = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $schoolClass->academicYear->periods()->where('sequence', 1)->firstOrFail();

            return app(CaptureInterimAssessment::class)->capture(
                $schoolClass,
                $period,
                Carbon::parse('2026-11-15'),
                $this->teacher,
            );
        });

        // A linha passa ao estado antigo: hash sobre outra ordem de chaves.
        DB::table('interim_assessments')->where('id', $interim->id)->update([
            'snapshot_hash' => $this->naiveHashOverReorderedKeys($interim->snapshot),
        ]);

        // O defeito, visto: intacta de facto, «adulterada» de nome.
        $this->assertFalse($this->asTenant(fn (): bool => $interim->fresh()->isIntact()));

        $this->repair();

        $this->assertTrue($this->asTenant(fn (): bool => $interim->fresh()->isIntact()));
    }

    #[Test]
    public function a_finalized_report_stored_with_a_naive_hash_is_intact_again_after_the_repair(): void
    {
        // Um documento com chaves deliberadamente fora de ordem: é a diferença
        // entre a forma canónica e a ingénua — sem ela, os dois hashes
        // coincidem e o caso não prova nada.
        $document = [
            'version' => Report::CURRENT_DOCUMENT_VERSION,
            'sections' => [['title' => 'Leitura', 'body' => 'Progride.']],
            'identity' => ['school' => 'Escola da Demo'],
        ];

        $report = $this->asTenant(fn (): Report => Report::factory()
            ->recycle($this->teacher->personalOrganization())
            ->finalized($document)
            ->create());

        DB::table('reports')->where('id', $report->id)->update([
            'document_hash' => $this->naiveHashOverReorderedKeys($document),
        ]);

        $this->assertFalse($this->asTenant(fn (): bool => $report->fresh()->isIntact()));

        $this->repair();

        $this->assertTrue($this->asTenant(fn (): bool => $report->fresh()->isIntact()));
    }

    #[Test]
    public function a_row_that_already_verifies_keeps_verifying_and_keeps_its_hash(): void
    {
        // A reparação não pode mexer no que já está certo: para uma linha
        // canónica o re-hash é um no-op de valor — mesmo hash, mesma verdade.
        $interim = $this->asTenant(function (): InterimAssessment {
            $schoolClass = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $schoolClass->academicYear->periods()->where('sequence', 1)->firstOrFail();

            return app(CaptureInterimAssessment::class)->capture(
                $schoolClass,
                $period,
                Carbon::parse('2026-11-20'),
                $this->teacher,
            );
        });

        $hashBefore = $interim->snapshot_hash;

        $this->repair();

        $fresh = $interim->fresh();
        $this->assertSame($hashBefore, $fresh->snapshot_hash);
        $this->assertTrue($this->asTenant(fn (): bool => $fresh->isIntact()));
    }
}
