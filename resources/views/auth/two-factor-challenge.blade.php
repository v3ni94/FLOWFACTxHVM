@extends('layouts.guest')

@section('title', 'Zweitfaktor bestätigen')

@section('content')
    <div class="card">
        <div class="card-title">Zweitfaktor bestätigen</div>
        <div class="card-body">
            <p class="hint">Geben Sie den sechsstelligen Code aus Ihrer Authenticator-App ein, oder verwenden Sie
                stattdessen einen Ihrer Wiederherstellungscodes.</p>

            <form method="POST" action="{{ route('two-factor.challenge.store') }}" class="stack">
                @csrf

                <div class="field @error('code') has-error @enderror">
                    <label for="code">Code aus der Authenticator-App</label>
                    <input type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" autofocus value="{{ old('code') }}">
                    @error('code')
                        <p class="error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="field">
                    <label for="recovery_code">Oder Wiederherstellungscode</label>
                    <input type="text" id="recovery_code" name="recovery_code" autocomplete="off" value="{{ old('recovery_code') }}">
                    <p class="hint">Nur ausfüllen, wenn kein Zugriff auf die Authenticator-App besteht.</p>
                </div>

                <div class="cluster">
                    <button type="submit" class="btn btn-primary">Bestätigen</button>
                </div>
            </form>
        </div>
    </div>
@endsection
