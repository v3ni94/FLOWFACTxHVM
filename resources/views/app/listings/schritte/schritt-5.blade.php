@extends('layouts.app')

@section('title', 'Bilder')

@section('content')
    @include('app.listings.schritte._header')

    @if ($errors->has('dateien'))
        <div class="alert alert-error">
            <ul>
                @foreach ($errors->get('dateien') as $fehler)
                    <li>{{ $fehler }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card">
        <div class="card-title">Datei hochladen</div>
        <div class="card-body">
            <form method="POST" action="{{ route('app.listings.media.store', $listing) }}" enctype="multipart/form-data" class="stack">
                @csrf

                <div class="field">
                    <label for="typ">Art</label>
                    <select id="typ" name="typ">
                        @foreach (\App\Enums\MediaTyp::options() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="dropzone">
                    <input type="file" name="dateien[]" multiple>
                    <p class="hint">Erlaubt: JPEG, PNG, WEBP, PDF. Maximal 15 MB je Datei, insgesamt 40 Dateien je Objekt.</p>
                </div>

                <div class="cluster">
                    <button type="submit" class="btn btn-primary">Hochladen</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-title">Hochgeladene Dateien</div>
        <div class="card-body">
            @if ($listing->media->isEmpty())
                <div class="empty-state">Es wurden noch keine Dateien hochgeladen.</div>
            @else
                <form method="POST" action="{{ route('app.listings.media.sort', $listing) }}">
                    @csrf

                    <div class="thumb-grid" data-sortable>
                        @foreach ($listing->media as $medium)
                            <div class="thumb">
                                @if (in_array($medium->mime, ['image/jpeg', 'image/png', 'image/webp'], true))
                                    <img src="{{ URL::temporarySignedRoute('app.media.show', now()->addMinutes(30), ['media' => $medium->id, 'variante' => 'vorschau']) }}" alt="{{ $medium->titel ?? $medium->dateiname_original }}">
                                @endif
                                <input type="hidden" name="reihenfolge[{{ $medium->id }}]" value="{{ $loop->index }}" data-order-input>
                                <div class="thumb-actions">
                                    <span class="badge badge-neutral">{{ $medium->typ->label() }}</span>
                                    <span class="cluster">
                                        <button type="button" class="btn btn-ghost btn-sm" data-move-up aria-label="Nach oben">↑</button>
                                        <button type="button" class="btn btn-ghost btn-sm" data-move-down aria-label="Nach unten">↓</button>
                                    </span>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="cluster">
                        <button type="submit" class="btn btn-secondary btn-sm">Reihenfolge speichern</button>
                    </div>
                </form>

                <div class="stack">
                    @foreach ($listing->media as $medium)
                        <div class="status-row">
                            <span>{{ $medium->dateiname_original }}</span>

                            <form method="POST" action="{{ route('app.listings.media.update', ['listing' => $listing, 'media' => $medium]) }}" class="cluster">
                                @csrf
                                <input type="text" name="titel" value="{{ $medium->titel }}" placeholder="Titel">
                                <input type="hidden" name="im_inserat" value="0">
                                <label class="field-inline">
                                    <input type="checkbox" name="im_inserat" value="1" @checked($medium->im_inserat)>
                                    <span>Im Inserat</span>
                                </label>
                                <button type="submit" class="btn btn-secondary btn-sm">Speichern</button>
                            </form>

                            <form method="POST" action="{{ route('app.listings.media.destroy', ['listing' => $listing, 'media' => $medium]) }}" data-confirm="Diese Datei wirklich löschen?">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-sm">Löschen</button>
                            </form>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <div class="cluster">
        <a href="{{ route('app.listings.step', ['listing' => $listing, 'schritt' => 4]) }}" class="btn btn-secondary">Zurück</a>
        <a href="{{ route('app.listings.step', ['listing' => $listing, 'schritt' => 6]) }}" class="btn btn-primary">Weiter</a>
    </div>
@endsection
