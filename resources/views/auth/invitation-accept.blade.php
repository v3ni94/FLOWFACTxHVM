@extends('layouts.guest')

@section('title', 'Einladung annehmen')

@section('content')
    <div class="card">
        <div class="card-title">Einladung annehmen</div>
        <div class="card-body">
            @if ($fehlermeldung)
                <div class="alert alert-error" role="alert">{{ $fehlermeldung }}</div>
            @else
                <p class="text-sekundaer">
                    Guten Tag {{ $invitation->name }}, vergeben Sie ein Passwort für Ihr Konto
                    ({{ $invitation->email }}) in Müller FLOW.
                </p>

                <form method="POST" action="{{ route('invitation.accept', ['token' => $token]) }}" class="stack">
                    @csrf

                    <div class="field @error('password') has-error @enderror">
                        <label for="password">Passwort</label>
                        <input type="password" id="password" name="password" required autofocus autocomplete="new-password">
                        <p class="hint">Mindestens 10 Zeichen, mit Groß- und Kleinbuchstaben sowie einer Ziffer.</p>
                        @error('password')
                            <p class="error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="field">
                        <label for="password_confirmation">Passwort bestätigen</label>
                        <input type="password" id="password_confirmation" name="password_confirmation" required autocomplete="new-password">
                    </div>

                    <div class="cluster">
                        <button type="submit" class="btn btn-primary">Konto einrichten und anmelden</button>
                    </div>
                </form>
            @endif
        </div>
    </div>
@endsection
