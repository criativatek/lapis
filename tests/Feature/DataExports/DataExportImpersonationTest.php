<?php

namespace Tests\Feature\DataExports;

use App\Actions\DataExports\GenerateDataExport;
use App\Models\AuditEvent;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A data export carries a student's name, birth date, school number and
 * marks — an admin who reaches it during a support impersonation session
 * must never be able to (a) have the audit trail say the TEACHER asked for
 * it, hiding that an admin was behind the wheel, or (b) download it at all.
 *
 * `resolveCauser()` in AuditLog already does the right thing on its own —
 * unless a caller passes `causer:` explicitly, which skips it entirely. This
 * is the regression test for both: the explicit `causer:` that GenerateDataExport
 * used to pass, and the missing impersonation refusal on the route itself.
 */
class DataExportImpersonationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_audit_trail_names_the_impersonating_admin_not_the_teacher(): void
    {
        Storage::fake('local');

        $admin = User::factory()->create();
        $teacher = User::factory()->create();
        $organization = $teacher->personalOrganization();

        // Simulates what the HTTP layer does during a support session: the
        // authenticated user IS the teacher (impersonation swaps identity),
        // but the session still remembers who is really behind the wheel.
        Auth::login($teacher);
        $this->app['session']->start();
        $this->app['session']->put('impersonator_id', $admin->id);

        $export = app(CurrentOrganization::class)->runFor(
            $organization,
            fn () => app(GenerateDataExport::class)->generate($organization, $teacher),
        );

        $this->assertTrue($export->isReady());

        $generated = AuditEvent::withoutGlobalScope('organization')
            ->where('event', 'data_export.generated')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($admin->id, $generated->causer_id, 'O causer tem de ser o admin que impersona, nunca o professor.');
        $this->assertSame($teacher->id, $generated->properties['acting_as_user_id'] ?? null, 'O evento tem de guardar quem estava a ser impersonado.');
    }

    #[Test]
    public function an_impersonating_admin_cannot_generate_a_data_export(): void
    {
        $admin = User::factory()->create();
        $teacher = User::factory()->create();
        $organization = $teacher->personalOrganization();

        $this->actingAs($teacher)
            ->withSession(['organization_id' => $organization->id, 'impersonator_id' => $admin->id])
            ->post('/data-exports')
            ->assertForbidden();
    }
}
