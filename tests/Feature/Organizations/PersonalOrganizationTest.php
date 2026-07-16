<?php

namespace Tests\Feature\Organizations;

use App\Models\OrganizationType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PersonalOrganizationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function registering_creates_a_personal_organization_for_the_teacher(): void
    {
        $this->skipUnlessFortifyHas('registration');

        $this->post('/register', [
            'name' => 'Ana Martins',
            'email' => 'ana.martins@example.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $user = User::where('email', 'ana.martins@example.test')->firstOrFail();
        $organization = $user->personalOrganization();

        $this->assertNotNull($organization, 'A newly registered teacher must own a personal organization.');
        $this->assertSame(OrganizationType::Personal, $organization->type);
        $this->assertSame('Ana Martins', $organization->name);
        $this->assertSame('Europe/Lisbon', $organization->timezone);
        $this->assertTrue($organization->members->contains($user));
    }

    #[Test]
    public function the_organization_is_exposed_by_ulid_not_by_sequential_id(): void
    {
        $this->skipUnlessFortifyHas('registration');

        $this->post('/register', [
            'name' => 'Inês Costa',
            'email' => 'ines.costa@example.test',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $organization = User::where('email', 'ines.costa@example.test')->firstOrFail()->personalOrganization();

        $this->assertSame('ulid', $organization->getRouteKeyName());
        $this->assertSame(26, strlen($organization->ulid));
        $this->assertNotSame((string) $organization->getKey(), $organization->getRouteKey());
    }

    #[Test]
    public function no_user_is_created_when_the_organization_cannot_be_created(): void
    {
        // The two writes share a transaction: an account with no organization would
        // authenticate and then 403 on every request.
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('organizations', 0);
    }
}
