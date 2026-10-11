<?php

namespace Tests\Feature\Usage;

use App\Support\Usage\UsageEventKind;
use App\Support\Usage\UsageEventType;
use App\Support\Usage\UsageFeature;
use App\Support\Usage\UsageMilestone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A promessa de docs/usage-insights.md, afirmada: não há onde guardar texto
 * livre, e o catálogo é fechado.
 */
class UsagePrivacyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_usage_events_columns_are_exactly_the_documented_ones(): void
    {
        $this->assertEqualsCanonicalizing(
            [
                'id', 'user_id', 'organization_id', 'occurred_at', 'occurred_on', 'feature', 'event',
                'kind', 'item_count', 'failure_code', 'dedupe_key', 'created_at',
            ],
            Schema::getColumnListing('usage_events'),
        );
    }

    #[Test]
    public function the_rollup_state_and_period_tables_hold_no_free_text_either(): void
    {
        $this->assertEqualsCanonicalizing(
            ['id', 'day', 'user_id', 'organization_id', 'feature', 'views', 'actions', 'useful_actions', 'items', 'failures'],
            Schema::getColumnListing('usage_daily_rollups'),
        );
        $this->assertEqualsCanonicalizing(
            ['id', 'ulid', 'user_id', 'class_available_since', 'first_class_created_on', 'first_students_added_on', 'first_useful_record_on', 'computed_at', 'created_at', 'updated_at'],
            Schema::getColumnListing('usage_user_states'),
        );
        $this->assertEqualsCanonicalizing(['id', 'started_at', 'ended_at'], Schema::getColumnListing('usage_collection_periods'));
        $this->assertEqualsCanonicalizing(['id', 'aggregated_through', 'aggregated_at'], Schema::getColumnListing('usage_aggregation_state'));
    }

    #[Test]
    public function the_event_catalogue_is_closed_and_well_formed(): void
    {
        foreach (UsageEventType::cases() as $type) {
            $this->assertMatchesRegularExpression('/^[a-z_]+\.[a-z_]+$/', $type->value);

            if ($type->kind() === UsageEventKind::Action) {
                $this->assertInstanceOf(UsageFeature::class, $type->feature(), "{$type->value} é uma ação e precisa de funcionalidade");
            }
        }

        $this->assertNull(UsageEventType::FeatureViewed->feature());
        $this->assertNull(UsageEventType::OperationFailed->feature());
    }

    #[Test]
    public function every_useful_record_proves_the_first_useful_record_milestone(): void
    {
        foreach (UsageEventType::cases() as $type) {
            if ($type->isUsefulRecord()) {
                $this->assertSame(UsageMilestone::FirstUsefulRecord, $type->milestone());
                $this->assertContains($type, UsageMilestone::FirstUsefulRecord->events());
            }
        }
    }

    #[Test]
    public function the_collection_migration_opened_a_period(): void
    {
        $this->assertSame(1, DB::table('usage_collection_periods')->whereNull('ended_at')->count());
    }
}
