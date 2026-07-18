<?php

use App\Http\Controllers\AcademicYearController;
use App\Http\Controllers\AssessmentProfileController;
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
});

require __DIR__.'/app.php';
require __DIR__.'/settings.php';
