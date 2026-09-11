<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Müller FLOW') – Müller FLOW</title>
    <link rel="stylesheet" href="{{ asset('css/flow.css') }}?v={{ @filemtime(public_path('css/flow.css')) ?: '1' }}">
</head>
<body>
<div class="app-shell">
    <header class="app-header">
        <div class="app-header-inner">
            <a href="{{ Route::has('app.dashboard') ? route('app.dashboard') : url('/') }}" class="app-brand">
                <span class="app-brand-mark">
                    <img src="{{ asset('img/logo-hvm.jpg') }}" alt="Hausverwaltung Müller GmbH">
                </span>
                <span class="app-brand-word">Müller FLOW</span>
            </a>

            @auth
                <nav class="app-nav" aria-label="Hauptnavigation">
                    @if (Route::has('app.dashboard'))
                        <a href="{{ route('app.dashboard') }}" class="{{ request()->routeIs('app.dashboard') ? 'is-active' : '' }}">Übersicht</a>
                    @endif
                    @if (Route::has('app.listings.index'))
                        <a href="{{ route('app.listings.index') }}" class="{{ request()->routeIs('app.listings.*') ? 'is-active' : '' }}">Objekte</a>
                    @endif
                    @if (Route::has('admin.users.index') && auth()->user()->isAdmin())
                        <a href="{{ route('admin.users.index') }}" class="{{ request()->routeIs('admin.users.*') ? 'is-active' : '' }}">Benutzer</a>
                    @endif
                    @if (Route::has('admin.flowfact.edit') && auth()->user()->isAdmin())
                        <a href="{{ route('admin.flowfact.edit') }}" class="{{ request()->routeIs('admin.flowfact.*') ? 'is-active' : '' }}">FLOWFACT</a>
                    @endif
                </nav>

                <div class="app-user">
                    @if (Route::has('account.edit'))
                        <a href="{{ route('account.edit') }}" class="app-user-name">{{ auth()->user()->name }}</a>
                    @else
                        <span class="app-user-name">{{ auth()->user()->name }}</span>
                    @endif

                    @if (Route::has('logout'))
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="btn btn-ghost">
                                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" stroke-linecap="round" stroke-linejoin="round"/>
                                    <path d="M16 17l5-5-5-5" stroke-linecap="round" stroke-linejoin="round"/>
                                    <path d="M21 12H9" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                                <span>Abmelden</span>
                            </button>
                        </form>
                    @endif
                </div>
            @endauth
        </div>
    </header>

    <main class="app-main">
        @if (session('status'))
            <div class="alert alert-success" role="status">{{ session('status') }}</div>
        @endif

        @if (session('error'))
            <div class="alert alert-error" role="alert">{{ session('error') }}</div>
        @endif

        @yield('content')
    </main>
</div>

<script src="{{ asset('js/flow.js') }}?v={{ @filemtime(public_path('js/flow.js')) ?: '1' }}"></script>
</body>
</html>
