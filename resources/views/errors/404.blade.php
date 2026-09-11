@extends('layouts.guest')

@section('title', 'Seite nicht gefunden')

@section('content')
    <div class="eyebrow">Fehler 404</div>
    <h1>Seite nicht gefunden</h1>
    <p class="text-sekundaer">Die aufgerufene Seite existiert nicht oder wurde verschoben. Bitte prüfen Sie die Adresse.</p>
    <a href="{{ url('/') }}" class="btn btn-primary">Zur Startseite</a>
@endsection
