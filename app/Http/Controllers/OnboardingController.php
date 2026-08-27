<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The "Primeiros passos" card's one piece of state (A1a, Onboarding & Help):
 * whether the acting teacher dismissed it. Never the progress itself — that
 * is computed live by DashboardController::firstSteps() and is not affected
 * by either action here.
 *
 * No {user} parameter on either route, mirroring account-closure
 * (Settings\ProfileController): both always act on the authenticated user,
 * never a target resolved from the URL, so there is no cross-account surface
 * to guard.
 */
class OnboardingController extends Controller
{
    /**
     * Hide the card. Dismissing never deletes or resets progress — there is
     * no progress stored here to reset.
     */
    public function dismiss(Request $request): RedirectResponse
    {
        $request->user()->onboarding_dismissed_at = now();
        $request->user()->save();

        return back();
    }

    /**
     * Bring the card back. Today reached only through this endpoint itself
     * (exercised by its own test) — A2's Help Center is the future UI entry
     * point that will let a teacher ask for it explicitly.
     */
    public function restore(Request $request): RedirectResponse
    {
        $request->user()->onboarding_dismissed_at = null;
        $request->user()->save();

        return back();
    }
}
