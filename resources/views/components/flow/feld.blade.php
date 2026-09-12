@props([
    'name',
    'label',
    'type' => 'text',
    'value' => null,
    'hint' => null,
    'options' => null,
    'placeholder' => null,
])
{{--
    Allgemeines Formularfeld nach docs/ui-klassen.md (.field): Beschriftung,
    Eingabe, Hilfetext, Fehlertext. Reduziert Wiederholung in den
    Schrittformularen. Zusätzliche Attribute (required, min, max, step,
    maxlength …) werden über $attributes durchgereicht.
--}}
@php
    $aktuellerWert = old($name, $value);
@endphp
<div class="field @error($name) has-error @enderror">
    <label for="feld-{{ $name }}">{{ $label }}</label>

    @if ($type === 'select')
        <select id="feld-{{ $name }}" name="{{ $name }}" {{ $attributes }}>
            @foreach ($options ?? [] as $optionWert => $optionLabel)
                <option value="{{ $optionWert }}" @selected((string) $aktuellerWert === (string) $optionWert)>{{ $optionLabel }}</option>
            @endforeach
        </select>
    @elseif ($type === 'textarea')
        <textarea id="feld-{{ $name }}" name="{{ $name }}" {{ $attributes }}>{{ $aktuellerWert }}</textarea>
    @else
        <input
            type="{{ $type }}"
            id="feld-{{ $name }}"
            name="{{ $name }}"
            value="{{ $aktuellerWert }}"
            @if ($placeholder) placeholder="{{ $placeholder }}" @endif
            {{ $attributes }}
        >
    @endif

    @if ($hint)
        <p class="hint">{{ $hint }}</p>
    @endif
    @error($name)
        <p class="error">{{ $message }}</p>
    @enderror
</div>
