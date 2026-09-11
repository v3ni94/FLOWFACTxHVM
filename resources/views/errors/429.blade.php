@extends('layouts.guest')

@section('title', 'Zu viele Anfragen')

@section('content')
    <div class="eyebrow">Fehler 429</div>
    <h1>Zu viele Anfragen</h1>
    <p class="text-sekundaer">Es wurden zu viele Anfragen in kurzer Zeit gestellt. Bitte warten Sie einen Moment und versuchen Sie es dann erneut.</p>
    <a href="{{ url('/') }}" class="btn btn-primary">Zur Startseite</a>
@endsection
