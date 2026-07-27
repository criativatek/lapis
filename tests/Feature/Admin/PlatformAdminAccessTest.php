<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The backoffice is reserved for platform administrators — a teacher must never
 * reach it. Mirrors the tenancy 403 test.
 */
class PlatformAdminAccessTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_teacher_is_forbidden_from_the_backoffice(): void
    {
        $teacher = User::factory()->create();

        $this->actingAs($teacher)->get('/admin')->assertForbidden();
    }

    #[Test]
    public function a_platform_admin_reaches_the_backoffice(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        $this->actingAs($admin)->get('/admin')->assertOk();
    }

    #[Test]
    public function the_backoffice_lists_organizations_across_tenants(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();
        User::factory()->create(); // another teacher + org, a different tenant

        $this->actingAs($admin)->get('/admin')->assertInertia(
            fn ($page) => $page
                ->component('admin/Accounts')
                // The admin's own org + the other teacher's org, both cross-tenant.
                ->where('organizations.total', fn (int $total) => $total >= 2),
        );
    }

    #[Test]
    public function the_make_admin_command_grants_and_revokes(): void
    {
        $user = User::factory()->create();

        $this->artisan('lapis:make-admin', ['email' => $user->email])->assertSuccessful();
        $this->assertTrue($user->fresh()->isPlatformAdmin());

        $this->artisan('lapis:make-admin', ['email' => $user->email, '--revoke' => true])->assertSuccessful();
        $this->assertFalse($user->fresh()->isPlatformAdmin());
    }
}
