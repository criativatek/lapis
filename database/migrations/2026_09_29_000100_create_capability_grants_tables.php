<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capability_grant_presets', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->text('notes')->nullable();
            $table->unsignedInteger('default_duration_days')->nullable();
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('capability_grant_preset_module', function (Blueprint $table): void {
            // Explicit short names below: the default {table}_{column}_foreign /
            // _unique convention overflows MySQL's 64-character identifier limit
            // for this table's long name — caught by the real-MySQL scratch gate,
            // invisible on SQLite where the suite otherwise runs.
            $table->foreignId('capability_grant_preset_id')->constrained(indexName: 'cap_grant_preset_module_preset_fk')->cascadeOnDelete();
            $table->foreignId('module_id')->constrained()->restrictOnDelete();
            $table->unique(['capability_grant_preset_id', 'module_id'], 'cap_grant_preset_module_unique');
        });

        Schema::create('capability_vouchers', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('code', 64);
            $table->string('normalized_code', 64)->unique();
            $table->string('label');
            $table->foreignId('preset_id')->nullable()->constrained('capability_grant_presets')->nullOnDelete();
            $table->unsignedInteger('duration_days');
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->foreignId('restricted_organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->timestamp('disabled_at')->nullable();
            $table->foreignId('disabled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('capability_voucher_module', function (Blueprint $table): void {
            $table->foreignId('capability_voucher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('module_id')->constrained()->restrictOnDelete();
            $table->unique(['capability_voucher_id', 'module_id']);
        });

        Schema::create('capability_grants', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('source', 20);
            $table->foreignId('capability_voucher_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'starts_at', 'expires_at']);
        });

        Schema::create('capability_grant_module', function (Blueprint $table): void {
            $table->foreignId('capability_grant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('module_id')->constrained()->restrictOnDelete();
            $table->unique(['capability_grant_id', 'module_id']);
            $table->index('module_id');
        });

        Schema::create('capability_voucher_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('capability_voucher_id')->constrained()->restrictOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('capability_grant_id')->constrained()->restrictOnDelete();
            $table->foreignId('redeemed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('redeemed_at');
            // Explicit short name: the default index name for this pair overflows
            // MySQL's 64-character identifier limit on this table's long name.
            $table->unique(['capability_voucher_id', 'organization_id'], 'cap_voucher_redemptions_org_unique');
            $table->timestamps();
        });

        $this->addChecks('capability_vouchers', [
            'capability_vouchers_duration_check' => 'duration_days >= 1',
            'capability_vouchers_capacity_check' => 'max_redemptions IS NULL OR max_redemptions >= 1',
            'capability_vouchers_window_check' => 'valid_from IS NULL OR valid_until IS NULL OR valid_until >= valid_from',
        ]);
        $this->addChecks('capability_grants', [
            'capability_grants_source_check' => "source IN ('voucher','direct')",
            'capability_grants_dates_check' => 'expires_at > starts_at',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('capability_voucher_redemptions');
        Schema::dropIfExists('capability_grant_module');
        Schema::dropIfExists('capability_grants');
        Schema::dropIfExists('capability_voucher_module');
        Schema::dropIfExists('capability_vouchers');
        Schema::dropIfExists('capability_grant_preset_module');
        Schema::dropIfExists('capability_grant_presets');
    }

    /** @param array<string, string> $checks */
    private function addChecks(string $table, array $checks): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        foreach ($checks as $name => $expression) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }
};
