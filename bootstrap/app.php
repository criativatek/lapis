<?php

use App\Http\Middleware\EnsureAccountIsOperational;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\EnsureSupportTechnician;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireModule;
use App\Http\Middleware\RequireOrganization;
use App\Http\Middleware\ResolveOrganization;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Inertia\Inertia;

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
            // Before ResolveOrganization: a deactivated account is shown the door
            // rather than given a tenant.
            EnsureUserIsActive::class,
            ResolveOrganization::class,
            // After ResolveOrganization: needs the resolved tenant (if any) to
            // check whether IT is in closure, on top of the account itself.
            EnsureAccountIsOperational::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'organization' => RequireOrganization::class,
            'module' => RequireModule::class,
            'platform-admin' => EnsurePlatformAdmin::class,
            'support-technician' => EnsureSupportTechnician::class,
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

        /*
         * Never flashed back to the session when validation fails.
         *
         * THIS IS THE ONLY PLACE THAT WORKS, and it is worth saying so: a
         * `$dontFlash` property on a FormRequest looks exactly like it does
         * this and does nothing at all. The list lives on the exception
         * handler, which is what actually runs
         * `Arr::except($request->input(), $this->dontFlash)` on the way to the
         * redirect. Without this line a rejected AI credential would sit in the
         * session store in the clear and be redrawn into the form.
         *
         * `dontFlash()` MERGES with Laravel's three password defaults rather
         * than replacing them.
         */
        $exceptions->dontFlash('ai_credential');

        // A request whose body outgrew post_max_size never reaches Laravel's own
        // validation — ValidatePostSize throws this from the GLOBAL middleware
        // group, before the "web" group (and StartSession within it) ever runs.
        // $request->hasSession() is therefore always false here: there is no
        // shortcut, the session has to be started by hand, the same way
        // StartSession itself does (read the id off the existing cookie —
        // never create a new session for a request that isn't really there).
        // Without this, the teacher sees a raw Laravel error page (a stack
        // trace under APP_DEBUG=true, a bare "Server Error" otherwise) instead
        // of the same friendly toast every other upload failure uses.
        $exceptions->render(function (PostTooLargeException $exception, Request $request) {
            $manager = app('session');

            if ($manager->getSessionConfig()['driver'] === null) {
                return null;
            }

            $session = $manager->driver();
            $session->setId($request->cookies->get($session->getName()));
            $session->start();
            $request->setLaravelSession($session);

            Inertia::flash('toast', [
                'type' => 'error',
                'message' => 'O ficheiro é demasiado grande para ser enviado. Reduza o tamanho e tente novamente.',
            ]);

            // Nothing downstream will call this: the "web" group's own
            // StartSession middleware, which normally saves the session on
            // its way out, never runs for a request that failed this early.
            $session->save();

            return redirect($request->headers->get('referer', route('dashboard')));
        });
    })->create();
