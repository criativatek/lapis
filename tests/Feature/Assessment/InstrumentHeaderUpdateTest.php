<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\Instrument;
use App\Models\InstrumentType;
use App\Models\Organization;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Services\Assessment\InstrumentBuilder;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Editing an instrument has to save the instrument itself, not only its
 * questions and groups. The header fields travel a different path from the
 * items — through the Form Request, resolveInstrumentType() and into
 * $instrument->update() — and that path has its own ways of losing a field.
 */
class InstrumentHeaderUpdateTest extends TestCase
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

    protected function schoolClass(): SchoolClass
    {
        $organization = $this->organization;
        $year = AcademicYear::factory()->recycle($organization)->create();
        AcademicPeriod::factory()->recycle($organization)->for($year)->create();
        $subject = Subject::factory()->recycle($organization)->create();

        $class = SchoolClass::factory()->recycle($organization)->create([
            'academic_year_id' => $year->id,
            'subject_id' => $subject->id,
        ]);
        $class->teachers()->attach($this->user, ['role' => 'owner']);

        return $class;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function attributes(SchoolClass $class, array $overrides = []): array
    {
        return array_merge([
            'academic_period_id' => AcademicPeriod::where('academic_year_id', $class->academic_year_id)->firstOrFail()->id,
            'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
            'title' => 'Teste A',
            'applied_on' => '2026-10-15',
            'status' => 'prepared',
            'counts_toward_classification' => true,
            'purpose' => 'summative',
            'total_points' => 100,
        ], $overrides);
    }

    protected function makeInstrument(SchoolClass $class): Instrument
    {
        return app(InstrumentBuilder::class)->create($class, $this->attributes($class), [
            ['code' => 'Q1', 'points_possible' => 100],
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function putPayload(Instrument $instrument, SchoolClass $class, array $overrides = []): array
    {
        $group = $instrument->groups()->orderBy('sequence')->firstOrFail();

        return array_merge([
            ...$this->attributes($class),
            'allow_bonus' => false,
            'groups' => [['ulid' => $group->ulid, 'label' => $group->label]],
            'items' => $instrument->items()->orderBy('sequence')->get()->map(fn ($item) => [
                'ulid' => $item->ulid,
                'group_index' => 0,
                'code' => $item->code,
                'label' => $item->label,
                'points_possible' => (float) $item->points_possible,
                'is_bonus' => $item->is_bonus,
                'domains' => [],
            ])->all(),
        ], $overrides);
    }

    #[Test]
    public function changing_only_the_title_persists_it(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $instrument = $this->makeInstrument($class);

            $this->assertSame('Teste A', $instrument->title);

            $itemUlids = $instrument->items()->pluck('ulid')->all();
            $groupUlid = $instrument->groups()->firstOrFail()->ulid;

            $this->actingAs($this->user)->put(
                "/instruments/{$instrument->ulid}",
                $this->putPayload($instrument, $class, ['title' => 'Teste B']),
            )->assertRedirect();

            $fresh = $instrument->fresh();

            $this->assertSame('Teste B', $fresh->title, 'The new title must reach the database.');
            // And nothing else moved.
            $this->assertSame($instrument->id, $fresh->id);
            $this->assertSame($instrument->ulid, $fresh->ulid);
            $this->assertSame(1, $fresh->groups()->count());
            $this->assertSame($groupUlid, $fresh->groups()->firstOrFail()->ulid);
            $this->assertSame($itemUlids, $fresh->items()->pluck('ulid')->all());
        });
    }

    #[Test]
    public function every_header_field_persists(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $instrument = $this->makeInstrument($class);

            $this->actingAs($this->user)->put(
                "/instruments/{$instrument->ulid}",
                $this->putPayload($instrument, $class, [
                    'title' => 'Cabeçalho novo',
                    'applied_on' => '2026-11-20',
                    'purpose' => 'formative',
                    'counts_toward_classification' => false,
                    'total_points' => 100,
                    'allow_bonus' => true,
                ]),
            )->assertRedirect();

            $fresh = $instrument->fresh();

            $this->assertSame('Cabeçalho novo', $fresh->title);
            $this->assertSame('2026-11-20', $fresh->applied_on->toDateString());
            $this->assertSame('formative', $fresh->purpose);
            $this->assertFalse($fresh->counts_toward_classification);
            $this->assertTrue($fresh->allow_bonus);
        });
    }

    #[Test]
    public function the_title_persists_alongside_a_question_change(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $instrument = $this->makeInstrument($class);
            $item = $instrument->items()->firstOrFail();
            $group = $instrument->groups()->firstOrFail();

            $this->actingAs($this->user)->put("/instruments/{$instrument->ulid}", [
                ...$this->attributes($class, ['title' => 'Título e questão']),
                'allow_bonus' => false,
                'groups' => [['ulid' => $group->ulid, 'label' => $group->label]],
                'items' => [[
                    'ulid' => $item->ulid,
                    'group_index' => 0,
                    'code' => 'Q1',
                    'label' => 'Enunciado novo',
                    'points_possible' => 100,
                    'is_bonus' => false,
                    'domains' => [],
                ]],
            ])->assertRedirect();

            $this->assertSame('Título e questão', $instrument->fresh()->title);
            $this->assertSame('Enunciado novo', $instrument->items()->firstOrFail()->label);
            $this->assertSame($item->ulid, $instrument->items()->firstOrFail()->ulid);
        });
    }

    #[Test]
    public function the_title_persists_alongside_a_group_rename(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $instrument = $this->makeInstrument($class);
            $item = $instrument->items()->firstOrFail();
            $group = $instrument->groups()->firstOrFail();

            $this->actingAs($this->user)->put("/instruments/{$instrument->ulid}", [
                ...$this->attributes($class, ['title' => 'Título e grupo']),
                'allow_bonus' => false,
                'groups' => [['ulid' => $group->ulid, 'label' => 'Oralidade']],
                'items' => [[
                    'ulid' => $item->ulid,
                    'group_index' => 0,
                    'code' => 'Q1',
                    'points_possible' => 100,
                    'is_bonus' => false,
                    'domains' => [],
                ]],
            ])->assertRedirect();

            $this->assertSame('Título e grupo', $instrument->fresh()->title);
            $this->assertSame('Oralidade', $instrument->groups()->firstOrFail()->label);
            $this->assertSame($group->ulid, $instrument->groups()->firstOrFail()->ulid);
        });
    }

    #[Test]
    public function the_pages_shown_after_saving_carry_the_new_title(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $instrument = $this->makeInstrument($class);

            $this->actingAs($this->user)->put(
                "/instruments/{$instrument->ulid}",
                $this->putPayload($instrument, $class, ['title' => 'Título novo']),
            )->assertRedirect("/instruments/{$instrument->ulid}");

            // What the teacher lands on after saving...
            $this->actingAs($this->user)->get("/instruments/{$instrument->ulid}")
                ->assertInertia(fn ($page) => $page->where('instrument.title', 'Título novo'));

            // ...and what they get if they open the edit form again.
            $this->actingAs($this->user)->get("/instruments/{$instrument->ulid}/edit")
                ->assertInertia(fn ($page) => $page->where('instrument.title', 'Título novo'));
        });
    }

    #[Test]
    public function saving_again_without_changes_does_not_revert_the_title(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $instrument = $this->makeInstrument($class);

            $this->actingAs($this->user)->put(
                "/instruments/{$instrument->ulid}",
                $this->putPayload($instrument, $class, ['title' => 'Teste B']),
            );

            $this->assertSame('Teste B', $instrument->fresh()->title);

            // Second save carrying the title it now has.
            $this->actingAs($this->user)->put(
                "/instruments/{$instrument->ulid}",
                $this->putPayload($instrument->fresh(), $class, ['title' => 'Teste B']),
            );

            $this->assertSame('Teste B', $instrument->fresh()->title);
        });
    }
}
