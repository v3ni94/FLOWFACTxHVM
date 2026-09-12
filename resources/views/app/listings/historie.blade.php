@extends('layouts.app')

@section('title', 'Änderungshistorie')

@section('content')
    <div class="page-header">
        <h1>{{ $listing->objektnummer }}: Änderungshistorie</h1>
        <div class="page-actions">
            <a href="{{ route('app.listings.show', $listing) }}" class="btn btn-ghost">Zur Objektübersicht</a>
        </div>
    </div>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Zeitpunkt</th>
                    <th>Benutzer</th>
                    <th>Feld</th>
                    <th>Vorher</th>
                    <th>Nachher</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($aenderungen as $aenderung)
                    @php [$tabelle, $spalte] = $aenderung->tabelleUndSpalte(); @endphp
                    <tr>
                        <td>{{ $aenderung->created_at?->format('d.m.Y H:i') }}</td>
                        <td>{{ $aenderung->user?->name ?? 'System' }}</td>
                        <td>{{ $spalte }}<span class="hint"> ({{ $tabelle }})</span></td>
                        <td>{{ $aenderung->alt ?? '–' }}</td>
                        <td>{{ $aenderung->neu ?? '–' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5">Für dieses Objekt liegen noch keine Änderungen vor.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $aenderungen->links() }}
@endsection
