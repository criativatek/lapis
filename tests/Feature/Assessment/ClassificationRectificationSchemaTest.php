<?php

namespace Tests\Feature\Assessment;

use App\Models\Classification;
use App\Models\ClassificationStatus;
use App\Models\RectificationReason;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Assessment\ProposeClassifications;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O esquema da retificação, tal como o SQLite o vê.
 *
 * As CHECK constraints não estão aqui: o SQLite em memória não as aplica (a
 * migração só as cria em MySQL/MariaDB). Essas provam-se em
 * `ClassificationRectificationMysqlGuaranteesTest`, sobre a tabela real.
 */
class ClassificationRectificationSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function seedDemo(): User
    {
        $teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        return $teacher;
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function asTenant(User $teacher, callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), $callback);
    }

    /** A proposal row, created the way the product creates it. */
    private function aClassification(): Classification
    {
        $class = SchoolClass::where('label', '7.º A')->firstOrFail();
        $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();
        app(ProposeClassifications::class)->forPeriod($class, $period);

        return Classification::query()->firstOrFail();
    }

    #[Test]
    public function the_table_has_the_five_rectification_columns(): void
    {
        foreach (['rectifies_id', 'rectification_reason', 'rectification_note', 'rectified_by', 'rectified_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('classifications', $column), "falta a coluna {$column}");
        }
    }

    #[Test]
    public function the_author_of_a_rectification_may_be_unknown_but_the_rest_of_the_history_is_kept(): void
    {
        $teacher = $this->seedDemo();

        $this->asTenant($teacher, function () {
            $old = $this->aClassification();
            $new = $old->replicate(['ulid', 'status_active_flag']);
            $new->forceFill([
                'status' => ClassificationStatus::Published,
                'enrollment_id' => $old->enrollment_id,
                'rectification_reason' => RectificationReason::EntryError,
                'rectification_note' => 'Histórico de um restauro.',
                'rectified_by' => null,
                'rectified_at' => '2026-11-25 10:00:00',
            ]);
            // Sequência do serviço, que as CHECK do MySQL impõem: ponteiro
            // provisório para si própria, linha nova (com decisão), ponteiro real.
            $old->forceFill(['status' => ClassificationStatus::Superseded, 'superseded_by_id' => $old->id])->saveQuietly();
            $new->forceFill(['rectifies_id' => $old->id, 'final_value' => '3'])->save();
            $old->forceFill(['superseded_by_id' => $new->id])->saveQuietly();

            $fresh = $new->fresh();
            $this->assertNull($fresh->rectified_by);
            $this->assertSame($old->id, $fresh->rectifies_id);
            $this->assertSame('Histórico de um restauro.', $fresh->rectification_note);
        });
    }

    #[Test]
    public function the_reasons_have_labels_and_only_other_requires_a_note(): void
    {
        $labels = [
            'entry_error' => 'Erro de lançamento',
            'calculation_error' => 'Erro de cálculo ou de dados',
            'review_or_complaint' => 'Revisão ou reclamação',
            'council_decision' => 'Decisão do conselho',
            'other' => 'Outro',
        ];

        $this->assertCount(count($labels), RectificationReason::cases());

        foreach ($labels as $value => $label) {
            $reason = RectificationReason::from($value);

            $this->assertSame($label, $reason->label());
            $this->assertSame($value === 'other', $reason->requiresNote());
        }
    }

    #[Test]
    public function the_model_casts_and_links_a_chain_of_rectifications(): void
    {
        $teacher = $this->seedDemo();

        $this->asTenant($teacher, function () use ($teacher) {
            $old = $this->aClassification();
            $when = Carbon::parse('2026-11-25 10:30:00');

            $new = $old->replicate(['ulid', 'status_active_flag']);
            $new->forceFill([
                'status' => ClassificationStatus::Published,
                'enrollment_id' => $old->enrollment_id,
                'rectification_reason' => RectificationReason::EntryError,
                'rectification_note' => 'Nota dada ao conselho de turma.',
                'rectified_by' => $teacher->id,
                'rectified_at' => $when,
            ]);

            // A antiga sai do índice «uma só viva» antes de a nova entrar.
            // Sequência do serviço, que as CHECK do MySQL impõem: ponteiro
            // provisório para si própria, linha nova (com decisão), ponteiro real.
            $old->forceFill(['status' => ClassificationStatus::Superseded, 'superseded_by_id' => $old->id])->saveQuietly();
            $new->forceFill(['rectifies_id' => $old->id, 'final_value' => '3'])->save();
            $old->forceFill(['superseded_by_id' => $new->id])->saveQuietly();

            $new = $new->fresh();
            $old = $old->fresh();

            $this->assertSame(RectificationReason::EntryError, $new->rectification_reason);
            $this->assertTrue($new->rectified_at->equalTo($when));
            $this->assertSame('Nota dada ao conselho de turma.', $new->rectification_note);

            $this->assertTrue($new->rectifies->is($old));
            $this->assertTrue($old->supersededBy->is($new));
            $this->assertNull($new->supersededBy);
            $this->assertNull($old->rectifies);

            $this->assertTrue($new->isLive());
            $this->assertFalse($old->isLive());
        });
    }

    #[Test]
    public function a_classification_that_is_not_superseded_is_live(): void
    {
        $teacher = $this->seedDemo();

        $this->asTenant($teacher, function () {
            $classification = $this->aClassification();

            foreach ([ClassificationStatus::Proposed, ClassificationStatus::Confirmed, ClassificationStatus::Published] as $status) {
                $classification->status = $status;
                $this->assertTrue($classification->isLive(), $status->value);
            }

            $classification->status = ClassificationStatus::Superseded;
            $this->assertFalse($classification->isLive());
        });
    }
}
