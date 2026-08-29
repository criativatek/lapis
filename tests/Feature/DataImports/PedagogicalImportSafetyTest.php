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
use App\Models\Intervention;
use App\Models\InterventionDescriptionSource;
use App\Models\InterventionDomainRelation;
use App\Models\InterventionStatus;
use App\Models\InterventionTargetType;
use App\Models\ItemDomainAllocation;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
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
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Assessment\ActivateProfileVersion;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SubscribesOrganizations;
use Tests\TestCase;

class PedagogicalImportSafetyTest extends TestCase
{
    use RefreshDatabase;
    use SubscribesOrganizations;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    #[Test]
    public function importing_the_same_full_pedagogical_backup_twice_creates_no_new_pedagogical_rows(): void
    {
        $teacher = User::factory()->create();
        $organization = $teacher->personalOrganization();
        $class = $this->fullScenario($organization, $teacher);
        $file = $this->backupUpload($organization, $teacher);
        $this->deletePedagogicalData($organization, $class);

        $this->confirm($organization, $teacher, $this->uploadInto($organization, $teacher, $file));
        $countsAfterFirstImport = $this->pedagogicalCounts($organization);

        $secondImport = $this->uploadInto($organization, $teacher, $file);
        $this->actingAs($teacher)->withSession(['organization_id' => $organization->id])
            ->get("/data-imports/{$secondImport->ulid}")
            ->assertInertia(fn ($page) => $page
                ->where('plan.counts.instruments.new', 0)
                ->where('plan.counts.instrument_items.new', 0)
                ->where('plan.counts.student_item_scores.new', 0)
                ->where('plan.counts.classifications.new', 0)
                ->where('plan.counts.self_assessments.new', 0)
                ->where('plan.counts.evidence_records.new', 0)
                ->where('plan.counts.interventions.new', 0)
                ->where('plan.counts.reports.new', 0)
                ->where('plan.can_confirm', false));

        $this->assertSame($countsAfterFirstImport, $this->pedagogicalCounts($organization));
    }

    #[Test]
    public function a_locally_edited_domain_is_a_conflict_on_reimport_and_is_never_overwritten(): void
    {
        $teacher = User::factory()->create();
        $organization = $teacher->personalOrganization();
        $class = $this->fullScenario($organization, $teacher);
        $file = $this->backupUpload($organization, $teacher);
        $this->deletePedagogicalData($organization, $class);
        $this->confirm($organization, $teacher, $this->uploadInto($organization, $teacher, $file));

        $domain = $this->inTenant($organization, fn (): Domain => Domain::where('code', 'CON')->firstOrFail());
        $domain->update(['name' => 'Conhecimento editado localmente']);
        $secondImport = $this->uploadInto($organization, $teacher, $file);

        $this->actingAs($teacher)->withSession(['organization_id' => $organization->id])
            ->get("/data-imports/{$secondImport->ulid}")
            ->assertInertia(fn ($page) => $page
                ->where('plan.counts.domains.conflict', 1)
                ->where('plan.counts.domains.new', 0));
        $this->confirm($organization, $teacher, $secondImport);

        $this->assertSame('Conhecimento editado localmente', $domain->fresh()->name);
    }

    #[Test]
    public function a_database_failure_during_the_new_tiers_rolls_back_the_entire_import(): void
    {
        $teacher = User::factory()->create();
        $organization = $teacher->personalOrganization();
        $class = $this->fullScenario($organization, $teacher);
        $file = $this->backupUpload($organization, $teacher);
        $this->deletePedagogicalData($organization, $class);
        $import = $this->uploadInto($organization, $teacher, $file);

        $snapshot = $import->canonical_snapshot;
        $duplicate = $snapshot['classifications'][0];
        $snapshot['classifications'][] = $duplicate;
        $import->forceFill(['canonical_snapshot' => $snapshot])->save();

        $this->actingAs($teacher)->withSession(['organization_id' => $organization->id])
            ->post("/data-imports/{$import->ulid}/confirm")
            ->assertSessionHasErrors('import');

        $this->assertSame('failed', $import->fresh()->status->value);
        $this->assertSame(0, $this->tenantCount(Instrument::class, $organization));
        $this->assertSame(0, $this->tenantCount(InstrumentItem::class, $organization));
        $this->assertSame(0, $this->tenantCount(Classification::class, $organization));
    }

    #[Test]
    public function restoring_new_pedagogical_domains_into_one_organization_never_touches_another(): void
    {
        $teacherA = User::factory()->create();
        $organizationA = $teacherA->personalOrganization();
        $class = $this->fullScenario($organizationA, $teacherA);
        $file = $this->backupUpload($organizationA, $teacherA);

        $teacherB = User::factory()->create();
        $organizationB = $teacherB->personalOrganization();
        $this->inTenant($organizationB, function () use ($organizationB): void {
            $subject = Subject::factory()->recycle($organizationB)->create(['name' => 'Matemática']);
            Domain::factory()->recycle($organizationB)->create(['subject_id' => $subject->id, 'name' => 'Conhecimento', 'code' => 'CON']);
            Scale::factory()->create(['organization_id' => $organizationB->id, 'name' => 'Observação rápida']);
        });
        $before = $this->pedagogicalCounts($organizationB);

        $this->deletePedagogicalData($organizationA, $class);
        $this->confirm($organizationA, $teacherA, $this->uploadInto($organizationA, $teacherA, $file));

        $this->assertSame($before, $this->pedagogicalCounts($organizationB));
        $this->assertSame(1, $this->tenantCount(Instrument::class, $organizationA));
        $this->assertSame(1, $this->tenantCount(Classification::class, $organizationA));
    }

    #[Test]
    public function an_institutional_owner_does_not_gain_access_to_imported_colleague_pedagogical_data(): void
    {
        [$organization, $owner] = $this->institutionalOrganization();
        $teacher = $this->member($organization);
        $class = $this->fullScenario($organization, $teacher);
        $file = $this->backupUpload($organization, $teacher);
        $this->deletePedagogicalData($organization, $class);

        $import = $this->uploadInto($organization, $owner, $file);
        $this->confirm($organization, $owner, $import);

        $restoredClass = SchoolClass::withoutGlobalScope('organization')->where('organization_id', $organization->id)->firstOrFail();
        $this->assertFalse($restoredClass->teachers()->exists());
        $this->assertFalse(Gate::forUser($owner)->allows('view', $restoredClass));
        $this->assertSame(1, $this->tenantCount(Classification::class, $organization));
        $this->assertSame(0, $this->tenantCount(EvidenceRecord::class, $organization));
        $this->assertSame(1, $import->fresh()->summary['classifications_created']);
    }

    #[Test]
    public function authors_are_mapped_only_to_the_matching_confirming_user_and_are_never_invented(): void
    {
        $teacherA = User::factory()->create(['email' => 'teacher-a@example.test']);
        $source = $teacherA->personalOrganization();
        $class = $this->fullScenario($source, $teacherA);
        $file = $this->backupUpload($source, $teacherA);
        $this->deletePedagogicalData($source, $class);

        $teacherB = User::factory()->create(['email' => 'teacher-b@example.test']);
        $destination = $teacherB->personalOrganization();
        $this->seedMatchingStructure($destination);
        $importAsB = $this->uploadInto($destination, $teacherB, $file);
        $this->actingAs($teacherB)->withSession(['organization_id' => $destination->id])
            ->get("/data-imports/{$importAsB->ulid}")
            ->assertInertia(fn ($page) => $page
                ->where('plan.counts.student_item_scores.new', 2)
                ->where('plan.counts.classifications.new', 1)
                ->where('plan.counts.evidence_records.invalid', 1));
        $this->confirm($destination, $teacherB, $importAsB);

        $scoreAsB = StudentItemScore::withoutGlobalScope('organization')->where('organization_id', $destination->id)->firstOrFail();
        $classificationAsB = Classification::withoutGlobalScope('organization')->where('organization_id', $destination->id)->firstOrFail();
        $this->assertNull($scoreAsB->assessed_by);
        $this->assertNull($classificationAsB->confirmed_by);
        $this->assertNotSame($teacherB->id, $scoreAsB->assessed_by);
        $this->assertSame(0, $this->tenantCount(EvidenceRecord::class, $destination));

        $restoredClass = SchoolClass::withoutGlobalScope('organization')->where('organization_id', $destination->id)->firstOrFail();
        $this->deletePedagogicalData($destination, $restoredClass);
        $importAsA = $this->uploadInto($source, $teacherA, $file);
        $this->confirm($source, $teacherA, $importAsA);

        $scoreAsA = StudentItemScore::withoutGlobalScope('organization')->where('organization_id', $source->id)->firstOrFail();
        $evidenceAsA = EvidenceRecord::withoutGlobalScope('organization')->where('organization_id', $source->id)->firstOrFail();
        $this->assertSame($teacherA->id, $scoreAsA->assessed_by);
        $this->assertSame($teacherA->id, $evidenceAsA->created_by);
    }

    #[Test]
    public function an_unknown_scale_reference_invalidates_only_the_affected_item_and_its_dependents(): void
    {
        $teacher = User::factory()->create();
        $organization = $teacher->personalOrganization();
        $class = $this->fullScenario($organization, $teacher);
        $file = $this->backupUpload($organization, $teacher);
        $this->deletePedagogicalData($organization, $class);
        $import = $this->uploadInto($organization, $teacher, $file);

        $snapshot = $import->canonical_snapshot;
        $snapshot['instrument_items'][0]['scale'] = [
            'ulid' => (string) Str::ulid(),
            'name' => 'Escala inexistente',
            'is_system' => false,
        ];
        $import->forceFill(['canonical_snapshot' => $snapshot])->save();

        $this->actingAs($teacher)->withSession(['organization_id' => $organization->id])
            ->get("/data-imports/{$import->ulid}")
            ->assertInertia(fn ($page) => $page
                ->where('plan.counts.instrument_items.invalid', 1)
                ->where('plan.counts.instrument_items.new', 1)
                ->where('plan.counts.item_domain_allocations.invalid', 1)
                ->where('plan.counts.student_item_scores.invalid', 1));
        $this->confirm($organization, $teacher, $import);

        $this->assertSame(1, $this->tenantCount(Instrument::class, $organization));
        $this->assertSame(1, $this->tenantCount(InstrumentItem::class, $organization));
        $this->assertSame(1, $this->tenantCount(StudentItemScore::class, $organization));
        $this->assertSame(1, $this->tenantCount(Classification::class, $organization));
    }

    private function fullScenario(Organization $organization, User $teacher): SchoolClass
    {
        return $this->inTenant($organization, function () use ($organization, $teacher): SchoolClass {
            $year = AcademicYear::factory()->recycle($organization)->create([
                'label' => '2025/2026', 'starts_on' => '2025-09-01', 'ends_on' => '2026-08-31',
            ]);
            $period = AcademicPeriod::factory()->recycle($organization)->for($year)->create([
                'label' => '1.º Período', 'sequence' => 1, 'starts_on' => '2025-09-01', 'ends_on' => '2025-12-31',
            ]);
            $subject = Subject::factory()->recycle($organization)->create(['name' => 'Matemática']);
            $scale = Scale::where('name', 'Escala 1 a 5')->with('levels')->firstOrFail();
            $profile = AssessmentProfile::factory()->recycle($organization)->create([
                'academic_year_id' => $year->id, 'subject_id' => $subject->id, 'name' => 'Perfil de Matemática',
            ]);
            $version = AssessmentProfileVersion::factory()->recycle($organization)->create([
                'assessment_profile_id' => $profile->id, 'scale_id' => $scale->id, 'domain_weight_mode' => 'must_total_100',
            ]);
            $domains = collect([
                Domain::factory()->recycle($organization)->create(['subject_id' => $subject->id, 'name' => 'Conhecimento', 'code' => 'CON', 'sequence' => 1]),
                Domain::factory()->recycle($organization)->create(['subject_id' => $subject->id, 'name' => 'Raciocínio', 'code' => 'RAC', 'sequence' => 2]),
            ]);
            foreach ($domains as $index => $domain) {
                $version->domains()->create(['domain_id' => $domain->id, 'weight_percent' => 50, 'sequence' => $index + 1]);
            }
            $version->periods()->create(['academic_period_id' => $period->id, 'contributes_to_accumulated' => true]);
            $version = app(ActivateProfileVersion::class)->activate($version->refresh(), $teacher);

            $class = SchoolClass::factory()->recycle($organization)->create([
                'academic_year_id' => $year->id, 'subject_id' => $subject->id, 'label' => '7.º A',
                'assessment_profile_version_id' => $version->id,
            ]);
            $class->teachers()->attach($teacher, ['role' => 'owner']);
            $student = Student::factory()->recycle($organization)->create();
            StudentIdentity::create(['student_id' => $student->id, 'organization_id' => $organization->id, 'display_name' => 'Ana Segura']);
            $enrollment = Enrollment::factory()->recycle($organization)->create([
                'class_id' => $class->id, 'student_id' => $student->id, 'class_number' => 1, 'enrolled_on' => '2025-09-01',
            ]);

            $instrument = Instrument::factory()->recycle($organization)->create([
                'class_id' => $class->id, 'academic_period_id' => $period->id, 'title' => 'Ficha de avaliação',
                'applied_on' => '2025-10-15', 'status' => 'completed', 'counts_toward_classification' => true, 'total_points' => 20,
            ]);
            $items = collect([
                InstrumentItem::factory()->recycle($organization)->create(['instrument_id' => $instrument->id, 'code' => 'Q1', 'sequence' => 1, 'points_possible' => 10]),
                InstrumentItem::factory()->recycle($organization)->create(['instrument_id' => $instrument->id, 'code' => 'Q2', 'sequence' => 2, 'points_possible' => 10]),
            ]);
            foreach ($items as $index => $item) {
                ItemDomainAllocation::create(['instrument_item_id' => $item->id, 'domain_id' => $domains[$index]->id, 'allocation_percent' => 100]);
                StudentItemScore::create([
                    'instrument_id' => $instrument->id, 'instrument_item_id' => $item->id, 'enrollment_id' => $enrollment->id,
                    'result_state' => ResultState::Assessed, 'points_earned' => $index === 0 ? 8 : 6,
                    'assessed_at' => '2025-10-20 12:00:00', 'assessed_by' => $teacher->id,
                ]);
            }

            $level = $scale->levels->firstWhere('code', '4') ?? $scale->levels->sortByDesc('sequence')->firstOrFail();
            Classification::create([
                'enrollment_id' => $enrollment->id, 'academic_period_id' => $period->id, 'scope' => ClassificationScope::Period,
                'assessment_profile_version_id' => $version->id, 'status' => ClassificationStatus::Confirmed,
                'proposed_normalized_value' => 70, 'proposed_value' => 4, 'proposed_scale_level_id' => $level->id,
                'final_value' => 4, 'final_scale_level_id' => $level->id, 'confirmed_by' => $teacher->id,
                'confirmed_at' => '2025-12-15 12:00:00',
            ]);

            $template = SelfAssessmentTemplate::create([
                'assessment_profile_version_id' => $version->id, 'class_id' => $class->id,
                'name' => 'Balanço do período', 'is_active' => true,
            ]);
            $question = SelfAssessmentQuestion::create([
                'self_assessment_template_id' => $template->id, 'role' => SelfAssessmentQuestionRole::Global,
                'prompt' => 'Como avalias o teu trabalho?', 'answer_kind' => 'scale', 'scale_id' => $scale->id, 'sequence' => 1,
            ]);
            $selfAssessment = SelfAssessment::create([
                'enrollment_id' => $enrollment->id, 'academic_period_id' => $period->id,
                'self_assessment_template_id' => $template->id, 'status' => SelfAssessmentStatus::Submitted,
                'filled_by' => SelfAssessmentFilledBy::Student, 'reflection' => 'Consegui melhorar.',
                'submitted_at' => '2025-12-10 10:00:00',
            ]);
            SelfAssessmentResponse::create([
                'self_assessment_id' => $selfAssessment->id, 'self_assessment_question_id' => $question->id, 'scale_level_id' => $level->id,
            ]);

            EvidenceRecord::create([
                'class_id' => $class->id, 'enrollment_id' => $enrollment->id, 'academic_period_id' => $period->id,
                'domain_id' => $domains[0]->id, 'occurred_at' => '2025-10-21 09:00:00', 'kind' => EvidenceKind::Note,
                'description' => 'Resolve problemas com autonomia.', 'created_by' => $teacher->id,
            ]);
            $intervention = Intervention::create([
                'class_id' => $class->id, 'enrollment_id' => $enrollment->id, 'academic_period_id' => $period->id,
                'domain_id' => $domains[0]->id, 'target_type' => InterventionTargetType::Student,
                'domain_relation' => InterventionDomainRelation::Specific, 'title' => 'Treino orientado',
                'description' => 'Resolver dois problemas por semana.', 'description_source' => InterventionDescriptionSource::Manual,
                'status' => InterventionStatus::InProgress, 'started_on' => '2025-10-22',
                'include_in_report' => true, 'available_for_reports' => true, 'created_by' => $teacher->id,
            ]);
            $intervention->participants()->attach($enrollment->id);
            Report::factory()->recycle($organization)->finalized()->create([
                'class_id' => $class->id, 'academic_year_id' => $year->id, 'academic_period_id' => $period->id,
                'title' => 'Relatório final', 'scope_label' => $period->label,
                'finalized_by' => $teacher->id, 'created_by' => $teacher->id,
            ]);

            return $class->fresh();
        });
    }

    private function backupUpload(Organization $organization, User $user): UploadedFile
    {
        $this->actingAs($user)->withSession(['organization_id' => $organization->id])
            ->post('/data-exports')->assertRedirect();
        $export = DataExport::withoutGlobalScope('organization')->where('requested_by', $user->id)->latest('id')->firstOrFail();

        return new UploadedFile(Storage::disk('local')->path($export->disk_path), 'backup.zip', 'application/zip', null, true);
    }

    private function uploadInto(Organization $organization, User $user, UploadedFile $file): DataImport
    {
        // The restore wizard is Pro and Institucional since the Base/Pro
        // realignment (Matriz §7, `data_backup_restore`). This test is about
        // what the wizard DOES, so the destination is put on a plan that
        // reaches it — the gate itself is asserted in
        // tests/Feature/DataImports/DataImportEntitlementTest.php.
        if (! app(Entitlements::class)->allowsFor($organization, 'data_backup_restore')) {
            $this->subscribeOrganizationTo($organization, 'pro');
        }

        $this->actingAs($user)->withSession(['organization_id' => $organization->id])
            ->post('/data-imports', ['file' => $file])->assertSessionHasNoErrors();

        return DataImport::withoutGlobalScope('organization')
            ->where('organization_id', $organization->id)->where('requested_by', $user->id)->latest('id')->firstOrFail();
    }

    private function confirm(Organization $organization, User $user, DataImport $import): void
    {
        $this->actingAs($user)->withSession(['organization_id' => $organization->id])
            ->post("/data-imports/{$import->ulid}/confirm")->assertRedirect()->assertSessionHasNoErrors();
    }

    private function deletePedagogicalData(Organization $organization, SchoolClass $class): void
    {
        $this->inTenant($organization, function () use ($class): void {
            $enrollments = Enrollment::where('class_id', $class->id)->get();
            $studentIds = $enrollments->pluck('student_id');
            $profileVersionIds = AssessmentProfileVersion::pluck('id');
            $profileIds = AssessmentProfile::pluck('id');
            $customScaleIds = Scale::whereNotNull('organization_id')->pluck('id');

            Report::where('class_id', $class->id)->delete();
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

    private function seedMatchingStructure(Organization $organization): void
    {
        $this->inTenant($organization, function () use ($organization): void {
            AcademicYear::factory()->recycle($organization)->create([
                'label' => '2025/2026', 'starts_on' => '2025-09-01', 'ends_on' => '2026-08-31',
            ]);
            Subject::factory()->recycle($organization)->create(['name' => 'Matemática']);
        });
    }

    /** @return array{Organization, User} */
    private function institutionalOrganization(): array
    {
        $owner = User::factory()->withoutOrganization()->create();
        $organization = Organization::factory()->institutional()->create(['owner_id' => $owner->id]);
        $organization->members()->attach($owner, ['joined_at' => now()]);
        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->id,
            'plan_id' => Plan::where('key', 'institutional')->firstOrFail()->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::now(),
        ]);

        return [$organization, $owner];
    }

    private function member(Organization $organization): User
    {
        $member = User::factory()->create();
        $organization->members()->attach($member, ['joined_at' => now()]);

        return $member;
    }

    /** @return array<string, int> */
    private function pedagogicalCounts(Organization $organization): array
    {
        return [
            'domains' => $this->tenantCount(Domain::class, $organization),
            'scales' => $this->tenantCount(Scale::class, $organization),
            'instruments' => $this->tenantCount(Instrument::class, $organization),
            'instrument_items' => $this->tenantCount(InstrumentItem::class, $organization),
            'student_item_scores' => $this->tenantCount(StudentItemScore::class, $organization),
            'classifications' => $this->tenantCount(Classification::class, $organization),
            'self_assessments' => $this->tenantCount(SelfAssessment::class, $organization),
            'evidence_records' => $this->tenantCount(EvidenceRecord::class, $organization),
            'interventions' => $this->tenantCount(Intervention::class, $organization),
            'reports' => $this->tenantCount(Report::class, $organization),
        ];
    }

    /** @param class-string<Model> $model */
    private function tenantCount(string $model, Organization $organization): int
    {
        return $model::withoutGlobalScope('organization')->where('organization_id', $organization->id)->count();
    }

    private function inTenant(Organization $organization, callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($organization, $callback);
    }
}
