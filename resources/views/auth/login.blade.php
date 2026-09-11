@extends('layouts.guest')

@section('title', 'Anmelden')

@section('content')
    <div class="card">
        <div class="card-title">Anmelden</div>
        <div class="card-body">
            <form method="POST" action="{{ route('login.store') }}" class="stack">
                @csrf

                <div class="field @error('email') has-error @enderror">
                    <label for="email">E-Mail-Adresse</label>
                    <input type="email" id="email" name="email" value="{{ old('email') }}" autofocus required autocomplete="username">
                    @error('email')
                        <p class="error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="field @error('password') has-error @enderror">
                    <label for="password">Passwort</label>
                    <input type="password" id="password" name="password" required autocomplete="current-password">
                    @error('password')
                        <p class="error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="checkbox-group">
                    <div class="field-inline">
                        <input type="checkbox" id="remember" name="remember" value="1" @checked(old('remember'))>
                        <label for="remember">Angemeldet bleiben</label>
                    </div>
                </div>

                <div class="cluster">
                    <button type="submit" class="btn btn-primary">Anmelden</button>
                </div>
            </form>
        </div>
    </div>
@endsection
