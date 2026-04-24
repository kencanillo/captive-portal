<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    @php
        $component = $page['component'] ?? '';
        $isPublicPage = str_starts_with($component, 'Public/');
        $isPortalEntryPage = $component === 'Public/PlanSelection';
    @endphp
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>
        <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
        <link rel="shortcut icon" href="{{ asset('favicon.ico') }}">
        <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">

        @if ($isPortalEntryPage)
            <style>
                [data-portal-shell] {
                    min-height: 100vh;
                    display: grid;
                    place-items: center;
                    padding: 24px;
                    background:
                        radial-gradient(circle at top left, rgba(91, 184, 254, 0.18), transparent 26%),
                        radial-gradient(circle at right 12%, rgba(78, 222, 163, 0.15), transparent 24%),
                        linear-gradient(180deg, #f7f9fb 0%, #eef2f7 100%);
                    color: #131b2e;
                    font-family: "Inter", "SF Pro Text", "SF Pro Display", "Segoe UI", "Helvetica Neue", Arial, sans-serif;
                }

                [data-portal-shell-card] {
                    width: min(100%, 720px);
                    border-radius: 28px;
                    border: 1px solid rgba(255, 255, 255, 0.72);
                    background: linear-gradient(180deg, rgba(255, 255, 255, 0.96), rgba(255, 255, 255, 0.88));
                    box-shadow: 0 34px 80px -44px rgba(19, 27, 46, 0.3);
                    padding: 28px;
                }

                [data-portal-shell-kicker] {
                    font-size: 11px;
                    font-weight: 700;
                    letter-spacing: 0.28em;
                    text-transform: uppercase;
                    color: #0284c7;
                }

                [data-portal-shell-title] {
                    margin: 14px 0 0;
                    font-size: clamp(2rem, 4vw, 2.75rem);
                    line-height: 1;
                    letter-spacing: -0.05em;
                }

                [data-portal-shell-copy] {
                    margin: 14px 0 0;
                    font-size: 14px;
                    line-height: 1.8;
                    color: #667085;
                }

                [data-portal-shell-banner] {
                    margin-top: 24px;
                    border-radius: 22px;
                    border: 1px solid rgba(226, 232, 240, 0.9);
                    background: rgba(248, 250, 252, 0.92);
                    padding: 16px 18px;
                }

                [data-portal-shell-banner-title] {
                    margin: 0;
                    font-size: 14px;
                    font-weight: 600;
                }

                [data-portal-shell-banner-copy] {
                    margin: 6px 0 0;
                    font-size: 14px;
                    color: #667085;
                }

                [data-portal-shell-grid] {
                    margin-top: 24px;
                    display: grid;
                    gap: 16px;
                    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
                }

                [data-portal-shell-plan] {
                    border-radius: 24px;
                    border: 1px solid rgba(226, 232, 240, 0.9);
                    background: rgba(255, 255, 255, 0.84);
                    padding: 20px;
                }

                [data-portal-shell-line] {
                    display: block;
                    border-radius: 999px;
                    background: linear-gradient(90deg, rgba(226, 232, 240, 0.75), rgba(226, 232, 240, 0.32), rgba(226, 232, 240, 0.75));
                    background-size: 200% 100%;
                    animation: portal-shell-shimmer 1.4s linear infinite;
                }

                @keyframes portal-shell-shimmer {
                    0% { background-position: 200% 0; }
                    100% { background-position: -200% 0; }
                }
            </style>
        @endif

        <!-- Scripts -->
        @unless ($isPublicPage)
            @routes
        @endunless
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @if ($isPortalEntryPage)
            <div data-portal-shell>
                <div data-portal-shell-card>
                    <p data-portal-shell-kicker>Client Registration</p>
                    <h1 data-portal-shell-title>Preparing your Wi-Fi portal</h1>
                    <p data-portal-shell-copy">The portal shell loads first. Device detection and controller lookups continue in the background so the page does not sit blank on captive networks.</p>

                    <div data-portal-shell-banner>
                        <p data-portal-shell-banner-title>Loading device context</p>
                        <p data-portal-shell-banner-copy">You will be able to start filling out registration details immediately.</p>
                    </div>

                    <div data-portal-shell-grid aria-hidden="true">
                        @for ($i = 0; $i < 4; $i++)
                            <div data-portal-shell-plan>
                                <span data-portal-shell-line style="height: 20px; width: 58%;"></span>
                                <span data-portal-shell-line style="height: 14px; width: 38%; margin-top: 12px;"></span>
                                <span data-portal-shell-line style="height: 32px; width: 46%; margin-top: 24px;"></span>
                                <span data-portal-shell-line style="height: 46px; width: 100%; margin-top: 24px;"></span>
                            </div>
                        @endfor
                    </div>
                </div>
            </div>
        @endif
        @inertia
    </body>
</html>
