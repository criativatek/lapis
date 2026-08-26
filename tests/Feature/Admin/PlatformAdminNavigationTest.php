<?php

namespace Tests\Feature\Admin;

use App\Actions\Organizations\CreateInstitutionalOrganization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The backoffice used to be reachable only by typing /admin. The application now
 * offers an entry point — «Administração da plataforma» in the account dropdown —
 * driven by a single shared boolean, `auth.is_platform_admin`.
 *
 * These tests own the CONTRACT the menu is drawn from. The link itself is a
 * `v-if` over this prop and is asserted in UserMenuContent.test.ts; asserting the
 * prop here is what makes that `v-if` mean something, because a wrong prop would
 * render a correct component wrongly.
 *
 * Nothing here weakens PlatformAdminAccessTest: the flag decides what is DRAWN,
 * `EnsurePlatformAdmin` decides what is SERVED, and the 403 case is restated
 * below precisely so the two can never drift into one.
 */
class PlatformAdminNavigationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_platform_admin_is_told_so_and_therefore_sees_the_link(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        $this->actingAs($admin)->get('/dashboard')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page->where('auth.is_platform_admin', true),
        );
    }

    #[Test]
    public function an_ordinary_teacher_is_not(): void
    {
        $teacher = User::factory()->create();

        $this->actingAs($teacher)->get('/dashboard')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page->where('auth.is_platform_admin', false),
        );
    }

    /**
     * The confusion this feature is most likely to cause. An institutional
     * administrator genuinely administers something — they own an institutional
     * organization and their menu really does carry «Administração
     * Institucional» — but that is a MODULE of their own tenant. It grants
     * nothing on the platform, so both halves are asserted in one test: the
     * institutional item present, the platform flag false.
     */
    #[Test]
    public function an_institutional_administrator_without_the_flag_is_not(): void
    {
        $owner = User::factory()->withoutOrganization()->create();
        app(CreateInstitutionalOrganization::class)->create('Escola Secundária X', $owner);

        $this->actingAs($owner)->get('/dashboard')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('auth.is_platform_admin', false)
                ->where('nav.sections', fn (Collection $sections) => $this->navLabels($sections)
                    ->contains('Administração Institucional')),
        );
    }

    #[Test]
    public function the_shared_boolean_never_opens_the_backoffice_by_itself(): void
    {
        $teacher = User::factory()->create();

        // Presentation is not access control: a teacher who forges the prop, or
        // simply types the URL, still meets EnsurePlatformAdmin.
        $this->actingAs($teacher)->get('/admin')->assertForbidden();
    }

    /**
     * «Voltar ao LÁPIS» in AdminLayout points at the dashboard. Its href is
     * asserted in AdminLayout.test.ts; what needs proving on the server is that
     * the destination actually serves the teacher-facing app to the operator —
     * the backoffice runs outside the `organization` middleware, so a platform
     * admin returning to /dashboard must still resolve their own tenant.
     */
    #[Test]
    public function voltar_ao_lapis_lands_back_in_the_teacher_facing_application(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        $this->actingAs($admin)->get('/dashboard')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                // Still an operator once back — the link out is where they came in.
                ->where('auth.is_platform_admin', true),
        );
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $sections
     * @return Collection<int, string>
     */
    private function navLabels(Collection $sections): Collection
    {
        return $sections
            ->flatMap(fn (array $section): array => $section['items'])
            ->map(fn (array $item): string => $item['label']);
    }
}
