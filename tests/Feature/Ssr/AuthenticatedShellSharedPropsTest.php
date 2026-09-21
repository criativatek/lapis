<?php

namespace Tests\Feature\Ssr;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The shared props the authenticated shell reads without asking.
 *
 * AppSidebar reads `nav.sections` and `nav.footer`; ContextBar reads `scope`
 * and `selectableAcademicYears`; the header and footer read `auth.user` and
 * `appVersion`. None of those components checks first — they are the shell,
 * and the contract is that `HandleInertiaRequests` puts all of it on every
 * page that has the shell. A page object without them is what produced
 * «Cannot read properties of undefined (reading 'footer' / 'sections' /
 * 'length')» in production. These tests hold the contract at its origin, on
 * the three kinds of shell page there are: one behind the organization
 * middleware, one outside it, and the one page a middleware renders itself.
 */
class AuthenticatedShellSharedPropsTest extends TestCase
{
    use RefreshDatabase;

    protected function assertCarriesTheShellProps(AssertableInertia $page): AssertableInertia
    {
        return $page
            ->has('nav.sections')
            ->has('nav.footer')
            ->has('scope')
            ->has('selectableAcademicYears')
            ->has('auth.user')
            ->has('appVersion');
    }

    #[Test]
    public function a_page_behind_the_organization_middleware_carries_the_shell_props(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $this->assertCarriesTheShellProps($page->component('Dashboard')));
    }

    #[Test]
    public function a_page_outside_the_organization_middleware_carries_the_shell_props(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/support')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $this->assertCarriesTheShellProps($page));
    }

    #[Test]
    public function the_page_a_middleware_renders_itself_carries_the_shell_props(): void
    {
        // RequireModule answers a locked module with ModuleUnavailable from
        // inside the middleware stack — after HandleInertiaRequests has
        // shared, which is what this proves.
        $this->actingAs(User::factory()->create())
            ->get('/activity/organization')
            ->assertForbidden()
            ->assertInertia(fn (AssertableInertia $page) => $this->assertCarriesTheShellProps($page->component('ModuleUnavailable')));
    }
}
