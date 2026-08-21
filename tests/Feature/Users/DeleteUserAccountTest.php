<?php

namespace Tests\Feature\Users;

use App\Actions\Users\DeleteUserAccount;
use App\Models\Organization;
use App\Models\User;
use App\Support\Accounts\AccountClosureException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DeleteUserAccountTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function deleting_an_account_removes_its_personal_organization(): void
    {
        $user = User::factory()->create();
        $personal = $user->personalOrganization();

        app(DeleteUserAccount::class)->delete($user);

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('organizations', ['id' => $personal->id]);
        $this->assertDatabaseMissing('organization_memberships', ['organization_id' => $personal->id]);
    }

    #[Test]
    public function deleting_an_account_does_not_delete_an_institution_the_user_merely_belongs_to(): void
    {
        $user = User::factory()->create();
        $institution = Organization::factory()->institutional()->withMember($user)->create();

        app(DeleteUserAccount::class)->delete($user);

        // Membership goes; the school and everyone else's data stay.
        $this->assertDatabaseHas('organizations', ['id' => $institution->id]);
        $this->assertDatabaseMissing('organization_memberships', [
            'organization_id' => $institution->id,
            'user_id' => $user->id,
        ]);
    }

    #[Test]
    public function deleting_an_account_that_owns_an_institution_is_refused_with_a_clear_reason(): void
    {
        $user = User::factory()->create();
        $institution = Organization::factory()->institutional()->withMember($user)->create(['owner_id' => $user->id]);

        // A named domain exception, not a raw QueryException — the operator is
        // told to transfer ownership first (TeamController::transferOwnership),
        // rather than being handed a SQL error as the first UX (§35).
        $this->expectException(AccountClosureException::class);

        try {
            app(DeleteUserAccount::class)->delete($user);
        } finally {
            $this->assertDatabaseHas('users', ['id' => $user->id]);
            $this->assertDatabaseHas('organizations', ['id' => $institution->id]);
        }
    }
}
