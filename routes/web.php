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
use App\Http\Controllers\ClassStatisticsController;
use App\Http\Controllers\CorrectionImportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\EvidenceController;
use App\Http\Controllers\InovarExportController;
use App\Http\Controllers\InstrumentController;
use App\Http\Controllers\InterimAssessmentController;
use App\Http\Controllers\InterventionController;
use App\Http\Controllers\PublicSelfAssessmentController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\ResultsController;
use App\Http\Controllers\RosterImportController;
use App\Http\Controllers\ScaleController;
use App\Http\Controllers\SelfAssessmentController;
use App\Http\Controllers\StudentPhotoController;
use App\Http\Controllers\SubjectController;
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

// Teacher-facing area. Everything here reads tenant-owned data, so an
// organization must be resolved before the request reaches a controller.
Route::middleware(['auth', 'verified', 'organization'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

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

    // Reports — the classification sheet (pauta) of decided grades, printable and
    // exportable to CSV.
    Route::middleware('module:reports')->group(function () {
        Route::get('reports', [ReportsController::class, 'index'])->name('reports.index');
        Route::get('classes/{class}/report', [ReportsController::class, 'show'])->name('reports.show');
        Route::get('classes/{class}/report/export', [ReportsController::class, 'export'])->name('reports.export');
        Route::put('classes/{class}/report/evidence-setting', [ReportsController::class, 'updateEvidenceSetting'])->name('reports.evidence-setting.update');
        Route::put('classes/{class}/report/students/{enrollment}/evidence-setting', [ReportsController::class, 'updateStudentEvidenceSetting'])->name('reports.student-evidence-setting.update');
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
});

require __DIR__.'/app.php';
require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
