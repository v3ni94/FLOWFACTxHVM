@extends('layouts.app')

@section('title', 'Konto')

@section('content')
    <div class="page-header">
        <h1>Konto</h1>
    </div>

    <div class="stack">
        @if (session('recovery_codes'))
            <div class="alert alert-warning">
                <strong>Wiederherstellungscodes</strong>
                <p>Diese Codes werden nur jetzt angezeigt. Bewahren Sie sie sicher auf, jeder Code kann einmal anstelle
                    des Zweitfaktors verwendet werden.</p>
                <ul>
                    @foreach (session('recovery_codes') as $code)
                        <li><code>{{ $code }}</code></li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="card">
            <div class="card-title">Angemeldet als</div>
            <div class="card-body">
                <dl class="kv">
                    <dt>Name</dt>
                    <dd>{{ $user->name }}</dd>
                    <dt>E-Mail-Adresse</dt>
                    <dd>{{ $user->email }}</dd>
                    <dt>Rolle</dt>
                    <dd>{{ $user->role->label() }}</dd>
                </dl>
            </div>
        </div>

        <div class="card">
            <div class="card-title">Passwort ändern</div>
            <div class="card-body">
                <form method="POST" action="{{ route('account.password.update') }}" class="stack">
                    @csrf
                    @method('PUT')

                    <div class="field @error('current_password') has-error @enderror">
                        <label for="current_password">Aktuelles Passwort</label>
                        <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>
                        @error('current_password')
                            <p class="error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="field @error('password') has-error @enderror">
                        <label for="password">Neues Passwort</label>
                        <input type="password" id="password" name="password" autocomplete="new-password" required>
                        <p class="hint">Mindestens 10 Zeichen, Groß- und Kleinbuchstaben sowie eine Zahl.</p>
                        @error('password')
                            <p class="error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="field">
                        <label for="password_confirmation">Neues Passwort bestätigen</label>
                        <input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" required>
                    </div>

                    <div class="cluster">
                        <button type="submit" class="btn btn-primary">Passwort ändern</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-title">Zweitfaktor (2FA)</div>
            <div class="card-body stack">
                @if ($user->hasTwoFactorEnabled())
                    <p><span class="badge badge-success">Aktiv</span></p>

                    <form method="POST" action="{{ route('account.two-factor.recovery') }}" class="stack">
                        @csrf
                        <div class="field @error('current_password', 'recovery') has-error @enderror">
                            <label for="recovery_current_password">Aktuelles Passwort</label>
                            <input type="password" id="recovery_current_password" name="current_password" autocomplete="current-password" required>
                            @error('current_password', 'recovery')
                                <p class="error">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="cluster">
                            <button type="submit" class="btn btn-secondary">Neue Wiederherstellungscodes erzeugen</button>
                        </div>
                    </form>

                    <form method="POST" action="{{ route('account.two-factor.disable') }}" class="stack" data-confirm="Zweitfaktor wirklich deaktivieren?">
                        @csrf
                        @method('DELETE')
                        <div class="field @error('current_password', 'disable') has-error @enderror">
                            <label for="disable_current_password">Aktuelles Passwort</label>
                            <input type="password" id="disable_current_password" name="current_password" autocomplete="current-password" required>
                            @error('current_password', 'disable')
                                <p class="error">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="cluster">
                            <button type="submit" class="btn btn-danger">Zweitfaktor deaktivieren</button>
                        </div>
                    </form>
                @elseif ($otpauthUri)
                    <p><span class="badge badge-warning">Einrichtung offen</span></p>
                    <p class="hint">Fügen Sie den Schlüssel in Ihrer Authenticator-App hinzu und bestätigen Sie mit
                        dem angezeigten Code.</p>

                    <div class="field">
                        <label for="otpauth_uri">Schlüssel-URI</label>
                        <input type="text" id="otpauth_uri" readonly value="{{ $otpauthUri }}">
                    </div>

                    <div class="field">
                        <label for="secret_formatted">Schlüssel zum Abtippen</label>
                        <input type="text" id="secret_formatted" readonly value="{{ $secretFormatted }}">
                    </div>

                    <form method="POST" action="{{ route('account.two-factor.confirm') }}" class="stack">
                        @csrf
                        <div class="field @error('code') has-error @enderror">
                            <label for="two_factor_code">Code aus der Authenticator-App</label>
                            <input type="text" id="two_factor_code" name="code" inputmode="numeric" autocomplete="one-time-code" required>
                            @error('code')
                                <p class="error">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="cluster">
                            <button type="submit" class="btn btn-primary">Zweitfaktor bestätigen</button>
                        </div>
                    </form>

                    <form method="POST" action="{{ route('account.two-factor.setup') }}">
                        @csrf
                        <button type="submit" class="btn btn-ghost">Neuen Schlüssel erzeugen</button>
                    </form>
                @else
                    <p><span class="badge badge-neutral">Nicht aktiviert</span></p>
                    <p class="hint">Der Zweitfaktor ist optional. Sie können ihn jederzeit aktivieren.</p>

                    <form method="POST" action="{{ route('account.two-factor.setup') }}">
                        @csrf
                        <button type="submit" class="btn btn-primary">Einrichtung starten</button>
                    </form>
                @endif
            </div>
        </div>
    </div>
@endsection
