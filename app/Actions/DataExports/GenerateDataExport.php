<?php

namespace App\Actions\DataExports;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\AssessmentProfile;
use App\Models\AssessmentProfileVersion;
use App\Models\AuditEvent;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\DataExport;
use App\Models\Domain;
use App\Models\Enrollment;
use App\Models\EvidenceRecord;
use App\Models\Instrument;
use App\Models\InstrumentGroup;
use App\Models\InstrumentItem;
use App\Models\InstrumentType;
use App\Models\InterimAssessment;
use App\Models\Intervention;
use App\Models\InterventionReview;
use App\Models\InterventionSupportMeasure;
use App\Models\ItemDomainAllocation;
use App\Models\Organization;
use App\Models\ProfileVersionDomain;
use App\Models\ProfileVersionPeriod;
use App\Models\Report;
use App\Models\ReportStatus;
use App\Models\Scale;
use App\Models\ScaleLevel;
use App\Models\SchoolClass;
use App\Models\SelfAssessment;
use App\Models\SelfAssessmentQuestion;
use App\Models\SelfAssessmentResponse;
use App\Models\SelfAssessmentTemplate;
use App\Models\Student;
use App\Models\StudentItemScore;
use App\Models\Subject;
use App\Models\User;
use App\Services\Assessment\BuildResultsProgression;
use App\Services\Assessment\ScaleProposalResolver;
use App\Services\Audit\AuditLog;
use App\Services\Reporting\Export\PdfRenderer;
use App\Services\Reporting\Export\ReportDocumentBuilder;
use App\Support\Assessment\DecisionScale;
use App\Support\Import\Backup\BackupSchemaCompatibility;
use App\Support\Retention\RetentionPolicy;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Builds a "my data" export ZIP (Fatia 4, §20-24) — synchronously, because
 * nothing in this app is queued yet and one teacher's own classes are a
 * small, bounded dataset (never "the whole organization's pedagogical data,"
 * even for the owner — see `addInstitutionalExtras()` below).
 *
 * Two files, two audiences (a manual-validation finding: the original
 * CSV-per-entity ZIP was technically correct but unreadable in Excel):
 *
 * - `Exportacao-Lapispro.xlsx` — for the teacher. Human names, not ids; the
 *   exact terminology the rest of the product already uses (Média
 *   Ponderada, Elementos de Avaliação, Estratégias e Medidas, …); one
 *   workbook, one sheet per domain, formatted to open cleanly.
 * - `backup-lapis.json` — for the platform. Stable ids and relations, built
 *   for a future import/restore, never meant to be read directly.
 *
 * Content is driven entirely by the SAME access `class_teachers` already
 * grants this user (§23, "only my classes") — an institutional owner gets
 * nothing extra pedagogically, only the governance-level rows their role
 * already lets them see (team roster, permitted audit events, identity).
 *
 * Figures that are computed rather than stored (Média Ponderada, Média
 * Ponderada Acumulada, the scale Proposta) are read from the SAME services
 * the product's own screens use — `BuildResultsProgression`,
 * `ScaleProposalResolver`, `DecisionScale` — never re-derived here. Each is
 * called ONCE PER CLASS, not per student/row, to avoid N+1.
 *
 * @phpstan-type ClassProgression array{periods: list<array<string, mixed>>, domains: list<array{id: int, name: string}>, students: list<array<string, mixed>>, context: array{class_id: int, cutoff: string|null}}
 * @phpstan-type BackupRefs array{classesById: Collection<int, SchoolClass>, academicPeriodsById: Collection<int, AcademicPeriod>, domainsById: Collection<int, Domain>, scalesById: Collection<int, Scale>, scaleLevelIndex: array<int, array{scale: Scale, level: ScaleLevel}>, profilesById: Collection<int, AssessmentProfile>, profileVersionsById: Collection<int, AssessmentProfileVersion>, instrumentTypesById: Collection<int, InstrumentType>, instrumentGroupsById: Collection<int, InstrumentGroup>, instrumentsById: Collection<int, Instrument>, selfAssessmentTemplatesById: Collection<int, SelfAssessmentTemplate>, selfAssessmentQuestionsById: Collection<int, SelfAssessmentQuestion>, selfAssessmentsById: Collection<int, SelfAssessment>, classificationsById: Collection<int, Classification>, interventionsById: Collection<int, Intervention>, reportsById: Collection<int, Report>, authorsById: Collection<int, User>}
 */
class GenerateDataExport
{
    public function __construct(
        protected AuditLog $audit,
        protected RetentionPolicy $retentionPolicy,
        protected BuildResultsProgression $progression,
        protected ScaleProposalResolver $proposals,
        protected ReportDocumentBuilder $reportBuilder,
        protected PdfRenderer $pdfRenderer,
    ) {}

    public function generate(Organization $organization, User $user): DataExport
    {
        $this->audit->record(
            'data_export.requested',
            $organization,
            summary: "{$user->name} pediu uma exportação dos seus dados.",
        );

        $export = DataExport::create([
            'requested_by' => $user->getKey(),
            'status' => 'failed',
        ]);

        try {
            $token = (string) Str::uuid();
            $relativePath = "data-exports/{$token}/export.zip";
            $absolutePath = Storage::disk('local')->path($relativePath);

            Storage::disk('local')->makeDirectory("data-exports/{$token}");

            $this->buildZip($absolutePath, $organization, $user);

            $export->update([
                'status' => 'ready',
                'disk_path' => $relativePath,
                'byte_size' => filesize($absolutePath) ?: null,
                'failed_reason' => null,
                'expires_at' => Carbon::now()->addHours($this->retentionPolicy->dataExportAvailabilityHours()),
            ]);

            $this->audit->record(
                'data_export.generated',
                $organization,
                summary: "Exportação de dados de {$user->name} gerada.",
            );
        } catch (Throwable $exception) {
            $export->update(['status' => 'failed', 'failed_reason' => substr($exception->getMessage(), 0, 255)]);

            throw $exception;
        }

        return $export->fresh();
    }

    protected function buildZip(string $absolutePath, Organization $organization, User $user): void
    {
        $classes = SchoolClass::query()
            ->whereHas('teachers', fn ($query) => $query->whereKey($user->getKey()))
            ->with(['subject', 'academicYear', 'teachers', 'profileVersion.scale.levels'])
            ->get();
        $classIds = $classes->pluck('id');

        $enrollments = Enrollment::query()->whereIn('class_id', $classIds)->with('student.identity')->get();
        $students = $enrollments->pluck('student')->filter()->unique('id')->values();

        $instruments = Instrument::query()->whereIn('class_id', $classIds)->with('type')->get();
        $evidenceRecords = EvidenceRecord::query()->whereIn('class_id', $classIds)->with('enrollment.student')->get();
        $classifications = Classification::query()
            ->whereHas('enrollment', fn ($query) => $query->whereIn('class_id', $classIds))
            ->with(['enrollment.student', 'academicPeriod', 'proposedScaleLevel', 'finalScaleLevel'])
            ->get();
        $selfAssessments = SelfAssessment::query()
            ->whereHas('enrollment', fn ($query) => $query->whereIn('class_id', $classIds))
            ->with(['enrollment.student'])
            ->get();
        $selfAssessmentTemplates = SelfAssessmentTemplate::query()
            ->whereIn('id', $selfAssessments->pluck('self_assessment_template_id')->unique())
            ->get()
            ->keyBy('id');
        $interventions = Intervention::query()->whereIn('class_id', $classIds)->with(['participants', 'supportMeasures'])->get();
        $interventionReviews = InterventionReview::query()->whereIn('intervention_id', $interventions->pluck('id'))->get();
        $reports = Report::query()->whereIn('class_id', $classIds)->with(['enrollment.student'])->get();
        $itemScores = StudentItemScore::query()
            ->whereIn('instrument_id', $instruments->pluck('id'))
            ->with(['item', 'enrollment.student'])
            ->get();

        // Fatia 6.1 — the pedagogical structure and facts a restore needs to
        // reproduce the same RESULTS via the canonical services (never a
        // second copy of the results themselves). Loaded here, once, from
        // exactly what the teacher's own classes reference — never the whole
        // organization's configuration (§83 data minimization).
        $academicPeriods = AcademicPeriod::query()->whereIn('academic_year_id', $classes->pluck('academic_year_id')->unique())->get();

        $profileVersionIds = $classes->pluck('assessment_profile_version_id')
            ->merge($classifications->pluck('assessment_profile_version_id'))
            ->merge($selfAssessmentTemplates->pluck('assessment_profile_version_id'))
            ->filter()->unique();
        $profileVersions = AssessmentProfileVersion::query()->whereIn('id', $profileVersionIds)
            ->with(['domains.domain', 'periods.academicPeriod'])
            ->get();
        $profiles = AssessmentProfile::query()->whereIn('id', $profileVersions->pluck('assessment_profile_id')->unique())
            ->with(['academicYear', 'subject', 'gradeLevels'])
            ->get();

        $instrumentGroups = InstrumentGroup::query()->whereIn('instrument_id', $instruments->pluck('id'))->get();
        $instrumentItems = InstrumentItem::query()->whereIn('instrument_id', $instruments->pluck('id'))->with('domainAllocations')->get();
        $instrumentTypes = InstrumentType::query()->whereIn('id', $instruments->pluck('instrument_type_id')->unique())->get();

        $selfAssessmentQuestions = SelfAssessmentQuestion::query()->whereIn('self_assessment_template_id', $selfAssessmentTemplates->pluck('id'))->get();
        $selfAssessmentResponses = SelfAssessmentResponse::query()->whereIn('self_assessment_id', $selfAssessments->pluck('id'))->get();

        $domains = $this->loadReferencedDomains($profileVersions, $instrumentItems, $evidenceRecords, $interventions, $selfAssessmentQuestions);
        $academicYears = AcademicYear::query()->whereIn('id', $classes->pluck('academic_year_id')
            ->merge($academicPeriods->pluck('academic_year_id'))->merge($profiles->pluck('academic_year_id'))
            ->merge($reports->pluck('academic_year_id'))->filter()->unique())->get();
        $subjects = Subject::query()->whereIn('id', $classes->pluck('subject_id')
            ->merge($profiles->pluck('subject_id'))->merge($domains->pluck('subject_id'))->filter()->unique())->get();

        $scaleLevelIds = $classifications->pluck('proposed_scale_level_id')
            ->merge($classifications->pluck('final_scale_level_id'))
            ->merge($itemScores->pluck('scale_level_id'))
            ->merge($evidenceRecords->pluck('quick_rating_scale_level_id'))
            ->merge($selfAssessmentResponses->pluck('scale_level_id'))
            ->filter()->unique();
        $scaleLevelsForLookup = ScaleLevel::query()->whereIn('id', $scaleLevelIds)->get();

        $scaleIds = $profileVersions->pluck('scale_id')
            ->merge($instruments->pluck('scale_id'))
            ->merge($instrumentItems->pluck('scale_id'))
            ->merge($selfAssessmentQuestions->pluck('scale_id'))
            ->merge($scaleLevelsForLookup->pluck('scale_id'))
            ->filter()->unique();
        $scales = Scale::query()->whereIn('id', $scaleIds)->with('levels')->get();

        $interimAssessments = InterimAssessment::query()->whereIn('class_id', $classIds)->get();

        $authors = $this->loadReferencedAuthors($instruments, $itemScores, $classifications, $selfAssessments, $evidenceRecords, $interventions, $interventionReviews, $interimAssessments, $reports);

        // Called ONCE per class — never inside a per-student/per-row loop —
        // and reused for both the "Média Ponderada" figures and the scale
        // Proposta, exactly like ClassificationController::alongsideResults()
        // and CurrentPeriodResultsSource already do for the product's own
        // screens. This IS the canonical formula; nothing here recomputes it.
        $progressionByClass = $classes->mapWithKeys(
            fn (SchoolClass $class) => [$class->id => $this->progression->for($class)],
        )->all();

        $spreadsheet = new Spreadsheet;

        try {
            $spreadsheet->removeSheetByIndex(0);

            $this->addResumoSheet($spreadsheet, $organization, $user, $classes, $students, $instruments, $classifications, $evidenceRecords, $reports);

            if ($classes->isNotEmpty()) {
                $this->addTurmasSheet($spreadsheet, $classes);
            }

            if ($students->isNotEmpty()) {
                $this->addAlunosSheet($spreadsheet, $enrollments, $classes);
            }

            if ($instruments->isNotEmpty()) {
                $this->addInstrumentsSheet($spreadsheet, $instruments, $classes);
            }

            if ($itemScores->isNotEmpty()) {
                $this->addAvaliacoesSheet($spreadsheet, $itemScores, $classes, $instruments);
            }

            if ($classifications->isNotEmpty()) {
                $this->addClassificacoesSheet($spreadsheet, $classifications, $classes, $progressionByClass);
            }

            if ($selfAssessments->isNotEmpty()) {
                $this->addAutoavaliacoesSheet($spreadsheet, $selfAssessments, $classes, $selfAssessmentTemplates);
            }

            if ($interventions->isNotEmpty()) {
                $this->addEstrategiasSheet($spreadsheet, $interventions, $classes);
            }

            if ($evidenceRecords->isNotEmpty()) {
                $this->addRegistosSheet($spreadsheet, $evidenceRecords, $classes);
            }

            if ($reports->isNotEmpty()) {
                $this->addRelatoriosSheet($spreadsheet, $reports, $classes);
            }

            $xlsxBytes = $this->renderXlsx($spreadsheet);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        $zip = new ZipArchive;

        if ($zip->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Não foi possível criar o ficheiro de exportação.');
        }

        $zip->addFromString('Exportacao-Lapispro.xlsx', $xlsxBytes);
        $zip->addFromString('backup-lapis.json', $this->technicalBackup(
            $organization,
            $user,
            $classes,
            $students,
            $enrollments,
            $academicYears,
            $subjects,
            $academicPeriods,
            $scales,
            $instrumentTypes,
            $domains,
            $profiles,
            $profileVersions,
            $instruments,
            $instrumentGroups,
            $instrumentItems,
            $itemScores,
            $classifications,
            $selfAssessmentTemplates,
            $selfAssessmentQuestions,
            $selfAssessments,
            $selfAssessmentResponses,
            $interimAssessments,
            $evidenceRecords,
            $interventions,
            $interventionReviews,
            $reports,
            $authors,
        ));
        $zip->addFromString('README.txt', $this->readme($organization));

        if ($user->owns($organization)) {
            $this->addInstitutionalExtras($zip, $organization);
        }

        $this->addReportPdfs($zip, $reports, $user);

        $zip->close();
    }

    // ------------------------------------------------------------- XLSX sheets

    /**
     * @param  Collection<int, SchoolClass>  $classes
     * @param  Collection<int, Student>  $students
     * @param  Collection<int, Instrument>  $instruments
     * @param  Collection<int, Classification>  $classifications
     * @param  Collection<int, EvidenceRecord>  $evidenceRecords
     * @param  Collection<int, Report>  $reports
     */
    protected function addResumoSheet(
        Spreadsheet $spreadsheet,
        Organization $organization,
        User $user,
        Collection $classes,
        Collection $students,
        Collection $instruments,
        Collection $classifications,
        Collection $evidenceRecords,
        Collection $reports,
    ): void {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Resumo');

        $academicYears = $classes->pluck('academicYear.label')->filter()->unique()->sort()->values()->implode(', ');

        $rows = [
            ['Utilizador', $user->name],
            ['Organização', $organization->name],
            ['Tipo de organização', $organization->type->value === 'institutional' ? 'Institucional' : 'Pessoal'],
            ['Data da exportação', Carbon::now()],
            ['Versão do Lapispro', (string) config('app.version')],
            ['Anos letivos incluídos', $academicYears === '' ? '—' : $academicYears],
            ['Nº de turmas', $classes->count()],
            ['Nº de alunos', $students->count()],
            ['Nº de elementos de avaliação', $instruments->count()],
            ['Nº de classificações', $classifications->count()],
            ['Nº de registos', $evidenceRecords->count()],
            ['Nº de relatórios', $reports->count()],
        ];

        $sheet->setCellValue('A1', 'Campo');
        $sheet->setCellValue('B1', 'Valor');
        $row = 2;

        foreach ($rows as [$label, $value]) {
            $sheet->setCellValue("A{$row}", $label);

            if ($value instanceof Carbon) {
                $this->writeDate($sheet, "B{$row}", $value, withTime: true);
            } else {
                $sheet->setCellValue("B{$row}", $value);
            }

            $row++;
        }

        $sheet->getColumnDimension('A')->setWidth(28);
        $sheet->getColumnDimension('B')->setWidth(40);
        $this->styleHeaderRow($sheet, 1, 2);
        $sheet->freezePane('A2');
    }

    /**
     * @param  Collection<int, SchoolClass>  $classes
     */
    protected function addTurmasSheet(Spreadsheet $spreadsheet, Collection $classes): void
    {
        $headers = ['Ano letivo', 'Disciplina', 'Ano', 'Turma', 'Estado', 'Professor(es)'];
        $sheet = $this->newSheet($spreadsheet, 'Turmas', $headers);

        $row = 2;

        foreach ($classes as $class) {
            /** @var SchoolClass $class */
            $sheet->setCellValue("A{$row}", $class->academicYear->label);
            $sheet->setCellValue("B{$row}", $class->subject->name);
            $sheet->setCellValue("C{$row}", $class->grade_level ?? '—');
            $sheet->setCellValue("D{$row}", $class->label);
            $sheet->setCellValue("E{$row}", $class->status->label());
            $sheet->setCellValue("F{$row}", $class->teachers->pluck('name')->implode(', '));
            $row++;
        }

        $this->finishSheet($sheet, count($headers), $row - 1);
    }

    /**
     * @param  Collection<int, Enrollment>  $enrollments
     * @param  Collection<int, SchoolClass>  $classes
     */
    protected function addAlunosSheet(Spreadsheet $spreadsheet, Collection $enrollments, Collection $classes): void
    {
        $headers = ['Ano letivo', 'Turma', 'N.º', 'Nome', 'Estado'];
        $sheet = $this->newSheet($spreadsheet, 'Alunos', $headers);

        $row = 2;

        foreach ($enrollments as $enrollment) {
            /** @var Enrollment $enrollment */
            $class = $classes->firstWhere('id', $enrollment->class_id);
            $sheet->setCellValue("A{$row}", $class === null ? '—' : $class->academicYear->label);
            $sheet->setCellValue("B{$row}", $class === null ? '—' : $class->label);
            $sheet->setCellValueExplicit("C{$row}", $enrollment->student?->identity?->processNumber() ?? '—', 's');
            $sheet->setCellValue("D{$row}", $this->studentDisplayName($enrollment));
            $sheet->setCellValue("E{$row}", $enrollment->status->label());
            $row++;
        }

        $this->finishSheet($sheet, count($headers), $row - 1);
    }

    /**
     * @param  Collection<int, Instrument>  $instruments
     * @param  Collection<int, SchoolClass>  $classes
     */
    protected function addInstrumentsSheet(Spreadsheet $spreadsheet, Collection $instruments, Collection $classes): void
    {
        $headers = ['Ano letivo', 'Turma', 'Elemento de avaliação', 'Tipo', 'Data', 'Peso', 'Estado'];
        $sheet = $this->newSheet($spreadsheet, 'Elementos de Avaliação', $headers);

        $row = 2;

        foreach ($instruments as $instrument) {
            /** @var Instrument $instrument */
            $class = $classes->firstWhere('id', $instrument->class_id);
            $sheet->setCellValue("A{$row}", $class === null ? '—' : $class->academicYear->label);
            $sheet->setCellValue("B{$row}", $class === null ? '—' : $class->label);
            $sheet->setCellValue("C{$row}", $instrument->title);
            $sheet->setCellValue("D{$row}", $instrument->type->name);
            $this->writeDate($sheet, "E{$row}", $instrument->applied_on);
            $sheet->setCellValue("F{$row}", $instrument->weight !== null ? (float) $instrument->weight : null);
            $sheet->setCellValue("G{$row}", $instrument->status->label());
            $row++;
        }

        $this->finishSheet($sheet, count($headers), $row - 1);
    }

    /**
     * @param  Collection<int, StudentItemScore>  $itemScores
     * @param  Collection<int, SchoolClass>  $classes
     * @param  Collection<int, Instrument>  $instruments
     */
    protected function addAvaliacoesSheet(Spreadsheet $spreadsheet, Collection $itemScores, Collection $classes, Collection $instruments): void
    {
        $headers = ['Ano letivo', 'Turma', 'Elemento de avaliação', 'Item', 'Aluno', 'Resultado', 'Estado'];
        $sheet = $this->newSheet($spreadsheet, 'Avaliações', $headers);

        $row = 2;

        foreach ($itemScores as $score) {
            /** @var StudentItemScore $score */
            $instrument = $instruments->firstWhere('id', $score->instrument_id);
            $class = $instrument === null ? null : $classes->firstWhere('id', $instrument->class_id);
            $sheet->setCellValue("A{$row}", $class === null ? '—' : $class->academicYear->label);
            $sheet->setCellValue("B{$row}", $class === null ? '—' : $class->label);
            $sheet->setCellValue("C{$row}", $instrument === null ? '—' : $instrument->title);
            $sheet->setCellValue("D{$row}", $score->item->label ?? $score->item->code);
            $sheet->setCellValue("E{$row}", $this->studentDisplayName($score->enrollment));
            $sheet->setCellValue("F{$row}", $score->points_earned !== null ? (float) $score->points_earned : null);
            $sheet->setCellValue("G{$row}", $score->result_state->label());
            $row++;
        }

        $this->finishSheet($sheet, count($headers), $row - 1);
    }

    /**
     * @param  Collection<int, Classification>  $classifications
     * @param  Collection<int, SchoolClass>  $classes
     * @param  array<int, ClassProgression>  $progressionByClass
     */
    protected function addClassificacoesSheet(Spreadsheet $spreadsheet, Collection $classifications, Collection $classes, array $progressionByClass): void
    {
        $headers = [
            'Ano letivo', 'Turma', 'Aluno', 'Período', 'Âmbito',
            'Média Ponderada', 'Proposta', 'Classificação atribuída', 'Estado',
        ];
        $sheet = $this->newSheet($spreadsheet, 'Classificações', $headers);

        $row = 2;

        foreach ($classifications as $classification) {
            /** @var Classification $classification */
            $enrollment = $classification->enrollment;
            $class = $enrollment === null ? null : $classes->firstWhere('id', $enrollment->class_id);
            $scale = $class?->profileVersion?->scale;
            $decision = DecisionScale::for($scale);

            $weightedAverage = $this->weightedAverageFor($progressionByClass, $class, $enrollment, $classification);

            $sheet->setCellValue("A{$row}", $class === null ? '—' : $class->academicYear->label);
            $sheet->setCellValue("B{$row}", $class === null ? '—' : $class->label);
            $sheet->setCellValue("C{$row}", $this->studentDisplayName($enrollment));
            $sheet->setCellValue("D{$row}", $classification->academicPeriod->label);
            $sheet->setCellValue("E{$row}", $classification->scope->label());
            $sheet->setCellValue("F{$row}", $weightedAverage);

            if ($weightedAverage !== null) {
                $sheet->getStyle("F{$row}")->getNumberFormat()->setFormatCode('0.0"%"');
            }

            $proposal = $this->proposals->resolve(
                $scale,
                $classification->proposed_scale_level_id,
                $classification->proposed_normalized_value,
                $classification->proposed_value,
            );
            $sheet->setCellValue("G{$row}", $proposal->value === null
                ? '—'
                : ($proposal->isPercentage ? "{$proposal->value}%" : $proposal->value));

            $finalLevelLabel = $classification->finalScaleLevel === null ? null : $classification->finalScaleLevel->label;
            $sheet->setCellValue("H{$row}", $classification->final_value ?? $finalLevelLabel ?? '—');
            $sheet->setCellValue("I{$row}", $classification->status->label());
            $row++;
        }

        // The header itself carries the decision label used by this profile
        // ("Nível atribuído" vs "Classificação atribuída", DecisionScale) —
        // classifications in this export can span more than one profile
        // version, so the column stays generically named rather than picking
        // one class's label for the whole sheet.
        $this->finishSheet($sheet, count($headers), $row - 1);
    }

    /**
     * @param  array<int, ClassProgression>  $progressionByClass
     */
    protected function weightedAverageFor(array $progressionByClass, ?SchoolClass $class, ?Enrollment $enrollment, Classification $classification): ?float
    {
        if ($class === null || $enrollment === null) {
            return null;
        }

        $progression = $progressionByClass[$class->id] ?? null;

        if ($progression === null) {
            return null;
        }

        foreach ($progression['students'] as $student) {
            if ((int) $student['enrollment_id'] !== $enrollment->id) {
                continue;
            }

            foreach ($student['periods'] as $period) {
                if ((int) $period['period_id'] !== $classification->academic_period_id) {
                    continue;
                }

                $value = $classification->scope === ClassificationScope::Accumulated
                    ? $period['accumulated_average']
                    : $period['weighted_average'];

                return $value === null ? null : (float) $value;
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, SelfAssessment>  $selfAssessments
     * @param  Collection<int, SchoolClass>  $classes
     * @param  Collection<int, SelfAssessmentTemplate>  $templates
     */
    protected function addAutoavaliacoesSheet(Spreadsheet $spreadsheet, Collection $selfAssessments, Collection $classes, Collection $templates): void
    {
        $headers = ['Ano letivo', 'Turma', 'Aluno', 'Modelo', 'Preenchida por', 'Estado', 'Submetida em'];
        $sheet = $this->newSheet($spreadsheet, 'Autoavaliações', $headers);

        $row = 2;

        foreach ($selfAssessments as $selfAssessment) {
            /** @var SelfAssessment $selfAssessment */
            $enrollment = $selfAssessment->enrollment;
            $class = $enrollment === null ? null : $classes->firstWhere('id', $enrollment->class_id);
            $template = $templates->get($selfAssessment->self_assessment_template_id);

            $sheet->setCellValue("A{$row}", $class === null ? '—' : $class->academicYear->label);
            $sheet->setCellValue("B{$row}", $class === null ? '—' : $class->label);
            $sheet->setCellValue("C{$row}", $this->studentDisplayName($enrollment));
            $sheet->setCellValue("D{$row}", $template === null ? '—' : $template->name);
            $sheet->setCellValue("E{$row}", $selfAssessment->filled_by->label());
            $sheet->setCellValue("F{$row}", $selfAssessment->status->label());

            if ($selfAssessment->submitted_at !== null) {
                $this->writeDate($sheet, "G{$row}", $selfAssessment->submitted_at, withTime: true);
            }

            $row++;
        }

        $this->finishSheet($sheet, count($headers), $row - 1);
    }

    /**
     * @param  Collection<int, Intervention>  $interventions
     * @param  Collection<int, SchoolClass>  $classes
     */
    protected function addEstrategiasSheet(Spreadsheet $spreadsheet, Collection $interventions, Collection $classes): void
    {
        $headers = ['Ano letivo', 'Turma', 'Âmbito', 'Título', 'Estado'];
        $sheet = $this->newSheet($spreadsheet, 'Estratégias e Medidas', $headers);

        $row = 2;

        foreach ($interventions as $intervention) {
            /** @var Intervention $intervention */
            $class = $classes->firstWhere('id', $intervention->class_id);
            $sheet->setCellValue("A{$row}", $class === null ? '—' : $class->academicYear->label);
            $sheet->setCellValue("B{$row}", $class === null ? '—' : $class->label);
            $sheet->setCellValue("C{$row}", $intervention->target_type->label());
            $sheet->setCellValue("D{$row}", $intervention->title);
            $sheet->setCellValue("E{$row}", $intervention->status->label());
            $row++;
        }

        $this->finishSheet($sheet, count($headers), $row - 1);
    }

    /**
     * @param  Collection<int, EvidenceRecord>  $evidenceRecords
     * @param  Collection<int, SchoolClass>  $classes
     */
    protected function addRegistosSheet(Spreadsheet $spreadsheet, Collection $evidenceRecords, Collection $classes): void
    {
        $headers = ['Ano letivo', 'Turma', 'Aluno', 'Data', 'Tipo', 'Descrição'];
        $sheet = $this->newSheet($spreadsheet, 'Registos', $headers);

        $row = 2;

        foreach ($evidenceRecords as $record) {
            /** @var EvidenceRecord $record */
            $class = $classes->firstWhere('id', $record->class_id);
            $sheet->setCellValue("A{$row}", $class === null ? '—' : $class->academicYear->label);
            $sheet->setCellValue("B{$row}", $class === null ? '—' : $class->label);
            $sheet->setCellValue("C{$row}", $this->studentDisplayName($record->enrollment));
            $this->writeDate($sheet, "D{$row}", $record->occurred_at);
            $sheet->setCellValue("E{$row}", $record->kind->label());
            $sheet->setCellValueExplicit("F{$row}", $record->description, 's');
            $sheet->getStyle("F{$row}")->getAlignment()->setWrapText(true);
            $row++;
        }

        $this->finishSheet($sheet, count($headers), $row - 1);
    }

    /**
     * @param  Collection<int, Report>  $reports
     * @param  Collection<int, SchoolClass>  $classes
     */
    protected function addRelatoriosSheet(Spreadsheet $spreadsheet, Collection $reports, Collection $classes): void
    {
        $headers = ['Ano letivo', 'Turma', 'Aluno', 'Título', 'Estado'];
        $sheet = $this->newSheet($spreadsheet, 'Relatórios', $headers);

        $row = 2;

        foreach ($reports as $report) {
            /** @var Report $report */
            $class = $classes->firstWhere('id', $report->class_id);
            $sheet->setCellValue("A{$row}", $class === null ? '—' : $class->academicYear->label);
            $sheet->setCellValue("B{$row}", $class === null ? '—' : $class->label);
            $sheet->setCellValue("C{$row}", $this->studentDisplayName($report->enrollment, 'Turma'));
            $sheet->setCellValue("D{$row}", $report->title);
            $sheet->setCellValue("E{$row}", $report->status->label());
            $row++;
        }

        $this->finishSheet($sheet, count($headers), $row - 1);
    }

    /**
     * Owner-only, and still bounded by what `OrganizationPolicy`/`AuditEvent::
     * scopeVisibleTo` already grant this exact user — never a pedagogical
     * bypass, just the governance data an owner already sees on the Equipa
     * and Atividade pages. Kept as CSV inside the ZIP (not a workbook sheet):
     * this is platform/governance data, not something a teacher reads
     * alongside their own classes.
     */
    protected function addInstitutionalExtras(ZipArchive $zip, Organization $organization): void
    {
        $members = $organization->members()->get();
        $zip->addFromString('configuracao/equipa.csv', $this->csv(
            ['nome', 'email', 'responsavel'],
            $members->map(fn (User $member): array => [
                $member->name,
                $member->email,
                $member->is($organization->owner) ? 'sim' : 'não',
            ]),
        ));

        $events = AuditEvent::query()->orderByDesc('created_at')->limit(5000)->get();
        $zip->addFromString('configuracao/auditoria.csv', $this->csv(
            ['data', 'evento', 'resumo'],
            $events->map(fn ($event): array => [
                $event->created_at->toDateTimeString(),
                $event->event,
                $event->summary,
            ]),
        ));
    }

    /**
     * Only for `ReportStatus::Finalized` reports: a draft "regenerates from
     * whatever the data says now" (`ReportStatus` docblock) and is not a
     * frozen document, so it is listed in the Relatórios sheet but never
     * rendered here. Re-checks `Gate::authorize('export', $report)` per
     * report — the export's own class-teacher scope already implies this in
     * practice, but a report is sensitive enough to verify directly rather
     * than assume.
     *
     * @param  Collection<int, Report>  $reports
     */
    protected function addReportPdfs(ZipArchive $zip, Collection $reports, User $user): void
    {
        foreach ($reports as $report) {
            /** @var Report $report */
            if ($report->status !== ReportStatus::Finalized) {
                continue;
            }

            if (Gate::forUser($user)->denies('export', $report)) {
                continue;
            }

            $report->loadMissing(['sections', 'author', 'schoolClass.subject', 'enrollment.student.identity', 'organization.identity']);

            $bytes = $this->pdfRenderer->render($this->reportBuilder->build($report));
            $name = str($report->title)->slug()->limit(80, '')->value();
            $name = $name === '' ? "relatorio-{$report->ulid}" : $name;

            $zip->addFromString("documentos/relatorios/{$name}.pdf", $bytes);
        }
    }

    // ------------------------------------------------------------- helpers

    /**
     * @param  list<string>  $headers
     */
    protected function newSheet(Spreadsheet $spreadsheet, string $title, array $headers): Worksheet
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($title);

        foreach ($headers as $index => $header) {
            $sheet->setCellValue([$index + 1, 1], $header);
        }

        return $sheet;
    }

    protected function styleHeaderRow(Worksheet $sheet, int $headerRow, int $columnCount): void
    {
        $lastColumn = Coordinate::stringFromColumnIndex($columnCount);
        $range = "A{$headerRow}:{$lastColumn}{$headerRow}";

        $style = $sheet->getStyle($range);
        $style->getFont()->setBold(true);
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E5E7EB');
        $style->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
    }

    /**
     * Freezes the header, autofilters the data, bolds/shades row 1, and
     * autosizes every column — the "immediately usable" bar from the brief,
     * applied once per sheet instead of repeated per call site.
     */
    protected function finishSheet(Worksheet $sheet, int $columnCount, int $lastDataRow): void
    {
        $this->styleHeaderRow($sheet, 1, $columnCount);

        $lastColumn = Coordinate::stringFromColumnIndex($columnCount);

        if ($lastDataRow >= 1) {
            $sheet->setAutoFilter("A1:{$lastColumn}1");
        }

        $sheet->freezePane('A2');

        for ($column = 1; $column <= $columnCount; $column++) {
            $sheet->getColumnDimensionByColumn($column)->setAutoSize(true);
        }
    }

    protected function writeDate(Worksheet $sheet, string $cell, CarbonInterface $date, bool $withTime = false): void
    {
        $sheet->setCellValue($cell, Date::PHPToExcel($date->toDateTime()));
        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode(
            $withTime ? 'dd/mm/yyyy hh:mm' : NumberFormat::FORMAT_DATE_DDMMYYYY,
        );
    }

    protected function renderXlsx(Spreadsheet $spreadsheet): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'lapis_export_xlsx_');

        if ($tempPath === false) {
            throw new RuntimeException('Não foi possível preparar o ficheiro Excel da exportação.');
        }

        try {
            (new Xlsx($spreadsheet))->save($tempPath);
            $contents = file_get_contents($tempPath);

            return $contents === false ? '' : $contents;
        } finally {
            @unlink($tempPath);
        }
    }

    /**
     * A `Student` never carries its own name (§11.2) — it lives on the
     * separate, optional `StudentIdentity` row. Centralised here since the
     * same three-hop lookup repeats across every sheet that lists students.
     */
    protected function studentDisplayName(?Enrollment $enrollment, string $fallback = '—'): string
    {
        $identity = $enrollment?->student?->identity;

        return $identity === null ? $fallback : $identity->display_name;
    }

    /**
     * @param  list<string>  $header
     * @param  iterable<array<int, mixed>>  $rows
     */
    protected function csv(array $header, iterable $rows): string
    {
        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            throw new RuntimeException('Não foi possível preparar o CSV da exportação.');
        }

        fputcsv($stream, $header);

        foreach ($rows as $row) {
            fputcsv($stream, $row);
        }

        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);

        return $contents === false ? '' : $contents;
    }

    /**
     * Every model with an author-shaped FK in the technical backup, resolved
     * once into a single email lookup (§30-31 of the import brief). Emails,
     * never names or internal ids — the ONLY safe cross-database match a
     * restore can make on its own is "this row's author is literally the
     * person confirming the import" (their own email); anything else is
     * either dropped (nullable author column) or blocks the row (required
     * one) rather than guessing.
     *
     * @param  Collection<int, Instrument>  $instruments
     * @param  Collection<int, StudentItemScore>  $itemScores
     * @param  Collection<int, Classification>  $classifications
     * @param  Collection<int, SelfAssessment>  $selfAssessments
     * @param  Collection<int, EvidenceRecord>  $evidenceRecords
     * @param  Collection<int, Intervention>  $interventions
     * @param  Collection<int, InterventionReview>  $interventionReviews
     * @param  Collection<int, InterimAssessment>  $interimAssessments
     * @param  Collection<int, Report>  $reports
     * @return Collection<int, User>
     */
    protected function loadReferencedAuthors(
        Collection $instruments,
        Collection $itemScores,
        Collection $classifications,
        Collection $selfAssessments,
        Collection $evidenceRecords,
        Collection $interventions,
        Collection $interventionReviews,
        Collection $interimAssessments,
        Collection $reports,
    ): Collection {
        $ids = $instruments->pluck('completed_by')
            ->merge($instruments->pluck('cancelled_by'))
            ->merge($itemScores->pluck('assessed_by'))
            ->merge($classifications->pluck('confirmed_by'))
            ->merge($classifications->pluck('overridden_by'))
            ->merge($selfAssessments->pluck('reviewed_by'))
            ->merge($evidenceRecords->pluck('created_by'))
            ->merge($interventions->pluck('created_by'))
            ->merge($interventionReviews->pluck('reviewed_by'))
            ->merge($interimAssessments->pluck('created_by'))
            ->merge($reports->pluck('created_by'))
            ->merge($reports->pluck('finalized_by'))
            ->filter()->unique();

        return User::query()->withoutGlobalScopes()->whereIn('id', $ids)->get();
    }

    /**
     * Every domain referenced anywhere — profile version weights, an item's
     * split allocation, a logbook entry, an intervention, a self-assessment
     * question — plus, one level up, the parent of any of those that isn't
     * already in the set. A domain tree is shallow in practice; one extra
     * pass is enough to keep a restored child's parent link resolvable
     * without walking the whole organization's domain list (§83).
     *
     * @param  Collection<int, AssessmentProfileVersion>  $profileVersions
     * @param  Collection<int, InstrumentItem>  $instrumentItems
     * @param  Collection<int, EvidenceRecord>  $evidenceRecords
     * @param  Collection<int, Intervention>  $interventions
     * @param  Collection<int, SelfAssessmentQuestion>  $selfAssessmentQuestions
     * @return Collection<int, Domain>
     */
    protected function loadReferencedDomains(
        Collection $profileVersions,
        Collection $instrumentItems,
        Collection $evidenceRecords,
        Collection $interventions,
        Collection $selfAssessmentQuestions,
    ): Collection {
        $ids = $profileVersions->flatMap(fn (AssessmentProfileVersion $version) => $version->domains->pluck('domain_id'))
            ->merge($instrumentItems->flatMap(fn (InstrumentItem $item) => $item->domainAllocations->pluck('domain_id')))
            ->merge($evidenceRecords->pluck('domain_id'))
            ->merge($interventions->pluck('domain_id'))
            ->merge($selfAssessmentQuestions->pluck('domain_id'))
            ->filter()->unique();

        $domains = Domain::query()->whereIn('id', $ids)->with('subject')->get();
        $parentIds = $domains->pluck('parent_domain_id')->filter()->unique()->diff($domains->pluck('id'));

        return $domains->merge(Domain::query()->whereIn('id', $parentIds)->with('subject')->get());
    }

    /**
     * Every lookup a row-builder method might need to resolve a reference,
     * gathered once. A single loosely-typed bag (`@phpstan-type BackupRefs`)
     * rather than passing a dozen individual collections into every one of
     * the ~25 row builders below — and, in practice, the fix for a real
     * problem: a single technicalBackup() with all of this inlined as
     * nested closures made the surrounding array literal too large for
     * PHPStan to analyse within composer types:check's own memory limit.
     * Splitting each collection's row shape into its own named method
     * (loosely typed `array<string, mixed>`, the same convention already
     * used by BuildImportPlan's row builders) is what keeps this checkable
     * at all, not just easier to read.
     *
     * @param  Collection<int, SchoolClass>  $classes
     * @param  Collection<int, AcademicPeriod>  $academicPeriods
     * @param  Collection<int, Domain>  $domains
     * @param  Collection<int, Scale>  $scales
     * @param  Collection<int, AssessmentProfile>  $profiles
     * @param  Collection<int, AssessmentProfileVersion>  $profileVersions
     * @param  Collection<int, InstrumentType>  $instrumentTypes
     * @param  Collection<int, InstrumentGroup>  $instrumentGroups
     * @param  Collection<int, Instrument>  $instruments
     * @param  Collection<int, SelfAssessmentTemplate>  $selfAssessmentTemplates
     * @param  Collection<int, SelfAssessmentQuestion>  $selfAssessmentQuestions
     * @param  Collection<int, SelfAssessment>  $selfAssessments
     * @param  Collection<int, Classification>  $classifications
     * @param  Collection<int, Intervention>  $interventions
     * @param  Collection<int, Report>  $reports
     * @param  Collection<int, User>  $authors
     * @return BackupRefs
     */
    protected function buildBackupRefs(
        Collection $classes,
        Collection $academicPeriods,
        Collection $domains,
        Collection $scales,
        Collection $profiles,
        Collection $profileVersions,
        Collection $instrumentTypes,
        Collection $instrumentGroups,
        Collection $instruments,
        Collection $selfAssessmentTemplates,
        Collection $selfAssessmentQuestions,
        Collection $selfAssessments,
        Collection $classifications,
        Collection $interventions,
        Collection $reports,
        Collection $authors,
    ): array {
        $scaleLevelIndex = [];

        foreach ($scales as $scale) {
            foreach ($scale->levels as $level) {
                $scaleLevelIndex[$level->id] = ['scale' => $scale, 'level' => $level];
            }
        }

        return [
            'classesById' => $classes->keyBy('id'),
            'academicPeriodsById' => $academicPeriods->keyBy('id'),
            'domainsById' => $domains->keyBy('id'),
            'scalesById' => $scales->keyBy('id'),
            'scaleLevelIndex' => $scaleLevelIndex,
            'profilesById' => $profiles->keyBy('id'),
            'profileVersionsById' => $profileVersions->keyBy('id'),
            'instrumentTypesById' => $instrumentTypes->keyBy('id'),
            'instrumentGroupsById' => $instrumentGroups->keyBy('id'),
            'instrumentsById' => $instruments->keyBy('id'),
            'selfAssessmentTemplatesById' => $selfAssessmentTemplates->keyBy('id'),
            'selfAssessmentQuestionsById' => $selfAssessmentQuestions->keyBy('id'),
            'selfAssessmentsById' => $selfAssessments->keyBy('id'),
            'classificationsById' => $classifications->keyBy('id'),
            'interventionsById' => $interventions->keyBy('id'),
            'reportsById' => $reports->keyBy('id'),
            'authorsById' => $authors->keyBy('id'),
        ];
    }

    /** @return array<string, mixed>|null */
    protected function scaleRef(?Scale $scale): ?array
    {
        return $scale === null ? null : ['ulid' => $scale->ulid, 'name' => $scale->name, 'is_system' => $scale->isSystem()];
    }

    /**
     * @param  BackupRefs  $refs
     * @return array<string, mixed>|null
     */
    protected function scaleRefById(?int $scaleId, array $refs): ?array
    {
        return $scaleId === null ? null : $this->scaleRef($refs['scalesById']->get($scaleId));
    }

    /**
     * @param  BackupRefs  $refs
     * @return array<string, mixed>|null
     */
    protected function scaleLevelRef(?int $scaleLevelId, array $refs): ?array
    {
        if ($scaleLevelId === null || ! isset($refs['scaleLevelIndex'][$scaleLevelId])) {
            return null;
        }

        return [
            'scale' => $this->scaleRef($refs['scaleLevelIndex'][$scaleLevelId]['scale']),
            'code' => $refs['scaleLevelIndex'][$scaleLevelId]['level']->code,
        ];
    }

    /**
     * @param  BackupRefs  $refs
     */
    protected function domainUlid(?int $domainId, array $refs): ?string
    {
        return $domainId === null ? null : $refs['domainsById']->get($domainId)?->ulid;
    }

    /**
     * @param  BackupRefs  $refs
     */
    protected function periodUlid(?int $periodId, array $refs): ?string
    {
        return $periodId === null ? null : $refs['academicPeriodsById']->get($periodId)?->ulid;
    }

    /**
     * @param  BackupRefs  $refs
     */
    protected function classUlidRef(?int $classId, array $refs): ?string
    {
        return $classId === null ? null : $refs['classesById']->get($classId)?->ulid;
    }

    /**
     * @param  BackupRefs  $refs
     */
    protected function authorEmail(?int $userId, array $refs): ?string
    {
        return $userId === null ? null : $refs['authorsById']->get($userId)?->email;
    }

    /**
     * @param  BackupRefs  $refs
     * @return array<string, mixed>|null
     */
    protected function instrumentTypeRef(?int $typeId, array $refs): ?array
    {
        $type = $typeId === null ? null : $refs['instrumentTypesById']->get($typeId);

        return $type === null ? null : ['ulid' => $type->ulid, 'code' => $type->code, 'is_system' => $type->isSystem()];
    }

    /** @return array<string, mixed> */
    protected function academicPeriodRow(AcademicPeriod $period): array
    {
        return [
            'ulid' => $period->ulid,
            'academic_year' => $period->academicYear->label,
            'label' => $period->label,
            'kind' => $period->kind->value,
            'sequence' => $period->sequence,
            'starts_on' => $period->starts_on->toDateString(),
            'ends_on' => $period->ends_on->toDateString(),
            'status' => $period->status->value,
        ];
    }

    /** @return array<string, mixed> */
    protected function academicYearRow(AcademicYear $year): array
    {
        return [
            'ulid' => $year->ulid, 'label' => $year->label,
            'starts_on' => $year->starts_on->toDateString(), 'ends_on' => $year->ends_on->toDateString(),
            'status' => $year->status->value, 'country_code' => $year->country_code, 'region_code' => $year->region_code,
        ];
    }

    /** @return array<string, mixed> */
    protected function subjectRow(Subject $subject): array
    {
        return ['ulid' => $subject->ulid, 'name' => $subject->name, 'code' => $subject->code];
    }

    /** @return array<string, mixed> */
    protected function scaleRow(Scale $scale): array
    {
        return [
            'ulid' => $scale->ulid,
            'name' => $scale->name,
            'kind' => $scale->kind,
            'is_system' => $scale->isSystem(),
            'min_value' => $scale->min_value,
            'max_value' => $scale->max_value,
            'levels' => $scale->levels->map(fn (ScaleLevel $level): array => [
                'code' => $level->code,
                'label' => $level->label,
                'inovar_code' => $level->inovar_code,
                'sequence' => $level->sequence,
                'numeric_value' => $level->numeric_value,
                'normalized_value' => $level->normalized_value,
                'band_min_normalized' => $level->band_min_normalized,
                'band_max_normalized' => $level->band_max_normalized,
                'is_negative' => $level->is_negative,
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    protected function instrumentTypeRow(InstrumentType $type): array
    {
        return [
            'ulid' => $type->ulid,
            'name' => $type->name,
            'code' => $type->code,
            'is_system' => $type->isSystem(),
            'default_purpose' => $type->default_purpose,
            'is_active' => $type->is_active,
        ];
    }

    /**
     * @param  BackupRefs  $refs
     * @return array<string, mixed>
     */
    protected function domainRow(Domain $domain, array $refs): array
    {
        return [
            'ulid' => $domain->ulid,
            'name' => $domain->name,
            'code' => $domain->code,
            'subject' => $domain->subject?->name,
            'parent_domain_ulid' => $this->domainUlid($domain->parent_domain_id, $refs),
            'sequence' => $domain->sequence,
            'is_active' => $domain->is_active,
        ];
    }

    /** @return array<string, mixed> */
    protected function profileRow(AssessmentProfile $profile): array
    {
        return [
            'ulid' => $profile->ulid,
            'name' => $profile->name,
            'description' => $profile->description,
            'academic_year' => $profile->academicYear?->label,
            'subject' => $profile->subject?->name,
            'grade_levels' => $profile->gradeLevels->pluck('grade_level')->all(),
            'is_institutional_template' => $profile->is_institutional_template,
        ];
    }

    /**
     * @param  BackupRefs  $refs
     * @return array<string, mixed>
     */
    protected function profileVersionRow(AssessmentProfileVersion $version, array $refs): array
    {
        return [
            'ulid' => $version->ulid,
            'profile_ulid' => $version->profile->ulid,
            'version_number' => $version->version_number,
            'status' => $version->status->value,
            'is_current' => $refs['profilesById']->get($version->assessment_profile_id)?->current_version_id === $version->id,
            'scale' => $this->scaleRef($version->scale),
            'domain_weight_mode' => $version->domain_weight_mode,
            'period_result_mode' => $version->period_result_mode,
            'accumulated_mode' => $version->accumulated_mode,
            'absence_mode' => $version->absence_mode,
            'rounding_mode' => $version->rounding_mode,
            'rounding_scale' => $version->rounding_scale,
            'rounding_stage' => $version->rounding_stage,
            'minimum_rules' => $version->minimum_rules,
            'activated_at' => $version->activated_at?->toIso8601String(),
            'frozen_at' => $version->frozen_at?->toIso8601String(),
            'superseded_at' => $version->superseded_at?->toIso8601String(),
            'change_note' => $version->change_note,
        ];
    }

    /**
     * @param  BackupRefs  $refs
     * @return array<int, array<string, mixed>>
     */
    protected function profileVersionDomainRows(AssessmentProfileVersion $version, array $refs): array
    {
        return $version->domains->map(fn (ProfileVersionDomain $row): array => [
            'version_ulid' => $version->ulid,
            'domain_ulid' => $this->domainUlid($row->domain_id, $refs),
            'weight_percent' => $row->weight_percent,
            'sequence' => $row->sequence,
            'expected_element_count' => $row->expected_element_count,
            'minimum_element_count' => $row->minimum_element_count,
        ])->values()->all();
    }

    /**
     * @param  BackupRefs  $refs
     * @return array<int, array<string, mixed>>
     */
    protected function profileVersionPeriodRows(AssessmentProfileVersion $version, array $refs): array
    {
        return $version->periods->map(fn (ProfileVersionPeriod $row): array => [
            'version_ulid' => $version->ulid,
            'academic_period_ulid' => $this->periodUlid($row->academic_period_id, $refs),
            'is_cumulative' => $row->is_cumulative,
            'period_weight_percent' => $row->period_weight_percent,
            'contributes_to_accumulated' => $row->contributes_to_accumulated,
        ])->values()->all();
    }

    /**
     * @param  BackupRefs  $refs
     * @return array<string, mixed>
     */
    protected function classRow(SchoolClass $class, array $refs): array
    {
        return [
            'ulid' => $class->ulid,
            'label' => $class->label,
            // Novo na v8. Sem ele uma turma de apoio restaurada voltava
            // silenciosamente a ser uma turma normal.
            'is_support_class' => $class->is_support_class,
            'status' => $class->status->value,
            'academic_year' => $class->academicYear->label,
            'subject' => $class->subject?->name,
            'assessment_profile_version_ulid' => $class->assessment_profile_version_id === null
                ? null
                : $refs['profileVersionsById']->get($class->assessment_profile_version_id)?->ulid,
        ];
    }

    /**
     * @param  BackupRefs  $refs
     * @return array<string, mixed>
     */
    protected function instrumentRow(Instrument $instrument, array $refs): array
    {
        return [
            'ulid' => $instrument->ulid,
            'class_ulid' => $this->classUlidRef($instrument->class_id, $refs),
            'title' => $instrument->title,
            'status' => $instrument->status->value,
            'applied_on' => $instrument->applied_on->toDateString(),
            'academic_period_ulid' => $this->periodUlid($instrument->academic_period_id, $refs),
            'instrument_type' => $this->instrumentTypeRef($instrument->instrument_type_id, $refs),
            'purpose' => $instrument->purpose,
            'counts_toward_classification' => $instrument->counts_toward_classification,
            'total_points' => $instrument->total_points,
            'scale' => $this->scaleRefById($instrument->scale_id, $refs),
            'weight' => $instrument->weight,
            'allow_bonus' => $instrument->allow_bonus,
        ];
    }

    /**
     * @param  BackupRefs  $refs
     * @return array<string, mixed>
     */
    protected function instrumentGroupRow(InstrumentGroup $group, array $refs): array
    {
        return [
            'ulid' => $group->ulid,
            'instrument_ulid' => $refs['instrumentsById']->get($group->instrument_id)?->ulid,
            'label' => $group->label,
            'sequence' => $group->sequence,
        ];
    }

    /**
     * @param  BackupRefs  $refs
     * @return array<string, mixed>
     */
    protected function instrumentItemRow(InstrumentItem $item, array $refs): array
    {
        return [
            'ulid' => $item->ulid,
            'instrument_ulid' => $refs['instrumentsById']->get($item->instrument_id)?->ulid,
            'group_ulid' => $refs['instrumentGroupsById']->get($item->instrument_group_id)?->ulid,
            'code' => $item->code,
            'label' => $item->label,
            'sequence' => $item->sequence,
            'points_possible' => $item->points_possible,
            'scoring_mode' => $item->scoring_mode,
            'scale' => $this->scaleRefById($item->scale_id, $refs),
            'is_bonus' => $item->is_bonus,
            'source_group_label' => $item->source_group_label,
        ];
    }

    /**
     * @param  BackupRefs  $refs
     * @return array<int, array<string, mixed>>
     */
    protected function itemDomainAllocationRows(InstrumentItem $item, array $refs): array
    {
        return $item->domainAllocations->map(fn (ItemDomainAllocation $allocation): array => [
            'item_ulid' => $item->ulid,
            'domain_ulid' => $this->domainUlid($allocation->domain_id, $refs),
            'allocation_percent' => $allocation->allocation_percent,
        ])->values()->all();
    }

    /**
     * @param  BackupRefs  $refs
     * @return array<string, mixed>
     */
    protected function studentItemScoreRow(StudentItemScore $score, array $refs): array
    {
        return [
            'item_ulid' => $score->item->ulid,
            'enrollment_ulid' => $score->enrollment?->ulid,
            'result_state' => $score->result_state->value,
            'points_earned' => $score->points_earned,
            'scale_level' => $this->scaleLevelRef($score->scale_level_id, $refs),
            'state_reason' => $score->state_reason,
            'assessed_at' => $score->assessed_at?->toIso8601String(),
            'assessed_by_email' => $this->authorEmail($score->assessed_by, $refs),
        ];
    }

    /**
     * @param  BackupRefs  $refs
     * @return array<string, mixed>
     */
    protected function classificationRow(Classification $classification, array $refs): array
    {
        return [
            'ulid' => $classification->ulid,
            'enrollment_ulid' => $classification->enrollment?->ulid,
            'academic_period_ulid' => $this->periodUlid($classification->academic_period_id, $refs),
            'assessment_profile_version_ulid' => $refs['profileVersionsById']->get($classification->assessment_profile_version_id)?->ulid,
            'scope' => $classification->scope->value,
            'status' => $classification->status->value,
            'proposed_normalized_value' => $classification->proposed_normalized_value,
            'proposed_value' => $classification->proposed_value,
            'proposed_scale_level' => $this->scaleLevelRef($classification->proposed_scale_level_id, $refs),
            'final_value' => $classification->final_value,
            'final_scale_level' => $this->scaleLevelRef($classification->final_scale_level_id, $refs),
            'override_reason' => $classification->override_reason,
            'overridden_by_email' => $this->authorEmail($classification->overridden_by, $refs),
            'overridden_at' => $classification->overridden_at?->toIso8601String(),
            'confirmed_by_email' => $this->authorEmail($classification->confirmed_by, $refs),
            'confirmed_at' => $classification->confirmed_at?->toIso8601String(),
            'published_at' => $classification->published_at?->toIso8601String(),
            'superseded_by_ulid' => $classification->superseded_by_id === null
                ? null
                : $refs['classificationsById']->get($classification->superseded_by_id)?->ulid,
        ];
    }

    /**
     * @param  BackupRefs  $refs
     * @return array<string, mixed>
     */
    protected function selfAssessmentTemplateRow(SelfAssessmentTemplate $template, array $refs): array
    {
        return [
            'ulid' => $template->ulid,
            'name' => $template->name,
            'is_active' => $template->is_active,
            'assessment_profile_version_ulid' => $refs['profileVersionsById']->get($template->assessment_profile_version_id)?->ulid,
            'class_ulid' => $this->classUlidRef($template->class_id, $refs),
        ];
    }

    /**
     * @param  BackupRefs  $refs
     * @return array<string, mixed>
     */
    protected function selfAssessmentQuestionRow(SelfAssessmentQuestion $question, array $refs): array
    {
        return [
            'template_ulid' => $refs['selfAssessmentTemplatesById']->get($question->self_assessment_template_id)?->ulid,
            'role' => $question->role?->value,
            'prompt' => $question->prompt,
            'answer_kind' => $question->answer_kind,
            'domain_ulid' => $this->domainUlid($question->domain_id, $refs),
            'scale' => $this->scaleRefById($question->scale_id, $refs),
            'sequence' => $question->sequence,
        ];
    }

    /**
     * @param  BackupRefs  $refs
     * @return array<string, mixed>
     */
    protected function selfAssessmentRow(SelfAssessment $selfAssessment, array $refs): array
    {
        return [
            'ulid' => $selfAssessment->ulid,
            'enrollment_ulid' => $selfAssessment->enrollment?->ulid,
            'academic_period_ulid' => $this->periodUlid($selfAssessment->academic_period_id, $refs),
            'template_ulid' => $refs['selfAssessmentTemplatesById']->get($selfAssessment->self_assessment_template_id)?->ulid,
            'status' => $selfAssessment->status->value,
            'filled_by' => $selfAssessment->filled_by->value,
            'reflection' => $selfAssessment->reflection,
            'submitted_at' => $selfAssessment->submitted_at?->toIso8601String(),
            'reviewed_at' => $selfAssessment->reviewed_at?->toIso8601String(),
            'reviewed_by_email' => $this->authorEmail($selfAssessment->reviewed_by, $refs),
        ];
    }

    /**
     * @param  BackupRefs  $refs
     * @return array<string, mixed>
     */
    protected function selfAssessmentResponseRow(SelfAssessmentResponse $response, array $refs): array
    {
        $question = $refs['selfAssessmentQuestionsById']->get($response->self_assessment_question_id);

        return [
            'self_assessment_ulid' => $refs['selfAssessmentsById']->get($response->self_assessment_id)?->ulid,
            'question_role' => $question?->role?->value,
            'question_sequence' => $question?->sequence,
            'scale_level' => $this->scaleLevelRef($response->scale_level_id, $refs),
            'text_value' => $response->text_value,
            'boolean_value' => $response->boolean_value,
        ];
    }

    /**
     * @param  BackupRefs  $refs
     * @return array<string, mixed>
     */
    protected function interimAssessmentRow(InterimAssessment $interim, array $refs): array
    {
        return [
            'ulid' => $interim->ulid,
            'class_ulid' => $this->classUlidRef($interim->class_id, $refs),
            'academic_period_ulid' => $this->periodUlid($interim->academic_period_id, $refs),
            'name' => $interim->name,
            'reference_date' => $interim->reference_date->toDateString(),
            'note' => $interim->note,
            'snapshot_version' => $interim->snapshot_version,
            'snapshot' => $interim->snapshot,
            'snapshot_hash' => $interim->snapshot_hash,
            'created_by_email' => $this->authorEmail($interim->created_by, $refs),
        ];
    }

    /**
     * @param  BackupRefs  $refs
     * @return array<string, mixed>
     */
    protected function evidenceRecordRow(EvidenceRecord $record, array $refs): array
    {
        return [
            'ulid' => $record->ulid,
            'class_ulid' => $this->classUlidRef($record->class_id, $refs),
            'enrollment_ulid' => $record->enrollment?->ulid,
            'academic_period_ulid' => $this->periodUlid($record->academic_period_id, $refs),
            'domain_ulid' => $this->domainUlid($record->domain_id, $refs),
            'quick_rating_scale_level' => $this->scaleLevelRef($record->quick_rating_scale_level_id, $refs),
            'occurred_at' => $record->occurred_at->toIso8601String(),
            'kind' => $record->kind->value,
            'description' => $record->description,
            'activity_include_in_report' => $record->activity_include_in_report,
            'homework_status' => $record->homework_status?->value,
            'participation_level' => $record->participation_level?->value,
            'activity_evaluation' => $record->activity_evaluation?->value,
            'disciplinary_severity' => $record->disciplinary_severity?->value,
            'created_by_email' => $this->authorEmail($record->created_by, $refs),
        ];
    }

    /**
     * @param  BackupRefs  $refs
     * @return array<string, mixed>
     */
    protected function interventionRow(Intervention $intervention, array $refs): array
    {
        return [
            'ulid' => $intervention->ulid,
            'created_batch_ulid' => $intervention->created_batch_ulid,
            'class_ulid' => $this->classUlidRef($intervention->class_id, $refs),
            'enrollment_ulid' => $intervention->enrollment?->ulid,
            'participant_enrollment_ulids' => $intervention->participants->pluck('ulid')->values()->all(),
            'academic_period_ulid' => $this->periodUlid($intervention->academic_period_id, $refs),
            'domain_ulid' => $this->domainUlid($intervention->domain_id, $refs),
            'target_type' => $intervention->target_type->value,
            'intervention_type' => $intervention->intervention_type,
            'motive_code' => $intervention->motive_code,
            'motive_label' => $intervention->motive_label,
            'strategy_code' => $intervention->strategy_code,
            'strategy_label' => $intervention->strategy_label,
            'objective' => $intervention->objective,
            'domain_relation' => $intervention->domain_relation,
            'title' => $intervention->title,
            'description' => $intervention->description,
            'description_source' => $intervention->description_source,
            'status' => $intervention->status->value,
            'started_on' => $intervention->started_on->toDateString(),
            'expected_end_on' => $intervention->expected_end_on?->toDateString(),
            'concluded_on' => $intervention->concluded_on?->toDateString(),
            'review_on' => $intervention->review_on?->toDateString(),
            'available_for_reports' => $intervention->available_for_reports,
            'support_measure_level' => $intervention->support_measure_level,
            'support_measure_code' => $intervention->support_measure_code,
            'evaluation_adaptation_code' => $intervention->evaluation_adaptation_code,
            'legal_mapping_source' => $intervention->legal_mapping_source,
            'support_measures' => $intervention->supportMeasures->map(fn (InterventionSupportMeasure $measure) => [
                'level' => $measure->support_measure_level->value,
                'code' => $measure->support_measure_code->value,
                'legal_mapping_source' => $measure->legal_mapping_source?->value,
            ])->values()->all(),
            'created_by_email' => $this->authorEmail($intervention->created_by, $refs),
        ];
    }

    /**
     * @param  BackupRefs  $refs
     * @return array<string, mixed>
     */
    protected function interventionReviewRow(InterventionReview $review, array $refs): array
    {
        return [
            'ulid' => $review->ulid,
            'intervention_ulid' => $refs['interventionsById']->get($review->intervention_id)?->ulid,
            'reviewed_on' => $review->reviewed_on->toDateString(),
            'effectiveness' => $review->effectiveness?->value,
            'notes' => $review->notes,
            'reviewed_by_email' => $this->authorEmail($review->reviewed_by, $refs),
        ];
    }

    /**
     * @param  BackupRefs  $refs
     * @return array<string, mixed>
     */
    protected function reportRow(Report $report, array $refs): array
    {
        return [
            'ulid' => $report->ulid,
            'type' => $report->type,
            'title' => $report->title,
            'tone' => $report->tone,
            'scope_kind' => $report->scope_kind,
            'scope_label' => $report->scope_label,
            'starts_on' => $report->starts_on?->toDateString(),
            'ends_on' => $report->ends_on?->toDateString(),
            'class_ulid' => $this->classUlidRef($report->class_id, $refs),
            'enrollment_ulid' => $report->enrollment?->ulid,
            'academic_year' => $report->academicYear?->label,
            'academic_period_ulid' => $this->periodUlid($report->academic_period_id, $refs),
            'interim_assessment_ulid' => $report->interimAssessment?->ulid,
            'document' => $report->document,
            'document_version' => $report->document_version,
            'document_hash' => $report->document_hash,
            'finalized_at' => $report->finalized_at?->toIso8601String(),
            'finalized_by_email' => $this->authorEmail($report->finalized_by, $refs),
            'created_by_email' => $this->authorEmail($report->created_by, $refs),
            'based_on_report_ulid' => $report->based_on_report_id === null
                ? null
                : $refs['reportsById']->get($report->based_on_report_id)?->ulid,
            'template_key' => $report->template_key,
            'template_snapshot' => $report->template_snapshot,
        ];
    }

    /**
     * The technical backup — stable ids and relations, for a future
     * import/restore. Deliberately not meant to be comfortable reading: the
     * XLSX is the document for that.
     *
     * schema_version 5 (Fatia 6.2, docs/backup-schema.md — the "schema v2"
     * capability tier): completes the pedagogical restore that schema
     * versions 2-3 left structural-only. Adds assessment structure
     * (academic periods, scales, instrument types, domains, assessment
     * profiles/versions/weights), elements/items/scores, the full
     * classification record, self-assessments, interim assessments,
     * pedagogical records (Registos) and finalized reports. Every one of
     * these is a PERSISTED FACT a teacher or the confirm-time engine already
     * wrote to a row — never a value this export computes. Weighted
     * averages, accumulated averages, evolution and class statistics are
     * deliberately absent: `BuildResultsProgression`/`ClassResultsCalculator`
     * recompute them from what this payload restores, exactly as they do
     * for data that was never exported at all (§20-22 of the import brief).
     *
     * @param  Collection<int, SchoolClass>  $classes
     * @param  Collection<int, Student>  $students
     * @param  Collection<int, Enrollment>  $enrollments
     * @param  Collection<int, AcademicYear>  $academicYears
     * @param  Collection<int, Subject>  $subjects
     * @param  Collection<int, AcademicPeriod>  $academicPeriods
     * @param  Collection<int, Scale>  $scales
     * @param  Collection<int, InstrumentType>  $instrumentTypes
     * @param  Collection<int, Domain>  $domains
     * @param  Collection<int, AssessmentProfile>  $profiles
     * @param  Collection<int, AssessmentProfileVersion>  $profileVersions
     * @param  Collection<int, Instrument>  $instruments
     * @param  Collection<int, InstrumentGroup>  $instrumentGroups
     * @param  Collection<int, InstrumentItem>  $instrumentItems
     * @param  Collection<int, StudentItemScore>  $itemScores
     * @param  Collection<int, Classification>  $classifications
     * @param  Collection<int, SelfAssessmentTemplate>  $selfAssessmentTemplates
     * @param  Collection<int, SelfAssessmentQuestion>  $selfAssessmentQuestions
     * @param  Collection<int, SelfAssessment>  $selfAssessments
     * @param  Collection<int, SelfAssessmentResponse>  $selfAssessmentResponses
     * @param  Collection<int, InterimAssessment>  $interimAssessments
     * @param  Collection<int, EvidenceRecord>  $evidenceRecords
     * @param  Collection<int, Intervention>  $interventions
     * @param  Collection<int, InterventionReview>  $interventionReviews
     * @param  Collection<int, Report>  $reports
     * @param  Collection<int, User>  $authors
     */
    protected function technicalBackup(
        Organization $organization,
        User $user,
        Collection $classes,
        Collection $students,
        Collection $enrollments,
        Collection $academicYears,
        Collection $subjects,
        Collection $academicPeriods,
        Collection $scales,
        Collection $instrumentTypes,
        Collection $domains,
        Collection $profiles,
        Collection $profileVersions,
        Collection $instruments,
        Collection $instrumentGroups,
        Collection $instrumentItems,
        Collection $itemScores,
        Collection $classifications,
        Collection $selfAssessmentTemplates,
        Collection $selfAssessmentQuestions,
        Collection $selfAssessments,
        Collection $selfAssessmentResponses,
        Collection $interimAssessments,
        Collection $evidenceRecords,
        Collection $interventions,
        Collection $interventionReviews,
        Collection $reports,
        Collection $authors,
    ): string {
        $refs = $this->buildBackupRefs(
            $classes, $academicPeriods, $domains, $scales, $profiles, $profileVersions,
            $instrumentTypes, $instrumentGroups, $instruments, $selfAssessmentTemplates,
            $selfAssessmentQuestions, $selfAssessments, $classifications, $interventions, $reports, $authors,
        );

        return json_encode([
            'schema_version' => BackupSchemaCompatibility::CURRENT,
            'app_version' => (string) config('app.version'),
            'generated_at' => Carbon::now()->toIso8601String(),
            'organization' => ['ulid' => $organization->ulid, 'name' => $organization->name, 'type' => $organization->type->value],
            'exported_by' => ['name' => $user->name, 'email' => $user->email],
            'capabilities' => [
                'classes', 'students', 'enrollments', 'academic_years', 'subjects', 'academic_periods', 'scales', 'instrument_types', 'domains',
                'assessment_profiles', 'assessment_profile_versions', 'profile_version_domains', 'profile_version_periods',
                'instruments', 'instrument_groups', 'instrument_items', 'item_domain_allocations', 'student_item_scores',
                'classifications', 'self_assessment_templates', 'self_assessment_questions', 'self_assessments',
                'self_assessment_responses', 'interim_assessments', 'evidence_records', 'interventions',
                'intervention_reviews', 'reports',
            ],

            'academic_years' => $academicYears->map(fn (AcademicYear $year): array => $this->academicYearRow($year))->values(),
            'subjects' => $subjects->map(fn (Subject $subject): array => $this->subjectRow($subject))->values(),
            'academic_periods' => $academicPeriods->map(fn (AcademicPeriod $period): array => $this->academicPeriodRow($period))->values(),
            'scales' => $scales->map(fn (Scale $scale): array => $this->scaleRow($scale))->values(),
            'instrument_types' => $instrumentTypes->map(fn (InstrumentType $type): array => $this->instrumentTypeRow($type))->values(),
            'domains' => $domains->map(fn (Domain $domain): array => $this->domainRow($domain, $refs))->values(),
            'assessment_profiles' => $profiles->map(fn (AssessmentProfile $profile): array => $this->profileRow($profile))->values(),
            'assessment_profile_versions' => $profileVersions->map(fn (AssessmentProfileVersion $version): array => $this->profileVersionRow($version, $refs))->values(),
            'profile_version_domains' => $profileVersions->flatMap(fn (AssessmentProfileVersion $version): array => $this->profileVersionDomainRows($version, $refs))->values(),
            'profile_version_periods' => $profileVersions->flatMap(fn (AssessmentProfileVersion $version): array => $this->profileVersionPeriodRows($version, $refs))->values(),

            'classes' => $classes->map(fn (SchoolClass $class): array => $this->classRow($class, $refs))->values(),
            'students' => $students->map(fn (Student $student): array => [
                'ulid' => $student->ulid,
                'pseudonym_code' => $student->pseudonym_code,
                'display_name' => $student->identity?->display_name,
            ])->values(),
            'enrollments' => $enrollments->map(fn (Enrollment $enrollment): array => [
                'ulid' => $enrollment->ulid,
                'class_ulid' => $classes->firstWhere('id', $enrollment->class_id)?->ulid,
                'student_ulid' => $enrollment->student?->ulid,
                'status' => $enrollment->status->value,
                'enrolled_on' => $enrollment->enrolled_on->toDateString(),
                'left_on' => $enrollment->left_on?->toDateString(),
                'class_number' => $enrollment->class_number,
            ])->values(),

            'instruments' => $instruments->map(fn (Instrument $instrument): array => $this->instrumentRow($instrument, $refs))->values(),
            'instrument_groups' => $instrumentGroups->map(fn (InstrumentGroup $group): array => $this->instrumentGroupRow($group, $refs))->values(),
            'instrument_items' => $instrumentItems->map(fn (InstrumentItem $item): array => $this->instrumentItemRow($item, $refs))->values(),
            'item_domain_allocations' => $instrumentItems->flatMap(fn (InstrumentItem $item): array => $this->itemDomainAllocationRows($item, $refs))->values(),
            'student_item_scores' => $itemScores->map(fn (StudentItemScore $score): array => $this->studentItemScoreRow($score, $refs))->values(),

            'classifications' => $classifications->map(fn (Classification $classification): array => $this->classificationRow($classification, $refs))->values(),

            'self_assessment_templates' => $selfAssessmentTemplates->map(fn (SelfAssessmentTemplate $template): array => $this->selfAssessmentTemplateRow($template, $refs))->values(),
            'self_assessment_questions' => $selfAssessmentQuestions->map(fn (SelfAssessmentQuestion $question): array => $this->selfAssessmentQuestionRow($question, $refs))->values(),
            'self_assessments' => $selfAssessments->map(fn (SelfAssessment $selfAssessment): array => $this->selfAssessmentRow($selfAssessment, $refs))->values(),
            'self_assessment_responses' => $selfAssessmentResponses->map(fn (SelfAssessmentResponse $response): array => $this->selfAssessmentResponseRow($response, $refs))->values(),

            'interim_assessments' => $interimAssessments->map(fn (InterimAssessment $interim): array => $this->interimAssessmentRow($interim, $refs))->values(),
            'evidence_records' => $evidenceRecords->map(fn (EvidenceRecord $record): array => $this->evidenceRecordRow($record, $refs))->values(),

            'interventions' => $interventions->map(fn (Intervention $intervention): array => $this->interventionRow($intervention, $refs))->values(),
            'intervention_reviews' => $interventionReviews->map(fn (InterventionReview $review): array => $this->interventionReviewRow($review, $refs))->values(),

            // Only finalized reports: a draft "regenerates from whatever the
            // data says now" (ReportStatus docblock) — it is not a frozen
            // fact yet, so it stays out of the restorable payload entirely
            // (§28 of the import brief). Its own PDF still travels via
            // addReportPdfs(), unchanged.
            'reports' => $reports->where('status', ReportStatus::Finalized)->map(fn (Report $report): array => $this->reportRow($report, $refs))->values(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    protected function readme(Organization $organization): string
    {
        $hours = $this->retentionPolicy->dataExportAvailabilityHours();

        return <<<TXT
        Lapispro — exportação de dados

        Organização: {$organization->name}
        Gerado em: {$this->now()}

        Este ficheiro contém os dados a que a sua conta tem acesso — as suas
        próprias turmas e o que lhes está associado. Não inclui trabalho
        pedagógico de colegas, nem dados de outras organizações.

        Exportacao-Lapispro.xlsx
          Ficheiro para consulta e utilização — abra no Excel ou equivalente.
          Nomes de turmas, alunos e disciplinas em vez de identificadores
          técnicos, uma folha por tipo de dado.

        backup-lapis.json
          Cópia técnica estruturada, para futura compatibilidade com o Lapispro
          (importação/restauro). Não se destina a leitura direta.

        Não inclui, em nenhuma circunstância: password, autenticação de dois
        fatores, passkeys, tokens de sessão ou de convite, nem segredos de
        configuração da plataforma.

        Este ficheiro fica disponível durante {$hours} horas e é depois
        removido automaticamente — não é um backup técnico da plataforma.
        TXT;
    }

    protected function now(): string
    {
        return Carbon::now()->toDayDateTimeString();
    }
}
