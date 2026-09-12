@extends('layouts.app')

@section('title', 'Benutzer einladen')

@section('content')
    <div class="page-header">
        <h1>Benutzer einladen</h1>
    </div>

    <div class="card">
        <div class="card-body">
            <p class="text-sekundaer">
                Die eingeladene Person erhält eine E-Mail mit einem Link und vergibt dort selbst
                ein Passwort. Der Link ist 72 Stunden gültig und kann nur einmal verwendet werden.
            </p>

            <form method="POST" action="{{ route('admin.users.invite.store') }}" class="stack">
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

                <div class="cluster">
                    <button type="submit" class="btn btn-primary">Einladung senden</button>
                    <a href="{{ route('admin.users.index') }}" class="btn btn-ghost">Abbrechen</a>
                </div>
            </form>
        </div>
    </div>
@endsection
