<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"  @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
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
        @if (($page['component'] ?? null) === 'Welcome')
            @php($landingDescription = 'O LÁPIS reúne perfis de avaliação, turmas, elementos de avaliação e grelhas de correção num só sítio, e propõe cada classificação de forma explicável. Para professores do básico, secundário, profissional e superior.')
            <meta name="description" content="{{ $landingDescription }}">
            <link rel="canonical" href="{{ url('/') }}">
            <meta property="og:type" content="website">
            <meta property="og:site_name" content="LÁPIS">
            <meta property="og:locale" content="pt_PT">
            <meta property="og:url" content="{{ url('/') }}">
            <meta property="og:title" content="LÁPIS — Mais tempo para ensinar">
            <meta property="og:description" content="{{ $landingDescription }}">
            {{-- summary, not summary_large_image: there is no OG image asset yet,
                 and the large card renders as a broken box without one. --}}
            <meta name="twitter:card" content="summary">
            <meta name="twitter:title" content="LÁPIS — Mais tempo para ensinar">
            <meta name="twitter:description" content="{{ $landingDescription }}">
            @php($landingStructuredData = [
                '@context' => 'https://schema.org',
                '@type' => 'SoftwareApplication',
                'name' => 'LÁPIS',
                'alternateName' => 'Laboratório de Apoio ao Professor, Informação e Simplificação',
                'applicationCategory' => 'EducationalApplication',
                'operatingSystem' => 'Web',
                'inLanguage' => 'pt-PT',
                'url' => url('/'),
                'description' => $landingDescription,
            ])
            <script type="application/ld+json">
                {!! json_encode($landingStructuredData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}
            </script>
        @endif

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ ($page['component'] ?? null) === 'Welcome' ? 'LÁPIS — Mais tempo para ensinar' : config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
