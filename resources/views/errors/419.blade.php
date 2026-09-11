@extends('layouts.guest')

@section('title', 'Sitzung abgelaufen')

@section('content')
    <div class="eyebrow">Fehler 419</div>
    <h1>Sitzung abgelaufen</h1>
    <p class="text-sekundaer">Ihre Sitzung ist abgelaufen, vermutlich weil diese Seite lange geöffnet war. Bitte laden Sie die Seite neu und versuchen Sie es erneut.</p>
    <a href="{{ url('/') }}" class="btn btn-primary">Neu laden</a>
@endsection
