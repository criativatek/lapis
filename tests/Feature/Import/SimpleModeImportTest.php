<?php

namespace Tests\Feature\Import;

use App\Domain\Import\Correction\CorrectionGridSource;
use App\Domain\Import\Correction\ImportMapping;
use App\Domain\Import\Correction\OverallResultItem;
use App\Models\CorrectionImport;
use App\Models\CorrectionImportStatus;
use App\Models\Domain;
use App\Models\Enrollment;
use App\Models\Instrument;
use App\Models\InstrumentItem;
use App\Models\InstrumentStatus;
use App\Models\InstrumentType;
use App\Models\ItemDomainAllocation;
use App\Models\ResultState;
use App\Models\StudentItemScore;
use App\Services\Assessment\InstrumentBuilder;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;

/**
 * Importing the classification the platform already worked out.
 *
 * A teacher who ran a quiz on Plickers has a number for every student before
 * Lapispro is opened at all. The old flow made them reconstruct twenty cotações
 * and twenty answer keys before it would accept that number — work whose entire
 * output was a figure that already existed, and which produced a DIFFERENT
 * figure the moment any question was worth more than another.
 *
 * So the ordinary path takes `source_score` and records it as one result. That
 * is not a new shape in the model: §4.2 of the domain model already decided that
 * anything assessed directly rather than question by question is an instrument
 * with a single item allocated to a domain, and gives an oral observation as the
 * example. A Plickers percentage is the same thing arriving from elsewhere.
 *
 * What these tests defend is that the simple path stays simple (nothing about
 * cotações or question structure may creep back into it), that the detailed path
 * is still there and still strict, and that the two never blend.
 */
class SimpleModeImportTest extends CorrectionImportHttpTest
{
    protected function importReady(): CorrectionImport
    {
        $import = $this->upload();
        $this->actingAs($this->teacher)->patch("/imports/correction/{$import->ulid}", $this->overallMapping());

        return $import;
    }

    protected function confirmed(): Instrument
    {
        $import = $this->importReady();
        $this->actingAs($this->teacher)->post("/imports/correction/{$import->ulid}/confirm")->assertRedirect();

        return app(CurrentOrganization::class)->runFor($this->organization, fn () => Instrument::firstOrFail());
    }

    /**
     * @return array<string, string> student name => points_earned, as stored
     */
    protected function marks(Instrument $instrument): array
    {
        return app(CurrentOrganization::class)->runFor($this->organization, function () use ($instrument): array {
            $byNumber = Enrollment::where('class_id', $this->class->id)->pluck('class_number', 'id')->all();

            $marks = [];

            foreach (StudentItemScore::where('instrument_id', $instrument->getKey())->get() as $score) {
                $marks['aluno:'.$byNumber[$score->enrollment_id]] = (string) $score->points_earned;
            }

            return $marks;
        });
    }

    // ------------------------------------------------- 1. source_score is used

    #[Test]
    public function the_simple_mode_records_the_score_the_platform_produced(): void
    {
        $instrument = $this->confirmed();

        // Ana scored 100%, Bruno 33%. Those are the numbers, unchanged: the item
        // is worth 100 precisely so that a percentage needs no conversion.
        $this->assertSame(
            ['aluno:1' => '100.0000', 'aluno:2' => '33.0000'],
            $this->marks($instrument),
        );

        app(CurrentOrganization::class)->runFor($this->organization, function (): void {
            $this->assertSame('100.0000', (string) Instrument::firstOrFail()->total_points);
        });
    }

    #[Test]
    public function the_questions_are_not_re_added_up_behind_the_teachers_back(): void
    {
        // Bruno got 1 of 3 right. Recomputed from the questions at any uniform
        // cotação that is 33.33…%, and at a non-uniform one it is something else
        // again. The file says 33% and 33% is what is recorded (§8).
        $instrument = $this->confirmed();

        $this->assertSame('33.0000', $this->marks($instrument)['aluno:2']);
    }

    // --------------------------------------- 2, 3. nothing else is demanded

    #[Test]
    public function the_simple_mode_asks_for_no_cotacao_at_all(): void
    {
        $import = $this->upload();
        $mapping = $this->overallMapping();

        // Not merely empty — absent. The wizard never collects it in this mode.
        $this->assertArrayNotHasKey('points', $mapping);

        $this->actingAs($this->teacher)->patch("/imports/correction/{$import->ulid}", $mapping);

        app(CurrentOrganization::class)->runFor($this->organization, function () use ($import): void {
            $this->assertSame(CorrectionImportStatus::Ready, $import->fresh()->status);
        });

        $this->actingAs($this->teacher)->post("/imports/correction/{$import->ulid}/confirm")->assertRedirect();
    }

    #[Test]
    public function the_simple_mode_asks_for_no_question_structure(): void
    {
        $mapping = $this->overallMapping();

        foreach (['items', 'domains', 'points'] as $perQuestion) {
            $this->assertArrayNotHasKey($perQuestion, $mapping);
        }

        $instrument = $this->confirmed();

        app(CurrentOrganization::class)->runFor($this->organization, function () use ($instrument): void {
            $items = InstrumentItem::where('instrument_id', $instrument->getKey())->get();

            // One item, not the file's three. The correction grid a teacher
            // opens after this shows one column, which is exactly what they
            // imported (§4.2).
            $this->assertCount(1, $items);
            $this->assertSame(OverallResultItem::CODE, $items->first()->code);
            $this->assertSame(OverallResultItem::LABEL, $items->first()->label);
            $this->assertSame('100.0000', (string) $items->first()->points_possible);
        });
    }

    // ------------------------------------------------------------ 4. domain

    #[Test]
    public function the_global_result_becomes_evidence_for_the_chosen_domain(): void
    {
        $instrument = $this->confirmed();

        app(CurrentOrganization::class)->runFor($this->organization, function () use ($instrument): void {
            $item = InstrumentItem::where('instrument_id', $instrument->getKey())->firstOrFail();
            $allocations = ItemDomainAllocation::where('instrument_item_id', $item->getKey())->get();

            // One allocation, 100%, to the domain the teacher picked. That is
            // what makes the engine count this result toward that domain, and
            // it is the whole reason the field is asked for (§6).
            $this->assertCount(1, $allocations);
            $this->assertSame(Domain::query()->firstOrFail()->id, $allocations->first()->domain_id);
            $this->assertSame('100.0000', (string) $allocations->first()->allocation_percent);
        });
    }

    #[Test]
    public function a_result_that_counts_may_not_be_imported_without_a_domain(): void
    {
        $import = $this->upload();
        $mapping = $this->overallMapping();
        $mapping['overall_domains'] = [];

        $this->actingAs($this->teacher)->patch("/imports/correction/{$import->ulid}", $mapping);

        app(CurrentOrganization::class)->runFor($this->organization, function () use ($import): void {
            // Allowed by the model — an item with no allocation is legitimate —
            // and refused here, because a classification that counts toward
            // nothing is a silent nothing (§6).
            $this->assertSame(CorrectionImportStatus::NeedsMapping, $import->fresh()->status);
        });

        $this->assertContains(
            'Selecione o domínio avaliado. É ele que diz para onde conta este resultado.',
            $this->messages($import),
        );
    }

    #[Test]
    public function a_result_that_does_not_count_needs_no_domain(): void
    {
        $import = $this->upload();
        $mapping = $this->overallMapping();
        $mapping['overall_domains'] = [];
        $mapping['instrument']['counts_toward_classification'] = false;

        $this->actingAs($this->teacher)->patch("/imports/correction/{$import->ulid}", $mapping);

        app(CurrentOrganization::class)->runFor($this->organization, function () use ($import): void {
            // Nothing depends on the domain here, so demanding it would be
            // paperwork (§4.3 allows an item with no allocation at all).
            $this->assertSame(CorrectionImportStatus::Ready, $import->fresh()->status);
        });
    }

    // ------------------------------------------- 5, 6. null is not zero

    #[Test]
    public function a_student_with_no_score_gets_no_mark_rather_than_a_zero(): void
    {
        $instrument = $this->confirmed();

        app(CurrentOrganization::class)->runFor($this->organization, function () use ($instrument): void {
            $carla = Enrollment::where('class_id', $this->class->id)->where('class_number', 3)->firstOrFail();

            // Carla's row in the file is «-» throughout. There is no mark for
            // her, and «no mark» is the absence of a row — not a zero, and not
            // a stored blank (§3, §13.3).
            $this->assertSame(2, StudentItemScore::where('instrument_id', $instrument->getKey())->count());
            $this->assertFalse(
                StudentItemScore::where('instrument_id', $instrument->getKey())
                    ->where('enrollment_id', $carla->getKey())
                    ->exists(),
            );
        });
    }

    #[Test]
    public function not_taking_part_is_never_turned_into_an_absence(): void
    {
        $instrument = $this->confirmed();

        app(CurrentOrganization::class)->runFor($this->organization, function () use ($instrument): void {
            $states = StudentItemScore::where('instrument_id', $instrument->getKey())
                ->get()->map(fn (StudentItemScore $score): string => $score->result_state->value)
                ->unique()->values()->all();

            // Every mark written is an assessed one. An absence is an event a
            // teacher records; a dash in an export is not evidence of one.
            $this->assertSame([ResultState::Assessed->value], $states);
        });
    }

    #[Test]
    public function a_zero_percent_is_a_mark_and_is_written(): void
    {
        // The other side of the same rule: a determined zero IS a result, and
        // dropping it would be as wrong as inventing one.
        $import = $this->analyseThirty();

        $mapping = $this->thirtyMapping($import);
        $this->actingAs($this->teacher)->patch("/imports/correction/{$import->ulid}", $mapping);
        $this->actingAs($this->teacher)->post("/imports/correction/{$import->ulid}/confirm")->assertRedirect();

        app(CurrentOrganization::class)->runFor($this->organization, function (): void {
            $instrument = Instrument::firstOrFail();
            $marks = StudentItemScore::where('instrument_id', $instrument->getKey())
                ->pluck('points_earned')->map(fn ($value): string => (string) $value)->all();

            // Adriana scored 0%; she is in, at zero.
            $this->assertContains('0.0000', $marks);
        });
    }

    // ------------------------------------------------- 7. provenance survives

    #[Test]
    public function the_platforms_own_result_is_kept_after_the_file_is_gone(): void
    {
        $import = $this->importReady();
        $this->actingAs($this->teacher)->post("/imports/correction/{$import->ulid}/confirm");

        app(CurrentOrganization::class)->runFor($this->organization, function () use ($import): void {
            $snapshot = $import->fresh()->canonical_snapshot;
            $rows = collect($snapshot['student_results']);

            $this->assertSame(ImportMapping::RESULT_OVERALL, $snapshot['result_mode']);
            $this->assertCount(3, $rows);

            // «Resultado na plataforma: 100%» stays answerable for as long as
            // the mark does, which is what §5 asks for.
            $this->assertSame(['100%', '33%', null], $rows->pluck('source_score')->all());
            $this->assertSame(['100', '33', null], $rows->pluck('source_percent')->all());

            // And still minimised: the mapping says who is who, so the source's
            // own names are not kept a second time (§10 of the privacy brief).
            $this->assertTrue($snapshot['students_minimised']);
            $this->assertStringNotContainsString('Ana Exemplo', (string) json_encode($snapshot));
        });
    }

    // --------------------------------------- 8, 9. the detailed mode survives

    #[Test]
    public function the_detailed_mode_is_still_available_and_still_works(): void
    {
        $import = $this->upload();
        $this->actingAs($this->teacher)->patch("/imports/correction/{$import->ulid}", $this->completeMapping());
        $this->actingAs($this->teacher)->post("/imports/correction/{$import->ulid}/confirm")->assertRedirect();

        app(CurrentOrganization::class)->runFor($this->organization, function (): void {
            $instrument = Instrument::firstOrFail();

            // Three questions, six marks: unchanged from before this rewrite.
            $this->assertSame(3, InstrumentItem::where('instrument_id', $instrument->getKey())->count());
            $this->assertSame(6, StudentItemScore::where('instrument_id', $instrument->getKey())->count());
        });
    }

    #[Test]
    public function the_detailed_mode_still_demands_the_cotacao_it_genuinely_needs(): void
    {
        $import = $this->upload();
        $mapping = $this->completeMapping();
        unset($mapping['points']['item:8']);

        $this->actingAs($this->teacher)->patch("/imports/correction/{$import->ulid}", $mapping);

        app(CurrentOrganization::class)->runFor($this->organization, function () use ($import): void {
            $this->assertSame(CorrectionImportStatus::NeedsMapping, $import->fresh()->status);
        });

        // Counted, not merely reported: «existem 3» and «existe 1» are
        // different amounts of work for the teacher (§11).
        $this->assertContains('Existe 1 pergunta sem cotação.', $this->messages($import));
    }

    #[Test]
    public function the_two_modes_are_not_blended(): void
    {
        $import = $this->upload();

        // A mapping carrying BOTH sets of decisions. The stored result mode is
        // what decides, and the other half is simply not consulted (§8).
        $mapping = array_merge($this->completeMapping(), [
            'result_mode' => ImportMapping::RESULT_OVERALL,
            'overall_domains' => [['domain_id' => Domain::query()->firstOrFail()->id, 'allocation_percent' => '100']],
        ]);

        $this->actingAs($this->teacher)->patch("/imports/correction/{$import->ulid}", $mapping);
        $this->actingAs($this->teacher)->post("/imports/correction/{$import->ulid}/confirm")->assertRedirect();

        app(CurrentOrganization::class)->runFor($this->organization, function (): void {
            $instrument = Instrument::firstOrFail();

            $this->assertSame(1, InstrumentItem::where('instrument_id', $instrument->getKey())->count());
            $this->assertSame(2, StudentItemScore::where('instrument_id', $instrument->getKey())->count());
        });
    }

    // -------------------------------------------- 10. messages name the field

    #[Test]
    public function a_missing_field_is_named_rather_than_alluded_to(): void
    {
        $import = $this->upload();
        $mapping = $this->overallMapping();
        $mapping['instrument']['applied_on'] = '';
        $mapping['instrument']['instrument_type_id'] = null;

        $this->actingAs($this->teacher)->patch("/imports/correction/{$import->ulid}", $mapping);

        $messages = $this->messages($import);

        $this->assertContains('Indique a data de aplicação.', $messages);
        $this->assertContains('Selecione o tipo de avaliação.', $messages);

        // And never the old catch-all, which named nothing and was wrong about
        // the cotação in a mode that has none (§11).
        foreach ($messages as $message) {
            $this->assertStringNotContainsString('Falta preencher a configuração', $message);
            $this->assertStringNotContainsString('cotação', $message);
        }
    }

    #[Test]
    public function the_period_is_offered_by_the_wizard_at_all(): void
    {
        $import = $this->upload();

        $this->actingAs($this->teacher)
            ->get("/imports/correction/{$import->ulid}")
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                // The exact reason the Save button could never be pressed: an
                // instrument needs an academic_period_id, readiness quite
                // correctly refused without one, and the wizard had no way to
                // state it — no select, and no periods in the catalogue (§10).
                $periods = $page->toArray()['props']['catalogue']['periods'];

                $this->assertNotEmpty($periods);
                $this->assertArrayHasKey('starts_on', $periods[0]);
                $this->assertArrayHasKey('ends_on', $periods[0]);
            });

        $wizard = $this->componentSource('resources/js/pages/imports/correction/Wizard.vue');

        $this->assertStringContainsString('id="i-period"', $wizard);
        $this->assertStringContainsString('Determinado pela data de aplicação.', $wizard);
    }

    #[Test]
    public function a_uniform_value_takes_effect_without_a_separate_button(): void
    {
        $wizard = $this->componentSource('resources/js/pages/imports/correction/Wizard.vue');

        // Filled box + disabled button + no explanation was the state the smoke
        // got stuck in. The value now applies when the field is committed (§10).
        $this->assertStringContainsString('@change="applyUniformPoints"', $wizard);
        $this->assertStringContainsString('@change="applyUniformDomain"', $wizard);
        $this->assertStringNotContainsString('Aplicar às {{ preview.counts.questions }} perguntas', $wizard);
        $this->assertStringNotContainsString('Aplicar a todas', $wizard);
    }

    #[Test]
    public function the_detail_of_the_questions_is_opt_in_and_off_by_default(): void
    {
        $wizard = $this->componentSource('resources/js/pages/imports/correction/Wizard.vue');

        // The checkbox became a three-way choice when the grouped granularity
        // arrived, but the claim is unchanged: the question-by-question detail
        // is one of the options and is never where a Plickers import starts.
        $this->assertStringContainsString('O que importar deste ficheiro', $wizard);
        $this->assertStringContainsString('Detalhe por perguntas', $wizard);
        $this->assertStringContainsString('Cada pergunta com a sua cotação e o seu domínio.', $wizard);

        $import = $this->upload();

        $this->actingAs($this->teacher)
            ->get("/imports/correction/{$import->ulid}")
            ->assertInertia(function (AssertableInertia $page): void {
                // Off by default means the SERVER says so — the interface merely
                // renders it. A default that lived only in the component would
                // be one refresh away from disagreeing.
                $this->assertSame(ImportMapping::RESULT_OVERALL, $page->toArray()['props']['preview']['result_mode']);
            });
    }

    // ------------------------------------------ 11, 14. associate an existing

    #[Test]
    public function a_global_result_can_land_on_an_existing_evaluation(): void
    {
        $target = app(CurrentOrganization::class)->runFor($this->organization, fn (): Instrument => app(InstrumentBuilder::class)->create(
            $this->class,
            [
                'academic_period_id' => $this->period->id,
                'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
                'title' => 'Ficha já existente',
                'applied_on' => now()->subDays(3)->toDateString(),
                'status' => InstrumentStatus::InCorrection->value,
                'counts_toward_classification' => true,
                'purpose' => 'formative',
                'total_points' => '20',
            ],
            [['code' => 'G', 'label' => 'Global', 'points_possible' => 20.0, 'group_index' => 0, 'domains' => []]],
        ));

        $itemId = app(CurrentOrganization::class)->runFor(
            $this->organization,
            fn (): int => (int) InstrumentItem::where('instrument_id', $target->getKey())->firstOrFail()->id,
        );

        $import = $this->upload();
        $mapping = $this->overallMapping();
        $mapping['mode'] = ImportMapping::MODE_ASSOCIATE;
        $mapping['instrument_id'] = $target->getKey();
        $mapping['overall_item_id'] = $itemId;
        unset($mapping['instrument'], $mapping['overall_domains']);

        $this->actingAs($this->teacher)->patch("/imports/correction/{$import->ulid}", $mapping);
        $this->actingAs($this->teacher)->post("/imports/correction/{$import->ulid}/confirm")->assertRedirect();

        app(CurrentOrganization::class)->runFor($this->organization, function () use ($target): void {
            // The destination's cotação decides: 100% of 20 is 20, 33% is 6.6.
            // Nothing the teacher configured is rewritten on the way past (§24).
            $marks = StudentItemScore::where('instrument_id', $target->getKey())
                ->orderBy('enrollment_id')->pluck('points_earned')->map(fn ($v): string => (string) $v)->all();

            $this->assertSame(['20.0000', '6.6000'], $marks);
            $this->assertSame(1, Instrument::count(), 'Não pode nascer uma avaliação nova.');
        });
    }

    #[Test]
    public function a_finished_correction_is_never_written_to_silently(): void
    {
        $target = app(CurrentOrganization::class)->runFor($this->organization, fn (): Instrument => app(InstrumentBuilder::class)->create(
            $this->class,
            [
                'academic_period_id' => $this->period->id,
                'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
                'title' => 'Ficha concluída',
                'applied_on' => now()->subDays(3)->toDateString(),
                'status' => InstrumentStatus::Completed->value,
                'counts_toward_classification' => true,
                'purpose' => 'formative',
                'total_points' => '20',
            ],
            [['code' => 'G', 'points_possible' => 20.0, 'group_index' => 0, 'domains' => []]],
        ));

        $import = $this->upload();
        $mapping = $this->overallMapping();
        $mapping['mode'] = ImportMapping::MODE_ASSOCIATE;
        $mapping['instrument_id'] = $target->getKey();
        unset($mapping['instrument'], $mapping['overall_domains']);

        $this->actingAs($this->teacher)->patch("/imports/correction/{$import->ulid}", $mapping);

        app(CurrentOrganization::class)->runFor($this->organization, function () use ($import, $target): void {
            $this->assertSame(CorrectionImportStatus::NeedsMapping, $import->fresh()->status);

            // A completed correction is not even offered as a destination, so
            // the id resolves to nothing and no mark is written (§14).
            $this->assertSame(0, StudentItemScore::where('instrument_id', $target->getKey())->count());
        });
    }

    // ------------------------------------------------------------- helpers

    /**
     * The blocking messages the preview is currently showing.
     *
     * @return list<string>
     */
    protected function messages(CorrectionImport $import): array
    {
        $preview = $this->actingAs($this->teacher)
            ->get("/imports/correction/{$import->ulid}")
            ->viewData('page')['props']['preview'];

        return array_values(array_map(
            fn (array $issue): string => $issue['message'],
            array_filter($preview['issues'], fn (array $issue): bool => $issue['severity'] === 'error'),
        ));
    }

    protected function analyseThirty(): CorrectionImport
    {
        Storage::fake('local');

        $this->actingAs($this->teacher)->post('/imports/correction', [
            'class_id' => $this->class->id,
            'source' => CorrectionGridSource::Plickers->value,
            'file' => $this->fixture('plickers-trinta-alunos.csv'),
        ])->assertRedirect();

        return app(CurrentOrganization::class)->runFor($this->organization, fn () => CorrectionImport::latest('id')->firstOrFail());
    }

    /**
     * The thirty-student file against a three-student class: three rows matched,
     * the rest explicitly left out. Enough to prove the arithmetic without
     * inventing twenty-seven enrolments.
     *
     * @return array<string, mixed>
     */
    protected function thirtyMapping(CorrectionImport $import): array
    {
        $mapping = $this->overallMapping();

        $students = app(CurrentOrganization::class)->runFor($this->organization, function (): array {
            $enrollments = Enrollment::where('class_id', $this->class->id)->orderBy('class_number')->pluck('id')->all();

            // Cards 1, 2 and 3: 0%, 25% and 50%.
            return ['student:1' => $enrollments[0], 'student:2' => $enrollments[1], 'student:3' => $enrollments[2]];
        });

        for ($card = 4; $card <= 30; $card++) {
            $students['student:'.$card] = null;
        }

        $mapping['students'] = $students;

        return $mapping;
    }
}
