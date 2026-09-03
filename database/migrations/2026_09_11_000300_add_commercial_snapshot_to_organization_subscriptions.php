<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WHAT WAS AGREED, in the row that records the adhesion (ADR-0008 §8).
 *
 * `plan_versions` versions functional RIGHTS. The commercial condition agreed
 * with a customer is a different fact, on a different clock, and it lives here.
 * The audit behind the ADR is blunt about the gap: the system already preserves
 * money well — `SubscriptionPayment` is immutable by construction — and the
 * PROMISE badly. Nothing anywhere records what was agreed when no payment
 * (yet) exists, which is precisely the free Base, the trial, the operator's
 * grant, and the fortnight between a bank-transfer request and its
 * confirmation.
 *
 * A SEPARATE MIGRATION FROM THE `plan_version_id` BACKFILL, ON PURPOSE. The two
 * make different promises and each has to be verifiable alone: the first
 * promises «nobody's access changes», this one promises «no commercial history
 * is invented».
 *
 * NULL IS NOT ZERO, and the distinction is the reason the column is nullable:
 * NULL means no price was ever agreed or recorded, `0` means somebody agreed
 * explicitly that this costs nothing. The same discipline the
 * 2026_09_08_000100 migration already fixed for `commercial_condition`, and the
 * same reason every existing row is left entirely NULL here: backfilling would
 * assert that accounts created before this column existed adhered under the
 * 2026/27 promotional condition, which the database has never held the evidence
 * to say. That decision is the operator's, taken later, as an audited UPDATE.
 *
 * `commercial_term_ends_at` IS NOT `ends_at`. `ends_at` is until when the
 * ACCESS runs; this is until when the CONDITION holds. A free Base created
 * today carries `contracted_price_cents = 0` and
 * `commercial_term_ends_at = 2027-08-31` while `ends_at` stays NULL: the access
 * does not end, the condition does. Nothing in `Entitlements` or `isInForce()`
 * reads any of these four columns — wiring the term into the resolver would
 * turn commercial proof into automatic expiry, a far larger decision that is
 * not being taken here.
 */
return new class extends Migration
{
    /**
     * `commercial_condition`'s allowed values, with `promotional` added.
     *
     * A limited-time promotional condition is NOT `standard`: `standard` is
     * documented as «Pro at list price» and using it would make
     * `CommercialCondition::normallyPaid()` answer `true` for accounts that owe
     * nothing.
     *
     * @var list<string>
     */
    protected const CONDITIONS = [
        'standard', 'founder', 'voucher', 'admin_grant', 'institutional', 'legacy', 'promotional', 'other',
    ];

    /** @var list<string> */
    protected const CONDITIONS_BEFORE = [
        'standard', 'founder', 'voucher', 'admin_grant', 'institutional', 'legacy', 'other',
    ];

    /**
     * No `monthly`. `commercial.ts` says there is no monthly product, and
     * inventing the value in the enum would be inventing the product.
     *
     * @var list<string>
     */
    protected const BILLING_PERIODS = ['annual', 'none'];

    public function up(): void
    {
        Schema::table('organization_subscriptions', function (Blueprint $table): void {
            $table->unsignedInteger('contracted_price_cents')->nullable()->after('commercial_condition_note');
            $table->char('contracted_currency', 3)->nullable()->after('contracted_price_cents');
            $table->string('billing_period', 20)->nullable()->after('contracted_currency');
            $table->timestamp('commercial_term_ends_at')->nullable()->after('billing_period');

            // The operator's queue: «who is on a condition that ends this
            // summer» has to be a query, not archaeology.
            $table->index(['commercial_term_ends_at']);
        });

        // NO BACKFILL. Every existing row keeps NULL in all four columns. See
        // the class docblock: inventing a price, a currency, a periodicity or a
        // term for accounts that predate the columns would be inventing
        // commercial history.

        $this->replaceCheck(
            'organization_subscriptions_commercial_condition_check',
            $this->inList('commercial_condition', self::CONDITIONS),
        );

        $this->addCheck(
            'organization_subscriptions_billing_period_check',
            $this->inList('billing_period', self::BILLING_PERIODS),
        );

        // A price without a currency is not a price. The reverse is allowed:
        // a currency recorded beside a NULL price is merely redundant, not a
        // contradiction, and refusing it would buy nothing.
        $this->addCheck(
            'organization_subscriptions_contracted_currency_check',
            'contracted_price_cents IS NULL OR contracted_currency IS NOT NULL',
        );
    }

    /**
     * REVERSIBLE ONLY WHILE NOTHING HAS BEEN RECORDED IN IT.
     *
     * These four columns exist to be evidence, and they are immutable once
     * written for exactly that reason. Dropping them discards what was agreed
     * with a customer, and no `up()` can bring it back — the same reasoning
     * that stops the sibling migration from discarding which version each
     * subscription contracted.
     *
     * While every row is still NULL — the state this migration leaves behind,
     * since it deliberately backfills nothing — there is nothing to lose and
     * the rollback is a plain schema change.
     */
    public function down(): void
    {
        $this->refuseIfCommercialEvidenceWouldBeLost();

        $this->dropCheck('organization_subscriptions_contracted_currency_check');
        $this->dropCheck('organization_subscriptions_billing_period_check');

        $this->replaceCheck(
            'organization_subscriptions_commercial_condition_check',
            $this->inList('commercial_condition', self::CONDITIONS_BEFORE),
        );

        Schema::table('organization_subscriptions', function (Blueprint $table): void {
            $table->dropIndex(['commercial_term_ends_at']);
            $table->dropColumn([
                'contracted_price_cents', 'contracted_currency', 'billing_period', 'commercial_term_ends_at',
            ]);
        });
    }

    /**
     * @throws RuntimeException when rolling back would discard a recorded condition
     */
    protected function refuseIfCommercialEvidenceWouldBeLost(): void
    {
        $recorded = DB::table('organization_subscriptions')
            ->whereNotNull('contracted_price_cents')
            ->orWhereNotNull('contracted_currency')
            ->orWhereNotNull('billing_period')
            ->orWhereNotNull('commercial_term_ends_at')
            ->count();

        if ($recorded > 0) {
            throw new RuntimeException(
                "Refusing to roll back: {$recorded} subscription(s) carry a recorded commercial condition "
                .'(price, currency, billing period or commercial term). Those columns are immutable proof of what '
                .'was agreed, and dropping them destroys it with nothing able to reconstruct it. This migration is '
                .'reversible only while every row is still NULL — the state it leaves behind when it first runs.'
            );
        }

        // THE VALUE THIS MIGRATION ADDED IS ALSO EVIDENCE. `commercial_condition`
        // itself predates this lot and survives the rollback, but «promotional»
        // does not: it is one of the values this migration added to the CHECK.
        // Narrowing the constraint back with such a row present made MySQL
        // refuse the ALTER with a bare «check constraint is violated», far from
        // the cause — the same error that has been failing CI. Say what it is,
        // and refuse for a reason that can be acted on.
        $promotional = DB::table('organization_subscriptions')
            ->where('commercial_condition', 'promotional')
            ->count();

        if ($promotional > 0) {
            throw new RuntimeException(
                "Refusing to roll back: {$promotional} subscription(s) are on the «promotional» condition, a value "
                .'this migration added. The world before it has no name for them, and relabelling an account to fit '
                .'an older constraint would be rewriting what was agreed. Decide what those accounts are on first.'
            );
        }
    }

    /**
     * @param  list<string>  $values
     */
    protected function inList(string $column, array $values): string
    {
        $quoted = implode(',', array_map(fn (string $value): string => "'{$value}'", $values));

        return "{$column} IS NULL OR {$column} IN ({$quoted})";
    }

    protected function replaceCheck(string $name, string $expression): void
    {
        $this->dropCheck($name);
        $this->addCheck($name, $expression);
    }

    /**
     * CHECK constraints only where the engine has them, exactly as the
     * 2026_09_08_000100 migration already established. SQLite (the test engine)
     * cannot add one to an existing table; the model-level guards and the
     * enum casts are what hold there.
     */
    protected function addCheck(string $name, string $expression): void
    {
        if ($this->supportsChecks()) {
            DB::statement("ALTER TABLE organization_subscriptions ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }

    protected function dropCheck(string $name): void
    {
        if ($this->supportsChecks()) {
            DB::statement("ALTER TABLE organization_subscriptions DROP CONSTRAINT {$name}");
        }
    }

    protected function supportsChecks(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
