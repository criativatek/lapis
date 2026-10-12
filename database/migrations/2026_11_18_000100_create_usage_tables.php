<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Métricas de utilização (docs/usage-insights.md).
 *
 * `usage_events` não tem coluna de texto livre nem JSON, de propósito: só
 * guarda quem, onde, quando, em que funcionalidade e que tipo de evento, tudo
 * de listas fechadas. Um teste afirma a lista exata de colunas.
 *
 * As tabelas não são de nenhuma organização (são de plataforma e só o
 * backoffice as lê), por isso `organization_id` aqui é um dado, não um âmbito.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->dateTime('occurred_at');
            $table->date('occurred_on');
            $table->string('feature', 24);
            $table->string('event', 48);
            $table->string('kind', 8); // view | action | failure
            $table->unsignedInteger('item_count')->nullable(); // só kind=action, >= 1
            // Discriminação de um lote (só kind=action): item_count = criados + alterados,
            // e os inalterados são os que vieram no lote sem mudar nada.
            $table->unsignedInteger('items_created')->nullable();
            $table->unsignedInteger('items_changed')->nullable();
            $table->unsignedInteger('items_unchanged')->nullable();
            $table->string('failure_code', 24)->nullable(); // só kind=failure
            $table->char('dedupe_key', 64)->unique();
            $table->timestamp('created_at')->nullable();

            $table->index(['occurred_on', 'user_id'], 'usage_events_day_user_index');
            $table->index(['user_id', 'occurred_on'], 'usage_events_user_day_index');
            $table->index(['organization_id', 'occurred_on'], 'usage_events_org_day_index');
        });

        $this->addCheck('usage_events', 'usage_events_kind_check', "kind IN ('view','action','failure')");
        $this->addCheck('usage_events', 'usage_events_item_count_check', "(kind = 'action') = (item_count IS NOT NULL)");
        $this->addCheck('usage_events', 'usage_events_failure_code_check', "(kind = 'failure') = (failure_code IS NOT NULL)");
        $this->addCheck('usage_events', 'usage_events_item_count_positive_check', 'item_count IS NULL OR item_count > 0');

        $this->addCheck('usage_events', 'usage_events_batch_breakdown_kind_check', "(items_created IS NULL AND items_changed IS NULL AND items_unchanged IS NULL) OR kind = 'action'");
        $this->addCheck('usage_events', 'usage_events_batch_breakdown_sum_check', 'items_created IS NULL OR items_changed IS NULL OR item_count = items_created + items_changed');

        Schema::create('usage_daily_rollups', function (Blueprint $table) {
            $table->id();
            $table->date('day');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('feature', 24);
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('actions')->default(0);
            $table->unsignedInteger('useful_actions')->default(0);
            $table->unsignedInteger('items')->default(0);
            $table->unsignedInteger('items_created')->default(0);
            $table->unsignedInteger('items_changed')->default(0);
            $table->unsignedInteger('items_unchanged')->default(0);
            $table->unsignedInteger('failures')->default(0);

            $table->unique(['day', 'user_id', 'organization_id', 'feature'], 'usage_rollups_identity_unique');
            $table->index(['user_id', 'day'], 'usage_rollups_user_day_index');
            $table->index(['organization_id', 'day'], 'usage_rollups_org_day_index');
        });

        Schema::create('usage_user_states', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique(); // chave de rota do detalhe
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->dateTime('class_available_since')->nullable(); // MIN(class_teachers.created_at): configuração
            $table->date('first_class_created_on')->nullable(); // primeira OBSERVAÇÃO
            $table->date('first_students_added_on')->nullable();
            $table->date('first_useful_record_on')->nullable();
            $table->dateTime('computed_at');
            $table->timestamps();
        });

        Schema::create('usage_collection_periods', function (Blueprint $table) {
            $table->id();
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
        });

        Schema::create('usage_aggregation_state', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary(); // sempre 1
            $table->date('aggregated_through')->nullable();
            $table->dateTime('aggregated_at')->nullable();
        });

        // A migração NÃO abre um período de recolha: a recolha nasce desligada
        // e o período abre-se na ativação efetiva (UsageCoverage::ensureOpen()).
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_aggregation_state');
        Schema::dropIfExists('usage_collection_periods');
        Schema::dropIfExists('usage_user_states');
        Schema::dropIfExists('usage_daily_rollups');
        Schema::dropIfExists('usage_events');
    }

    protected function addCheck(string $table, string $name, string $expression): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }
};
