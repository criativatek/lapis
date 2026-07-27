<?php

namespace App\Http\Controllers;

use App\Http\Requests\ScaleRequest;
use App\Models\Scale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class ScaleController extends Controller
{
    public function store(ScaleRequest $request): RedirectResponse
    {
        Gate::authorize('create', Scale::class);

        Scale::create([...$request->validated(), 'kind' => 'custom']);

        return back();
    }
}
