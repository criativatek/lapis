<?php

use App\Http\Controllers\AcademicYearController;
use App\Http\Controllers\AssessmentProfileController;
use App\Http\Controllers\ClassController;
use App\Http\Controllers\ClassificationController;
use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\InstrumentController;
use App\Http\Controllers\ResultsController;
use App\Http\Controllers\SubjectController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

// Teacher-facing area. Everything here reads tenant-owned data, so an
// organization must be resolved before the request reaches a controller.
Route::middleware(['auth', 'verified', 'organization'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

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
        Route::put('classes/{class}/profile', [ClassController::class, 'updateProfile'])->name('classes.profile.update');
        Route::delete('classes/{class}', [ClassController::class, 'destroy'])->name('classes.destroy');

        Route::post('classes/{class}/students', [EnrollmentController::class, 'store'])->name('classes.students.store');
        Route::delete('classes/{class}/students/{enrollment}', [EnrollmentController::class, 'destroy'])->name('classes.students.destroy');
    });

    // Instruments and the grading grid. Created inside a class; the grid is the
    // instrument's own page.
    Route::middleware('module:instruments')->group(function () {
        Route::get('instruments', [InstrumentController::class, 'index'])->name('instruments.index');
        Route::get('classes/{class}/instruments/create', [InstrumentController::class, 'create'])->name('instruments.create');
        Route::post('classes/{class}/instruments', [InstrumentController::class, 'store'])->name('instruments.store');
        Route::get('instruments/{instrument}', [InstrumentController::class, 'show'])->name('instruments.show');
        Route::post('instruments/{instrument}/scores', [InstrumentController::class, 'saveScores'])->name('instruments.scores.save');
        Route::delete('instruments/{instrument}', [InstrumentController::class, 'destroy'])->name('instruments.destroy');
    });

    // Results — the calculation engine's output for a class, per period.
    Route::middleware('module:results')->group(function () {
        Route::get('results', [ResultsController::class, 'index'])->name('results.index');
        Route::get('classes/{class}/results/{period?}', [ResultsController::class, 'show'])->name('results.show');

        // The decision layer (§7): propose from the engine, then the teacher confirms.
        Route::get('classes/{class}/classifications/{period?}', [ClassificationController::class, 'show'])->name('classifications.show');
        Route::post('classes/{class}/classifications/{period}/propose', [ClassificationController::class, 'propose'])->name('classifications.propose');
        Route::post('classifications/{classification}/confirm', [ClassificationController::class, 'confirm'])->name('classifications.confirm');
    });
});

require __DIR__.'/app.php';
require __DIR__.'/settings.php';
