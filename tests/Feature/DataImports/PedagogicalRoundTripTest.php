<?php

namespace Tests\Feature\DataImports;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\AssessmentProfile;
use App\Models\AssessmentProfileVersion;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\ClassificationStatus;
use App\Models\DataExport;
use App\Models\DataImport;
use App\Models\Domain;
use App\Models\Enrollment;
use App\Models\EvidenceKind;
use App\Models\EvidenceRecord;
use App\Models\Instrument;
use App\Models\InstrumentItem;
use App\Models\InterimAssessment;
use App\Models\Intervention;
use App\Models\InterventionDescriptionSource;
use App\Models\InterventionDomainRelation;
use App\Models\InterventionEffectiveness;
use App\Models\InterventionReview;
use App\Models\InterventionStatus;
use App\Models\InterventionTargetType;
use App\Models\ItemDomainAllocation;
use App\Models\Report;
use App\Models\ResultState;
use App\Models\Scale;
use App\Models\SchoolClass;
use App\Models\SelfAssessment;
use App\Models\SelfAssessmentFilledBy;
use App\Models\SelfAssessmentQuestion;
use App\Models\SelfAssessmentQuestionRole;
use App\Models\SelfAssessmentResponse;
use App\Models\SelfAssessmentStatus;
use App\Models\SelfAssessmentTemplate;
use App\Models\Student;
use App\Models\StudentIdentity;
use App\Models\StudentItemScore;
use App\Models\Subject;
use App\Models\User;
use App\Services\Assessment\ActivateProfileVersion;
use App\Services\Assessment\BuildResultsProgression;
use App\Services\Assessment\CaptureInterimAssessment;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SubscribesOrganizations;
use Tests\TestCase;

class PedagogicalRoundTripTest extends TestCase
{
    use RefreshDatabase;
    use SubscribesOrganizations;

    private User $teacher;

    private function inTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->teacher->personalOrganization(), $callback);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->teacher = User::factory()->create();
    }

    #[Test]
    public function the_complete_pedagogical_graph_restores_the_same_freshly_calculated_progression(): void
    {
        $class = $this->scenario('complete', includePedagogicalRecords: true);
        $originProgression = $this->progression($class);

        $restoredProgression = $this->roundTrip($class);

        $this->assertSame($this->semanticProgression($originProgression), $this->semanticProgression($restoredProgression));

        $student = collect($restoredProgression['students'])->firstWhere('name', 'Ana Completa');
        $period = $student['periods'][0];
        $this->assertSame('70.000000', $period['weighted_average']);
        $this->assertSame('confirmed', $period['classification']['status']);
        $this->assertSame('4', $period['classification']['proposed']['code']);
        $this->assertSame('4', $period['classification']['final']['code']);
        $this->assertSame('4', $period['self_assessment']['code']);
        $this->assertSame('4', $period['domains'][0]['self_assessment']['code']);
    }

    #[Test]
    public function partial_coverage_keeps_a_derived_non_zero_result_after_round_trip(): void
    {
        $class = $this->scenario('partial');
        $originProgression = $this->progression($class);
        $originPeriod = $originProgression['students'][0]['periods'][0];

        $this->assertNotNull($originPeriod['weighted_average']);
        $this->assertNotSame('0.000000', $originPeriod['weighted_average']);
        $this->assertTrue($originPeriod['coverage_warning']);

        $restoredProgression = $this->roundTrip($class);
        $restoredPeriod = $restoredProgression['students'][0]['periods'][0];

        $this->assertSame($this->semanticProgression($originProgression), $this->semanticProgression($restoredProgression));
        $this->assertSame($originPeriod['weighted_average'], $restoredPeriod['weighted_average']);
        $this->assertNotNull($restoredPeriod['weighted_average']);
        $this->assertNotSame('0.000000', $restoredPeriod['weighted_average']);
    }

    #[Test]
    public function no_evaluated_elements_remain_null_instead_of_becoming_zero_after_round_trip(): void
    {
        $class = $this->scenario('empty');
        $originProgression = $this->progression($class);
        $this->assertNull($originProgression['students'][0]['periods'][0]['weighted_average']);

        $restoredProgression = $this->roundTrip($class);

        $this->assertSame($this->semanticProgression($originProgression), $this->semanticProgression($restoredProgression));
        $this->assertNull($restoredProgression['students'][0]['periods'][0]['weighted_average']);
        $this->assertNull($restoredProgression['students'][0]['periods'][0]['accumulated_average']);
    }

    private function scenario(string $scoreMode, bool $includePedagogicalRecords = false): SchoolClass
    {
        return $this->inTenant(function () use ($scoreMode, $includePedagogicalRecords): SchoolClass {
            $organization = $this->teacher->personalOrganization();
            $year = AcademicYear::factory()->recycle($organization)->create([
                'label' => '2025/2026',
                'starts_on' => '2025-09-01',
                'ends_on' => '2026-08-31',
            ]);
            $period = AcademicPeriod::factory()->recycle($organization)->for($year)->create([
                'label' => '1.º Período',
                'sequence' => 1,
                'starts_on' => '2025-09-01',
                'ends_on' => '2025-12-31',
            ]);
            $subject = Subject::factory()->recycle($organization)->create(['name' => 'Matemática']);
            $scale = Scale::where('name', 'Escala 1 a 5')->with('levels')->firstOrFail();
            $profile = AssessmentProfile::factory()->recycle($organization)->create([
                'academic_year_id' => $year->id,
                'subject_id' => $subject->id,
                'name' => 'Perfil de Matemática',
            ]);
            $version = AssessmentProfileVersion::factory()->recycle($organization)->create([
                'assessment_profile_id' => $profile->id,
                'scale_id' => $scale->id,
                'domain_weight_mode' => 'must_total_100',
            ]);
            $domains = collect([
                Domain::factory()->recycle($organization)->create(['subject_id' => $subject->id, 'name' => 'Conhecimento', 'code' => 'CON', 'sequence' => 1]),
                Domain::factory()->recycle($organization)->create(['subject_id' => $subject->id, 'name' => 'Raciocínio', 'code' => 'RAC', 'sequence' => 2]),
            ]);

            foreach ($domains as $index => $domain) {
                $version->domains()->create(['domain_id' => $domain->id, 'weight_percent' => 50, 'sequence' => $index + 1]);
            }
            $version->periods()->create(['academic_period_id' => $period->id, 'contributes_to_accumulated' => true]);
            $version = app(ActivateProfileVersion::class)->activate($version->refresh(), $this->teacher);

            $class = SchoolClass::factory()->recycle($organization)->create([
                'academic_year_id' => $year->id,
                'subject_id' => $subject->id,
                'label' => '7.º A '.$scoreMode,
                'assessment_profile_version_id' => $version->id,
            ]);
            $class->teachers()->attach($this->teacher, ['role' => 'owner']);

            $enrollments = collect($scoreMode === 'complete' ? ['Ana Completa', 'Bruno Completo'] : ['Carla '.$scoreMode])
                ->values()
                ->map(function (string $name, int $index) use ($organization, $class): Enrollment {
                    $student = Student::factory()->recycle($organization)->create();
                    StudentIdentity::create([
                        'student_id' => $student->id,
                        'organization_id' => $organization->id,
                        'display_name' => $name,
                    ]);

                    return Enrollment::factory()->recycle($organization)->create([
                        'class_id' => $class->id,
                        'student_id' => $student->id,
                        'class_number' => $index + 1,
                        'enrolled_on' => '2025-09-01',
                    ]);
                });

            $instrument = Instrument::factory()->recycle($organization)->create([
                'class_id' => $class->id,
                'academic_period_id' => $period->id,
                'title' => 'Ficha de avaliação',
                'applied_on' => '2025-10-15',
                'status' => 'completed',
                'counts_toward_classification' => true,
                'total_points' => 20,
            ]);
            $items = collect([
                InstrumentItem::factory()->recycle($organization)->create(['instrument_id' => $instrument->id, 'code' => 'Q1', 'sequence' => 1, 'points_possible' => 10]),
                InstrumentItem::factory()->recycle($organization)->create(['instrument_id' => $instrument->id, 'code' => 'Q2', 'sequence' => 2, 'points_possible' => 10]),
            ]);

            foreach ($items as $index => $item) {
                ItemDomainAllocation::create(['instrument_item_id' => $item->id, 'domain_id' => $domains[$index]->id, 'allocation_percent' => 100]);
            }

            if ($scoreMode !== 'empty') {
                $points = $scoreMode === 'complete' ? [[8, 6], [5, 9]] : [[8, null]];
                foreach ($enrollments as $studentIndex => $enrollment) {
                    foreach ($items as $itemIndex => $item) {
                        if ($points[$studentIndex][$itemIndex] === null) {
                            continue;
                        }
                        StudentItemScore::create([
                            'instrument_id' => $instrument->id,
                            'instrument_item_id' => $item->id,
                            'enrollment_id' => $enrollment->id,
                            'result_state' => ResultState::Assessed,
                            'points_earned' => $points[$studentIndex][$itemIndex],
                            'assessed_at' => '2025-10-20 12:00:00',
                            'assessed_by' => $this->teacher->id,
                        ]);
                    }
                }
            }

            if ($includePedagogicalRecords) {
                $this->addPedagogicalRecords($class, $period, $version, $domains->all(), $enrollments->all(), $scale);
            }

            return $class->fresh();
        });
    }

    /**
     * @param  list<Domain>  $domains
     * @param  list<Enrollment>  $enrollments
     */
    private function addPedagogicalRecords(SchoolClass $class, AcademicPeriod $period, AssessmentProfileVersion $version, array $domains, array $enrollments, Scale $systemScale): void
    {
        $organization = $this->teacher->personalOrganization();
        $customScale = Scale::factory()->create(['organization_id' => $organization->id, 'name' => 'Observação rápida', 'kind' => 'level', 'min_value' => 1, 'max_value' => 2]);
        $customLevel = $customScale->levels()->create(['code' => 'P', 'label' => 'Progrediu', 'sequence' => 2, 'normalized_value' => 100, 'is_negative' => false]);

        foreach ($enrollments as $enrollment) {
            EvidenceRecord::create([
                'class_id' => $class->id,
                'enrollment_id' => $enrollment->id,
                'academic_period_id' => $period->id,
                'domain_id' => $domains[0]->id,
                'quick_rating_scale_level_id' => $customLevel->id,
                'occurred_at' => '2025-10-21 09:00:00',
                'kind' => EvidenceKind::Note,
                'description' => 'Resolve problemas com autonomia.',
                'created_by' => $this->teacher->id,
            ]);
        }

        $level = $systemScale->levels->firstWhere('code', '4') ?? $systemScale->levels->sortByDesc('sequence')->firstOrFail();
        $template = SelfAssessmentTemplate::create([
            'assessment_profile_version_id' => $version->id,
            'class_id' => $class->id,
            'name' => 'Balanço do período',
            'is_active' => true,
        ]);
        $globalQuestion = SelfAssessmentQuestion::create([
            'self_assessment_template_id' => $template->id,
            'role' => SelfAssessmentQuestionRole::Global,
            'prompt' => 'Como avalias globalmente o teu trabalho?',
            'answer_kind' => 'scale',
            'scale_id' => $systemScale->id,
            'sequence' => 1,
        ]);
        $domainQuestion = SelfAssessmentQuestion::create([
            'self_assessment_template_id' => $template->id,
            'domain_id' => $domains[0]->id,
            'prompt' => 'Como avalias o teu conhecimento?',
            'answer_kind' => 'scale',
            'scale_id' => $systemScale->id,
            'sequence' => 2,
        ]);
        $selfAssessment = SelfAssessment::create([
            'enrollment_id' => $enrollments[0]->id,
            'academic_period_id' => $period->id,
            'self_assessment_template_id' => $template->id,
            'status' => SelfAssessmentStatus::Submitted,
            'filled_by' => SelfAssessmentFilledBy::Student,
            'reflection' => 'Consegui melhorar.',
            'submitted_at' => '2025-12-10 10:00:00',
        ]);
        foreach ([$globalQuestion, $domainQuestion] as $question) {
            SelfAssessmentResponse::create([
                'self_assessment_id' => $selfAssessment->id,
                'self_assessment_question_id' => $question->id,
                'scale_level_id' => $level->id,
            ]);
        }

        Classification::create([
            'enrollment_id' => $enrollments[0]->id,
            'academic_period_id' => $period->id,
            'scope' => ClassificationScope::Period,
            'assessment_profile_version_id' => $version->id,
            'status' => ClassificationStatus::Confirmed,
            'proposed_normalized_value' => 70,
            'proposed_value' => 4,
            'proposed_scale_level_id' => $level->id,
            'final_value' => 4,
            'final_scale_level_id' => $level->id,
            'confirmed_by' => $this->teacher->id,
            'confirmed_at' => '2025-12-15 12:00:00',
        ]);

        $intervention = Intervention::create([
            'class_id' => $class->id,
            'enrollment_id' => $enrollments[0]->id,
            'academic_period_id' => $period->id,
            'domain_id' => $domains[0]->id,
            'target_type' => InterventionTargetType::Student,
            'domain_relation' => InterventionDomainRelation::Specific,
            'title' => 'Treino orientado',
            'description' => 'Resolver dois problemas por semana.',
            'description_source' => InterventionDescriptionSource::Manual,
            'status' => InterventionStatus::InProgress,
            'started_on' => '2025-10-22',
            'include_in_report' => true,
            'available_for_reports' => true,
            'created_by' => $this->teacher->id,
        ]);
        $intervention->participants()->attach($enrollments[0]->id);
        InterventionReview::create([
            'intervention_id' => $intervention->id,
            'reviewed_on' => '2025-11-30',
            'effectiveness' => InterventionEffectiveness::Effective,
            'notes' => 'Maior autonomia.',
            'reviewed_by' => $this->teacher->id,
        ]);

        app(CaptureInterimAssessment::class)->capture(
            $class->fresh(),
            $period,
            Carbon::parse('2025-11-15'),
            $this->teacher,
            ['name' => 'Avaliação intercalar de novembro'],
        );

        Report::factory()->recycle($organization)->finalized()->create([
            'class_id' => $class->id,
            'academic_year_id' => $class->academic_year_id,
            'academic_period_id' => $period->id,
            'title' => 'Relatório final do 1.º período',
            'scope_label' => $period->label,
            'finalized_by' => $this->teacher->id,
            'created_by' => $this->teacher->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function progression(SchoolClass $class): array
    {
        return $this->inTenant(fn (): array => app(BuildResultsProgression::class)->for($class->fresh()));
    }

    /** @return array<string, mixed> */
    private function roundTrip(SchoolClass $class): array
    {
        $organization = $this->teacher->personalOrganization();
        $backup = $this->backupUpload();
        $classUlid = $class->ulid;

        $this->deletePedagogicalData($class);
        $import = $this->uploadInto($backup);
        $this->actingAs($this->teacher)->withSession(['organization_id' => $organization->id])
            ->post("/data-imports/{$import->ulid}/confirm")
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $restoredClass = $this->inTenant(fn (): SchoolClass => SchoolClass::where('ulid', $classUlid)->firstOrFail());

        return $this->progression($restoredClass);
    }

    private function backupUpload(): UploadedFile
    {
        $organization = $this->teacher->personalOrganization();
        $this->actingAs($this->teacher)->withSession(['organization_id' => $organization->id])
            ->post('/data-exports')->assertRedirect();
        $export = DataExport::withoutGlobalScope('organization')->where('requested_by', $this->teacher->id)->latest('id')->firstOrFail();

        return new UploadedFile(Storage::disk('local')->path($export->disk_path), 'backup.zip', 'application/zip', null, true);
    }

    private function uploadInto(UploadedFile $file): DataImport
    {
        $organization = $this->teacher->personalOrganization();

        // The restore wizard is Pro and Institucional since the Base/Pro
        // realignment (Matriz §7, `data_backup_restore`). This test is about
        // what the wizard DOES, so the destination is put on a plan that
        // reaches it — the gate itself is asserted in
        // tests/Feature/DataImports/DataImportEntitlementTest.php.
        if (! app(Entitlements::class)->allowsFor($organization, 'data_backup_restore')) {
            $this->subscribeOrganizationTo($organization, 'pro');
        }

        $this->actingAs($this->teacher)->withSession(['organization_id' => $organization->id])
            ->post('/data-imports', ['file' => $file])
            ->assertSessionHasNoErrors();

        return DataImport::withoutGlobalScope('organization')
            ->where('organization_id', $organization->id)
            ->where('requested_by', $this->teacher->id)
            ->latest('id')
            ->firstOrFail();
    }

    private function deletePedagogicalData(SchoolClass $class): void
    {
        $this->inTenant(function () use ($class): void {
            $enrollments = Enrollment::where('class_id', $class->id)->get();
            $studentIds = $enrollments->pluck('student_id');
            $profileVersionIds = AssessmentProfileVersion::pluck('id');
            $profileIds = AssessmentProfile::pluck('id');
            $customScaleIds = Scale::whereNotNull('organization_id')->pluck('id');

            Report::where('class_id', $class->id)->delete();
            InterimAssessment::where('class_id', $class->id)->delete();
            InterventionReview::query()->delete();
            DB::table('intervention_enrollment')->whereIn('enrollment_id', $enrollments->pluck('id'))->delete();
            Intervention::where('class_id', $class->id)->forceDelete();
            EvidenceRecord::where('class_id', $class->id)->forceDelete();
            SelfAssessmentResponse::query()->delete();
            SelfAssessment::query()->delete();
            SelfAssessmentQuestion::query()->delete();
            SelfAssessmentTemplate::query()->delete();
            Classification::query()->delete();
            DB::table('student_item_scores')->whereIn('enrollment_id', $enrollments->pluck('id'))->delete();
            DB::table('enrollment_instrument_applicability')->whereIn('enrollment_id', $enrollments->pluck('id'))->delete();
            DB::table('item_domain_allocations')->delete();
            InstrumentItem::query()->delete();
            DB::table('instrument_groups')->delete();
            Instrument::where('class_id', $class->id)->forceDelete();

            $class->teachers()->detach();
            DB::table('classes')->where('id', $class->id)->update(['assessment_profile_version_id' => null]);
            Enrollment::where('class_id', $class->id)->delete();
            StudentIdentity::whereIn('student_id', $studentIds)->delete();
            Student::whereIn('id', $studentIds)->delete();
            DB::table('classes')->where('id', $class->id)->delete();

            DB::table('assessment_profiles')->whereIn('id', $profileIds)->update(['current_version_id' => null]);
            DB::table('profile_version_periods')->whereIn('assessment_profile_version_id', $profileVersionIds)->delete();
            DB::table('profile_version_domains')->whereIn('assessment_profile_version_id', $profileVersionIds)->delete();
            DB::table('assessment_profile_versions')->whereIn('id', $profileVersionIds)->delete();
            DB::table('assessment_profiles')->whereIn('id', $profileIds)->delete();
            Domain::query()->delete();
            DB::table('scale_levels')->whereIn('scale_id', $customScaleIds)->delete();
            Scale::whereIn('id', $customScaleIds)->delete();
            AcademicPeriod::query()->delete();
        });
    }

    /** @return array<string, mixed> */
    private function semanticProgression(array $progression): array
    {
        $domainNames = collect($progression['domains'])->mapWithKeys(fn (array $domain): array => [$domain['id'] => $domain['name']]);

        foreach ($progression['students'] as &$student) {
            foreach ($student['periods'] as &$period) {
                foreach ($period['domains'] as &$domain) {
                    $domain['name'] = $domainNames[$domain['domain_id']];
                }
                usort($period['domains'], fn (array $left, array $right): int => $left['name'] <=> $right['name']);
            }
        }
        unset($student, $period, $domain);

        usort($progression['students'], fn (array $left, array $right): int => $left['name'] <=> $right['name']);
        $progression['domains'] = collect($progression['domains'])->pluck('name')->sort()->values()->all();

        return $this->stripDatabaseIdentity($progression);
    }

    private function stripDatabaseIdentity(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            // IDENTIDADE FORA, SEMÂNTICA DENTRO. Um clone noutra organização é
            // outra linha, com outro id e outro ulid, e TEM de o ser — o que
            // este teste compara é o que a leitura diz, não onde ela está
            // guardada. `*_ulid` entra nesta lista pela mesma razão que `*_id`:
            // um ulid de chave estrangeira é identidade tanto como o inteiro que
            // substitui, e deixá-lo passar faz o teste falhar no dia em que uma
            // leitura passe a levar mais um — que é exatamente o que aconteceu
            // quando o Quadro Síntese ganhou o `enrollment_ulid` de que precisa
            // para endereçar a decomposição de um acumulado.
            if (is_string($key) && ($key === 'ulid' || $key === 'id' || str_ends_with($key, '_id') || str_ends_with($key, '_ulid'))) {
                continue;
            }
            $normalized[$key] = $this->stripDatabaseIdentity($item);
        }

        return $normalized;
    }
}
