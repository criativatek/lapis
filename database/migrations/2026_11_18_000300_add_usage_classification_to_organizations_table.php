<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A classificação de uma organização PARA AS MÉTRICAS DE UTILIZAÇÃO.
 *
 * Não é `is_test_account`. Essa marca é comercial («ninguém prometeu nada a esta
 * conta») e só o pré-voo a lê; em produção as organizações estão todas marcadas,
 * incluindo professores parceiros que usam o produto a sério. «Teste» comercial
 * não quer dizer dados fictícios, e reutilizar o campo para métricas seria usá-lo
 * para uma finalidade que ele não tem.
 *
 * SEM BACKFILL, SEM INFERÊNCIA. Todas as organizações, as que existem e as que
 * vierem, nascem `unclassified`. Nada é derivado de `is_test_account`, do nome,
 * do email, do plano ou da atividade: só uma pessoa decide, por
 * `SetUsageClassification`.
 *
 * Aditiva e reversível.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('usage_classification', 16)->default('unclassified')->after('is_test_account');
            $table->timestamp('usage_classified_at')->nullable()->after('usage_classification');
            $table->index('usage_classification');
        });

        if ($this->supportsChecks()) {
            DB::statement("ALTER TABLE organizations ADD CONSTRAINT organizations_usage_classification_check CHECK (usage_classification in ('unclassified','real','test','internal'))");
        }
    }

    public function down(): void
    {
        if ($this->supportsChecks()) {
            DB::statement('ALTER TABLE organizations DROP CONSTRAINT organizations_usage_classification_check');
        }

        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropIndex(['usage_classification']);
            $table->dropColumn(['usage_classification', 'usage_classified_at']);
        });
    }

    /** SQLite não acrescenta CHECK a uma tabela existente; o CI (MySQL) é que o prova. */
    private function supportsChecks(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
