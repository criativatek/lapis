<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChangelogTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_logged_in_user_can_view_the_changelog_without_a_resolved_organization(): void
    {
        $user = User::factory()->create();

        // Deliberately no organization/tenant set up for this user — the
        // changelog is not tenant data, so this must not throw
        // TenantNotResolvedException.
        $this->actingAs($user)
            ->get('/novidades')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('changelog/Index')->has('entries'));
    }

    #[Test]
    public function a_guest_is_redirected_to_login(): void
    {
        $this->get('/novidades')->assertRedirect('/login');
    }
}
