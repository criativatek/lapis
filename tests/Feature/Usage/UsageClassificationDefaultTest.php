<?php

namespace Tests\Feature\Usage;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A regra por defeito («utilização real até prova em contrário») e o backfill
 * único da migration que a introduziu.
 */
class UsageClassificationDefaultTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_backfill_only_moves_organizations_nobody_ever_decided(): void
    {
        $a = $this->organizationWith('unclassified', null);
        $b = $this->organizationWith('unclassified', '2026-10-20 09:00:00');
        $c = $this->organizationWith('test', null);

        // Corre-se só o backfill (público na migration): o `up()` completo refaz a
        // coluna com `change()` em SQLite, o que não faz sentido repetir numa BD
        // que já está migrada. O DEFAULT prova-se no teste seguinte.
        $migration = require base_path('database/migrations/2026_11_28_000100_default_usage_classification_to_real.php');
        $migration->backfill();

        $this->assertSame('real', $a->fresh()->usage_classification);
        $this->assertNull($a->fresh()->usage_classified_at, 'o backfill não data uma decisão que ninguém tomou');
        $this->assertSame('unclassified', $b->fresh()->usage_classification);
        $this->assertSame('2026-10-20 09:00:00', $b->fresh()->usage_classified_at?->toDateTimeString());
        $this->assertSame('test', $c->fresh()->usage_classification);
    }

    #[Test]
    public function the_column_default_is_real_for_rows_inserted_without_a_value(): void
    {
        // Em SQLite a migration não mexe na coluna (`change()` rebenta com as
        // chaves estrangeiras); o valor de origem vem do modelo. O DEFAULT da
        // coluna prova-se onde ele existe: MySQL (CI e produção).
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('O DEFAULT da coluna só se altera em MySQL/MariaDB.');
        }

        $owner = User::factory()->create();

        $id = DB::table('organizations')->insertGetId([
            'ulid' => '01JZZZZZZZZZZZZZZZZZZZZZZZ',
            'name' => 'Sem valor',
            'type' => 'personal',
            'owner_id' => $owner->id,
            'timezone' => 'Europe/Lisbon',
            'locale' => 'pt-PT',
            'created_at' => '2026-11-03 10:00:00',
            'updated_at' => '2026-11-03 10:00:00',
        ]);

        $this->assertSame('real', DB::table('organizations')->where('id', $id)->value('usage_classification'));
    }

    private function organizationWith(string $classification, ?string $classifiedAt): Organization
    {
        $organization = User::factory()->create()->personalOrganization();
        DB::table('organizations')->where('id', $organization->id)->update([
            'usage_classification' => $classification,
            'usage_classified_at' => $classifiedAt,
        ]);

        return $organization;
    }
}
