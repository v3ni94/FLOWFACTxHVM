@extends('layouts.app')

@section('title', 'Benutzer bearbeiten')

@section('content')
    <div class="page-header">
        <h1>Benutzer bearbeiten</h1>
    </div>

    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ route('admin.users.update', $editUser) }}" class="stack">
                @csrf
                @method('PUT')

                <div class="field @error('name') has-error @enderror">
                    <label for="name">Name</label>
                    <input type="text" id="name" name="name" value="{{ old('name', $editUser->name) }}" required>
                    @error('name')
                        <p class="error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="field @error('email') has-error @enderror">
                    <label for="email">E-Mail-Adresse</label>
                    <input type="email" id="email" name="email" value="{{ old('email', $editUser->email) }}" required>
                    @error('email')
                        <p class="error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="field @error('phone') has-error @enderror">
                    <label for="phone">Telefon</label>
                    <input type="text" id="phone" name="phone" value="{{ old('phone', $editUser->phone) }}">
                    @error('phone')
                        <p class="error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="field @error('role') has-error @enderror">
                    <label for="role">Rolle</label>
                    <select id="role" name="role" required>
                        @foreach ($roles as $value => $label)
                            <option value="{{ $value }}" @selected(old('role', $editUser->role->value) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('role')
                        <p class="error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="field-inline @error('darf_veroeffentlichen') has-error @enderror">
                    <input type="checkbox" id="darf_veroeffentlichen" name="darf_veroeffentlichen" value="1" @checked(old('darf_veroeffentlichen', $editUser->darf_veroeffentlichen))>
                    <label for="darf_veroeffentlichen">Darf veröffentlichen</label>
                    @error('darf_veroeffentlichen')
                        <p class="error">{{ $message }}</p>
                    @enderror
                </div>
                <p class="hint">Ein Administrator darf immer veröffentlichen, unabhängig von diesem Recht.</p>

                <div class="field">
                    <span class="hint">
                        Status:
                        @if ($editUser->is_active)
                            <span class="badge badge-success">Aktiv</span>
                        @else
                            <span class="badge badge-error">Deaktiviert</span>
                        @endif
                        · Letzte Anmeldung: {{ $editUser->last_login_at?->format('d.m.Y H:i') ?? 'noch nie' }}
                    </span>
                </div>

                <div class="cluster">
                    <button type="submit" class="btn btn-primary">Speichern</button>
                    <a href="{{ route('admin.users.index') }}" class="btn btn-ghost">Abbrechen</a>
                </div>
            </form>
        </div>
    </div>
@endsection
