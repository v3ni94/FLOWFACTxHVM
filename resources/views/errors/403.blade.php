@extends('layouts.guest')

@section('title', 'Kein Zugriff')

@section('content')
    <div class="eyebrow">Fehler 403</div>
    <h1>Kein Zugriff</h1>
    <p class="text-sekundaer">Sie haben keine Berechtigung, diese Seite aufzurufen. Wenn Sie glauben, dass dies ein Fehler ist, wenden Sie sich bitte an Ihren Administrator.</p>
    <a href="{{ url('/') }}" class="btn btn-primary">Zur Startseite</a>
@endsection
