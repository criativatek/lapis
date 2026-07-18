<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Renders the "em construção" page for a navigation item whose real page a later
 * phase will build. Every teacher menu item routes here until its phase lands,
 * so the shell is fully navigable and module gating is exercised through real
 * navigation, not just tests.
 *
 * The item's label and phase come from config/navigation.php via the route's
 * defaults, set in routes/app.php.
 */
class PlaceholderController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $route = $request->route();

        return Inertia::render('Placeholder', [
            'title' => $route->defaults['navLabel'] ?? __('Módulo'),
            'phase' => $route->defaults['navPhase'] ?? null,
        ]);
    }
}
