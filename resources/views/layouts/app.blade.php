<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="theme-color" content="#081426">
        <meta name="application-name" content="FindScholarship">
        <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
        <link rel="icon" type="image/png" sizes="192x192" href="{{ asset('pwa/icon-192.png') }}">
        <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('pwa/apple-touch-icon.png') }}">
        @if ($recordUrl = request()->attributes->get('opaque_record_url'))
            <meta name="portal-route-prefix" content="{{ $recordUrl['prefix'] }}">
            <meta name="portal-route-opaque-prefix" content="{{ $recordUrl['opaque_prefix'] }}">
        @endif
        <title>@yield('title', 'Scholarship Portal')</title>
        @yield('head')
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body>
        <script nonce="{{ Illuminate\Support\Facades\Vite::cspNonce() }}">
            window.portalUser = {{ Illuminate\Support\Js::from(
                auth()->check()
                    ? auth()->user()->loadMissing(['studentProfile', 'providerProfile', 'adminProfile'])->publicPayload()
                    : null
            ) }};
            window.portalPrivacy = {{ Illuminate\Support\Js::from([
                'contact_email' => config('privacy.contact_email'),
                'notice_version' => App\Support\Terms::VERSION,
            ]) }};
        </script>
        <div id="app" data-page="@yield('page', 'landing')" @yield('appAttributes')></div>
    </body>
</html>
