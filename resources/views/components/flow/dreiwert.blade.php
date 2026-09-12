@props([
    'name',
    'value' => 'unbekannt',
    'label' => null,
    'hint' => null,
])
{{--
    Dreiwertfeld Ja/Nein/Nicht bekannt als Segmentgruppe (Masterprompt-Abgleich
    B.1 Schritt 5, docs/ui-klassen.md erweitert um .dreiwert). Tastatur- und
    Screenreader-bedienbar über native Radiobuttons, nur optisch als Pillen
    dargestellt.
--}}
<div class="dreiwert-row">
    @if ($label)
        <div>
            <span class="dreiwert-row-label">{{ $label }}</span>
            @if ($hint)
                <p class="hint">{{ $hint }}</p>
            @endif
        </div>
    @endif
    <div class="dreiwert" role="radiogroup" @if($label) aria-label="{{ $label }}" @endif>
        @foreach (['ja' => 'Ja', 'nein' => 'Nein', 'unbekannt' => 'Nicht bekannt'] as $wert => $beschriftung)
            @php $id = $name.'_'.$wert; @endphp
            <input type="radio" name="{{ $name }}" id="{{ $id }}" value="{{ $wert }}" @checked($value === $wert)>
            <label for="{{ $id }}">{{ $beschriftung }}</label>
        @endforeach
    </div>
</div>
