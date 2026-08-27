<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MONEY THAT ACTUALLY CHANGED HANDS. Nothing else.
 *
 * The rule this table exists to make enforceable: **plan is not payment**.
 * Revenue is never `count(plano = pro) x 44,90` — that figure would be wrong
 * for every account this database has ever held, because a Pro subscription can
 * exist through a launch condition, a voucher, a trial, an operator's grant or
 * an institutional arrangement, and none of those is 44,90 EUR. Revenue is the
 * sum of the rows here that are `paid`, and nothing else. No rows, no revenue.
 *
 * THERE IS NO GATEWAY, and this table does not pretend otherwise. It records
 * payments an operator has already received by other means — a bank transfer,
 * MB Way — so `provider`/`provider_reference` are nullable and exist purely so
 * that a future gateway has somewhere to land without a second migration. No
 * card data is stored here, ever, and no fiscal document is produced: an
 * invoice or receipt is a legal artefact with its own rules, deliberately out
 * of scope.
 *
 * THE AMOUNT IS IMMUTABLE. `amount_cents` is what was charged on the day it was
 * charged; if the Pro price moves tomorrow, every row here still says what it
 * said. `App\Models\SubscriptionPayment` refuses at the model level any update
 * that touches the money, the date, the organization or the period — see its
 * `booted()` guard — so "corrigir" can never quietly become "rewrite history".
 *
 * WHICH LEAVES EXACTLY ONE WAY TO UNDO SOMETHING: a status transition, with a
 * reason and a name attached. A payment that was refunded becomes `refunded`; a
 * payment recorded in error becomes `cancelled`. Both keep the original row, the
 * original figure and the original date, and both leave revenue — which only
 * ever counts `paid` — correct without a single number being edited. Partial
 * refunds are not supported and are not faked: the infrastructure to represent
 * one honestly does not exist yet.
 *
 * Integer cents, never a float and never a decimal that a sum could round:
 * `amount_cents` + `currency`. The project's "DECIMAL, never float" rule (§24.4)
 * is about grades — money has its own, stricter answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            // Tenant-scoped like `organization_subscriptions` itself: the
            // backoffice reads across organizations with
            // withoutGlobalScope('organization'), and nothing inside a tenant
            // should ever reach another tenant's money by accident.
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();

            // Nullable on purpose. A payment that arrives for an account whose
            // subscription history has since moved on still belongs to the
            // ACCOUNT, and losing the payment because the period it paid for was
            // superseded would be losing revenue that really was received.
            $table->foreignId('organization_subscription_id')->nullable()->constrained()->nullOnDelete();

            // The money. Never updated once written — see the model guard.
            $table->unsignedBigInteger('amount_cents');
            $table->char('currency', 3)->default('EUR');

            $table->string('status', 16);
            $table->string('method', 20)->nullable();

            // Where a future gateway will identify its own transaction. Both
            // null for everything an operator records by hand.
            $table->string('provider', 40)->nullable();
            $table->string('provider_reference', 191)->nullable();

            // A COPY, taken at the moment of payment, never a join. The
            // subscription's own `commercial_condition` may legitimately change
            // later (an account moves off the Fundador condition at renewal);
            // what this payment was made under must not change with it.
            $table->string('commercial_condition', 20)->nullable();

            // A code, as a literal string. There is no voucher table, no
            // campaign, no redemption and no validation — writing the code an
            // operator was given is recording a fact; resolving it would be
            // inventing a system that does not exist.
            $table->string('voucher_code', 60)->nullable();

            // The period this payment bought. Nullable: a payment whose period
            // nobody recorded is still a payment.
            $table->dateTime('period_starts_at')->nullable();
            $table->dateTime('period_ends_at')->nullable();

            // When the money actually arrived — the ONLY date revenue is ever
            // grouped by. Not `created_at`, which is when somebody got round to
            // typing it in.
            $table->dateTime('paid_at')->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();

            // The one mutable group, and the whole correction mechanism: who
            // moved the status, when, and why. A refund and a void differ only
            // in which status they land on.
            $table->dateTime('status_changed_at')->nullable();
            $table->string('status_reason', 255)->nullable();
            $table->foreignId('status_changed_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'paid_at']);
            // Revenue over a window is `status = 'paid'` filtered by `paid_at`;
            // this is the index that answers it without a scan.
            $table->index(['status', 'paid_at']);
        });

        $this->addCheck(
            'subscription_payments',
            'subscription_payments_status_check',
            "status IN ('pending','paid','failed','refunded','cancelled')",
        );

        // A payment that counts as revenue must say when the money arrived.
        // Without this, a row could be `paid` with a null `paid_at` and fall
        // silently out of every period total while still inflating the all-time
        // one.
        $this->addCheck(
            'subscription_payments',
            'subscription_payments_paid_at_check',
            "status <> 'paid' OR paid_at IS NOT NULL",
        );

        $this->addCheck(
            'subscription_payments',
            'subscription_payments_commercial_condition_check',
            "commercial_condition IS NULL OR commercial_condition IN ('standard','founder','voucher','admin_grant','institutional','legacy','other')",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
    }

    protected function addCheck(string $table, string $name, string $expression): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }
};
