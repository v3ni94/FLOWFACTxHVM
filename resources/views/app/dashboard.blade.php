@extends('layouts.app')

@section('title', 'Übersicht')

@section('content')
    <div class="page-header">
        <h1>Übersicht</h1>
    </div>

    <div class="card">
        <div class="card-title">Willkommen, {{ $user->name }}</div>
        <div class="card-body">
            <p>Dies ist ein vorläufiges Dashboard. Die Erfassungs- und Veröffentlichungsübersicht für Objekte folgt
                in einem späteren Ausbauschritt.</p>
        </div>
    </div>
@endsection
