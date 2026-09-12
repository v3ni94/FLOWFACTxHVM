@extends('layouts.guest')

@section('title', 'Passwort zurücksetzen')

@section('content')
    <div class="card">
        <div class="card-title">Neues Passwort vergeben</div>
        <div class="card-body">
            <form method="POST" action="{{ route('password.update') }}" class="stack">
                @csrf

                <input type="hidden" name="token" value="{{ $token }}">

                <div class="field @error('email') has-error @enderror">
                    <label for="email">E-Mail-Adresse</label>
                    <input type="email" id="email" name="email" value="{{ old('email', $email) }}" autofocus required autocomplete="username">
                    @error('email')
                        <p class="error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="field @error('password') has-error @enderror">
                    <label for="password">Neues Passwort</label>
                    <input type="password" id="password" name="password" required autocomplete="new-password">
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
                    <button type="submit" class="btn btn-primary">Passwort setzen</button>
                </div>
            </form>
        </div>
    </div>
@endsection
