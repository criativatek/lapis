<?php

namespace Tests\Feature\Usage;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UsageAccessTest extends TestCase
{
    use RefreshDatabase;

    private function admin(bool $usageInsights = false): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_platform_admin' => true, 'can_view_usage_insights' => $usageInsights])->save();

        return $user;
    }

    #[Test]
    public function viewing_needs_both_the_admin_status_and_the_authorisation(): void
    {
        $this->assertTrue($this->admin(true)->canViewUsageInsights());
        $this->assertFalse($this->admin(false)->canViewUsageInsights());

        $flagOnly = User::factory()->create();
        $flagOnly->forceFill(['can_view_usage_insights' => true])->save();
        $this->assertFalse($flagOnly->canViewUsageInsights());

        $this->assertFalse(User::factory()->create()->canViewUsageInsights());
    }

    #[Test]
    public function the_grant_command_gives_the_authorisation_to_an_admin_and_refuses_everyone_else(): void
    {
        $admin = $this->admin();
        $teacher = User::factory()->create();

        $this->artisan('lapis:grant-usage-insights', ['email' => $admin->email])->assertSuccessful();
        $this->assertTrue($admin->refresh()->can_view_usage_insights);

        $this->artisan('lapis:grant-usage-insights', ['email' => $teacher->email])->assertFailed();
        $this->assertFalse($teacher->refresh()->can_view_usage_insights);

        $this->artisan('lapis:grant-usage-insights', ['email' => 'ninguem@exemplo.pt'])->assertFailed();
    }

    #[Test]
    public function the_grant_command_can_revoke(): void
    {
        $admin = $this->admin(true);

        $this->artisan('lapis:grant-usage-insights', ['email' => $admin->email, '--revoke' => true])->assertSuccessful();

        $this->assertFalse($admin->refresh()->can_view_usage_insights);
        $this->assertTrue($admin->is_platform_admin);
    }

    #[Test]
    public function revoking_admin_status_by_command_also_revokes_the_authorisation(): void
    {
        $admin = $this->admin(true);

        $this->artisan('lapis:make-admin', ['email' => $admin->email, '--revoke' => true])->assertSuccessful();

        $admin->refresh();
        $this->assertFalse($admin->is_platform_admin);
        $this->assertFalse($admin->can_view_usage_insights);
    }

    #[Test]
    public function granting_admin_status_does_not_grant_the_authorisation(): void
    {
        $teacher = User::factory()->create();

        $this->artisan('lapis:make-admin', ['email' => $teacher->email])->assertSuccessful();

        $teacher->refresh();
        $this->assertTrue($teacher->is_platform_admin);
        $this->assertFalse($teacher->can_view_usage_insights);
    }

    #[Test]
    public function revoking_admin_status_in_the_backoffice_also_revokes_the_authorisation(): void
    {
        $operator = $this->admin();
        $target = $this->admin(true);

        $this->actingAs($operator)
            ->post("/admin/accounts/{$target->personalOrganization()->ulid}/toggle-admin")
            ->assertRedirect();

        $target->refresh();
        $this->assertFalse($target->is_platform_admin);
        $this->assertFalse($target->can_view_usage_insights);
    }

    #[Test]
    public function the_usage_insights_middleware_refuses_an_admin_without_the_authorisation(): void
    {
        Route::middleware(['web', 'auth', 'usage-insights'])->get('/_usage/guarded', fn () => response('ok'));

        $this->actingAs(User::factory()->create())->get('/_usage/guarded')->assertForbidden();
        $this->actingAs($this->admin(false))->get('/_usage/guarded')->assertForbidden();
        $this->actingAs($this->admin(true))->get('/_usage/guarded')->assertOk();
    }
}
