<?php

namespace App\Actions\DataExports;

use App\Models\AuditEvent;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\DataExport;
use App\Models\Enrollment;
use App\Models\EvidenceRecord;
use App\Models\Instrument;
use App\Models\Intervention;
use App\Models\Organization;
use App\Models\Report;
use App\Models\ReportStatus;
use App\Models\SchoolClass;
use App\Models\SelfAssessment;
use App\Models\SelfAssessmentTemplate;
use App\Models\Student;
use App\Models\StudentItemScore;
use App\Models\User;
use App\Services\Assessment\BuildResultsProgression;
use App\Services\Assessment\ScaleProposalResolver;
use App\Services\Audit\AuditLog;
use App\Services\Reporting\Export\PdfRenderer;
use App\Services\Reporting\Export\ReportDocumentBuilder;
use App\Support\Assessment\DecisionScale;
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
 * - `Exportacao-LAPIS.xlsx` — for the teacher. Human names, not ids; the
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
            causer: $user,
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
                causer: $user,
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
        $interventions = Intervention::query()->whereIn('class_id', $classIds)->get();
        $reports = Report::query()->whereIn('class_id', $classIds)->with(['enrollment.student'])->get();
        $itemScores = StudentItemScore::query()
            ->whereIn('instrument_id', $instruments->pluck('id'))
            ->with(['item', 'enrollment.student'])
            ->get();

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

        $zip->addFromString('Exportacao-LAPIS.xlsx', $xlsxBytes);
        $zip->addFromString('backup-lapis.json', $this->technicalBackup(
            $organization,
            $user,
            $classes,
            $students,
            $enrollments,
            $instruments,
            $classifications,
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
            ['Versão do LÁPIS', (string) config('app.version')],
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
     * The technical backup — stable ids and relations, for a future
     * import/restore. Deliberately not meant to be comfortable reading: the
     * XLSX is the document for that.
     *
     * @param  Collection<int, SchoolClass>  $classes
     * @param  Collection<int, Student>  $students
     * @param  Collection<int, Enrollment>  $enrollments
     * @param  Collection<int, Instrument>  $instruments
     * @param  Collection<int, Classification>  $classifications
     */
    protected function technicalBackup(
        Organization $organization,
        User $user,
        Collection $classes,
        Collection $students,
        Collection $enrollments,
        Collection $instruments,
        Collection $classifications,
    ): string {
        return json_encode([
            'schema_version' => 2,
            'app_version' => (string) config('app.version'),
            'generated_at' => Carbon::now()->toIso8601String(),
            'organization' => ['ulid' => $organization->ulid, 'name' => $organization->name, 'type' => $organization->type->value],
            'exported_by' => ['name' => $user->name, 'email' => $user->email],
            'classes' => $classes->map(fn (SchoolClass $class): array => [
                'ulid' => $class->ulid,
                'label' => $class->label,
                'status' => $class->status->value,
                'academic_year' => $class->academicYear->label,
                'subject' => $class->subject?->name,
            ])->values(),
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
            ])->values(),
            'instruments' => $instruments->map(fn (Instrument $instrument): array => [
                'ulid' => $instrument->ulid,
                'class_ulid' => $classes->firstWhere('id', $instrument->class_id)?->ulid,
                'title' => $instrument->title,
                'status' => $instrument->status->value,
            ])->values(),
            'classifications' => $classifications->map(fn (Classification $classification): array => [
                'ulid' => $classification->ulid,
                'enrollment_ulid' => $classification->enrollment?->ulid,
                'scope' => $classification->scope->value,
                'status' => $classification->status->value,
                'proposed_value' => $classification->proposed_value,
                'final_value' => $classification->final_value,
            ])->values(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    protected function readme(Organization $organization): string
    {
        $hours = $this->retentionPolicy->dataExportAvailabilityHours();

        return <<<TXT
        LÁPIS — exportação de dados

        Organização: {$organization->name}
        Gerado em: {$this->now()}

        Este ficheiro contém os dados a que a sua conta tem acesso — as suas
        próprias turmas e o que lhes está associado. Não inclui trabalho
        pedagógico de colegas, nem dados de outras organizações.

        Exportacao-LAPIS.xlsx
          Ficheiro para consulta e utilização — abra no Excel ou equivalente.
          Nomes de turmas, alunos e disciplinas em vez de identificadores
          técnicos, uma folha por tipo de dado.

        backup-lapis.json
          Cópia técnica estruturada, para futura compatibilidade com o LÁPIS
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
