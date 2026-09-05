<?php

namespace Tests\Feature\Students;

use App\Models\Organization;
use App\Models\Student;
use App\Models\StudentIdentity;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Tenancy\TenantNotResolvedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * StudentIdentity is the only place a student's real name, birth date and
 * photo live — an audit found it was also the only tenant-owned table
 * without the organization global scope (ADR-0002), isolation left entirely
 * to each caller remembering the filter. This proves the scope is now in
 * place, the same way OrganizationScopeTest proves it for the general case.
 */
class StudentIdentityTenancyTest extends TestCase
{
    use RefreshDatabase;

    protected function tenancy(): CurrentOrganization
    {
        return app(CurrentOrganization::class);
    }

    #[Test]
    public function an_organization_cannot_see_another_organizations_student_identities(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();

        $studentB = $this->tenancy()->runFor($organizationB, function () use ($organizationB): Student {
            $student = Student::create(['pseudonym_code' => 'ALU-1']);
            $student->identity()->create([
                'organization_id' => $organizationB->getKey(),
                'display_name' => 'Aluno da Organização B',
            ]);

            return $student;
        });

        $this->tenancy()->runFor($organizationA, function () use ($studentB): void {
            $this->assertNull(
                StudentIdentity::query()->where('student_id', $studentB->getKey())->first(),
                'A organização A não pode ver a identidade de um aluno da organização B.',
            );
        });
    }

    #[Test]
    public function it_throws_rather_than_leaking_when_no_tenant_is_resolved(): void
    {
        $organization = Organization::factory()->create();

        $this->tenancy()->runFor($organization, function () use ($organization): void {
            $student = Student::create(['pseudonym_code' => 'ALU-2']);
            $student->identity()->create([
                'organization_id' => $organization->getKey(),
                'display_name' => 'Aluno',
            ]);
        });

        $this->expectException(TenantNotResolvedException::class);

        StudentIdentity::query()->first();
    }
}
