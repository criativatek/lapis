<!DOCTYPE html>
@php
    /*
     * The public pages are light only. Marketing has one theme; dark mode is a
     * preference for working inside the application, not for reading the
     * landing. The lock is an attribute the client honours (useAppearance.ts)
     * and removes when the visitor moves into the app without a reload.
     */
    $publicPage = \App\Support\Seo\PublicPages::isPublicComponent($page['component']);
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
        <link rel="manifest" href="/site.webmanifest" type="application/manifest+json">
        <meta name="theme-color" content="#ffffff">

        {{-- Server-rendered SEO for the public landing page.

             It lives HERE and not in the Vue <Head>, because Inertia SSR is off
             (config/inertia.php): an Inertia <Head> only writes these tags once
             the bundle has run, which is after a crawler has already read the
             document. The tab title stays in the component; everything a robot
             reads has to be in the response. --}}
        @php($component = $page['component'] ?? null)
        @php($public = $component === null ? null : \App\Support\Seo\PublicPages::current($component, request()->path()))
        @php($isLanding = $component === 'Welcome')

        @if ($public !== null)
            {{-- Every public page: its own canonical, title and description
                 from PublicPages, the shared social image, and a JSON-LD block
                 on the landing only. --}}
            <meta name="description" content="{{ $public['description'] }}">
            <link rel="canonical" href="{{ \App\Support\Seo\PublicPages::url($public['path']) }}">
            {{-- max-image-preview:large is what lets a result carry a picture;
                 max-snippet:-1 stops Google trimming the snippet shorter than
                 the description written for it. --}}
            <meta name="robots" content="index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1">
            <meta property="og:type" content="{{ $isLanding ? 'website' : 'article' }}">
            <meta property="og:site_name" content="Lapispro">
            <meta property="og:locale" content="pt_PT">
            <meta property="og:url" content="{{ \App\Support\Seo\PublicPages::url($public['path']) }}">
            <meta property="og:title" content="{{ $isLanding ? \App\Support\Seo\LandingSeo::SOCIAL_TITLE : $public['title'] }}">
            <meta property="og:description" content="{{ $isLanding ? \App\Support\Seo\LandingSeo::SOCIAL_DESCRIPTION : $public['description'] }}">
            <meta property="og:image" content="{{ \App\Support\Seo\LandingSeo::ogImage() }}">
            <meta property="og:image:width" content="1200">
            <meta property="og:image:height" content="630">
            <meta property="og:image:alt" content="{{ \App\Support\Seo\LandingSeo::SOCIAL_TITLE }}">
            <meta name="twitter:card" content="summary_large_image">
            <meta name="twitter:title" content="{{ $isLanding ? \App\Support\Seo\LandingSeo::SOCIAL_TITLE : $public['title'] }}">
            <meta name="twitter:description" content="{{ $isLanding ? \App\Support\Seo\LandingSeo::SOCIAL_DESCRIPTION : $public['description'] }}">
            <meta name="twitter:image" content="{{ \App\Support\Seo\LandingSeo::ogImage() }}">
            @if ($isLanding)
                <script type="application/ld+json">
                    {!! json_encode(\App\Support\Seo\LandingSeo::structuredData(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}
                </script>
            @else
                @foreach (\App\Support\Seo\PublicPages::structuredData($public) as $block)
                    <script type="application/ld+json">
                        {!! json_encode($block, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}
                    </script>
                @endforeach
            @endif
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
            <title>{{ $public !== null ? $public['title'] : config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
