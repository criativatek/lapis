<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UsageInsightsRequest;
use App\Models\UsageUserState;
use App\Services\Usage\Insights\UsageOverview;
use App\Services\Usage\Insights\UsageRanking;
use App\Services\Usage\Insights\UsageUserDirectory;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

/**
 * O painel de utilização e acompanhamento (`/admin/usage`). Só leitura.
 *
 * O acesso é do middleware `usage-insights` nas rotas; este controlador não
 * decide quem vê, só o que mostra. Tudo vem do agregado diário — nunca de
 * conteúdos pedagógicos.
 */
class AdminUsageController extends Controller
{
    public function overview(UsageInsightsRequest $request, UsageOverview $overview): Response
    {
        return Inertia::render('admin/usage/Overview', $overview->build($request->filters(), CarbonImmutable::now()));
    }

    public function users(UsageInsightsRequest $request, UsageUserDirectory $directory): Response
    {
        return Inertia::render('admin/usage/Users', $directory->list($request->filters(), CarbonImmutable::now()));
    }

    public function user(UsageInsightsRequest $request, UsageUserState $usageUser, UsageUserDirectory $directory): Response
    {
        return Inertia::render('admin/usage/User', $directory->detail($usageUser, $request->filters(), CarbonImmutable::now()));
    }

    public function ranking(UsageInsightsRequest $request, UsageRanking $ranking): Response
    {
        return Inertia::render('admin/usage/Ranking', $ranking->build($request->filters()));
    }
}
