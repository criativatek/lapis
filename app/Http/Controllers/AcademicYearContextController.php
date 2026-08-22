<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AcademicYearContextController extends Controller
{
    public function select(Request $request, AcademicYear $academic_year): RedirectResponse
    {
        $request->session()->put('academic_year_id', $academic_year->getKey());

        return to_route('dashboard');
    }
}
