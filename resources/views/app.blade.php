<!DOCTYPE html>
@php
    /*
     * The public pages are light only. Marketing has one theme; dark mode is a
     * preference for working inside the application, not for reading the
     * landing. The lock is an attribute the client honours (useAppearance.ts)
     * and removes when the visitor moves into the app without a reload.
     */
    $publicPage = in_array($page['component'], ['Welcome', 'legal/Document'], true);
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"  @class(['dark' => ! $publicPage && ($appearance ?? 'system') == 'dark']) @if ($publicPage) data-theme-lock="light" @endif>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        {{-- Inertia's own POST/PUT/DELETE requests carry CSRF via the XSRF-TOKEN
             cookie automatically. This meta tag is only for the rare direct
             `fetch()` call (a raw file download, for instance) that Inertia's
             router can't make. --}}
        <meta name="csrf-token" content="{{ csrf_token() }}">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system' && !document.documentElement.dataset.themeLock) {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.145 0 0);
            }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        {{-- Server-rendered SEO for the public landing page.

             It lives HERE and not in the Vue <Head>, because Inertia SSR is off
             (config/inertia.php): an Inertia <Head> only writes these tags once
             the bundle has run, which is after a crawler has already read the
             document. The tab title stays in the component; everything a robot
             reads has to be in the response. --}}
        @php($component = $page['component'] ?? null)
        @php($isLanding = $component === 'Welcome')
        {{-- As páginas legais são públicas e devem ser encontráveis: alguém que
             procure «política de privacidade Lapispro» tem de lá chegar sem passar
             pela landing. Cada uma canonicaliza-se a si própria, não à raiz. --}}
        @php($isLegal = $component === 'legal/Document')
        @php($publicPath = $isLegal ? request()->path() : '/')
        @php($publicUrl = \App\Support\Seo\LandingSeo::canonical().($publicPath === '/' ? '' : '/'.$publicPath))

        @if ($isLegal)
            <link rel="canonical" href="{{ $publicUrl }}">
            <meta name="robots" content="index, follow, max-snippet:-1">
            <meta property="og:type" content="article">
            <meta property="og:site_name" content="Lapispro">
            <meta property="og:locale" content="pt_PT">
            <meta property="og:url" content="{{ $publicUrl }}">
            <meta property="og:image" content="{{ \App\Support\Seo\LandingSeo::ogImage() }}">
            <meta name="twitter:card" content="summary_large_image">
            <meta name="twitter:image" content="{{ \App\Support\Seo\LandingSeo::ogImage() }}">
        @elseif ($isLanding)
            <meta name="description" content="{{ \App\Support\Seo\LandingSeo::DESCRIPTION }}">
            <link rel="canonical" href="{{ \App\Support\Seo\LandingSeo::canonical() }}">
            {{-- max-image-preview:large is what lets a result carry a picture at
                 all once an og:image exists; max-snippet:-1 stops Google
                 trimming the snippet shorter than the description written for
                 it. Both are inert until they matter, and free to state now. --}}
            <meta name="robots" content="index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1">
            <meta property="og:type" content="website">
            <meta property="og:site_name" content="Lapispro">
            <meta property="og:locale" content="pt_PT">
            <meta property="og:url" content="{{ \App\Support\Seo\LandingSeo::canonical() }}">
            <meta property="og:title" content="{{ \App\Support\Seo\LandingSeo::SOCIAL_TITLE }}">
            <meta property="og:description" content="{{ \App\Support\Seo\LandingSeo::SOCIAL_DESCRIPTION }}">
            <meta property="og:image" content="{{ \App\Support\Seo\LandingSeo::ogImage() }}">
            <meta property="og:image:width" content="1200">
            <meta property="og:image:height" content="630">
            <meta property="og:image:alt" content="{{ \App\Support\Seo\LandingSeo::SOCIAL_TITLE }}">
            <meta name="twitter:card" content="summary_large_image">
            <meta name="twitter:image" content="{{ \App\Support\Seo\LandingSeo::ogImage() }}">
            <meta name="twitter:title" content="{{ \App\Support\Seo\LandingSeo::SOCIAL_TITLE }}">
            <meta name="twitter:description" content="{{ \App\Support\Seo\LandingSeo::SOCIAL_DESCRIPTION }}">
            <script type="application/ld+json">
                {!! json_encode(\App\Support\Seo\LandingSeo::structuredData(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}
            </script>
        @else
            {{-- EVERY OTHER PAGE IS OUT OF THE INDEX. The teacher-facing app is
                 behind auth and has nothing to rank for, but two public routes
                 do reach a browser without a login: the signed self-assessment
                 a student opens, and an institutional invitation. Neither
                 should ever appear in a search result, and «it needs a
                 signature» is not an answer once somebody pastes the link
                 somewhere a crawler reads. --}}
            <meta name="robots" content="noindex, nofollow">
        @endif

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ $isLanding ? \App\Support\Seo\LandingSeo::TITLE : config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
