<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireModule;
use App\Http\Middleware\RequireOrganization;
use App\Http\Middleware\ResolveOrganization;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            ResolveOrganization::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'organization' => RequireOrganization::class,
            'module' => RequireModule::class,
        ]);

        // Resolve the tenant BEFORE route-model binding runs. Otherwise
        // SubstituteBindings queries with whatever organization was last resolved
        // (or none), and a scoped binding could load — or fail to load — the wrong
        // organization's record. Priority ordering guarantees auth runs first,
        // then ResolveOrganization, then bindings. RequireOrganization follows so
        // a missing tenant is a clean 403 rather than a binding-time surprise.
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveOrganization::class);
        $middleware->appendToPriorityList(ResolveOrganization::class, RequireOrganization::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
