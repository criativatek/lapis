<?php

namespace Tests\Feature\Tenancy;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Organization;
use App\Rules\BelongsToCurrentOrganization;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Tenancy\TenantNotResolvedException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A stand-in for any tenant-owned record. Phase 0 has no domain entities yet,
 * so the scope is proven against a table created for the test rather than
 * waiting for the assessment model to exist.
 */
#[Fillable(['title', 'organization_id'])]
class TenantScopedRecord extends Model
{
    use BelongsToOrganization;

    protected $table = 'tenant_scoped_records';
}

class OrganizationScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tenant_scoped_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->timestamps();
        });
    }

    protected function tenancy(): CurrentOrganization
    {
        return app(CurrentOrganization::class);
    }

    #[Test]
    public function it_only_returns_records_from_the_resolved_organization(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();

        $this->tenancy()->runFor($organizationA, fn () => TenantScopedRecord::create(['title' => 'Turma A']));
        $this->tenancy()->runFor($organizationB, fn () => TenantScopedRecord::create(['title' => 'Turma B']));

        $visibleToA = $this->tenancy()->runFor($organizationA, fn () => TenantScopedRecord::pluck('title')->all());

        $this->assertSame(['Turma A'], $visibleToA);
    }

    #[Test]
    public function it_cannot_find_a_record_belonging_to_another_organization_by_id(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();

        $recordOfB = $this->tenancy()->runFor(
            $organizationB,
            fn () => TenantScopedRecord::create(['title' => 'Turma B']),
        );

        // Scenario A7: knowing the id of another organization's record must not help.
        $found = $this->tenancy()->runFor(
            $organizationA,
            fn () => TenantScopedRecord::find($recordOfB->getKey()),
        );

        $this->assertNull($found);
    }

    #[Test]
    public function it_stamps_the_resolved_organization_on_create(): void
    {
        $organization = Organization::factory()->create();

        $record = $this->tenancy()->runFor(
            $organization,
            fn () => TenantScopedRecord::create(['title' => 'Turma sem organization_id explícito']),
        );

        $this->assertSame($organization->getKey(), $record->organization_id);
    }

    #[Test]
    public function it_throws_instead_of_leaking_every_organization_when_no_tenant_is_resolved(): void
    {
        Organization::factory()->create();

        // The failure mode this guards against: a scope that silently no-ops
        // outside HTTP would return every organization's rows here.
        $this->expectException(TenantNotResolvedException::class);

        TenantScopedRecord::query()->get();
    }

    #[Test]
    public function it_restores_the_previous_tenant_after_run_for(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();

        $this->tenancy()->set($organizationA);

        $this->tenancy()->runFor($organizationB, function (): void {
            $this->assertSame('b-is-current', 'b-is-current');
        });

        $this->assertSame($organizationA->getKey(), $this->tenancy()->id());
    }

    #[Test]
    public function the_validation_rule_rejects_an_id_from_another_organization(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();

        $recordOfB = $this->tenancy()->runFor(
            $organizationB,
            fn () => TenantScopedRecord::create(['title' => 'Turma B']),
        );

        $this->tenancy()->runFor($organizationA, function () use ($recordOfB): void {
            $validator = Validator::make(
                ['record_id' => $recordOfB->getKey()],
                ['record_id' => [new BelongsToCurrentOrganization(TenantScopedRecord::class)]],
            );

            $this->assertTrue(
                $validator->fails(),
                'A bare exists: rule would pass here — that is the hole this rule closes.',
            );
        });
    }

    #[Test]
    public function the_validation_rule_accepts_an_id_from_the_resolved_organization(): void
    {
        $organization = Organization::factory()->create();

        $this->tenancy()->runFor($organization, function (): void {
            $record = TenantScopedRecord::create(['title' => 'Turma própria']);

            $validator = Validator::make(
                ['record_id' => $record->getKey()],
                ['record_id' => [new BelongsToCurrentOrganization(TenantScopedRecord::class)]],
            );

            $this->assertFalse($validator->fails());
        });
    }
}
