<?php

use App\Http\Controllers\AcademicYearController;
use App\Http\Controllers\ActivityController;
use App\Http\Controllers\AssessmentController;
use App\Http\Controllers\AssessmentProfileController;
use App\Http\Controllers\ChangelogController;
use App\Http\Controllers\ClassController;
use App\Http\Controllers\ClassificationController;
use App\Http\Controllers\ClassPhotoImportController;
use App\Http\Controllers\ClassProfileMigrationController;
use App\Http\Controllers\ClassReassignmentController;
use App\Http\Controllers\ClassStatisticsController;
use App\Http\Controllers\CorrectionImportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DataExportController;
use App\Http\Controllers\DataImportController;
use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\EvidenceController;
use App\Http\Controllers\InovarExportController;
use App\Http\Controllers\InstrumentController;
use App\Http\Controllers\InstitutionAdminController;
use App\Http\Controllers\InterimAssessmentController;
use App\Http\Controllers\InterventionController;
use App\Http\Controllers\InvitationAcceptanceController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\OrganizationMembershipController;
use App\Http\Controllers\PublicSelfAssessmentController;
use App\Http\Controllers\Reports\ReportController;
use App\Http\Controllers\Reports\ReportExportController;
use App\Http\Controllers\Reports\ReportRewriteController;
use App\Http\Controllers\Reports\ReportSectionController;
use App\Http\Controllers\Reports\ReportTemplateController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\ResultsController;
use App\Http\Controllers\RosterImportController;
use App\Http\Controllers\ScaleController;
use App\Http\Controllers\SelfAssessmentController;
use App\Http\Controllers\StudentPhotoController;
use App\Http\Controllers\StudentProgressController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\TeamController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

// Not tenant data — the changelog is the same for everyone, so it stays
// outside the 'organization' group (no tenant resolution needed).
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('novidades', [ChangelogController::class, 'index'])->name('changelog.index');
});

// The student's no-login entry into a self-assessment (§15): no 'auth', no
// 'organization' — the signed URL itself is the only access control, and the
// controller resolves the tenant by hand from the class in the URL. GET and
// POST share the exact same path on purpose: a signed URL's signature covers
// the path + query string, not the HTTP verb, so the same link a student
// opens is also what the form posts back to.
Route::middleware(['signed', 'throttle:120,1'])->group(function () {
    Route::get('auto/{classUlid}/{periodUlid}/{enrollmentUlid}', [PublicSelfAssessmentController::class, 'edit'])
        ->name('self-assessments.public.edit');
    Route::post('auto/{classUlid}/{periodUlid}/{enrollmentUlid}', [PublicSelfAssessmentController::class, 'store'])
        ->name('self-assessments.public.store');
});

// An invitation into an institutional organization (Fatia 3): no `auth`, no
// `organization` — reached by a guest as often as by someone already signed
// in, and the token itself (looked up by hash, never by a guessable id) is
// the access control, the same shape password-reset tokens already use. Not
// `signed`: this is not a Laravel-signed URL, it is this app's own
// single-use, expirable, hash-stored token.
Route::middleware('throttle:60,1')->get('invitations/{token}', [InvitationAcceptanceController::class, 'show'])
    ->name('invitations.show');

// Teacher-facing area. Everything here reads tenant-owned data, so an
// organization must be resolved before the request reaches a controller.
Route::middleware(['auth', 'verified', 'organization'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    // Which organization the session is acting for (Fatia 2). The membership
    // check lives in the controller, not a route param binding — a stranger's
    // ulid must 403, never a clean 404 that confirms it exists.
    Route::post('organizations/switch', [OrganizationController::class, 'switch'])->name('organizations.switch');

    // Audit trail (§22.4) — read-only view of the organization's recorded events.
    Route::get('activity', [ActivityController::class, 'index'])->name('activity.index');

    // Academic years are the temporal foundation (§9). Reached from the header
    // year selector, not the sidebar — the year is a context, not a menu item.
    Route::get('academic-years', [AcademicYearController::class, 'index'])->name('academic-years.index');
    Route::get('academic-years/create', [AcademicYearController::class, 'create'])->name('academic-years.create');
    Route::post('academic-years', [AcademicYearController::class, 'store'])->name('academic-years.store');
    Route::get('academic-years/{academic_year}/edit', [AcademicYearController::class, 'edit'])->name('academic-years.edit');
    Route::put('academic-years/{academic_year}', [AcademicYearController::class, 'update'])->name('academic-years.update');
    Route::delete('academic-years/{academic_year}', [AcademicYearController::class, 'destroy'])->name('academic-years.destroy');

    // Subjects — the teacher's disciplines. Managed from the header subject
    // selector, like academic years.
    Route::get('subjects', [SubjectController::class, 'index'])->name('subjects.index');
    Route::post('subjects', [SubjectController::class, 'store'])->name('subjects.store');
    Route::put('subjects/{subject}', [SubjectController::class, 'update'])->name('subjects.update');
    Route::delete('subjects/{subject}', [SubjectController::class, 'destroy'])->name('subjects.destroy');

    // Assessment profiles — a sidebar module (gated by the assessment_profiles
    // module). Activation freezes the draft into an immutable version.
    Route::get('assessment-profiles', [AssessmentProfileController::class, 'index'])
        ->middleware('module:assessment_profiles')->name('assessment-profiles.index');
    Route::get('assessment-profiles/create', [AssessmentProfileController::class, 'create'])
        ->middleware('module:assessment_profiles')->name('assessment-profiles.create');
    Route::post('assessment-profiles', [AssessmentProfileController::class, 'store'])
        ->middleware('module:assessment_profiles')->name('assessment-profiles.store');
    Route::post('scales', [ScaleController::class, 'store'])
        ->middleware('module:assessment_profiles')->name('scales.store');
    Route::get('assessment-profiles/{assessment_profile}/edit', [AssessmentProfileController::class, 'edit'])
        ->middleware('module:assessment_profiles')->name('assessment-profiles.edit');
    Route::put('assessment-profiles/{assessment_profile}', [AssessmentProfileController::class, 'update'])
        ->middleware('module:assessment_profiles')->name('assessment-profiles.update');
    Route::post('assessment-profiles/{assessment_profile}/activate', [AssessmentProfileController::class, 'activate'])
        ->middleware('module:assessment_profiles')->name('assessment-profiles.activate');
    Route::delete('assessment-profiles/{assessment_profile}', [AssessmentProfileController::class, 'destroy'])
        ->middleware('module:assessment_profiles')->name('assessment-profiles.destroy');

    // Classes — the teacher's own turmas (gated by the classes module). Students
    // are enrolled from the class detail page.
    Route::middleware('module:institution_admin')->group(function () {
        // Must precede `classes/{class}` so "reassignment" is not consumed as a class ULID.
        Route::get('classes/reassignment', [ClassReassignmentController::class, 'index'])->name('classes.reassignment.index');
        Route::post('classes/reassignment/{class}/assign', [ClassReassignmentController::class, 'assign'])->name('classes.reassignment.assign');
    });

    Route::middleware('module:classes')->group(function () {
        Route::get('classes', [ClassController::class, 'index'])->name('classes.index');
        Route::get('classes/create', [ClassController::class, 'create'])->name('classes.create');
        Route::post('classes', [ClassController::class, 'store'])->name('classes.store');
        Route::get('classes/{class}', [ClassController::class, 'show'])->name('classes.show');
        Route::post('classes/{class}/activate', [ClassController::class, 'activate'])->name('classes.activate');
        Route::put('classes/{class}/profile', [ClassController::class, 'updateProfile'])->name('classes.profile.update');
        Route::delete('classes/{class}', [ClassController::class, 'destroy'])->name('classes.destroy');

        // Profile migration (§10.2, A4): required when a class with results changes
        // version — preview the impact, then confirm with a reason.
        Route::get('classes/{class}/profile-migration', [ClassProfileMigrationController::class, 'create'])->name('classes.profile-migration.create');
        Route::post('classes/{class}/profile-migration', [ClassProfileMigrationController::class, 'store'])->name('classes.profile-migration.store');

        Route::post('classes/{class}/students', [EnrollmentController::class, 'store'])->name('classes.students.store');
        Route::put('classes/{class}/students/{enrollment}', [EnrollmentController::class, 'update'])->name('classes.students.update');
        // The school's own identifiers, saved as the teacher has them: a column.
        Route::put('classes/{class}/process-numbers', [EnrollmentController::class, 'updateProcessNumbers'])->name('classes.process-numbers.update');
        Route::delete('classes/{class}/students/{enrollment}', [EnrollmentController::class, 'destroy'])->name('classes.students.destroy');
        // The photo is its own request: an upload and a data edit fail
        // differently, and one must not discard the other.
        Route::post('classes/{class}/students/{enrollment}/photo', [StudentPhotoController::class, 'update'])->name('classes.students.photo.update');
        Route::delete('classes/{class}/students/{enrollment}/photo', [StudentPhotoController::class, 'destroy'])->name('classes.students.photo.destroy');

        Route::post('classes/{class}/roster-imports', [RosterImportController::class, 'store'])->name('classes.roster-imports.store');
        Route::post('classes/{class}/photos', [ClassPhotoImportController::class, 'store'])->name('classes.photos.store');
        Route::post('classes/{class}/roster-imports/{token}/photos', [RosterImportController::class, 'attachPhotos'])
            ->where('token', '[0-9a-fA-F-]{36}')
            ->name('classes.roster-imports.attach-photos');
        // Constrained to the shape RosterImportTempStorage::newToken() actually
        // generates (a UUID) — never a bare string, so a token can never itself
        // carry a path segment like ".." into the temp-folder path it builds.
        Route::post('classes/{class}/roster-imports/{token}/confirm', [RosterImportController::class, 'confirm'])
            ->where('token', '[0-9a-fA-F-]{36}')
            ->name('classes.roster-imports.confirm');
        Route::get('classes/{class}/roster-imports/{token}/photos/{index}', [RosterImportController::class, 'previewPhoto'])
            ->where(['token' => '[0-9a-fA-F-]{36}', 'index' => '[0-9]+'])
            ->name('classes.roster-imports.preview-photo');

        Route::get('students/{student}/photo', [StudentPhotoController::class, 'show'])->name('students.photo');
    });

    // Instruments and the grading grid. Created inside a class; the grid is the
    // instrument's own page.
    Route::middleware('module:instruments')->group(function () {
        Route::get('instruments', [InstrumentController::class, 'index'])->name('instruments.index');
        Route::get('classes/{class}/instruments/create', [InstrumentController::class, 'create'])->name('instruments.create');
        Route::post('classes/{class}/instruments', [InstrumentController::class, 'store'])->name('instruments.store');
        Route::get('instruments/{instrument}', [InstrumentController::class, 'show'])->name('instruments.show');
        // The grid a teacher fills in offline and brings back. Deliberately on
        // the instrument: it is generated FROM one, and there is nothing to ask
        // when the answer is the evaluation already on screen (§11).
        Route::get('instruments/{instrument}/grelha', [InstrumentController::class, 'downloadGrid'])->name('instruments.grid');
        Route::get('instruments/{instrument}/edit', [InstrumentController::class, 'edit'])->name('instruments.edit');
        Route::put('instruments/{instrument}', [InstrumentController::class, 'update'])->name('instruments.update');
        Route::post('instruments/{instrument}/cancel', [InstrumentController::class, 'cancel'])->name('instruments.cancel');
        Route::post('instruments/{instrument}/revert-cancellation', [InstrumentController::class, 'revertCancellation'])->name('instruments.revert-cancellation');
        Route::post('instruments/{instrument}/scores', [InstrumentController::class, 'saveScores'])->name('instruments.scores.save');
        // Saving and finishing are different acts: the grid's "Guardar" never
        // closes a correction, and closing one is its own explicit decision.
        Route::post('instruments/{instrument}/complete', [InstrumentController::class, 'completeCorrection'])->name('instruments.complete');
        Route::post('instruments/{instrument}/reopen', [InstrumentController::class, 'reopenCorrection'])->name('instruments.reopen');
        Route::delete('instruments/{instrument}', [InstrumentController::class, 'destroy'])->name('instruments.destroy');
    });

    // Avaliações — a read-only view of instruments-as-applications. Creating
    // and correcting hand off to the existing instruments routes above;
    // nothing here writes an Instrument.
    Route::middleware('module:assessments')->group(function () {
        Route::get('assessments', [AssessmentController::class, 'index'])->name('assessments.index');
        Route::get('assessments/{instrument}', [AssessmentController::class, 'show'])->name('assessments.show');
    });

    // Importing correction grids exported from other assessment platforms
    // (Plickers today, others later). A Pro/Institucional capability: the
    // middleware is the access control, and a Base organization cannot reach any
    // of these by typing a URL. Every route also runs the policy, because the
    // plan says "may your organization", not "is this yours".
    Route::middleware('module:correction_grid_import')->group(function () {
        Route::get('imports/correction/create', [CorrectionImportController::class, 'create'])->name('correction-imports.create');
        Route::post('imports/correction', [CorrectionImportController::class, 'store'])->name('correction-imports.store');
        Route::get('imports/correction/{import}', [CorrectionImportController::class, 'edit'])->name('correction-imports.edit');
        Route::patch('imports/correction/{import}', [CorrectionImportController::class, 'update'])->name('correction-imports.update');
        Route::post('imports/correction/{import}/confirm', [CorrectionImportController::class, 'confirm'])->name('correction-imports.confirm');
        Route::delete('imports/correction/{import}', [CorrectionImportController::class, 'destroy'])->name('correction-imports.destroy');
    });

    // Results — the calculation engine's output for a class, per period.
    Route::middleware('module:results')->group(function () {
        Route::get('results', [ResultsController::class, 'index'])->name('results.index');
        // Declared BEFORE the {period?} wildcard, which would otherwise swallow
        // it and go looking for a period called «quadro-sintese».
        Route::get('classes/{class}/results/quadro-sintese', [ResultsController::class, 'summary'])->name('results.summary');
        // Same reason as above: declared before the {period?} wildcard. The
        // period is optional — without one the read model opens on the latest
        // period that actually has results.
        Route::get('classes/{class}/results/estatistica/{period?}', [ClassStatisticsController::class, 'show'])->name('results.statistics');

        // Avaliações intercalares — a kept photograph of a class on a date.
        // Inside Resultados, because that is what it is a photograph OF; not a
        // module of its own (§21).
        Route::post('classes/{class}/avaliacoes-intercalares', [InterimAssessmentController::class, 'store'])->name('interim-assessments.store');
        Route::get('classes/{class}/avaliacoes-intercalares/{interimAssessment}', [InterimAssessmentController::class, 'show'])->name('interim-assessments.show');
        Route::get('classes/{class}/avaliacoes-intercalares/{interimAssessment}/comparar', [InterimAssessmentController::class, 'compare'])->name('interim-assessments.compare');
        // Renaming only — the snapshot itself refuses to move.
        Route::put('classes/{class}/avaliacoes-intercalares/{interimAssessment}', [InterimAssessmentController::class, 'update'])->name('interim-assessments.update');
        Route::delete('classes/{class}/avaliacoes-intercalares/{interimAssessment}', [InterimAssessmentController::class, 'destroy'])->name('interim-assessments.destroy');

        // Exporting a period's qualitative mentions into the grid INOVAR
        // produced. Its own capability: the assessment core does not depend on
        // it, and a school that never uses INOVAR never meets it.
        Route::middleware('module:inovar_export')->group(function () {
            Route::get('classes/{class}/exports/inovar/{period}', [InovarExportController::class, 'create'])->name('exports.inovar.create');
            Route::post('classes/{class}/exports/inovar/{period}', [InovarExportController::class, 'store'])->name('exports.inovar.store');
            // A DOWNLOAD, so a GET the browser can follow on its own — the same
            // shape the instrument grid and the pauta export already use. A
            // binary response cannot come back through an Inertia visit.
            Route::get('classes/{class}/exports/inovar/{period}/{token}', [InovarExportController::class, 'generate'])->name('exports.inovar.generate');
        });
        Route::get('classes/{class}/results/{period?}', [ResultsController::class, 'show'])->name('results.show');

        // The decision layer (§7): propose from the engine, then the teacher confirms.
        Route::get('classes/{class}/classifications/{period?}', [ClassificationController::class, 'show'])->name('classifications.show');
        Route::post('classes/{class}/classifications/{period}/propose', [ClassificationController::class, 'propose'])->name('classifications.propose');
        Route::post('classes/{class}/classifications/{period}/publish', [ClassificationController::class, 'publish'])->name('classifications.publish');
        // The decision is identified by WHOSE it is — this student, this period,
        // this scope — and not by the row that happens to store it. A period
        // whose proposals were never generated has no row yet, and the teacher's
        // classification must not wait on one; this opens it and confirms
        // through the same service either way.
        Route::post('classes/{class}/classifications/{period}/{enrollment}/decide', [ClassificationController::class, 'decide'])
            ->name('classifications.decide');
    });

    // Relatórios (§53). Two artifacts under one module, deliberately named
    // apart: `reports.*` is the report DOCUMENT — sections, an author, a draft
    // that gets finalized and exported — and `pautas.*` is the classification
    // sheet, a table of decided grades. Sharing the `reports.show` name between
    // them would have made every link ambiguous.
    Route::middleware('module:reports')->group(function () {
        Route::get('reports', [ReportController::class, 'index'])->name('reports.index');

        // Every literal segment is declared before `reports/{report}`, or it
        // would be swallowed as an (invalid) report ulid.
        Route::get('reports/novo', [ReportController::class, 'create'])->name('reports.create');
        Route::post('reports', [ReportController::class, 'store'])->name('reports.store');
        Route::get('reports/contexto/{class}', [ReportController::class, 'context'])->name('reports.context');

        // Modelos (§25). Declared before `reports/{report}`, or «modelos» would
        // be swallowed as an (invalid) report ulid.
        Route::get('reports/modelos', [ReportTemplateController::class, 'index'])->name('reports.templates.index');
        Route::post('reports/modelos', [ReportTemplateController::class, 'store'])->name('reports.templates.store');
        Route::get('reports/modelos/{template}', [ReportTemplateController::class, 'edit'])->name('reports.templates.edit');
        Route::put('reports/modelos/{template}', [ReportTemplateController::class, 'update'])->name('reports.templates.update');
        Route::post('reports/modelos/{template}/duplicar', [ReportTemplateController::class, 'duplicate'])->name('reports.templates.duplicate');

        // The pauta.
        Route::get('reports/pautas', [ReportsController::class, 'index'])->name('pautas.index');
        Route::get('classes/{class}/report', [ReportsController::class, 'show'])->name('pautas.show');
        Route::get('classes/{class}/report/export', [ReportsController::class, 'export'])->name('pautas.export');
        Route::put('classes/{class}/report/evidence-setting', [ReportsController::class, 'updateEvidenceSetting'])->name('pautas.evidence-setting.update');
        Route::put('classes/{class}/report/students/{enrollment}/evidence-setting', [ReportsController::class, 'updateStudentEvidenceSetting'])->name('pautas.student-evidence-setting.update');

        // The report document itself. Last, so the wildcard cannot shadow the
        // literal segments above.
        Route::get('reports/{report}', [ReportController::class, 'show'])->name('reports.show');
        Route::put('reports/{report}', [ReportController::class, 'update'])->name('reports.update');
        Route::delete('reports/{report}', [ReportController::class, 'destroy'])->name('reports.destroy');
        Route::post('reports/{report}/gerar', [ReportController::class, 'regenerate'])->name('reports.regenerate');
        // §37: after this the document is fixed. There is no route back —
        // correcting a finished report means deriving a new one (§32).
        Route::post('reports/{report}/finalizar', [ReportController::class, 'finalize'])->name('reports.finalize');
        Route::post('reports/{report}/derivar', [ReportController::class, 'derive'])->name('reports.derive');
        // §18: the draft's arrangement, kept for next time. Structure only.
        Route::post('reports/{report}/guardar-modelo', [ReportTemplateController::class, 'storeFromReport'])
            ->name('reports.templates.from-report');
        // The logo frozen INTO this report — served from the private disk by an
        // authorizing controller, exactly as the live one is (§39, §65).
        Route::get('reports/{report}/logotipo', [ReportController::class, 'logo'])->name('reports.logo');

        // §47: two formats, one content. GETs the browser can follow on its
        // own — a binary response cannot come back through an Inertia visit.
        Route::get('reports/{report}/pdf', [ReportExportController::class, 'pdf'])->name('reports.export.pdf');
        Route::get('reports/{report}/word', [ReportExportController::class, 'docx'])->name('reports.export.docx');

        // One section at a time (§44) — edit, regenerate from today's data,
        // restore the last automatic text, reorder.
        Route::put('reports/{report}/seccoes/ordem', [ReportSectionController::class, 'reorder'])->name('reports.sections.reorder');
        Route::put('reports/{report}/seccoes/{section}', [ReportSectionController::class, 'update'])->name('reports.sections.update');
        Route::post('reports/{report}/seccoes/{section}/gerar', [ReportSectionController::class, 'regenerate'])->name('reports.sections.regenerate');
        Route::post('reports/{report}/seccoes/{section}/restaurar', [ReportSectionController::class, 'restore'])->name('reports.sections.restore');

        // «Aperfeiçoar redação» (§4 do brief de IA). Rate limited per user AND
        // per organization: one teacher holding down a button cannot spend the
        // school's budget, and thirty teachers each within their own limit still
        // cannot (§28).
        Route::post('reports/{report}/seccoes/{section}/aperfeicoar', [ReportRewriteController::class, 'store'])
            ->middleware('throttle:report-writing-assistant')
            ->name('reports.sections.rewrite');
    });

    // Evolução do Aluno — one student's year, read forwards. A VIEW, not a
    // document: Relatórios already produces the document, and this feeds it
    // without replacing it (§3 do brief).
    Route::middleware('module:student_progress')->group(function () {
        Route::get('evolucao', [StudentProgressController::class, 'index'])->name('student-progress.index');
        Route::get('classes/{class}/evolucao', [StudentProgressController::class, 'show'])->name('student-progress.class');
        // Through the ENROLMENT, never a student id: a result belongs to the
        // (student, class) pair, and a student who left still has a year (§58).
        Route::get('classes/{class}/evolucao/{enrollment}', [StudentProgressController::class, 'student'])->name('student-progress.student');
    });

    // Records — the teacher's logbook (§14). Qualitative evidence, never a grade.
    Route::middleware('module:records')->group(function () {
        Route::get('records', [EvidenceController::class, 'index'])->name('records.index');
        Route::get('classes/{class}/records', [EvidenceController::class, 'show'])->name('records.show');
        Route::post('classes/{class}/records', [EvidenceController::class, 'store'])->name('records.store');
        Route::put('records/{record}', [EvidenceController::class, 'update'])->name('records.update');
        Route::delete('records/{record}', [EvidenceController::class, 'destroy'])->name('records.destroy');
    });

    // Self-assessment (§15). Compared with the calculated grade, never summed in.
    Route::middleware('module:self_assessments')->group(function () {
        Route::get('self-assessments', [SelfAssessmentController::class, 'index'])->name('self-assessments.index');
        Route::get('classes/{class}/self-assessments/{period?}', [SelfAssessmentController::class, 'show'])->name('self-assessments.show');
        // Must come before the {enrollment} wildcard route below, or "links"
        // would itself be swallowed as an (invalid) enrollment ulid. Gated by
        // its own module — the base plan keeps self_assessments (the teacher
        // fills it in an interview) but not the student's no-login link.
        Route::get('classes/{class}/self-assessments/{period}/links', [SelfAssessmentController::class, 'links'])
            ->middleware('module:self_assessment_links')
            ->name('self-assessments.links');
        Route::get('classes/{class}/self-assessments/{period}/{enrollment}', [SelfAssessmentController::class, 'edit'])->name('self-assessments.edit');
        Route::post('classes/{class}/self-assessments/{period}/{enrollment}', [SelfAssessmentController::class, 'store'])->name('self-assessments.store');
    });

    // Interventions (§14): what the teacher did, for a student, a group or the
    // whole class — never part of the calculation. PATCH stays the light
    // status-only action so the quick "Concluir" button does not have to
    // resend the whole record; PUT is the full edit.
    Route::middleware('module:interventions')->group(function () {
        Route::get('interventions', [InterventionController::class, 'index'])->name('interventions.index');
        Route::get('classes/{class}/interventions', [InterventionController::class, 'show'])->name('interventions.show');
        Route::post('classes/{class}/interventions', [InterventionController::class, 'store'])->name('interventions.store');
        Route::put('interventions/{intervention}', [InterventionController::class, 'update'])->name('interventions.update');
        Route::patch('interventions/{intervention}', [InterventionController::class, 'updateStatus'])->name('interventions.status.update');
        Route::post('interventions/{intervention}/reviews', [InterventionController::class, 'addReview'])->name('interventions.reviews.store');
        Route::delete('interventions/{intervention}', [InterventionController::class, 'destroy'])->name('interventions.destroy');
    });

    // Leaving is not an "Equipa" action — any member, on any plan, must be
    // able to leave any institutional organization they belong to, so this
    // sits outside the `module:institution_admin` group below (which exists
    // to keep a Base/Pro organization out of governance features it hasn't
    // paid for; leaving isn't one of those).
    Route::post('organizations/leave', [OrganizationMembershipController::class, 'leave'])
        ->name('organizations.leave');

    // "Exportar os meus dados" (Fatia 4, §20/§52) — every plan, not gated by
    // any module: this is portability, not a paid feature.
    Route::get('data-exports', [DataExportController::class, 'index'])->name('data-exports.index');
    Route::post('data-exports', [DataExportController::class, 'store'])->name('data-exports.store');
    Route::get('data-exports/{data_export}', [DataExportController::class, 'download'])->name('data-exports.download');

    // "Importar dados" (Fatia 6) — restoring a backup this same export
    // produces. Deliberately absent from EnsureAccountIsOperational's
    // allow-list: unlike export, a restore is never permitted while the
    // account or the current organization is winding down (§37-38).
    Route::get('data-imports/create', [DataImportController::class, 'create'])->name('data-imports.create');
    Route::post('data-imports', [DataImportController::class, 'store'])->name('data-imports.store');
    Route::get('data-imports/{data_import}', [DataImportController::class, 'edit'])->name('data-imports.edit');
    Route::post('data-imports/{data_import}/confirm', [DataImportController::class, 'confirm'])->name('data-imports.confirm');
    Route::delete('data-imports/{data_import}', [DataImportController::class, 'destroy'])->name('data-imports.destroy');

    // Equipa (Fatia 3) — an institutional organization's members and pending
    // invitations, owner-only (TeamController, OrganizationInvitationPolicy).
    // Fatia 4 adds removing a member, transferring ownership, and reassigning
    // orphaned classes — still owner-only. The module gate keeps a Base/Pro
    // organization out; it cannot check WHO is asking or the organization's
    // actual TYPE, which is why the policy still runs on every action
    // underneath it.
    Route::middleware('module:institution_admin')->group(function () {
        Route::get('institution', [InstitutionAdminController::class, 'index'])->name('institution.index');
        Route::get('team', [TeamController::class, 'index'])->name('team.index');
        Route::post('team/invitations', [TeamController::class, 'store'])->name('team.invitations.store');
        Route::delete('team/invitations/{invitation}', [TeamController::class, 'destroy'])->name('team.invitations.destroy');
        // No {member} route parameter: `User` carries no `ulid` (nothing has
        // ever needed to address one in a URL before this fatia), so the
        // target travels in the request body instead of exposing a raw
        // sequential id in the path — see TeamController::targetMember().
        Route::delete('team/members', [TeamController::class, 'removeMember'])->name('team.members.destroy');
        Route::post('team/members/transfer-ownership', [TeamController::class, 'transferOwnership'])->name('team.members.transfer-ownership');

        // Organization closure (Fatia 5, §9-§12) — owner-only. The UI lives
        // at institution.index; these mutation paths stay stable.
        Route::post('team/closure', [TeamController::class, 'requestClosure'])->name('organization.closure.request');
        Route::delete('team/closure', [TeamController::class, 'cancelClosure'])->name('organization.closure.cancel');
    });
});

require __DIR__.'/app.php';
require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
