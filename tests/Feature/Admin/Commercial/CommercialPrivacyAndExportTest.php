<?php

namespace Tests\Feature\Admin\Commercial;

use App\Models\Organization;
use App\Models\PaymentStatus;
use App\Models\Plan;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\Organizations\ChangeOrganizationPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Two guarantees that are easy to lose by accident.
 *
 * §19 — commercial management and pedagogical data are separate areas, and no
 * student ever appears in this one. The temptation is real: the account page one
 * click away shows class and student counts, and "just one more column" is how a
 * commercial screen ends up carrying a minor's name.
 *
 * §21 — the export is the listing, under the same filters, for the same person,
 * with no pedagogical column.
 */
class CommercialPrivacyAndExportTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        return $admin;
    }

    protected function account(string $name, string $planKey = 'pro'): Organization
    {
        $user = User::factory()->create();
        $organization = $user->personalOrganization();
        $organization->forceFill(['name' => $name])->save();

        if ($planKey !== 'base') {
            app(ChangeOrganizationPlan::class)->to($organization, Plan::where('key', $planKey)->firstOrFail());
        }

        return $organization->fresh();
    }

    /** The vocabulary of the pedagogical domain. None of it belongs here. */
    protected const PEDAGOGICAL_KEYS = [
        'students', 'student', 'classes', 'school_class', 'classifications',
        'evidence', 'reports', 'interventions', 'assessment', 'enrollments',
        'self_assessments', 'grades',
    ];

    /**
     * Only the props THIS controller produces.
     *
     * `HandleInertiaRequests` shares the teacher-facing navigation with every
     * response in the application, and its menu labels legitimately name
     * «students» and «reports». That is a static menu, shared long before this
     * area existed, and asserting over it would be testing someone else's code
     * while saying nothing about whether the commercial screens carry a
     * student. So the assertion is scoped to what the commercial controller
     * itself put on the page — which is the thing under test.
     *
     * @param  list<string>  $keys
     */
    protected function ownProps(array $props, array $keys): string
    {
        return (string) json_encode(array_intersect_key($props, array_flip($keys)));
    }

    #[Test]
    public function the_commercial_listing_carries_no_pedagogical_data(): void
    {
        $this->account('Escola');

        $props = [];
        $this->actingAs($this->admin())->get('/admin/commercial')->assertInertia(function ($page) use (&$props) {
            $props = $page->toArray()['props'];
        });

        $encoded = $this->ownProps($props, ['metrics', 'subscriptions', 'filters', 'options']);

        foreach (self::PEDAGOGICAL_KEYS as $key) {
            $this->assertStringNotContainsString(
                "\"{$key}\"",
                $encoded,
                "A listagem comercial não deve transportar «{$key}».",
            );
        }
    }

    #[Test]
    public function the_commercial_account_detail_carries_no_pedagogical_data(): void
    {
        $account = $this->account('Escola');

        $props = [];
        $this->actingAs($this->admin())->get("/admin/commercial/{$account->ulid}")->assertInertia(function ($page) use (&$props) {
            $props = $page->toArray()['props'];
        });

        $encoded = $this->ownProps($props, [
            'account', 'current', 'history', 'overrides', 'payments', 'totals', 'audit', 'options',
        ]);

        foreach (self::PEDAGOGICAL_KEYS as $key) {
            $this->assertStringNotContainsString(
                "\"{$key}\"",
                $encoded,
                "O detalhe comercial não deve transportar «{$key}».",
            );
        }

        // What it DOES carry is the minimum a subscription needs: whose it is.
        $this->assertSame($account->owner->email, $props['account']['owner']['email']);
    }

    #[Test]
    public function the_commercial_detail_never_counts_a_students_classes_or_reports(): void
    {
        // The account page one click away legitimately shows these counts, as
        // blocking dependencies for deletion. This page must not acquire them:
        // "quantos alunos tem" is a pedagogical question, and a commercial
        // screen has no business answering it.
        $account = $this->account('Escola');

        $props = [];
        $this->actingAs($this->admin())->get("/admin/commercial/{$account->ulid}")->assertInertia(function ($page) use (&$props) {
            $props = $page->toArray()['props'];
        });

        $this->assertArrayNotHasKey('blocking', $props);
        $this->assertArrayNotHasKey('members', $props);
        $this->assertArrayNotHasKey('modules', $props['account']);
    }

    #[Test]
    public function the_export_is_reserved_for_the_platform_operator(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/commercial/export')->assertForbidden();
    }

    #[Test]
    public function the_export_carries_the_commercial_columns_and_the_recorded_amounts(): void
    {
        $account = $this->account('Agrupamento do Norte');

        SubscriptionPayment::withoutGlobalScope('organization')->create([
            'organization_id' => $account->getKey(),
            'amount_cents' => 2990, 'currency' => 'EUR',
            'status' => PaymentStatus::Paid, 'paid_at' => Carbon::parse('2026-08-15'),
        ]);

        $response = $this->actingAs($this->admin())->get('/admin/commercial/export');
        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();

        $this->assertStringContainsString('Condição comercial', $csv);
        $this->assertStringContainsString('Agrupamento do Norte', $csv);
        // The figure as recorded, in a Portuguese decimal.
        $this->assertStringContainsString('29,90', $csv);
        $this->assertStringContainsString('Origem não registada', $csv);
    }

    #[Test]
    public function the_export_respects_the_filters_on_screen(): void
    {
        $this->account('Conta Pro', 'pro');
        $this->account('Conta Base', 'base');

        $csv = $this->actingAs($this->admin())->get('/admin/commercial/export?plan=pro')->streamedContent();

        $this->assertStringContainsString('Conta Pro', $csv);
        $this->assertStringNotContainsString('Conta Base', $csv);
    }

    #[Test]
    public function the_export_names_no_pedagogical_column(): void
    {
        $this->account('Escola');

        $header = strtok($this->actingAs($this->admin())->get('/admin/commercial/export')->streamedContent(), "\n");

        foreach (['Aluno', 'Turma', 'Nota', 'Avalia', 'Relatório'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $header);
        }
    }
}
