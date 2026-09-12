@extends('layouts.guest')

@section('title', 'Passwort vergessen')

@section('content')
    <div class="card">
        <div class="card-title">Passwort vergessen</div>
        <div class="card-body">
            <p class="text-sekundaer">
                Geben Sie Ihre E-Mail-Adresse ein. Falls zu dieser Adresse ein Konto besteht,
                senden wir Ihnen einen Link zum Zurücksetzen des Passworts.
            </p>

            <form method="POST" action="{{ route('password.email') }}" class="stack">
                @csrf

                <div class="field @error('email') has-error @enderror">
                    <label for="email">E-Mail-Adresse</label>
                    <input type="email" id="email" name="email" value="{{ old('email') }}" autofocus required autocomplete="username">
                    @error('email')
                        <p class="error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="cluster">
                    <button type="submit" class="btn btn-primary">Link anfordern</button>
                    <a href="{{ route('login') }}" class="btn btn-ghost">Zurück zur Anmeldung</a>
                </div>
            </form>
        </div>
    </div>
@endsection
