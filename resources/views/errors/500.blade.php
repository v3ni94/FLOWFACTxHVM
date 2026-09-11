@extends('layouts.guest')

@section('title', 'Serverfehler')

@section('content')
    <div class="eyebrow">Fehler 500</div>
    <h1>Es ist ein Fehler aufgetreten</h1>
    <p class="text-sekundaer">Bei der Verarbeitung Ihrer Anfrage ist ein unerwarteter Fehler aufgetreten. Bitte versuchen Sie es später erneut. Besteht das Problem weiterhin, wenden Sie sich an Ihren Administrator.</p>
    <a href="{{ url('/') }}" class="btn btn-primary">Zur Startseite</a>
@endsection
