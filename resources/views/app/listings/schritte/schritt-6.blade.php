@extends('layouts.app')

@section('title', 'Bilder und Unterlagen')

@section('content')
    @include('app.listings.schritte._header')

    <div class="card">
        <div class="card-body">
            <div class="tab-nav" data-tabs role="tablist">
                @foreach ($kategorien as $wert => $label)
                    <button type="button" data-tab-target="#kategorie-{{ $wert }}" role="tab" class="@if ($loop->first) is-active @endif">{{ $label }}</button>
                @endforeach
            </div>

            @foreach ($kategorien as $wert => $label)
                @php
                    $medien = ($medienNachTyp[$wert] ?? collect())->sortBy('sortierung')->values();
                    $istBild = $wert === 'bild';
                    $akzeptiert = in_array($wert, ['bild', 'grundriss'], true) ? 'image/jpeg,image/png,image/webp' : 'image/jpeg,image/png,image/webp,application/pdf';
                @endphp
                <section id="kategorie-{{ $wert }}" class="tab-panel stack" @if (! $loop->first) hidden @endif>
                    <form method="POST" action="{{ route('app.listings.media.store', $listing) }}" enctype="multipart/form-data" class="cluster">
                        @csrf
                        <input type="hidden" name="typ" value="{{ $wert }}">
                        <div class="field field-grow">
                            <label for="dateien-{{ $wert }}">{{ $label }} hochladen</label>
                            <input type="file" id="dateien-{{ $wert }}" name="dateien[]" multiple accept="{{ $akzeptiert }}" capture="environment">
                            <p class="hint">JPEG, PNG oder WebP{{ $istBild ? '' : ', bei Unterlagen auch PDF' }}. HEIC wird derzeit nicht unterstützt, bitte als JPEG exportieren.</p>
                        </div>
                        <button type="submit" class="btn btn-primary">Hochladen</button>
                    </form>

                    @if ($medien->isEmpty())
                        <div class="empty-state">
                            <p>Noch keine Dateien in dieser Kategorie.</p>
                        </div>
                    @else
                        <form method="POST" action="{{ route('app.listings.media.sort', $listing) }}">
                            @csrf
                            <div class="thumb-grid" data-sortable>
                                @foreach ($medien as $medium)
                                    <div class="thumb @if (! $medium->freigegeben) is-not-freigegeben @endif">
                                        @if ($istBild && $loop->first)
                                            <span class="badge badge-info thumb-badge">Titelbild</span>
                                        @endif

                                        @if (in_array($medium->mime, ['image/jpeg', 'image/png', 'image/webp'], true))
                                            <img
                                                src="{{ route('app.media.show', ['media' => $medium, 'variante' => 'vorschau']) }}"
                                                alt="{{ $medium->titel ?? $medium->dateiname_original }}"
                                                class="@if ($medium->rotation) rot-{{ $medium->rotation }} @endif"
                                            >
                                        @else
                                            <a href="{{ route('app.media.show', ['media' => $medium, 'variante' => 'original']) }}" class="btn btn-ghost btn-sm">{{ $medium->dateiname_original }}</a>
                                        @endif

                                        <input type="hidden" name="reihenfolge[{{ $medium->id }}]" value="{{ $medium->sortierung }}" data-order-input>

                                        <div class="thumb-actions">
                                            <button type="button" class="btn btn-ghost btn-sm" data-move-up>↑</button>
                                            <button type="button" class="btn btn-ghost btn-sm" data-move-down>↓</button>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                            <button type="submit" class="btn btn-secondary btn-sm">Reihenfolge speichern</button>
                        </form>

                        <div class="stack">
                            @foreach ($medien as $medium)
                                <div class="card card-canvas">
                                    <div class="card-body cluster">
                                        <form method="POST" action="{{ route('app.listings.media.update', ['listing' => $listing, 'media' => $medium]) }}" class="cluster field-grow">
                                            @csrf
                                            <div class="field field-grow">
                                                <label for="titel-{{ $medium->id }}">Titel</label>
                                                <input type="text" id="titel-{{ $medium->id }}" name="titel" value="{{ $medium->titel }}" maxlength="255">
                                            </div>
                                            <input type="hidden" name="im_inserat" value="1">
                                            <label class="field-inline">
                                                <input type="hidden" name="freigegeben" value="0">
                                                <input type="checkbox" name="freigegeben" value="1" @checked($medium->freigegeben)>
                                                <span>Freigegeben{{ $wert === 'dokument' || $wert === 'energieausweis' ? ' für die Übertragung' : '' }}</span>
                                            </label>
                                            <button type="submit" class="btn btn-secondary btn-sm">Speichern</button>
                                        </form>

                                        @if (in_array($medium->mime, ['image/jpeg', 'image/png', 'image/webp'], true))
                                            <form method="POST" action="{{ route('app.listings.media.rotate', ['listing' => $listing, 'media' => $medium]) }}">
                                                @csrf
                                                <input type="hidden" name="richtung" value="links">
                                                <button type="submit" class="btn btn-ghost btn-sm">↺ Links drehen</button>
                                            </form>
                                            <form method="POST" action="{{ route('app.listings.media.rotate', ['listing' => $listing, 'media' => $medium]) }}">
                                                @csrf
                                                <input type="hidden" name="richtung" value="rechts">
                                                <button type="submit" class="btn btn-ghost btn-sm">↻ Rechts drehen</button>
                                            </form>
                                        @endif

                                        <form method="POST" action="{{ route('app.listings.media.destroy', ['listing' => $listing, 'media' => $medium]) }}" data-confirm="Diese Datei wirklich löschen?">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-danger btn-sm">Löschen</button>
                                        </form>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </section>
            @endforeach
        </div>
    </div>

    <form method="POST" action="{{ route('app.listings.step.store', ['listing' => $listing, 'schritt' => 6]) }}" class="card">
        @csrf
        <div class="card-body">
            @include('app.listings.schritte._speichern')
        </div>
    </form>
@endsection
