<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Anmeldung') – Müller FLOW</title>
    <link rel="stylesheet" href="{{ asset('css/flow.css') }}?v={{ @filemtime(public_path('css/flow.css')) ?: '1' }}">
</head>
<body>
<div class="guest-shell">
    <div class="guest-wrap">
        <a href="{{ url('/') }}" class="guest-brand">
            <span class="app-brand-mark">
                <img src="{{ asset('img/logo-hvm.jpg') }}" alt="Hausverwaltung Müller GmbH">
            </span>
            <span class="guest-brand-word">Müller FLOW</span>
        </a>

        <div class="card">
            <div class="card-body stack">
                @if (session('status'))
                    <div class="alert alert-success" role="status">{{ session('status') }}</div>
                @endif

                @if (session('error'))
                    <div class="alert alert-error" role="alert">{{ session('error') }}</div>
                @endif

                @yield('content')
            </div>
        </div>
    </div>
</div>

<script src="{{ asset('js/flow.js') }}?v={{ @filemtime(public_path('js/flow.js')) ?: '1' }}"></script>
</body>
</html>
