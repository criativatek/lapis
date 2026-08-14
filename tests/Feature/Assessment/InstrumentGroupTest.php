<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\Domain;
use App\Models\Instrument;
use App\Models\InstrumentGroup;
use App\Models\InstrumentItem;
use App\Models\InstrumentType;
use App\Models\Organization;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Services\Assessment\InstrumentBuilder;
use App\Support\Assessment\InstrumentValidationException;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Groups are how an instrument is laid out; domains are what each question
 * assesses. Conflating them is what produced two Q2 in one instrument and a
 * unique-constraint violation the teacher saw as a 500.
 *
 * A question belongs to exactly one group and may still split across several
 * domains — so a code identifies a question WITHIN ITS GROUP, never across the
 * whole instrument.
 */
class InstrumentGroupTest extends TestCase
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

        // "Only my classes" (§23): without this the teacher cannot open the
        // edit page at all, and the HTTP tests below would get a 403.
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
            'title' => 'Teste de Português',
            'applied_on' => '2026-10-15',
            'status' => 'prepared',
            'counts_toward_classification' => true,
            'purpose' => 'summative',
            'total_points' => 100,
        ], $overrides);
    }

    // ----------------------------------------------------- implicit group

    #[Test]
    public function a_simple_instrument_gets_one_implicit_group_without_asking(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();

            // No groups submitted at all — the ordinary case.
            $instrument = app(InstrumentBuilder::class)->create($class, $this->attributes($class), [
                ['code' => 'Q1', 'points_possible' => 60],
                ['code' => 'Q2', 'points_possible' => 40],
            ]);

            $groups = $instrument->groups()->get();

            $this->assertCount(1, $groups);
            $this->assertNull($groups->first()->label, 'The implicit group is unnamed, so the UI can hide it.');
            $this->assertTrue($groups->first()->isImplicit());
            $this->assertSame(2, $instrument->items()->where('instrument_group_id', $groups->first()->id)->count());
        });
    }

    #[Test]
    public function editing_a_simple_instrument_does_not_accumulate_groups(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $builder = app(InstrumentBuilder::class);

            $instrument = $builder->create($class, $this->attributes($class), [
                ['code' => 'Q1', 'points_possible' => 100],
            ]);
            $groupId = $instrument->groups()->firstOrFail()->id;

            $builder->update($instrument, $this->attributes($class), [
                ['ulid' => $instrument->items()->firstOrFail()->ulid, 'code' => 'Q1', 'points_possible' => 100],
            ]);

            $this->assertSame(1, $instrument->groups()->count());
            $this->assertSame($groupId, $instrument->groups()->firstOrFail()->id, 'The same implicit group is reused.');
        });
    }

    // ----------------------------------------------------- explicit groups

    #[Test]
    public function the_same_code_is_allowed_in_different_groups(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();

            // The whole point of this delivery.
            $instrument = app(InstrumentBuilder::class)->create(
                $class,
                $this->attributes($class),
                [
                    ['group_index' => 0, 'code' => 'Q1', 'points_possible' => 25],
                    ['group_index' => 0, 'code' => 'Q2', 'points_possible' => 25],
                    ['group_index' => 1, 'code' => 'Q1', 'points_possible' => 25],
                    ['group_index' => 1, 'code' => 'Q2', 'points_possible' => 25],
                ],
                [['label' => 'Oralidade'], ['label' => 'Gramática']],
            );

            $this->assertSame(2, $instrument->groups()->count());
            $this->assertSame(4, $instrument->items()->count());

            $oralidade = $instrument->groups()->where('label', 'Oralidade')->firstOrFail();
            $gramatica = $instrument->groups()->where('label', 'Gramática')->firstOrFail();

            $this->assertSame(['Q1', 'Q2'], $oralidade->items()->pluck('code')->sort()->values()->all());
            $this->assertSame(['Q1', 'Q2'], $gramatica->items()->pluck('code')->sort()->values()->all());
            $this->assertNotSame($oralidade->id, $gramatica->id);
        });
    }

    #[Test]
    public function the_same_code_twice_in_one_group_is_refused_before_any_sql(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();

            $this->expectException(InstrumentValidationException::class);
            $this->expectExceptionMessage('Oralidade');

            app(InstrumentBuilder::class)->create(
                $class,
                $this->attributes($class),
                [
                    ['group_index' => 0, 'code' => 'Q2', 'points_possible' => 50],
                    ['group_index' => 0, 'code' => 'Q2', 'points_possible' => 50],
                ],
                [['label' => 'Oralidade']],
            );
        });
    }

    #[Test]
    public function two_groups_may_share_a_label_because_identity_is_the_ulid(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();

            $instrument = app(InstrumentBuilder::class)->create(
                $class,
                $this->attributes($class),
                [
                    ['group_index' => 0, 'code' => 'Q1', 'points_possible' => 50],
                    ['group_index' => 1, 'code' => 'Q1', 'points_possible' => 50],
                ],
                [['label' => 'Parte A'], ['label' => 'Parte A']],
            );

            $groups = $instrument->groups()->get();

            $this->assertCount(2, $groups);
            $this->assertNotSame($groups[0]->ulid, $groups[1]->ulid);
        });
    }

    #[Test]
    public function renaming_a_group_changes_nothing_but_its_label(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $builder = app(InstrumentBuilder::class);

            $instrument = $builder->create($class, $this->attributes($class), [
                ['group_index' => 0, 'code' => 'Q1', 'points_possible' => 100],
            ], [['label' => 'Oralidade']]);

            $group = $instrument->groups()->firstOrFail();
            $item = $instrument->items()->firstOrFail();

            $builder->update($instrument, $this->attributes($class), [
                ['ulid' => $item->ulid, 'group_index' => 0, 'code' => 'Q1', 'points_possible' => 100],
            ], [['ulid' => $group->ulid, 'label' => 'Compreensão Oral']]);

            $fresh = $instrument->groups()->firstOrFail();

            $this->assertSame($group->id, $fresh->id);
            $this->assertSame($group->ulid, $fresh->ulid);
            $this->assertSame('Compreensão Oral', $fresh->label);
            $this->assertSame($item->ulid, $instrument->items()->firstOrFail()->ulid);
        });
    }

    #[Test]
    public function a_group_still_holding_questions_cannot_be_removed(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $builder = app(InstrumentBuilder::class);

            $instrument = $builder->create($class, $this->attributes($class), [
                ['group_index' => 0, 'code' => 'Q1', 'points_possible' => 50],
                ['group_index' => 1, 'code' => 'Q1', 'points_possible' => 50],
            ], [['label' => 'Oralidade'], ['label' => 'Gramática']]);

            $oralidade = $instrument->groups()->where('label', 'Oralidade')->firstOrFail();
            $items = $instrument->items()->get();

            $this->expectException(InstrumentValidationException::class);

            // Gramática is dropped from the payload while its question stays.
            $builder->update($instrument, $this->attributes($class), [
                ['ulid' => $items[0]->ulid, 'group_index' => 0, 'code' => 'Q1', 'points_possible' => 50],
                ['ulid' => $items[1]->ulid, 'group_index' => 0, 'code' => 'Q2', 'points_possible' => 50],
            ], [['ulid' => $oralidade->ulid, 'label' => 'Oralidade']]);
        });
    }

    // -------------------------------------------- groups are not domains

    #[Test]
    public function a_question_in_a_group_still_splits_across_several_domains(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $escrita = Domain::factory()->recycle($this->organization)->create(['name' => 'Escrita']);
            $gramatica = Domain::factory()->recycle($this->organization)->create(['name' => 'Gramática']);

            // "GRUPO II — Produção escrita, Q1: Escrita 70% / Gramática 30%".
            $instrument = app(InstrumentBuilder::class)->create(
                $class,
                $this->attributes($class),
                [['group_index' => 0, 'code' => 'Q1', 'points_possible' => 100, 'domains' => [
                    ['domain_id' => $escrita->id, 'allocation_percent' => 70],
                    ['domain_id' => $gramatica->id, 'allocation_percent' => 30],
                ]]],
                [['label' => 'Produção escrita']],
            );

            $item = $instrument->items()->firstOrFail();

            $this->assertCount(2, $item->domainAllocations);
            $this->assertSame('Produção escrita', $item->group->label);
            // One group, two domains — the two axes are independent.
            $this->assertSame(1, $instrument->groups()->count());
        });
    }

    // -------------------------------------------------------- the regression

    #[Test]
    public function the_case_that_produced_the_500_now_saves_and_re_saves(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $builder = app(InstrumentBuilder::class);
            $oralidade = Domain::factory()->recycle($this->organization)->create(['name' => 'Oralidade']);
            $gramatica = Domain::factory()->recycle($this->organization)->create(['name' => 'Gramática']);

            $instrument = $builder->create(
                $class,
                $this->attributes($class),
                [
                    ['group_index' => 0, 'code' => 'Q2', 'points_possible' => 50, 'domains' => [
                        ['domain_id' => $oralidade->id, 'allocation_percent' => 100],
                    ]],
                    ['group_index' => 1, 'code' => 'Q2', 'points_possible' => 50, 'domains' => [
                        ['domain_id' => $gramatica->id, 'allocation_percent' => 100],
                    ]],
                ],
                [['label' => 'Oralidade'], ['label' => 'Gramática']],
            );

            $itemsBefore = $instrument->items()->orderBy('sequence')->get();
            $ulidsBefore = $itemsBefore->pluck('ulid')->all();
            $groupsBefore = $instrument->groups()->orderBy('sequence')->pluck('ulid')->all();

            $this->assertCount(2, $itemsBefore);
            $this->assertSame(['Q2', 'Q2'], $itemsBefore->pluck('code')->all());
            $this->assertNotSame(
                $itemsBefore[0]->instrument_group_id,
                $itemsBefore[1]->instrument_group_id,
                'Two Q2 are only legal because they sit in different groups.',
            );

            // Edit and save again — the step that used to throw.
            $builder->update(
                $instrument,
                $this->attributes($class),
                [
                    ['ulid' => $itemsBefore[0]->ulid, 'group_index' => 0, 'code' => 'Q2', 'points_possible' => 50, 'domains' => [
                        ['domain_id' => $oralidade->id, 'allocation_percent' => 100],
                    ]],
                    ['ulid' => $itemsBefore[1]->ulid, 'group_index' => 1, 'code' => 'Q2', 'points_possible' => 50, 'domains' => [
                        ['domain_id' => $gramatica->id, 'allocation_percent' => 100],
                    ]],
                ],
                [
                    ['ulid' => $groupsBefore[0], 'label' => 'Oralidade'],
                    ['ulid' => $groupsBefore[1], 'label' => 'Gramática'],
                ],
            );

            $itemsAfter = $instrument->items()->orderBy('sequence')->get();

            $this->assertCount(2, $itemsAfter);
            $this->assertSame(['Q2', 'Q2'], $itemsAfter->pluck('code')->all());
            // Same rows, not recreated ones.
            $this->assertSame($ulidsBefore, $itemsAfter->pluck('ulid')->all());
            $this->assertSame($groupsBefore, $instrument->groups()->orderBy('sequence')->pluck('ulid')->all());
            $this->assertNotSame(
                $itemsAfter[0]->instrument_group_id,
                $itemsAfter[1]->instrument_group_id,
            );
            $this->assertSame('100.0000', $instrument->itemPointsTotal());

            foreach ($itemsAfter as $item) {
                $this->assertCount(1, $item->domainAllocations);
                $this->assertSame('100.0000', $item->domainAllocations->first()->allocation_percent);
            }
        });
    }

    // ------------------------------------------------------- other guarantees

    #[Test]
    public function the_same_code_in_different_instruments_stays_legal(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $builder = app(InstrumentBuilder::class);

            $first = $builder->create($class, $this->attributes($class, ['title' => 'Teste A']), [
                ['code' => 'Q1', 'points_possible' => 100],
            ]);
            $second = $builder->create($class, $this->attributes($class, ['title' => 'Teste B']), [
                ['code' => 'Q1', 'points_possible' => 100],
            ]);

            $this->assertSame('Q1', $first->items()->firstOrFail()->code);
            $this->assertSame('Q1', $second->items()->firstOrFail()->code);
            $this->assertNotSame(
                $first->items()->firstOrFail()->instrument_group_id,
                $second->items()->firstOrFail()->instrument_group_id,
            );
        });
    }

    #[Test]
    public function a_manual_edit_no_longer_wipes_an_imported_questions_provenance(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $builder = app(InstrumentBuilder::class);

            $instrument = $builder->create($class, $this->attributes($class), [
                ['code' => 'Q1', 'points_possible' => 100, 'source_group_label' => 'Grupo de Questões: Oralidade'],
            ]);

            $item = $instrument->items()->firstOrFail();
            $this->assertSame('Grupo de Questões: Oralidade', $item->source_group_label);

            // The edit form neither shows nor sends source_group_label. It must
            // survive anyway — it is where an imported row came from.
            $builder->update($instrument, $this->attributes($class), [
                ['ulid' => $item->ulid, 'code' => 'Q1', 'points_possible' => 100],
            ]);

            $this->assertSame(
                'Grupo de Questões: Oralidade',
                $instrument->items()->firstOrFail()->source_group_label,
            );
        });
    }

    #[Test]
    public function a_group_belongs_to_its_instrument_and_its_organization(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();

            $instrument = app(InstrumentBuilder::class)->create($class, $this->attributes($class), [
                ['code' => 'Q1', 'points_possible' => 100],
            ]);

            $group = $instrument->groups()->firstOrFail();

            $this->assertSame($instrument->id, $group->instrument_id);
            $this->assertSame($this->organization->id, $group->organization_id);
            $this->assertSame($group->organization_id, $instrument->organization_id);
        });
    }

    // ------------------------------------------------- what the form relies on

    #[Test]
    public function a_backfilled_instrument_opens_as_a_simple_one_with_no_named_group(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();

            $instrument = app(InstrumentBuilder::class)->create($class, $this->attributes($class), [
                ['code' => 'Q1', 'points_possible' => 100],
            ]);

            // The edit page must not suddenly show "Grupo sem nome" on an
            // instrument the teacher never structured.
            $this->actingAs($this->user)->get("/instruments/{$instrument->ulid}/edit")
                ->assertInertia(fn ($page) => $page
                    ->count('instrument.groups', 1)
                    ->where('instrument.groups.0.label', null)
                    ->where('instrument.items.0.group_index', 0));
        });
    }

    #[Test]
    public function saving_and_reopening_keeps_the_whole_group_structure(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();

            $instrument = app(InstrumentBuilder::class)->create(
                $class,
                $this->attributes($class),
                [
                    ['group_index' => 0, 'code' => 'Q1', 'points_possible' => 25],
                    ['group_index' => 0, 'code' => 'Q2', 'points_possible' => 25],
                    ['group_index' => 1, 'code' => 'Q1', 'points_possible' => 25],
                    ['group_index' => 1, 'code' => 'Q2', 'points_possible' => 25],
                ],
                [['label' => 'Oralidade'], ['label' => 'Gramática']],
            );

            $this->actingAs($this->user)->get("/instruments/{$instrument->ulid}/edit")
                ->assertInertia(function ($page) {
                    $props = $page->toArray()['props']['instrument'];

                    $this->assertSame(['Oralidade', 'Gramática'], array_column($props['groups'], 'label'));

                    // Each question comes back pointing at the right section.
                    $byGroup = [];
                    foreach ($props['items'] as $item) {
                        $byGroup[$item['group_index']][] = $item['code'];
                    }

                    $this->assertSame(['Q1', 'Q2'], $byGroup[0]);
                    $this->assertSame(['Q1', 'Q2'], $byGroup[1]);

                    return $page;
                });
        });
    }

    #[Test]
    public function moving_a_question_to_another_group_keeps_its_identity_and_domains(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $builder = app(InstrumentBuilder::class);
            $leitura = Domain::factory()->recycle($this->organization)->create(['name' => 'Leitura']);

            $instrument = $builder->create(
                $class,
                $this->attributes($class),
                [
                    ['group_index' => 0, 'code' => 'Q1', 'points_possible' => 100, 'domains' => [
                        ['domain_id' => $leitura->id, 'allocation_percent' => 100],
                    ]],
                ],
                [['label' => 'Oralidade'], ['label' => 'Gramática']],
            );

            $item = $instrument->items()->firstOrFail();
            $groups = $instrument->groups()->orderBy('sequence')->get();

            // Same question, now in the second group.
            $builder->update($instrument, $this->attributes($class), [
                ['ulid' => $item->ulid, 'group_index' => 1, 'code' => 'Q1', 'points_possible' => 100, 'domains' => [
                    ['domain_id' => $leitura->id, 'allocation_percent' => 100],
                ]],
            ], [
                ['ulid' => $groups[0]->ulid, 'label' => 'Oralidade'],
                ['ulid' => $groups[1]->ulid, 'label' => 'Gramática'],
            ]);

            $moved = $instrument->items()->firstOrFail();

            $this->assertSame($item->id, $moved->id);
            $this->assertSame($item->ulid, $moved->ulid);
            $this->assertSame($groups[1]->id, $moved->instrument_group_id);
            $this->assertCount(1, $moved->domainAllocations);
            $this->assertSame($leitura->id, $moved->domainAllocations->first()->domain_id);
        });
    }

    #[Test]
    public function moving_a_question_onto_a_code_that_group_already_has_is_refused(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $builder = app(InstrumentBuilder::class);

            $instrument = $builder->create(
                $class,
                $this->attributes($class),
                [
                    ['group_index' => 0, 'code' => 'Q2', 'points_possible' => 50],
                    ['group_index' => 1, 'code' => 'Q2', 'points_possible' => 50],
                ],
                [['label' => 'Oralidade'], ['label' => 'Gramática']],
            );

            $items = $instrument->items()->orderBy('sequence')->get();
            $groups = $instrument->groups()->orderBy('sequence')->get();

            $this->expectException(InstrumentValidationException::class);

            // Oralidade/Q2 moved into Gramática, which already holds a Q2.
            $builder->update($instrument, $this->attributes($class), [
                ['ulid' => $items[0]->ulid, 'group_index' => 1, 'code' => 'Q2', 'points_possible' => 50],
                ['ulid' => $items[1]->ulid, 'group_index' => 1, 'code' => 'Q2', 'points_possible' => 50],
            ], [
                ['ulid' => $groups[0]->ulid, 'label' => 'Oralidade'],
                ['ulid' => $groups[1]->ulid, 'label' => 'Gramática'],
            ]);
        });
    }

    #[Test]
    public function a_group_ulid_from_another_instrument_is_never_adopted(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $builder = app(InstrumentBuilder::class);

            $mine = $builder->create($class, $this->attributes($class, ['title' => 'Meu']), [
                ['code' => 'Q1', 'points_possible' => 100],
            ], [['label' => 'Oralidade']]);

            $other = $builder->create($class, $this->attributes($class, ['title' => 'Outro']), [
                ['code' => 'Q1', 'points_possible' => 100],
            ], [['label' => 'Gramática']]);

            $foreignUlid = $other->groups()->firstOrFail()->ulid;
            $item = $mine->items()->firstOrFail();

            // The controller strips a ulid that is not this instrument's, so
            // the payload reaching the builder carries none.
            $this->actingAs($this->user)->put("/instruments/{$mine->ulid}", [
                ...$this->attributes($class, ['title' => 'Meu']),
                'allow_bonus' => false,
                'groups' => [['ulid' => $foreignUlid, 'label' => 'Roubado']],
                'items' => [['ulid' => $item->ulid, 'group_index' => 0, 'code' => 'Q1', 'points_possible' => 100]],
            ]);

            // The other instrument's group is untouched.
            $this->assertSame('Gramática', $other->groups()->firstOrFail()->label);
            $this->assertSame(1, $other->groups()->count());
            $this->assertSame(1, $mine->groups()->count());
        });
    }

    // ------------------------------------- identity across the round trip

    #[Test]
    public function saving_a_backfilled_instrument_unchanged_reuses_its_group(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $builder = app(InstrumentBuilder::class);

            $instrument = $builder->create($class, $this->attributes($class), [
                ['code' => 'Q1', 'points_possible' => 50],
                ['code' => 'Q2', 'points_possible' => 50],
            ]);

            $group = $instrument->groups()->firstOrFail();
            $itemUlids = $instrument->items()->orderBy('sequence')->pluck('ulid')->all();

            // The payload the edit page actually sends, now that Edit.vue
            // carries the group's ulid through.
            $this->actingAs($this->user)->put("/instruments/{$instrument->ulid}", [
                ...$this->attributes($class),
                'allow_bonus' => false,
                'groups' => [['ulid' => $group->ulid, 'label' => null]],
                'items' => [
                    ['ulid' => $itemUlids[0], 'group_index' => 0, 'code' => 'Q1', 'points_possible' => 50],
                    ['ulid' => $itemUlids[1], 'group_index' => 0, 'code' => 'Q2', 'points_possible' => 50],
                ],
            ])->assertRedirect();

            $after = $instrument->groups()->get();

            $this->assertCount(1, $after, 'No second group may be created.');
            $this->assertSame($group->id, $after->first()->id);
            $this->assertSame($group->ulid, $after->first()->ulid);
            $this->assertSame(1, $after->first()->sequence);
            $this->assertNull($after->first()->label);
            $this->assertSame($itemUlids, $instrument->items()->orderBy('sequence')->pluck('ulid')->all());
        });
    }

    #[Test]
    public function saving_three_times_never_adds_a_group(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $builder = app(InstrumentBuilder::class);

            $instrument = $builder->create($class, $this->attributes($class), [
                ['code' => 'Q1', 'points_possible' => 100],
            ]);

            $group = $instrument->groups()->firstOrFail();
            $item = $instrument->items()->firstOrFail();

            foreach (range(1, 3) as $ignored) {
                $builder->update($instrument, $this->attributes($class), [
                    ['ulid' => $item->ulid, 'group_index' => 0, 'code' => 'Q1', 'points_possible' => 100],
                ], [['ulid' => $group->ulid, 'label' => null]]);

                $this->assertSame(1, $instrument->groups()->count());
            }

            $this->assertSame($group->ulid, $instrument->groups()->firstOrFail()->ulid);
        });
    }

    #[Test]
    public function promoting_the_implicit_group_names_it_without_creating_another(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $builder = app(InstrumentBuilder::class);

            $instrument = $builder->create($class, $this->attributes($class), [
                ['code' => 'Q1', 'points_possible' => 100],
            ]);

            $group = $instrument->groups()->firstOrFail();
            $item = $instrument->items()->firstOrFail();

            // "Organizar por grupos/secções" then typing a name.
            $builder->update($instrument, $this->attributes($class), [
                ['ulid' => $item->ulid, 'group_index' => 0, 'code' => 'Q1', 'points_possible' => 100],
            ], [['ulid' => $group->ulid, 'label' => 'Oralidade']]);

            $this->assertSame(1, $instrument->groups()->count());
            $this->assertSame($group->id, $instrument->groups()->firstOrFail()->id);
            $this->assertSame($group->ulid, $instrument->groups()->firstOrFail()->ulid);
            $this->assertSame('Oralidade', $instrument->groups()->firstOrFail()->label);
            $this->assertSame($item->ulid, $instrument->items()->firstOrFail()->ulid);
        });
    }

    #[Test]
    public function a_new_group_is_created_once_and_reused_on_the_next_save(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $builder = app(InstrumentBuilder::class);

            $instrument = $builder->create($class, $this->attributes($class), [
                ['code' => 'Q1', 'points_possible' => 100],
            ], [['label' => 'Oralidade']]);

            $first = $instrument->groups()->firstOrFail();
            $item = $instrument->items()->firstOrFail();

            // Second group arrives without a ulid — it is genuinely new.
            $builder->update($instrument, $this->attributes($class), [
                ['ulid' => $item->ulid, 'group_index' => 0, 'code' => 'Q1', 'points_possible' => 100],
            ], [
                ['ulid' => $first->ulid, 'label' => 'Oralidade'],
                ['label' => 'Gramática'],
            ]);

            $this->assertSame(2, $instrument->groups()->count());
            $created = $instrument->groups()->where('label', 'Gramática')->firstOrFail();

            // Saving again with its ulid must reuse it, not add a third.
            $builder->update($instrument, $this->attributes($class), [
                ['ulid' => $item->ulid, 'group_index' => 0, 'code' => 'Q1', 'points_possible' => 100],
            ], [
                ['ulid' => $first->ulid, 'label' => 'Oralidade'],
                ['ulid' => $created->ulid, 'label' => 'Gramática'],
            ]);

            $this->assertSame(2, $instrument->groups()->count());
            $this->assertSame($created->ulid, $instrument->groups()->where('label', 'Gramática')->firstOrFail()->ulid);
        });
    }

    // ---------------------------------------------------------- reordering

    #[Test]
    public function two_groups_can_swap_places_without_a_unique_collision(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $builder = app(InstrumentBuilder::class);

            $instrument = $builder->create($class, $this->attributes($class), [
                ['group_index' => 0, 'code' => 'Q1', 'points_possible' => 50],
                ['group_index' => 1, 'code' => 'Q1', 'points_possible' => 50],
            ], [['label' => 'A'], ['label' => 'B']]);

            $groups = $instrument->groups()->orderBy('sequence')->get();
            $items = $instrument->items()->orderBy('sequence')->get();

            // B first, A second — the naive implementation wrote A's new
            // sequence 2 while B still held it.
            $builder->update($instrument, $this->attributes($class), [
                ['ulid' => $items[1]->ulid, 'group_index' => 0, 'code' => 'Q1', 'points_possible' => 50],
                ['ulid' => $items[0]->ulid, 'group_index' => 1, 'code' => 'Q1', 'points_possible' => 50],
            ], [
                ['ulid' => $groups[1]->ulid, 'label' => 'B'],
                ['ulid' => $groups[0]->ulid, 'label' => 'A'],
            ]);

            $after = $instrument->groups()->orderBy('sequence')->get();

            $this->assertSame(['B', 'A'], $after->pluck('label')->all());
            $this->assertSame([1, 2], $after->pluck('sequence')->all());
            // Same rows — reordering is not recreation.
            $this->assertSame($groups[1]->ulid, $after[0]->ulid);
            $this->assertSame($groups[0]->ulid, $after[1]->ulid);
        });
    }

    #[Test]
    public function three_groups_can_be_reordered_and_a_new_one_added_at_once(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $builder = app(InstrumentBuilder::class);

            $instrument = $builder->create($class, $this->attributes($class), [
                ['group_index' => 0, 'code' => 'Q1', 'points_possible' => 50],
                ['group_index' => 1, 'code' => 'Q1', 'points_possible' => 50],
            ], [['label' => 'A'], ['label' => 'B']]);

            $groups = $instrument->groups()->orderBy('sequence')->get();
            $items = $instrument->items()->orderBy('sequence')->get();

            // B, then a brand-new C, then A — reorder and create together.
            $builder->update($instrument, $this->attributes($class), [
                ['ulid' => $items[1]->ulid, 'group_index' => 0, 'code' => 'Q1', 'points_possible' => 50],
                ['ulid' => $items[0]->ulid, 'group_index' => 2, 'code' => 'Q1', 'points_possible' => 50],
            ], [
                ['ulid' => $groups[1]->ulid, 'label' => 'B'],
                ['label' => 'C'],
                ['ulid' => $groups[0]->ulid, 'label' => 'A'],
            ]);

            $after = $instrument->groups()->orderBy('sequence')->get();

            $this->assertSame(['B', 'C', 'A'], $after->pluck('label')->all());
            $this->assertSame([1, 2, 3], $after->pluck('sequence')->all());
            $this->assertSame($groups[1]->ulid, $after[0]->ulid);
            $this->assertSame($groups[0]->ulid, $after[2]->ulid);
            $this->assertNotContains($after[1]->ulid, [$groups[0]->ulid, $groups[1]->ulid]);
        });
    }

    #[Test]
    public function a_group_can_be_removed_while_another_takes_its_sequence(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $builder = app(InstrumentBuilder::class);

            $instrument = $builder->create($class, $this->attributes($class), [
                ['group_index' => 1, 'code' => 'Q1', 'points_possible' => 100],
            ], [['label' => 'A'], ['label' => 'B']]);

            $groups = $instrument->groups()->orderBy('sequence')->get();
            $item = $instrument->items()->firstOrFail();

            // A is empty and dropped; B moves up into sequence 1.
            $builder->update($instrument, $this->attributes($class), [
                ['ulid' => $item->ulid, 'group_index' => 0, 'code' => 'Q1', 'points_possible' => 100],
            ], [['ulid' => $groups[1]->ulid, 'label' => 'B']]);

            $after = $instrument->groups()->get();

            $this->assertCount(1, $after);
            $this->assertSame($groups[1]->ulid, $after->first()->ulid);
            $this->assertSame(1, $after->first()->sequence);
        });
    }

    #[Test]
    public function the_migration_left_every_existing_item_in_a_group(): void
    {
        // The backfill runs as part of the test suite's migrations, so this
        // asserts the invariant it must leave behind: no item without a group,
        // and no group without an instrument.
        $this->inTenant(function (): void {
            $class = $this->schoolClass();

            app(InstrumentBuilder::class)->create($class, $this->attributes($class), [
                ['code' => 'Q1', 'points_possible' => 100],
            ]);

            $this->assertSame(0, InstrumentItem::withoutGlobalScopes()->whereNull('instrument_group_id')->count());
            $this->assertSame(
                0,
                InstrumentGroup::withoutGlobalScopes()
                    ->whereNotIn('instrument_id', Instrument::withoutGlobalScopes()->pluck('id'))
                    ->count(),
            );
        });
    }
}
