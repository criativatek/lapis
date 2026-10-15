<?php

namespace Tests\Feature\Audit;

use App\Models\AuditEvent;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR-0014: durante o acesso técnico, qualquer escrita fica atribuída a quem a
 * fez — também quando o código passa o causer explicitamente (`$this->user()`,
 * que em impersonação é o professor).
 */
class TechnicalAccessAuditTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true, 'is_support_technician' => true])->save();

        return $admin;
    }

    private function record(User $teacher, ?User $causer, array $properties = []): AuditEvent
    {
        return app(CurrentOrganization::class)->runFor(
            $teacher->personalOrganization(),
            fn (): AuditEvent => app(AuditLog::class)->record('test.explicit_causer', $teacher->personalOrganization(), $causer, null, $properties),
        );
    }

    #[Test]
    public function an_explicit_causer_equal_to_the_represented_user_is_attributed_to_the_administrator(): void
    {
        $admin = $this->admin();
        $teacher = User::factory()->create();
        $this->actingAs($teacher)->withSession(['impersonator_id' => $admin->id]);

        $event = $this->record($teacher, $teacher, ['x' => 1]);

        $this->assertSame($admin->id, $event->causer_id);
        $this->assertSame($teacher->id, $event->properties['acting_as_user_id']);
        $this->assertSame(1, $event->properties['x']);
    }

    #[Test]
    public function outside_impersonation_nothing_changes(): void
    {
        $teacher = User::factory()->create();
        $this->actingAs($teacher);

        $event = $this->record($teacher, $teacher);

        $this->assertSame($teacher->id, $event->causer_id);
        $this->assertNull($event->properties);
    }

    #[Test]
    public function an_explicit_causer_different_from_the_represented_user_is_left_alone(): void
    {
        $admin = $this->admin();
        $teacher = User::factory()->create();
        $someoneElse = User::factory()->create();
        $this->actingAs($teacher)->withSession(['impersonator_id' => $admin->id]);

        $event = $this->record($teacher, $someoneElse);

        $this->assertSame($someoneElse->id, $event->causer_id);
        $this->assertNull($event->properties);
    }

    #[Test]
    public function the_administrator_acting_as_himself_is_left_alone(): void
    {
        $admin = $this->admin();
        $teacher = User::factory()->create();
        $this->actingAs($admin)->withSession(['impersonator_id' => $admin->id]);

        $event = $this->record($teacher, $admin);

        $this->assertSame($admin->id, $event->causer_id);
        $this->assertNull($event->properties);
    }
}
