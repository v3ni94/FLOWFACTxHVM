@extends('layouts.app')

@section('title', 'Benutzer')

@section('content')
    <div class="page-header">
        <h1>Benutzer</h1>
        <div class="page-actions">
            <a href="{{ route('admin.users.invite') }}" class="btn btn-primary">Benutzer einladen</a>
            <a href="{{ route('admin.users.create') }}" class="btn btn-secondary">Mit Initialpasswort anlegen</a>
        </div>
    </div>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>E-Mail-Adresse</th>
                    <th>Rolle</th>
                    <th>Darf veröffentlichen</th>
                    <th>2FA</th>
                    <th>Status</th>
                    <th>Letzte Anmeldung</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($users as $listedUser)
                    <tr>
                        <td>{{ $listedUser->name }}</td>
                        <td>{{ $listedUser->email }}</td>
                        <td>{{ $listedUser->role->label() }}</td>
                        <td>
                            @if ($listedUser->kannVeroeffentlichen())
                                <span class="badge badge-success">Ja</span>
                            @else
                                <span class="badge badge-neutral">Nein</span>
                            @endif
                        </td>
                        <td>
                            @if ($listedUser->hasTwoFactorEnabled())
                                <span class="badge badge-success">Ja</span>
                            @else
                                <span class="badge badge-neutral">Nein</span>
                            @endif
                        </td>
                        <td>
                            @if ($listedUser->is_active)
                                <span class="badge badge-success">Aktiv</span>
                            @else
                                <span class="badge badge-error">Deaktiviert</span>
                            @endif
                        </td>
                        <td>{{ $listedUser->last_login_at?->format('d.m.Y H:i') ?? '–' }}</td>
                        <td>
                            <div class="cluster">
                                <a href="{{ route('admin.users.edit', $listedUser) }}" class="btn btn-secondary btn-sm">Bearbeiten</a>

                                @if ($listedUser->hasTwoFactorEnabled())
                                    <form method="POST" action="{{ route('admin.users.reset-two-factor', $listedUser) }}" data-confirm="Zweitfaktor dieses Benutzers wirklich zurücksetzen?">
                                        @csrf
                                        <button type="submit" class="btn btn-ghost btn-sm">2FA zurücksetzen</button>
                                    </form>
                                @endif

                                @if ($listedUser->is_active)
                                    <form method="POST" action="{{ route('admin.users.deactivate', $listedUser) }}" data-confirm="Diesen Benutzer wirklich deaktivieren?">
                                        @csrf
                                        <button type="submit" class="btn btn-danger btn-sm">Deaktivieren</button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('admin.users.activate', $listedUser) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-secondary btn-sm">Aktivieren</button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8">
                            <div class="empty-state">Es sind noch keine Benutzer angelegt.</div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="page-header">
        <h2>Offene Einladungen</h2>
    </div>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>E-Mail-Adresse</th>
                    <th>Rolle</th>
                    <th>Gültig bis</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($invitations as $invitation)
                    <tr>
                        <td>{{ $invitation->name }}</td>
                        <td>{{ $invitation->email }}</td>
                        <td>{{ $invitation->role->label() }}</td>
                        <td>
                            {{ $invitation->expires_at->format('d.m.Y H:i') }}
                            @if ($invitation->istAbgelaufen())
                                <span class="badge badge-error">Abgelaufen</span>
                            @endif
                        </td>
                        <td>
                            <div class="cluster">
                                <form method="POST" action="{{ route('admin.users.invitations.resend', $invitation) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-secondary btn-sm">Erneut senden</button>
                                </form>
                                <form method="POST" action="{{ route('admin.users.invitations.revoke', $invitation) }}" data-confirm="Diese Einladung wirklich widerrufen?">
                                    @csrf
                                    <button type="submit" class="btn btn-danger btn-sm">Widerrufen</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5">
                            <div class="empty-state">Es liegen keine offenen Einladungen vor.</div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
