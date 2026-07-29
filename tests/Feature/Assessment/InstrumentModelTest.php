<?php

namespace Tests\Feature\Assessment;

use App\Models\Instrument;
use App\Models\InstrumentItem;
use App\Models\Organization;
use App\Models\StudentItemScore;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InstrumentModelTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->organization = $this->user->personalOrganization();
    }

    protected function inTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->organization, $callback);
    }

    #[Test]
    public function status_before_cancellation_is_mass_assignable_and_nullable_by_default(): void
    {
        $this->inTenant(function (): void {
            $instrument = Instrument::factory()->recycle($this->organization)->create();

            $this->assertNull($instrument->status_before_cancellation);

            $instrument->update(['status_before_cancellation' => 'prepared']);

            $this->assertSame('prepared', $instrument->refresh()->status_before_cancellation);
        });
    }

    #[Test]
    public function an_instrument_item_can_list_its_own_scores(): void
    {
        $this->inTenant(function (): void {
            $item = InstrumentItem::factory()->recycle($this->organization)->create();

            $this->assertCount(0, $item->scores()->get());

            StudentItemScore::factory()->recycle($this->organization)->create([
                'instrument_id' => $item->instrument_id,
                'instrument_item_id' => $item->id,
                'result_state' => 'assessed',
                'points_earned' => 5,
            ]);

            $this->assertCount(1, $item->fresh()->scores()->get());
        });
    }
}
