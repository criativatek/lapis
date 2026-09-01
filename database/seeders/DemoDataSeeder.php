<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\AssessmentProfile;
use App\Models\AssessmentProfileVersion;
use App\Models\Domain;
use App\Models\InstrumentType;
use App\Models\ResultState;
use App\Models\Scale;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Services\Assessment\ActivateProfileVersion;
use App\Services\Assessment\InstrumentBuilder;
use App\Services\Assessment\ProfileBuilder;
use App\Services\Assessment\RecordScores;
use App\Services\StudentEnrollmentService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Seeder;

/**
 * A demonstration scenario with entirely fictional data (§26.6).
 *
 * Deliberately includes the awkward cases the calculation engine must handle:
 * a late-entry student who joined after the first test, an absence, and cells
 * left unmarked — so "empty is not zero" and late entry can be seen working
 * rather than only asserted in tests.
 *
 * Local/demo environments only. Never run where real data lives.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command->warn('DemoDataSeeder skipped in production.');

            return;
        }

        $teacher = User::where('email', 'ana.martins@lapis.test')->first()
            ?? User::factory()->create(['name' => 'Professora Ana Martins', 'email' => 'ana.martins@lapis.test']);

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($teacher): void {
            $year = $this->academicYear();
            $subject = Subject::firstOrCreate(['code' => 'PT7'], ['name' => 'Português']);
            $version = $this->activatedProfile($year, $subject, $teacher);
            $class = $this->classWithStudents($year, $subject, $version, $teacher);
            $this->instrumentWithScores($class, $year, $teacher);
            $this->secondPeriodInstrument($class, $year, $teacher);
        });

        $this->command->info('Cenário de demonstração criado: 7.º A de Português, com ingresso tardio, uma ausência e um instrumento no 2.º período (acumulado).');
    }

    protected function academicYear(): AcademicYear
    {
        $year = AcademicYear::firstOrCreate(
            ['label' => '2026/2027'],
            ['starts_on' => '2026-09-14', 'ends_on' => '2027-06-30', 'status' => 'active', 'country_code' => 'PT'],
        );

        if ($year->periods()->count() === 0) {
            $year->periods()->createMany([
                ['label' => '1.º Semestre', 'kind' => 'semester', 'sequence' => 1, 'starts_on' => '2026-09-14', 'ends_on' => '2027-01-29', 'status' => 'open'],
                ['label' => '2.º Semestre', 'kind' => 'semester', 'sequence' => 2, 'starts_on' => '2027-02-01', 'ends_on' => '2027-06-16', 'status' => 'draft'],
            ]);
        }

        return $year;
    }

    protected function activatedProfile(AcademicYear $year, Subject $subject, User $teacher): AssessmentProfileVersion
    {
        $existing = AssessmentProfile::where('name', 'Português – 7.º Ano')->first();

        if ($existing?->currentVersion !== null) {
            return $existing->currentVersion;
        }

        $profile = app(ProfileBuilder::class)->create(
            [
                'academic_year_id' => $year->id,
                'subject_id' => $subject->id,
                'name' => 'Português – 7.º Ano',
                'description' => 'Perfil de demonstração.',
            ],
            Scale::where('name', 'Escala 1 a 5')->firstOrFail()->id,
            [
                ['name' => 'Oralidade', 'weight' => 20],
                ['name' => 'Leitura', 'weight' => 25],
                ['name' => 'Escrita', 'weight' => 20],
                ['name' => 'Gramática', 'weight' => 15],
                ['name' => 'Educação Literária', 'weight' => 20],
            ],
            ['7.º'],
        );

        return app(ActivateProfileVersion::class)->activate($profile->draftVersion(), $teacher);
    }

    protected function classWithStudents(AcademicYear $year, Subject $subject, AssessmentProfileVersion $version, User $teacher): SchoolClass
    {
        $class = SchoolClass::firstOrCreate(
            ['academic_year_id' => $year->id, 'subject_id' => $subject->id, 'label' => '7.º A'],
            ['grade_level' => '7.º', 'status' => 'active', 'assessment_profile_version_id' => $version->id],
        );

        if ($class->teachers()->count() === 0) {
            $class->teachers()->attach($teacher, ['role' => 'owner']);
        }

        if ($class->enrollments()->count() > 0) {
            return $class;
        }

        $enrollment = app(StudentEnrollmentService::class);

        // Fictional names, clearly invented (§26.6).
        $roster = [
            ['name' => 'Ana Marques', 'class_number' => 1, 'enrolled_on' => '2026-09-14'],
            ['name' => 'Bruno Teixeira', 'class_number' => 2, 'enrolled_on' => '2026-09-14'],
            ['name' => 'Carolina Nunes', 'class_number' => 3, 'enrolled_on' => '2026-09-14'],
            ['name' => 'Diogo Ferreira', 'class_number' => 4, 'enrolled_on' => '2026-09-14'],
            ['name' => 'Eva Salgado', 'class_number' => 5, 'enrolled_on' => '2026-09-14'],
            // Joined after the first test — must not be penalised for it (A3).
            ['name' => 'Filipe Andrade', 'class_number' => 6, 'enrolled_on' => '2026-11-03'],
        ];

        foreach ($roster as $student) {
            $enrollment->enrollNew($class, $student);
        }

        return $class;
    }

    protected function instrumentWithScores(SchoolClass $class, AcademicYear $year, User $teacher): void
    {
        if ($class->instruments()->count() > 0) {
            return;
        }

        $period = $year->periods()->where('sequence', 1)->firstOrFail();
        $domains = Domain::whereIn('code', ['LEITURA', 'ESCRITA', 'GRAMATICA'])->get()->keyBy('code');

        $instrument = app(InstrumentBuilder::class)->create($class, [
            'academic_period_id' => $period->id,
            'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
            'title' => 'Teste de Compreensão Leitora',
            'applied_on' => '2026-10-15',
            'status' => 'in_correction',
            'counts_toward_classification' => true,
            'purpose' => 'summative',
            'total_points' => 100,
        ], [
            ['code' => 'Q1', 'label' => 'Compreensão do texto', 'points_possible' => 40, 'domains' => [
                ['domain_id' => $domains['LEITURA']->id, 'allocation_percent' => 100],
            ]],
            // Scenario A2: one question split across two domains.
            ['code' => 'Q2', 'label' => 'Resposta desenvolvida', 'points_possible' => 40, 'domains' => [
                ['domain_id' => $domains['LEITURA']->id, 'allocation_percent' => 60],
                ['domain_id' => $domains['ESCRITA']->id, 'allocation_percent' => 40],
            ]],
            ['code' => 'Q3', 'label' => 'Análise gramatical', 'points_possible' => 20, 'domains' => [
                ['domain_id' => $domains['GRAMATICA']->id, 'allocation_percent' => 100],
            ]],
        ]);

        $items = $instrument->items->keyBy('code');
        $enrollments = $class->enrollments()->orderBy('class_number')->get();

        $marks = [
            1 => ['Q1' => 34, 'Q2' => 31, 'Q3' => 16],
            2 => ['Q1' => 28, 'Q2' => 24, 'Q3' => 11],
            3 => ['Q1' => 38, 'Q2' => 36, 'Q3' => 18],
            // Absent — no marks at all, and never a zero.
            4 => null,
            // Partially marked: Q3 left untouched, which is "por avaliar".
            5 => ['Q1' => 22, 'Q2' => 19],
        ];

        $cells = [];

        foreach ($enrollments as $enrollment) {
            $studentMarks = $marks[$enrollment->class_number] ?? null;

            // Filipe (nº 6) joined after this test — no cells at all.
            if ($enrollment->class_number === 6) {
                continue;
            }

            if ($studentMarks === null) {
                foreach ($items as $item) {
                    $cells[] = [
                        'enrollment_id' => $enrollment->id,
                        'instrument_item_id' => $item->id,
                        'result_state' => ResultState::Absent->value,
                        'state_reason' => 'Faltou ao teste.',
                    ];
                }

                continue;
            }

            foreach ($studentMarks as $code => $points) {
                $cells[] = [
                    'enrollment_id' => $enrollment->id,
                    'instrument_item_id' => $items[$code]->id,
                    'result_state' => ResultState::Assessed->value,
                    'points_earned' => $points,
                ];
            }
        }

        app(RecordScores::class)->save($instrument, $cells, $teacher);
    }

    /**
     * A second-period instrument so the accumulated result (§6.3, Q4) has more
     * than one period to reprocess. Applied 2027-03-10 — after Filipe's late
     * entry (2026-11-03), so it applies to him even though the first test did
     * not: his accumulated is built only from the elements that reach him.
     * Diogo, absent from the first test, is present here — his accumulated gains
     * a value where his first-period result had none.
     */
    protected function secondPeriodInstrument(SchoolClass $class, AcademicYear $year, User $teacher): void
    {
        $period = $year->periods()->where('sequence', 2)->firstOrFail();

        if ($class->instruments()->where('academic_period_id', $period->id)->exists()) {
            return;
        }

        $domains = Domain::whereIn('code', ['ORALIDADE', 'ESCRITA'])->get()->keyBy('code');

        $instrument = app(InstrumentBuilder::class)->create($class, [
            'academic_period_id' => $period->id,
            'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
            'title' => 'Apresentação Oral e Texto de Opinião',
            'applied_on' => '2027-03-10',
            'status' => 'in_correction',
            'counts_toward_classification' => true,
            'purpose' => 'summative',
            'total_points' => 40,
        ], [
            ['code' => 'O1', 'label' => 'Apresentação oral', 'points_possible' => 20, 'domains' => [
                ['domain_id' => $domains['ORALIDADE']->id, 'allocation_percent' => 100],
            ]],
            ['code' => 'E1', 'label' => 'Texto de opinião', 'points_possible' => 20, 'domains' => [
                ['domain_id' => $domains['ESCRITA']->id, 'allocation_percent' => 100],
            ]],
        ]);

        $items = $instrument->items->keyBy('code');

        // Everyone present this time, including the late entry and the student who
        // was absent from the first test.
        $marks = [
            1 => ['O1' => 15, 'E1' => 14],
            2 => ['O1' => 12, 'E1' => 13],
            3 => ['O1' => 17, 'E1' => 18],
            4 => ['O1' => 14, 'E1' => 15],
            5 => ['O1' => 16, 'E1' => 17],
            6 => ['O1' => 13, 'E1' => 12],
        ];

        $cells = [];

        foreach ($class->enrollments()->orderBy('class_number')->get() as $enrollment) {
            foreach ($marks[$enrollment->class_number] ?? [] as $code => $points) {
                $cells[] = [
                    'enrollment_id' => $enrollment->id,
                    'instrument_item_id' => $items[$code]->id,
                    'result_state' => ResultState::Assessed->value,
                    'points_earned' => (float) $points,
                ];
            }
        }

        app(RecordScores::class)->save($instrument, $cells, $teacher);
    }
}
