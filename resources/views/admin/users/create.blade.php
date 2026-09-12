@extends('layouts.app')

@section('title', 'Benutzer anlegen')

@section('content')
    <div class="page-header">
        <h1>Benutzer anlegen</h1>
    </div>

    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ route('admin.users.store') }}" class="stack">
                @csrf

                <div class="field @error('name') has-error @enderror">
                    <label for="name">Name</label>
                    <input type="text" id="name" name="name" value="{{ old('name') }}" required>
                    @error('name')
                        <p class="error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="field @error('email') has-error @enderror">
                    <label for="email">E-Mail-Adresse</label>
                    <input type="email" id="email" name="email" value="{{ old('email') }}" required>
                    @error('email')
                        <p class="error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="field @error('phone') has-error @enderror">
                    <label for="phone">Telefon</label>
                    <input type="text" id="phone" name="phone" value="{{ old('phone') }}">
                    @error('phone')
                        <p class="error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="field @error('role') has-error @enderror">
                    <label for="role">Rolle</label>
                    <select id="role" name="role" required>
                        @foreach ($roles as $value => $label)
                            <option value="{{ $value }}" @selected(old('role') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('role')
                        <p class="error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="field-inline @error('darf_veroeffentlichen') has-error @enderror">
                    <input type="checkbox" id="darf_veroeffentlichen" name="darf_veroeffentlichen" value="1" @checked(old('darf_veroeffentlichen'))>
                    <label for="darf_veroeffentlichen">Darf veröffentlichen</label>
                    @error('darf_veroeffentlichen')
                        <p class="error">{{ $message }}</p>
                    @enderror
                </div>
                <p class="hint">Ein Administrator darf immer veröffentlichen, unabhängig von diesem Recht.</p>

                <div class="field @error('password') has-error @enderror">
                    <label for="password">Initiales Passwort</label>
                    <input type="password" id="password" name="password" required>
                    <p class="hint">Mindestens 12 Zeichen. Geben Sie es dem Benutzer über einen sicheren Weg weiter.</p>
                    @error('password')
                        <p class="error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="field">
                    <label for="password_confirmation">Passwort bestätigen</label>
                    <input type="password" id="password_confirmation" name="password_confirmation" required>
                </div>

                <div class="cluster">
                    <button type="submit" class="btn btn-primary">Benutzer anlegen</button>
                    <a href="{{ route('admin.users.index') }}" class="btn btn-ghost">Abbrechen</a>
                </div>
            </form>
        </div>
    </div>
@endsection
